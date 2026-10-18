<?php
namespace local_bitrixsync\services;

use local_bitrixsync\adapters\bitrix24\client;
use local_bitrixsync\adapters\corporate\api_client as corporate_api_client;
use local_bitrixsync\logging\logger;

defined('MOODLE_INTERNAL') || die();

/**
 * Contract bridge for Bitrix24 Contact corporate-learning roles.
 *
 * The role list is sourced from KBA Corporate Dashboard Public API v1.10.
 * KBA Connect owns Bitrix24 REST and never mutates Corporate Dashboard tables.
 */
class corporate_role_schema_service {
    private const ENTITY = 'contact';
    private const LABEL = 'KBA — Роли корпоративного обучения';
    private const XML_ID = 'KBA_CORPORATE_LEARNING_ROLES';
    private const FIELD_NAME = 'KBA_CORPORATE_ROLES';

    /** @var client */
    private $bitrix;
    /** @var corporate_api_client */
    private $corporate;
    /** @var crm_field_manager */
    private $fieldmanager;

    public function __construct(
        ?client $bitrix = null,
        ?corporate_api_client $corporate = null,
        ?crm_field_manager $fieldmanager = null
    ) {
        $this->bitrix = $bitrix ?? new client();
        $this->corporate = $corporate ?? new corporate_api_client();
        $this->fieldmanager = $fieldmanager ?? new crm_field_manager($this->bitrix);
    }

    public function status(): array {
        logger::corporate_schema('Status requested');

        try {
            $schema = $this->role_schema();

            $resolved = $this->fieldmanager->resolve_field(
                'contact',
                'corporate_roles',
                false
            );

            if (empty($resolved['ready']) || empty($resolved['field_id'])) {
                logger::corporate_schema('Contact role field not found by CRM Field Engine');
                return [
                    'success' => true,
                    'ready' => false,
                    'entity' => self::ENTITY,
                    'reason' => 'field_missing',
                    'bitrix24' => [
                        'connected' => true,
                        'source' => 'crm_field_engine',
                    ],
                    'dashboard' => $this->dashboard_status(),
                ];
            }

            logger::corporate_schema('Bitrix connection OK via CRM Field Engine');

            $field = $this->get_field((int)$resolved['field_id']);
            if (!$field) {
                throw new \moodle_exception(
                    'corporate_schema_field_read_failed',
                    'local_bitrixsync'
                );
            }

            $type = (string)($field['USER_TYPE_ID'] ?? '');
            if ($type !== 'enumeration') {
                return $this->schema_not_ready('field_wrong_type', $field, $schema);
            }

            $multiple = ((string)($field['MULTIPLE'] ?? 'N') === 'Y');
            if (!$multiple) {
                return $this->schema_not_ready('field_not_multiple', $field, $schema);
            }

            $full = $this->get_field((int)($field['ID'] ?? 0));
            if ($full) {
                $field = array_merge($field, $full);
            }

            $roles = $this->role_status($field, $schema);
            $ready = true;
            foreach ($roles as $row) {
                if (empty($row['exists'])) {
                    $ready = false;
                    break;
                }
            }

            $this->save_field_identity($field);
            $this->save_enum_mapping($field, $schema);

            return [
                'success' => true,
                'ready' => $ready,
                'entity' => self::ENTITY,
                'reason' => $ready ? null : 'enum_missing',
                'field' => $this->field_payload($field),
                'roles' => $roles,
                'enum_values' => $this->actual_enum_values($field, $schema),
                'bitrix24' => [
                    'connected' => true,
                    'source' => 'crm_field_engine',
                ],
                'dashboard' => $this->dashboard_status(),
            ];
        } catch (\Throwable $e) {
            return $this->error_from_exception($e);
        }
    }

    public function provision(): array {
        logger::corporate_schema('Provision started');

        try {
            $schema = $this->role_schema();
            $changes = [];

            $before = $this->fieldmanager->resolve_field(
                'contact',
                'corporate_roles',
                false
            );
            $resolved = $this->fieldmanager->resolve_field(
                'contact',
                'corporate_roles',
                true
            );

            if (empty($resolved['ready']) || empty($resolved['field_id'])) {
                throw new \moodle_exception(
                    'corporate_schema_field_create_failed',
                    'local_bitrixsync'
                );
            }

            $field = $this->get_field((int)$resolved['field_id']);
            if (!$field) {
                throw new \moodle_exception(
                    'corporate_schema_field_read_failed',
                    'local_bitrixsync'
                );
            }

            if (empty($before['ready'])) {
                $changes[] = 'field_created';
                logger::corporate_schema('Field created via CRM Field Engine', [
                    'field_id' => (int)$resolved['field_id'],
                    'field_name' => (string)$resolved['field_name'],
                ]);
            }

            if ((string)($field['USER_TYPE_ID'] ?? '') !== 'enumeration' ||
                    (string)($field['MULTIPLE'] ?? 'N') !== 'Y') {
                logger::corporate_schema('Existing field schema conflict', [
                    'field_id' => (int)($field['ID'] ?? 0),
                    'type' => (string)($field['USER_TYPE_ID'] ?? ''),
                    'multiple' => (string)($field['MULTIPLE'] ?? 'N'),
                ]);
                return [
                    'success' => false,
                    'code' => 'field_schema_conflict',
                    'message' => 'Existing KBA role field has incompatible schema',
                    'field' => $this->field_payload($field),
                ];
            }

            $missing = $this->missing_roles($field, $schema);
            if ($missing) {
                $field = $this->ensure_roles($field, $schema, $missing);
                foreach ($missing as $rolecode) {
                    $changes[] = 'enum_added:' . $rolecode;
                    logger::corporate_schema('Added enum ' . $rolecode);
                }
            } else {
                logger::corporate_schema('Existing compatible field found');
                logger::corporate_schema('No changes required');
            }

            $this->save_field_identity($field);
            $this->save_enum_mapping($field, $schema);

            $result = $this->status();
            $result['changes'] = $changes;
            if (!empty($result['ready'])) {
                logger::corporate_schema('SUCCESS');
            }
            return $result;
        } catch (\Throwable $e) {
            return $this->error_from_exception($e);
        }
    }

    /** Read Contact enum values as stable role codes. */
    public function read_contact_roles(int $contactid): array {
        if ($contactid <= 0) {
            return ['success' => false, 'code' => 'invalid_contact_id', 'roles' => []];
        }

        $status = $this->status();
        if (empty($status['success']) || empty($status['ready'])) {
            return [
                'success' => false,
                'code' => 'role_schema_not_ready',
                'roles' => [],
                'schema' => $status,
            ];
        }

        try {
            $response = $this->bitrix->call_json('crm.contact.get', ['id' => $contactid]);
            $contact = is_array($response['result'] ?? null) ? $response['result'] : [];
            $fieldname = (string)($status['field']['field_name'] ?? '');
            $raw = $fieldname !== '' ? ($contact[$fieldname] ?? []) : [];
            if (!is_array($raw)) {
                $raw = ($raw === '' || $raw === null) ? [] : [$raw];
            }

            $mapping = $this->enum_mapping();
            $roles = [];
            foreach ($raw as $value) {
                $key = (string)$value;
                if (isset($mapping[$key])) {
                    $roles[] = $mapping[$key];
                    continue;
                }
                // Defensive compatibility if Bitrix returns XML_ID directly.
                if (in_array($key, $mapping, true)) {
                    $roles[] = $key;
                }
            }

            return [
                'success' => true,
                'contactid' => $contactid,
                'field_name' => $fieldname,
                'roles' => array_values(array_unique($roles)),
            ];
        } catch (\Throwable $e) {
            return $this->error_from_exception($e) + ['roles' => []];
        }
    }

    /**
     * Last role schema normalization diagnostic. Contains no secrets.
     */
    public function role_schema_diagnostic(): array {
        $raw = (string)get_config(
            'local_bitrixsync',
            'corporate_role_schema_last_diagnostic'
        );
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function enum_mapping(): array {
        $raw = (string)get_config('local_bitrixsync', 'corporate_role_enum_map');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Legacy compatibility helper.
     * New feature code relies on crm_field_manager for Bitrix access.
     */
    private function assert_bitrix_available(): void {
        $this->fieldmanager->resolve_entity('contact', false);
    }

    /**
     * Return the normalized Corporate role contract.
     *
     * SECURITY / DATA INTEGRITY RULE:
     * Only these four machine codes may ever become Bitrix enum values.
     * Unknown top-level schema metadata must never be interpreted as roles.
     */
    private function role_schema(): array {
        $allowed = [
            'employee' => 'Сотрудник',
            'corporate_manager' => 'Корпоративный менеджер',
            'department_manager' => 'Руководитель подразделения',
            'hr' => 'HR',
        ];

        $raw = [];
        try {
            $raw = $this->corporate->get_role_schema();
        } catch (\Throwable $ignored) {
            $raw = [];
        }

        $rolesraw = [];
        $source = 'none';

        // Accept only an explicit roles container from the Corporate contract.
        if (isset($raw['roles']) && is_array($raw['roles'])) {
            $rolesraw = $raw['roles'];
            $source = 'dashboard.roles';
        } else {
            // Paired API 1.11+ has a stable canonical role contract.
            $dashboard = $this->dashboard_status();
            if (!empty($dashboard['detected']) &&
                    version_compare((string)($dashboard['api_release'] ?? '0'), '1.11', '>=')) {
                $rolesraw = $allowed;
                $source = 'canonical_api_1_11';
            }
        }

        $roles = [];
        $unexpected = [];

        foreach ($rolesraw as $key => $value) {
            $code = '';
            $label = '';
            $xmlid = '';

            if (is_string($key) && array_key_exists($key, $allowed)) {
                $code = $key;

                if (is_array($value)) {
                    $label = trim((string)(
                        $value['label'] ??
                        $value['name'] ??
                        $value['title'] ??
                        $allowed[$code]
                    ));
                    $xmlid = trim((string)(
                        $value['xml_id'] ??
                        $value['xmlid'] ??
                        $value['code'] ??
                        $code
                    ));
                } else {
                    $label = trim((string)$value);
                    $xmlid = $code;
                }
            } else if (is_array($value)) {
                $candidate = trim((string)(
                    $value['code'] ??
                    $value['rolecode'] ??
                    $value['xml_id'] ??
                    $value['xmlid'] ??
                    ''
                ));

                if ($candidate !== '' && array_key_exists($candidate, $allowed)) {
                    $code = $candidate;
                    $label = trim((string)(
                        $value['label'] ??
                        $value['name'] ??
                        $value['title'] ??
                        $allowed[$code]
                    ));
                    $xmlid = trim((string)(
                        $value['xml_id'] ??
                        $value['xmlid'] ??
                        $code
                    ));
                }
            }

            if ($code === '') {
                $unexpected[] = is_string($key) ? $key : json_encode($value, JSON_UNESCAPED_UNICODE);
                continue;
            }

            // XML_ID is also normalized to the stable rolecode. We do not allow
            // arbitrary metadata to control it.
            $roles[$code] = [
                'code' => $code,
                'label' => $label !== '' ? $label : $allowed[$code],
                'xml_id' => $code,
            ];
        }

        // The contract is complete only when all four roles are present.
        // For paired API 1.11+ canonical fallback this will naturally be 4/4.
        $missing = [];
        foreach ($allowed as $code => $label) {
            if (!isset($roles[$code])) {
                $missing[] = $code;
            }
        }

        $diagnostic = [
            'time' => time(),
            'source' => $source,
            'raw_keys' => is_array($raw) ? array_keys($raw) : [],
            'normalized_roles' => array_keys($roles),
            'unexpected_values' => array_values(array_filter($unexpected, function($v) {
                return $v !== null && $v !== '';
            })),
            'missing_roles' => $missing,
        ];

        set_config(
            'corporate_role_schema_last_diagnostic',
            json_encode(
                $diagnostic,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );

        logger::corporate_schema('Role schema normalized', $diagnostic);

        if ($missing) {
            throw new \moodle_exception(
                'corporate_role_schema_invalid',
                'local_bitrixsync',
                '',
                implode(', ', $missing)
            );
        }

        return $roles;
    }


    private function dashboard_status(): array {
        // Pairing is the primary connection truth from v5.0.34 onward.
        // The local PHP bridge remains only a legacy fallback for older setups.
        try {
            $pairing = new \local_bitrixsync\services\corporate_pairing_service();
            $connection = $pairing->latest_connection();
            if ($connection) {
                $api = (string)($connection->apiversion ?? '0');
                return [
                    'detected' => true,
                    'source' => 'pairing',
                    'component' => (string)($connection->component ?? 'local_kbacorporate'),
                    'client_version' => (string)($connection->clientversion ?? ''),
                    'api_version' => 1,
                    'api_release' => $api,
                    'role_schema_compatible' => version_compare($api, '1.11', '>='),
                    'last_seen' => (int)($connection->lastseen ?? 0),
                ];
            }
        } catch (\Throwable $ignored) {
            // Continue to legacy local bridge fallback.
        }

        $contract = $this->corporate->contract();
        return [
            'detected' => $this->corporate->is_available(),
            'source' => 'legacy_bridge',
            'component' => 'local_kbacorporate',
            'client_version' => '',
            'api_version' => (int)($contract['api_version'] ?? 0),
            'api_release' => (string)($contract['api_release'] ?? '0'),
            'role_schema_compatible' => $this->corporate->is_role_schema_compatible(),
            'last_seen' => 0,
        ];
    }

    private function discover_field(): ?array {
        $savedid = (int)get_config('local_bitrixsync', 'corporate_role_field_id');
        $savedname = trim((string)get_config('local_bitrixsync', 'corporate_role_field_name'));
        if ($savedid > 0) {
            try {
                $field = $this->get_field($savedid);
                if ($field && $this->is_candidate_field($field, $savedname)) {
                    return $field;
                }
            } catch (\Throwable $ignored) {
                // Fall back to non-destructive discovery below.
            }
        }

        $fields = $this->list_fields();
        $wanted = $this->normalize_label(self::LABEL);
        foreach ($fields as $field) {
            $labels = $this->field_labels($field);
            foreach ($labels as $label) {
                if ($this->normalize_label($label) === $wanted) {
                    $full = $this->get_field((int)($field['ID'] ?? 0));
                    return $full ?: $field;
                }
            }
        }

        // Fallback to stable XML_ID for a field originally provisioned by KBA Connect.
        foreach ($fields as $field) {
            if ((string)($field['XML_ID'] ?? '') === self::XML_ID) {
                $full = $this->get_field((int)($field['ID'] ?? 0));
                return $full ?: $field;
            }
        }

        return null;
    }

    private function list_fields(): array {
        $items = [];
        $start = 0;
        $guard = 0;
        do {
            $response = $this->bitrix->call_json('crm.contact.userfield.list', [
                'filter' => ['LANG' => 'ru'],
                'order' => ['ID' => 'ASC'],
                'start' => $start,
            ]);
            $page = is_array($response['result'] ?? null) ? $response['result'] : [];
            foreach ($page as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
            if (isset($response['next']) && is_numeric($response['next'])) {
                $next = (int)$response['next'];
                if ($next <= $start) {
                    break;
                }
                $start = $next;
            } else {
                $start = null;
            }
            $guard++;
        } while ($start !== null && $guard < 100);
        return $items;
    }

    private function get_field(int $fieldid): array {
        if ($fieldid <= 0) {
            return [];
        }
        $response = $this->bitrix->call_json('crm.contact.userfield.get', ['id' => $fieldid]);
        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    private function create_field(array $schema): int {
        $list = [];
        $sort = 100;
        foreach ($schema as $role) {
            $list[] = [
                'VALUE' => $role['label'],
                'XML_ID' => $role['xml_id'],
                'SORT' => $sort,
                'DEF' => 'N',
            ];
            $sort += 100;
        }

        $fields = [
            'LABEL' => self::LABEL,
            'EDIT_FORM_LABEL' => ['ru' => self::LABEL, 'en' => self::LABEL],
            'LIST_COLUMN_LABEL' => ['ru' => self::LABEL, 'en' => self::LABEL],
            'LIST_FILTER_LABEL' => ['ru' => self::LABEL, 'en' => self::LABEL],
            'USER_TYPE_ID' => 'enumeration',
            'FIELD_NAME' => self::FIELD_NAME,
            'XML_ID' => self::XML_ID,
            'MULTIPLE' => 'Y',
            'MANDATORY' => 'N',
            'SHOW_FILTER' => 'Y',
            'SHOW_IN_LIST' => 'Y',
            'EDIT_IN_LIST' => 'Y',
            'IS_SEARCHABLE' => 'N',
            'SORT' => 6200,
            'LIST' => $list,
        ];

        $response = $this->bitrix->call_json('crm.contact.userfield.add', ['fields' => $fields]);
        return (int)($response['result'] ?? 0);
    }

    /**
     * Return only missing roles from the strict normalized contract.
     */
    private function missing_roles(array $field, array $schema): array {
        $allowed = [
            'employee',
            'corporate_manager',
            'department_manager',
            'hr',
        ];

        foreach (array_keys($schema) as $code) {
            if (!in_array((string)$code, $allowed, true)) {
                throw new \moodle_exception(
                    'corporate_role_schema_unexpected_value',
                    'local_bitrixsync',
                    '',
                    (string)$code
                );
            }
        }

        $status = $this->role_status($field, $schema);
        $missing = [];

        foreach ($allowed as $code) {
            if (empty($schema[$code])) {
                continue;
            }
            if (empty($status[$code]['exists'])) {
                $missing[] = $code;
            }
        }

        return $missing;
    }

    private function ensure_roles(array $field, array $schema, array $missing): array {
        $fieldid = (int)($field['ID'] ?? 0);
        if ($fieldid <= 0) {
            throw new \moodle_exception(
                'corporate_schema_field_read_failed',
                'local_bitrixsync'
            );
        }

        $beforelist = is_array($field['LIST'] ?? null) ? $field['LIST'] : [];
        $list = [];
        $sort = 100;

        // Preserve only documented enum fields. Do not resend response-only
        // metadata received from crm.contact.userfield.get.
        foreach ($beforelist as $item) {
            if (!is_array($item) || empty($item['ID'])) {
                continue;
            }

            $row = [
                'ID' => (string)$item['ID'],
                'VALUE' => (string)($item['VALUE'] ?? ''),
                'SORT' => max(0, (int)($item['SORT'] ?? $sort)),
                'DEF' => ((string)($item['DEF'] ?? 'N') === 'Y') ? 'Y' : 'N',
            ];

            if (!empty($item['XML_ID'])) {
                $row['XML_ID'] = (string)$item['XML_ID'];
            }

            $list[] = $row;
            $sort = max($sort + 100, ((int)$row['SORT']) + 100);
        }

        $added = [];
        foreach ($missing as $code) {
            if (empty($schema[$code])) {
                continue;
            }

            $role = $schema[$code];
            $list[] = [
                'VALUE' => (string)$role['label'],
                'XML_ID' => (string)$role['xml_id'],
                'SORT' => $sort,
                'DEF' => 'N',
            ];
            $added[] = (string)$code;
            $sort += 100;
        }

        logger::corporate_schema('Enum provisioning request', [
            'field_id' => $fieldid,
            'existing_count' => count($beforelist),
            'requested_add' => $added,
            'payload_count' => count($list),
        ]);

        $response = $this->bitrix->call_json(
            'crm.contact.userfield.update',
            [
                'id' => $fieldid,
                'fields' => [
                    'LIST' => $list,
                ],
            ]
        );

        if (empty($response['result'])) {
            throw new \moodle_exception(
                'corporate_schema_enum_update_failed',
                'local_bitrixsync'
            );
        }

        // Verify by reading the same field again.
        $updated = $this->get_field($fieldid);
        $afterlist = is_array($updated['LIST'] ?? null) ? $updated['LIST'] : [];
        $remaining = $this->missing_roles($updated, $schema);

        set_config(
            'corporate_role_enum_last_diagnostic',
            json_encode([
                'time' => time(),
                'field_id' => $fieldid,
                'before_count' => count($beforelist),
                'requested_add' => $added,
                'update_result' => (bool)$response['result'],
                'after_count' => count($afterlist),
                'remaining_missing' => $remaining,
                'http_code' => $this->bitrix->get_last_http_code(),
                'transport_diagnostic' => $this->bitrix->get_last_diagnostic(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'local_bitrixsync'
        );

        logger::corporate_schema('Enum provisioning verified', [
            'field_id' => $fieldid,
            'after_count' => count($afterlist),
            'remaining_missing' => $remaining,
        ]);

        if ($remaining) {
            throw new \moodle_exception(
                'corporate_schema_enum_verify_failed',
                'local_bitrixsync',
                '',
                implode(', ', $remaining)
            );
        }

        return $updated;
    }

    /**
     * Resolve required roles against the actual Bitrix LIST.
     *
     * Preferred recognition:
     * 1. exact stable XML_ID / rolecode;
     * 2. exact normalized VALUE matching the role label.
     *
     * The VALUE fallback is required because some Bitrix24 userfield update
     * flows preserve the display value but do not return our XML_ID exactly as
     * submitted.
     */
    private function role_status(array $field, array $schema): array {
        $matched = [];

        foreach (($field['LIST'] ?? []) as $item) {
            if (!is_array($item) || empty($item['ID'])) {
                continue;
            }

            $code = $this->match_role_code($item, $schema);
            if ($code === '') {
                continue;
            }

            // First recognized enum wins. We never manufacture duplicate
            // mappings if Bitrix contains legacy duplicate display values.
            if (!isset($matched[$code])) {
                $matched[$code] = $item;
            }
        }

        $out = [];
        foreach ($schema as $code => $role) {
            $item = $matched[$code] ?? null;
            $out[$code] = [
                'exists' => (bool)$item,
                'enum_id' => $item ? (int)($item['ID'] ?? 0) : 0,
                'matched_by' => $item ? $this->match_role_source($item, $role) : '',
                'value' => $item ? (string)($item['VALUE'] ?? '') : '',
                'xml_id' => $item ? (string)($item['XML_ID'] ?? '') : '',
            ];
        }

        return $out;
    }

    /**
     * Return the normalized rolecode for one Bitrix enum item.
     */
    private function match_role_code(array $item, array $schema): string {
        $xmlid = trim((string)($item['XML_ID'] ?? ''));
        if ($xmlid !== '' && isset($schema[$xmlid])) {
            return $xmlid;
        }

        $value = $this->normalize_label((string)($item['VALUE'] ?? ''));
        if ($value === '') {
            return '';
        }

        foreach ($schema as $code => $role) {
            $label = $this->normalize_label((string)($role['label'] ?? ''));
            if ($label !== '' && $value === $label) {
                return (string)$code;
            }
        }

        return '';
    }

    private function match_role_source(array $item, array $role): string {
        $xmlid = trim((string)($item['XML_ID'] ?? ''));
        if ($xmlid !== '' && $xmlid === (string)($role['xml_id'] ?? '')) {
            return 'xml_id';
        }

        $value = $this->normalize_label((string)($item['VALUE'] ?? ''));
        $label = $this->normalize_label((string)($role['label'] ?? ''));
        if ($value !== '' && $label !== '' && $value === $label) {
            return 'value';
        }

        return '';
    }

    /**
     * Sanitized copy of the real Bitrix enum list for administrator
     * diagnostics. No secrets are present here.
     */
    private function actual_enum_values(array $field, array $schema): array {
        $out = [];

        foreach (($field['LIST'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code = $this->match_role_code($item, $schema);

            $out[] = [
                'id' => (int)($item['ID'] ?? 0),
                'value' => (string)($item['VALUE'] ?? ''),
                'xml_id' => (string)($item['XML_ID'] ?? ''),
                'rolecode' => $code,
                'recognized' => $code !== '',
            ];
        }

        return $out;
    }


    private function save_field_identity(array $field): void {
        if (!empty($field['ID'])) {
            set_config('corporate_role_field_id', (int)$field['ID'], 'local_bitrixsync');
        }
        if (!empty($field['FIELD_NAME'])) {
            set_config('corporate_role_field_name', (string)$field['FIELD_NAME'], 'local_bitrixsync');
        }
    }

    private function save_enum_mapping(array $field, array $schema): void {
        $mapping = [];

        foreach (($field['LIST'] ?? []) as $item) {
            if (!is_array($item) || empty($item['ID'])) {
                continue;
            }

            $code = $this->match_role_code($item, $schema);
            if ($code === '') {
                continue;
            }

            $mapping[(string)$item['ID']] = $code;
        }

        set_config(
            'corporate_role_enum_map',
            json_encode(
                $mapping,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'local_bitrixsync'
        );
    }


    private function field_payload(array $field): array {
        return [
            'exists' => !empty($field),
            'id' => (int)($field['ID'] ?? 0),
            'field_name' => (string)($field['FIELD_NAME'] ?? ''),
            'label' => self::LABEL,
            'type' => (string)($field['USER_TYPE_ID'] ?? ''),
            'multiple' => ((string)($field['MULTIPLE'] ?? 'N') === 'Y'),
        ];
    }

    private function schema_not_ready(string $reason, array $field, array $schema): array {
        return [
            'success' => true,
            'ready' => false,
            'entity' => self::ENTITY,
            'reason' => $reason,
            'field' => $this->field_payload($field),
            'roles' => $this->role_status($field, $schema),
            'enum_values' => $this->actual_enum_values($field, $schema),
            'dashboard' => $this->dashboard_status(),
        ];
    }

    private function is_candidate_field(array $field, string $savedname = ''): bool {
        if ((string)($field['XML_ID'] ?? '') === self::XML_ID) {
            return true;
        }
        if ($savedname !== '' && (string)($field['FIELD_NAME'] ?? '') === $savedname) {
            return true;
        }
        $wanted = $this->normalize_label(self::LABEL);
        foreach ($this->field_labels($field) as $label) {
            if ($this->normalize_label($label) === $wanted) {
                return true;
            }
        }
        return false;
    }

    private function field_labels(array $field): array {
        $out = [];
        foreach (['LABEL', 'EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL', 'LIST_FILTER_LABEL'] as $key) {
            if (!isset($field[$key])) {
                continue;
            }
            $value = $field[$key];
            if (is_string($value)) {
                $out[] = $value;
            } else if (is_array($value)) {
                foreach ($value as $label) {
                    if (is_string($label)) {
                        $out[] = $label;
                    }
                }
            }
        }
        return array_values(array_unique($out));
    }

    private function normalize_label(string $label): string {
        $label = trim(preg_replace('/\s+/u', ' ', $label));
        return function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    }

    private function error_from_exception(\Throwable $e): array {
        $message = $e->getMessage();
        $lower = strtolower($message);
        $code = 'corporate_schema_error';

        // Moodle exceptions created by this feature are semantic schema errors,
        // not network failures. Classify them before generic text heuristics.
        $moodleerror = '';
        if ($e instanceof \moodle_exception && property_exists($e, 'errorcode')) {
            $moodleerror = (string)$e->errorcode;
        }

        $schemaerrors = [
            'corporate_schema_enum_verify_failed',
            'corporate_schema_enum_update_failed',
            'corporate_schema_field_read_failed',
            'corporate_schema_field_create_failed',
            'corporate_role_schema_invalid',
            'corporate_role_schema_unexpected_value',
        ];

        if ($moodleerror !== '' && in_array($moodleerror, $schemaerrors, true)) {
            $code = $moodleerror;
        } else if ($e instanceof \local_bitrixsync\exception\bitrix_api_exception) {
            $bitrixcode = strtoupper($e->get_bitrix_code());
            $httpcode = $e->get_http_code();

            if ($httpcode === 401 || $httpcode === 403 ||
                    strpos($bitrixcode, 'ACCESS') !== false ||
                    strpos($bitrixcode, 'PERMISSION') !== false ||
                    strpos($bitrixcode, 'SCOPE') !== false) {
                $code = 'bitrix_permission_denied';
            } else {
                $code = 'bitrix_unavailable';
                $message = 'Bitrix24 API is unavailable';
            }
        } else if (strpos($lower, 'permission') !== false ||
                strpos($lower, 'access denied') !== false ||
                strpos($lower, 'insufficient') !== false) {
            $code = 'bitrix_permission_denied';
        } else if (strpos($lower, 'http') !== false ||
                strpos($lower, 'curl') !== false ||
                strpos($lower, 'connection refused') !== false ||
                strpos($lower, 'timed out') !== false) {
            // Generic network classification deliberately does NOT use the word
            // "bitrix" as a trigger. KBA schema error messages may legitimately
            // contain that product name while the API itself is healthy.
            $code = 'bitrix_unavailable';
            $message = 'Bitrix24 API is unavailable';
        } else if (strpos($lower, 'corporate') !== false ||
                strpos($lower, 'role schema') !== false) {
            $code = 'corporate_dashboard_unavailable';
        }

        logger::corporate_schema('ERROR', [
            'code' => $code,
            'moodle_errorcode' => $moodleerror,
            'message' => $message,
        ]);

        return [
            'success' => false,
            'ready' => false,
            'code' => $code,
            'message' => $message,
        ];
    }

}
