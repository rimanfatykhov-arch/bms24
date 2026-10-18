<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;

defined('MOODLE_INTERNAL') || die();

/**
 * CRM field discovery/provisioning.
 *
 * v5.0.31 deliberately rebases the remote userfield mechanics on v0.4.6:
 * - JSON body
 * - `<method>.json`
 * - pagination only through Bitrix `next`
 * - XML_ID first, then localized label
 *
 * v5 adds current/legacy FIELD_NAME matching and cached registry support.
 */
class crm_field_manager {
    /** @var client */
    private $client;

    public function __construct(?client $client = null) {
        $this->client = $client ?? new client();
    }

    public function resolve_entity(
        string $entity,
        bool $create = false
    ): array {
        $definitions = $this->definitions();

        if (empty($definitions[$entity])) {
            throw new \moodle_exception(
                'crm_entity_unknown',
                'local_bitrixsync',
                '',
                $entity
            );
        }

        $fields = $this->list_fields($entity);
        $out = [];
        $missing = [];

        foreach ($definitions[$entity] as $key => $def) {
            $existing = $this->find_existing(
                $fields,
                $def
            );

            $out[$key] = $this->result_row(
                $def,
                $existing
            );

            if (!$existing) {
                $missing[$key] = $def;
            }
        }

        if ($create && $missing) {
            // v0.4.6 created one missing field and refreshed the catalog.
            // Do the same. It is slower but deterministic and avoids creating
            // duplicates when Bitrix assigns/normalizes FIELD_NAME values.
            foreach ($missing as $key => $def) {
                $this->create_field(
                    $entity,
                    $def
                );

                $fields = $this->list_fields($entity);

                $out[$key] = $this->result_row(
                    $def,
                    $this->find_existing(
                        $fields,
                        $def
                    )
                );
            }

            // Final one-pass refresh for all definitions.
            $fields = $this->list_fields($entity);
            $out = [];

            foreach ($definitions[$entity] as $key => $def) {
                $out[$key] = $this->result_row(
                    $def,
                    $this->find_existing(
                        $fields,
                        $def
                    )
                );
            }
        }

        $this->merge_entity_cache(
            $entity,
            $out
        );

        return $out;
    }

    /**
     * Resolve one field through the same production CRM Field Engine used by
     * the rest of KBA Connect.
     */
    public function resolve_field(
        string $entity,
        string $key,
        bool $create = false
    ): array {
        $resolved = $this->resolve_entity($entity, $create);

        if (!array_key_exists($key, $resolved)) {
            throw new \moodle_exception(
                'crm_field_unknown',
                'local_bitrixsync',
                '',
                $entity . ':' . $key
            );
        }

        return $resolved[$key];
    }

    /**
     * Return live Bitrix24 user fields for one supported CRM entity.
     *
     * Intended for administrator selectors/diagnostics. No provisioning or
     * remote writes are performed.
     */
    public function list_entity_fields(string $entity): array {
        $definitions = $this->definitions();
        if (empty($definitions[$entity])) {
            throw new \moodle_exception('crm_entity_unknown', 'local_bitrixsync', '', $entity);
        }
        return $this->list_fields($entity);
    }

    /**
     * Resolve an administrator-selected Bitrix FIELD_NAME exactly.
     *
     * This intentionally does not use XML_ID/label fallbacks: an explicit
     * production setting must point to one concrete Bitrix field.
     */
    public function resolve_explicit_field(string $entity, string $fieldname): array {
        $fieldname = trim($fieldname);
        if ($fieldname === '') {
            return [
                'ready' => false,
                'field_name' => '',
                'source' => 'not_configured',
            ];
        }

        // First resolve against the same verified logical cache used by crm_fields.php.
        // This keeps an administrator-selected FIELD_NAME usable even when a live REST
        // userfield-list call is temporarily unavailable.
        $cached = $this->get_cached_resolution();
        foreach ((array)($cached[$entity] ?? []) as $cachedfield) {
            if (empty($cachedfield['ready']) ||
                    strcasecmp((string)($cachedfield['field_name'] ?? ''), $fieldname) !== 0) {
                continue;
            }
            return [
                'ready' => true,
                'field_name' => (string)($cachedfield['field_name'] ?? ''),
                'field_id' => (int)($cachedfield['field_id'] ?? 0),
                'xml_id' => (string)($cachedfield['xml_id'] ?? ''),
                'label' => (string)($cachedfield['label'] ?? $fieldname),
                'user_type_id' => (string)($cachedfield['user_type_id'] ?? ''),
                'multiple' => !empty($cachedfield['multiple']),
                'source' => 'explicit_config_cache',
            ];
        }

        foreach ($this->list_entity_fields($entity) as $field) {
            if (strcasecmp((string)($field['FIELD_NAME'] ?? ''), $fieldname) !== 0) {
                continue;
            }
            return [
                'ready' => true,
                'field_name' => (string)($field['FIELD_NAME'] ?? ''),
                'field_id' => (int)($field['ID'] ?? 0),
                'xml_id' => (string)($field['XML_ID'] ?? ''),
                'label' => $this->field_label($field),
                'user_type_id' => (string)($field['USER_TYPE_ID'] ?? ''),
                'multiple' => (string)($field['MULTIPLE'] ?? 'N') === 'Y',
                'source' => 'explicit_config_live',
            ];
        }

        return [
            'ready' => false,
            'field_name' => $fieldname,
            'source' => 'configured_field_missing',
        ];
    }

    /** Pick a readable localized label from a Bitrix userfield row. */
    private function field_label(array $field): string {
        foreach (['EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $key) {
            $label = $field[$key] ?? '';
            if (is_array($label)) {
                foreach (['ru', 'en'] as $lang) {
                    if (!empty($label[$lang]) && is_scalar($label[$lang])) {
                        return trim((string)$label[$lang]);
                    }
                }
                foreach ($label as $value) {
                    if (is_scalar($value) && trim((string)$value) !== '') {
                        return trim((string)$value);
                    }
                }
            } else if (is_scalar($label) && trim((string)$label) !== '') {
                return trim((string)$label);
            }
        }
        return (string)($field['FIELD_NAME'] ?? '');
    }

    public function provision_entity(
        string $entity
    ): array {
        return $this->resolve_entity(
            $entity,
            true
        );
    }

    public function definitions(): array {
        return [
            'company' => [
                'connected' => $this->def(
                    'KBA_CORP_CONNECTED',
                    'KBA_CONNECT_COMPANY_CONNECTED',
                    'Moodle — корпоративный кабинет',
                    'string'
                ),
                'corporate_company_id' => $this->def(
                    'KBA_CORP_ID',
                    'KBA_CONNECT_CORPORATE_COMPANY_ID',
                    'Moodle — Corporate Company ID',
                    'integer'
                ),
                'course_count' => $this->def(
                    'KBA_COURSES_N',
                    'KBA_CONNECT_COMPANY_COURSE_COUNT',
                    'Moodle — количество курсов',
                    'integer'
                ),
                'course_list' => $this->def(
                    'KBA_COURSES',
                    'KBA_CONNECT_COMPANY_COURSE_LIST',
                    'Moodle — список курсов',
                    'string',
                    true
                ),
                'employee_count' => $this->def(
                    'KBA_EMPLOYEES_N',
                    'KBA_CONNECT_COMPANY_EMPLOYEE_COUNT',
                    'Moodle — количество сотрудников',
                    'integer'
                ),
                'sync_date' => $this->def(
                    'KBA_SYNC_AT',
                    'KBA_CONNECT_COMPANY_SYNC_DATE',
                    'Moodle — дата синхронизации',
                    'datetime'
                ),
                'status' => $this->def(
                    'KBA_STATUS',
                    'KBA_CONNECT_COMPANY_STATUS',
                    'Moodle — статус',
                    'string'
                ),
                'bms_client' => $this->def(
                    'KBA_BMS_CLIENT',
                    'KBA_CONNECT_BMS_CLIENT',
                    'KBA — Клиент BMS',
                    'boolean'
                ),
            ],

            'contact' => [
                'moodle_user_id' => $this->def(
                    'KBA_MOODLE_ID',
                    'KBA_CONNECT_CONTACT_MOODLE_USER_ID',
                    'Moodle User ID',
                    'integer',
                    false,
                    ['KBA_MOODLE_USER_ID'],
                    ['MBA_M_UID']
                ),
                'moodle_login' => $this->def(
                    'KBA_MOODLE_LOGIN',
                    'KBA_CONNECT_CONTACT_MOODLE_LOGIN',
                    'Moodle Login',
                    'string',
                    false,
                    ['KBA_MOODLE_LOGIN'],
                    ['MBA_M_LOGIN']
                ),
                'profile_url' => $this->def(
                    'KBA_MOODLE_URL',
                    'KBA_CONNECT_CONTACT_MOODLE_PROFILE_URL',
                    'Moodle Profile URL',
                    'url',
                    false,
                    ['KBA_MOODLE_PROFILE_URL'],
                    ['MBA_M_URL']
                ),
                'corporate_employee' => $this->def(
                    'KBA_CORP_EMP',
                    'KBA_CONNECT_CONTACT_CORPORATE_EMPLOYEE',
                    'Moodle — сотрудник Corporate Dashboard',
                    'string'
                ),
                'corporate_roles' => $this->def(
                    'KBA_CORPORATE_ROLES',
                    'KBA_CORPORATE_LEARNING_ROLES',
                    'KBA — Роли корпоративного обучения',
                    'enumeration',
                    true
                ),
                'position' => $this->def(
                    'KBA_POSITION',
                    'KBA_CONNECT_CONTACT_POSITION',
                    'Moodle — должность',
                    'string'
                ),
                'last_login' => $this->def(
                    'KBA_LAST_LOGIN',
                    'KBA_CONNECT_CONTACT_LAST_LOGIN',
                    'Moodle — последний вход',
                    'datetime'
                ),
            ],

            'deal' => [
                'enrollment_status' => $this->def(
                    'KBA_ENROLL_STATUS',
                    'KBA_CONNECT_DEAL_ENROLLMENT_STATUS',
                    'Moodle — статус зачисления',
                    'string',
                    false,
                    ['KBA_MOODLE_ENROL_STATUS'],
                    ['MBA_M_ENROL']
                ),
                'courses_selected' => $this->def(
                    'KBA_COURSES_SEL',
                    'KBA_CONNECT_DEAL_COURSES_SELECTED',
                    'Moodle — курсов выбрано',
                    'integer'
                ),
                'courses_enrolled' => $this->def(
                    'KBA_COURSES_ENR',
                    'KBA_CONNECT_DEAL_COURSES_ENROLLED',
                    'Moodle — курсов зачислено',
                    'integer'
                ),
                'sync_date' => $this->def(
                    'KBA_SYNC_AT',
                    'KBA_CONNECT_DEAL_SYNC_DATE',
                    'Moodle — дата синхронизации',
                    'datetime',
                    false,
                    ['KBA_MOODLE_LAST_SYNC'],
                    ['MBA_M_SYNC'],
                    ['Moodle — дата последней синхронизации']
                ),
                'error' => $this->def(
                    'KBA_ERROR',
                    'KBA_CONNECT_DEAL_ERROR',
                    'Moodle — ошибка',
                    'string',
                    false,
                    ['KBA_MOODLE_SYNC_ERROR'],
                    ['MBA_M_ERR'],
                    ['Moodle — ошибка синхронизации']
                ),
            ],
        ];
    }

    public function resolve_all(
        bool $create = false
    ): array {
        $out = [];

        foreach (array_keys($this->definitions()) as $entity) {
            $out[$entity] = $this->resolve_entity(
                $entity,
                $create
            );
        }

        return $out;
    }

    public function import_legacy_fieldmap(): array {
        $legacy = $this->get_legacy_fieldmap();
        if (!$legacy) {
            return [
                'imported' => 0,
                'entities' => [],
            ];
        }

        $definitions = $this->definitions();
        $cached = $this->get_cached_resolution();
        $imported = 0;
        $entities = [];

        foreach ($definitions as $entity => $defs) {
            foreach ($defs as $key => $def) {
                $legacyrow = $this->find_legacy_map_row(
                    $legacy,
                    $entity,
                    $def
                );

                if (!$legacyrow ||
                        empty($legacyrow['field_name'])) {
                    continue;
                }

                $cached[$entity][$key] = [
                    'ready' => true,
                    'field_name' =>
                        (string)$legacyrow['field_name'],
                    'field_id' =>
                        (int)($legacyrow['id'] ?? 0),
                    'label' => $def['LABEL'],
                    'xml_id' => $def['XML_ID'],
                    // IMPORTANT: use actual old type, not the new desired type.
                    'user_type_id' =>
                        (string)(
                            $legacyrow['user_type_id'] ??
                            $def['USER_TYPE_ID']
                        ),
                    'multiple' => $def['MULTIPLE'],
                    'source' => 'legacy_v0_4_6_fieldmap',
                    'legacy_xml_id' =>
                        (string)($legacyrow['xml_id'] ?? ''),
                    'enum' => is_array(
                        $legacyrow['enum'] ?? null
                    ) ? $legacyrow['enum'] : [],
                ];

                $imported++;
                $entities[$entity] =
                    ($entities[$entity] ?? 0) + 1;
            }
        }

        $this->save_cache($cached);

        return [
            'imported' => $imported,
            'entities' => $entities,
        ];
    }

    public function get_legacy_fieldmap(): array {
        $raw = (string)get_config(
            'local_bitrixsync',
            'fieldmap'
        );

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode(
            $raw,
            true
        );

        return is_array($decoded)
            ? $decoded
            : [];
    }

    public function legacy_field_summary(): array {
        $legacy = $this->get_legacy_fieldmap();
        $out = [];

        foreach ($legacy as $entity => $rows) {
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $xmlid => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $label = (string)($row['label'] ?? '');
                $fieldname = (string)($row['field_name'] ?? '');

                if (stripos($label, 'moodle') === false &&
                        stripos((string)$xmlid, 'MOODLE') === false &&
                        stripos($fieldname, 'MBA_M_') === false) {
                    continue;
                }

                $out[$entity][] = [
                    'xml_id' => (string)$xmlid,
                    'label' => $label,
                    'field_name' => $fieldname,
                    'id' => (int)($row['id'] ?? 0),
                    'type' =>
                        (string)($row['user_type_id'] ?? ''),
                ];
            }
        }

        return $out;
    }

    public function get_cached_resolution(): array {
        $raw = (string)get_config(
            'local_bitrixsync',
            'crm_field_resolution_cache'
        );

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode(
            $raw,
            true
        );

        if (!is_array($decoded) ||
                empty($decoded['fields']) ||
                !is_array($decoded['fields'])) {
            return [];
        }

        return $decoded['fields'];
    }

    public function get_cached_resolution_time(): int {
        $raw = (string)get_config(
            'local_bitrixsync',
            'crm_field_resolution_cache'
        );

        if ($raw === '') {
            return 0;
        }

        $decoded = json_decode(
            $raw,
            true
        );

        return is_array($decoded)
            ? (int)($decoded['time'] ?? 0)
            : 0;
    }

    public function get_registry(
        bool $refresh = false
    ): array {
        if ($refresh) {
            return $this->simple_registry(
                $this->resolve_all(false)
            );
        }

        $raw = (string)get_config(
            'local_bitrixsync',
            'crm_field_registry'
        );

        if ($raw !== '') {
            $decoded = json_decode(
                $raw,
                true
            );

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $cached = $this->get_cached_resolution();

        return $cached
            ? $this->simple_registry($cached)
            : [];
    }

    public function is_ready(
        bool $refresh = false
    ): bool {
        $registry = $this->get_registry(
            $refresh
        );
        $definitions = $this->definitions();

        foreach ($definitions as $entity => $fields) {
            foreach ($fields as $key => $definition) {
                if (empty($registry[$entity][$key])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Convert the v5 status into the correct value for an old v0.4.6
     * enumeration field, when that exact old field is being reused.
     */
    public function encode_deal_enrollment_status(
        string $status
    ) {
        $cached = $this->get_cached_resolution();
        $field = $cached['deal']['enrollment_status'] ?? [];

        if (($field['user_type_id'] ?? '') !== 'enumeration') {
            return $status;
        }

        $labelmap = [
            'completed' => 'Зачислен',
            'processing' => 'Ожидает зачисления',
            'partial' => 'Ошибка',
            'error' => 'Ошибка',
        ];

        $label = $labelmap[$status] ?? $status;

        $enum = is_array($field['enum'] ?? null)
            ? $field['enum']
            : [];

        $id = $this->find_enum_id(
            $enum,
            $label
        );

        if ($id !== null) {
            return $id;
        }

        // Old fieldmap may not have cached LIST IDs. Fetch the full existing
        // field definition exactly as v0.4.6 did.
        $fieldid = (int)($field['field_id'] ?? 0);

        if ($fieldid > 0) {
            try {
                $response = $this->client->call_json(
                    'crm.deal.userfield.get',
                    ['id' => $fieldid]
                );

                $fullfield = is_array(
                    $response['result'] ?? null
                ) ? $response['result'] : [];

                $enum = [];
                foreach (($fullfield['LIST'] ?? []) as $item) {
                    if (isset($item['VALUE']) &&
                            isset($item['ID'])) {
                        $enum[(string)$item['VALUE']] =
                            (string)$item['ID'];
                    }
                }

                $id = $this->find_enum_id(
                    $enum,
                    $label
                );

                if ($id !== null) {
                    // Persist fetched enum IDs.
                    $cached['deal']['enrollment_status']['enum'] =
                        $enum;
                    $this->save_cache($cached);

                    return $id;
                }
            } catch (\Throwable $ignored) {
                // CRM writeback will report the actual Bitrix error if the
                // field cannot accept the fallback text value.
            }
        }

        return $status;
    }

    private function list_fields(
        string $entity
    ): array {
        $method =
            'crm.' .
            $entity .
            '.userfield.list';

        $items = [];
        $start = 0;
        $guard = 0;
        $seenstarts = [];

        // EXACT v0.4.6 pagination contract:
        // keep going only when Bitrix explicitly returns `next`.
        do {
            if (isset($seenstarts[$start])) {
                throw new \moodle_exception(
                    'crm_field_pagination_stalled',
                    'local_bitrixsync',
                    '',
                    $entity
                );
            }

            $seenstarts[$start] = true;

            $response = $this->client->call_json(
                $method,
                [
                    'filter' => [
                        'LANG' => 'ru',
                    ],
                    'order' => [
                        'ID' => 'ASC',
                    ],
                    'start' => $start,
                ]
            );

            $page = $response['result'] ?? [];

            if (!is_array($page)) {
                break;
            }

            foreach ($page as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }

            if (isset($response['next']) &&
                    is_numeric($response['next'])) {
                $next = (int)$response['next'];

                if ($next <= $start) {
                    throw new \moodle_exception(
                        'crm_field_pagination_stalled',
                        'local_bitrixsync',
                        '',
                        $entity
                    );
                }

                $start = $next;
            } else {
                $start = null;
            }

            $guard++;
        } while ($start !== null && $guard < 100);

        if ($guard >= 100) {
            throw new \moodle_exception(
                'crm_field_pagination_limit',
                'local_bitrixsync',
                '',
                $entity
            );
        }

        return $items;
    }

    private function find_existing(
        array $fields,
        array $def
    ): ?array {
        // 1. Current and legacy XML_ID.
        $xmlids = array_merge(
            [$def['XML_ID']],
            $def['LEGACY_XML_IDS']
        );

        foreach ($fields as $field) {
            $xmlid = (string)($field['XML_ID'] ?? '');

            if ($xmlid !== '' &&
                    in_array($xmlid, $xmlids, true)) {
                $field['_source'] =
                    $xmlid === $def['XML_ID']
                        ? 'xml_id'
                        : 'legacy_xml_id';

                return $field;
            }
        }

        // 2. Current and old FIELD_NAME. Use PHP 7 compatible suffix check.
        $fieldnames = array_merge(
            [$def['FIELD_NAME']],
            $def['LEGACY_FIELD_NAMES']
        );

        foreach ($fields as $field) {
            $actual = strtoupper((string)(
                $field['FIELD_NAME'] ?? ''
            ));

            foreach ($fieldnames as $wanted) {
                $wanted = strtoupper((string)$wanted);
                $suffix = '_' . $wanted;

                $suffixmatches =
                    strlen($actual) >= strlen($suffix) &&
                    substr(
                        $actual,
                        -strlen($suffix)
                    ) === $suffix;

                if ($actual === $wanted ||
                        $suffixmatches) {
                    $field['_source'] =
                        $wanted === strtoupper($def['FIELD_NAME'])
                            ? 'field_name'
                            : 'legacy_field_name';

                    return $field;
                }
            }
        }

        // 3. v0.4.6 behavior: localized human label.
        $labels = array_merge(
            [$def['LABEL']],
            $def['LEGACY_LABELS']
        );

        $wantedlabels = array_map(
            [$this, 'normalize_label'],
            $labels
        );

        foreach ($fields as $field) {
            foreach ($this->field_labels($field) as $label) {
                if ($label === '') {
                    continue;
                }

                if (in_array(
                        $this->normalize_label($label),
                        $wantedlabels,
                        true
                    )) {
                    $field['_source'] = 'legacy_label';
                    return $field;
                }
            }
        }

        return null;
    }

    private function field_labels(
        array $field
    ): array {
        $out = [];

        foreach ([
            'EDIT_FORM_LABEL',
            'LIST_COLUMN_LABEL',
            'LIST_FILTER_LABEL',
            'LABEL',
        ] as $key) {
            if (empty($field[$key])) {
                continue;
            }

            if (is_string($field[$key])) {
                $out[] = $field[$key];
                continue;
            }

            if (is_array($field[$key])) {
                if (!empty($field[$key]['ru'])) {
                    $out[] = (string)$field[$key]['ru'];
                }

                $first = reset($field[$key]);
                if (is_string($first)) {
                    $out[] = $first;
                }

                foreach ($field[$key] as $label) {
                    if (is_string($label)) {
                        $out[] = $label;
                    }
                }
            }
        }

        return array_values(
            array_unique($out)
        );
    }

    private function create_field(
        string $entity,
        array $def
    ): int {
        $fields = [
            'LABEL' => $def['LABEL'],
            'USER_TYPE_ID' => $def['USER_TYPE_ID'],
            'FIELD_NAME' => $def['FIELD_NAME'],
            'XML_ID' => $def['XML_ID'],
            'MULTIPLE' =>
                $def['MULTIPLE'] ? 'Y' : 'N',
            'MANDATORY' => 'N',
            'SHOW_FILTER' => 'Y',
            'SHOW_IN_LIST' => 'N',
            'EDIT_IN_LIST' => 'Y',
            'IS_SEARCHABLE' => 'N',
            'SORT' => $def['SORT'],
        ];

        $response = $this->client->call_json(
            'crm.' .
            $entity .
            '.userfield.add',
            ['fields' => $fields]
        );

        return (int)($response['result'] ?? 0);
    }

    private function def(
        string $fieldname,
        string $xmlid,
        string $label,
        string $type,
        bool $multiple = false,
        array $legacyxmlids = [],
        array $legacyfieldnames = [],
        array $legacylabels = []
    ): array {
        static $sort = 5000;
        $sort += 10;

        return [
            'FIELD_NAME' => $fieldname,
            'XML_ID' => $xmlid,
            'LABEL' => $label,
            'USER_TYPE_ID' => $type,
            'MULTIPLE' => $multiple,
            'SORT' => $sort,
            'LEGACY_XML_IDS' => $legacyxmlids,
            'LEGACY_FIELD_NAMES' => $legacyfieldnames,
            'LEGACY_LABELS' => $legacylabels,
        ];
    }

    private function result_row(
        array $def,
        ?array $resolved
    ): array {
        return [
            'ready' => (bool)$resolved,
            'field_name' => $resolved
                ? (string)($resolved['FIELD_NAME'] ?? '')
                : '',
            'field_id' => $resolved
                ? (int)($resolved['ID'] ?? 0)
                : 0,
            'label' => $def['LABEL'],
            'xml_id' => $def['XML_ID'],
            // Use the ACTUAL field type when reusing an old v0.4.6 field.
            'user_type_id' => $resolved
                ? (string)(
                    $resolved['USER_TYPE_ID'] ??
                    $def['USER_TYPE_ID']
                )
                : $def['USER_TYPE_ID'],
            'multiple' => $resolved
                ? ((string)($resolved['MULTIPLE'] ?? 'N') === 'Y')
                : $def['MULTIPLE'],
            'source' => $resolved
                ? (string)($resolved['_source'] ?? 'existing')
                : 'missing',
            'enum' => [],
        ];
    }

    private function merge_entity_cache(
        string $entity,
        array $fields
    ): void {
        $cached = $this->get_cached_resolution();
        $cached[$entity] = $fields;
        $this->save_cache($cached);
    }

    private function save_cache(
        array $cached
    ): void {
        set_config(
            'crm_field_resolution_cache',
            json_encode(
                [
                    'time' => time(),
                    'fields' => $cached,
                ],
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );

        set_config(
            'crm_field_registry',
            json_encode(
                $this->simple_registry($cached),
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );
    }

    private function find_legacy_map_row(
        array $legacy,
        string $entity,
        array $def
    ): ?array {
        if (empty($legacy[$entity]) ||
                !is_array($legacy[$entity])) {
            return null;
        }

        $xmlids = array_merge(
            [$def['XML_ID']],
            $def['LEGACY_XML_IDS']
        );

        foreach ($xmlids as $xmlid) {
            if (!empty($legacy[$entity][$xmlid]) &&
                    is_array($legacy[$entity][$xmlid])) {
                return $legacy[$entity][$xmlid];
            }
        }

        $wantedlabels = array_map(
            [$this, 'normalize_label'],
            array_merge(
                [$def['LABEL']],
                $def['LEGACY_LABELS']
            )
        );

        foreach ($legacy[$entity] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = (string)($row['label'] ?? '');

            if ($label !== '' &&
                    in_array(
                        $this->normalize_label($label),
                        $wantedlabels,
                        true
                    )) {
                return $row;
            }
        }

        return null;
    }

    private function simple_registry(
        array $resolved
    ): array {
        $out = [];

        foreach ($resolved as $entity => $fields) {
            foreach ($fields as $key => $field) {
                if (!empty($field['ready']) &&
                        !empty($field['field_name'])) {
                    $out[$entity][$key] =
                        (string)$field['field_name'];
                }
            }
        }

        return $out;
    }

    private function find_enum_id(
        array $enum,
        string $label
    ): ?string {
        if (array_key_exists($label, $enum)) {
            return (string)$enum[$label];
        }

        $wanted = $this->normalize_label(
            $label
        );

        foreach ($enum as $value => $id) {
            if ($this->normalize_label(
                    (string)$value
                ) === $wanted) {
                return (string)$id;
            }
        }

        return null;
    }

    private function normalize_label(
        string $value
    ): string {
        $value = trim($value);
        $value = str_replace(
            ['–', '—'],
            '-',
            $value
        );
        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

        return \core_text::strtolower(
            (string)$value
        );
    }
}
