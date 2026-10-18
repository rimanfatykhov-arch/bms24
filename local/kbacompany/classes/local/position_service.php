<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Company-scoped positions and staffing service. */
class position_service {
    public const CONTRACT_VERSION = '0.2.3.3';
    private const ASSIGNMENT_TYPES = ['primary', 'acting', 'additional'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'kba_positions_staffing',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['PositionDefinition', 'LegacyPositionAssignment'],
            'assignment_types' => self::ASSIGNMENT_TYPES,
            'write_policy' => 'explicit_authorised_commands',
            'null_policy' => ['unfilled_position' => 'vacant', 'missing_employee' => 'unknown'],
            'rules' => [
                'position_does_not_imply_person',
                'assignment_does_not_change_competency',
                'course_completion_does_not_confirm_competency_level',
                'ended_assignments_remain_history',
                'no_automatic_legacy_position_import',
                'plannedcount_is_legacy_compatibility_only',
                'new_staffing_uses_staffing_service',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    /** Return positions, current staffing and eligible company employees. */
    public static function positions_context(string $companyuuid, int $userid, string $orgnodeuuid = ''): array {
        global $DB;

        $company = company_context_service::context($companyuuid, $userid);
        if ($orgnodeuuid !== '') {
            self::require_node($companyuuid, $orgnodeuuid);
        }
        $conditions = ['companyuuid' => $companyuuid, 'active' => 1];
        if ($orgnodeuuid !== '') {
            $conditions['orgnodeuuid'] = $orgnodeuuid;
        }
        $positions = $DB->get_records('local_kbacompany_position', $conditions,
            'sortorder ASC, code ASC, name ASC');
        $organization = organization_service::organization_context($companyuuid, $userid);
        $employees = [];
        foreach ($organization['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = [
                'user_id' => (int)$employee['user_id'],
                'full_name' => (string)$employee['full_name'],
            ];
        }
        $now = time();
        $positiondtos = [];
        $staffed = 0;
        $planned = 0;
        foreach ($positions as $position) {
            $assignments = [];
            $records = $DB->get_records('local_kbacompany_posassign', [
                'companyuuid' => $companyuuid, 'positionuuid' => $position->uuid,
            ], 'active DESC, effectivefrom DESC, id DESC');
            foreach ($records as $assignment) {
                $current = self::is_current($assignment, $now);
                if ($current) {
                    $staffed++;
                }
                $assignments[] = [
                    'id' => (int)$assignment->id,
                    'user_id' => (int)$assignment->userid,
                    'employee_name' => $employees[(int)$assignment->userid]['full_name'] ?? null,
                    'type' => (string)$assignment->assignmenttype,
                    'effective_from' => (int)$assignment->effectivefrom,
                    'effective_to' => (int)$assignment->effectiveto,
                    'current' => $current,
                ];
            }
            $plannedcount = max(1, (int)$position->plannedcount);
            $planned += $plannedcount;
            $currentcount = count(array_filter($assignments, static function(array $item): bool {
                return $item['current'];
            }));
            $positiondtos[] = [
                'uuid' => (string)$position->uuid,
                'branch_uuid' => (string)$position->branchuuid,
                'org_node_uuid' => (string)$position->orgnodeuuid,
                'code' => (string)$position->code,
                'name' => (string)$position->name,
                'purpose' => (string)($position->purpose ?? ''),
                'is_manager' => !empty($position->ismanager),
                'planned_count' => $plannedcount,
                'current_count' => $currentcount,
                'vacant_count' => max(0, $plannedcount - $currentcount),
                'assignments' => $assignments,
            ];
        }
        return [
            'company' => $company,
            'org_node_uuid' => $orgnodeuuid,
            'positions' => $positiondtos,
            'employees' => array_values($employees),
            'summary' => [
                'positions' => count($positiondtos),
                'planned_places' => $planned,
                'current_assignments' => $staffed,
                'vacant_places' => max(0, $planned - $staffed),
            ],
            'can_manage' => organizing_board_service::can_manage($companyuuid, $userid),
        ];
    }

    /** Create a position without inventing an employee assignment. */
    public static function create_position(string $companyuuid, int $userid, array $data): string {
        global $DB;

        self::require_manage($companyuuid, $userid);
        $node = self::require_node($companyuuid, trim((string)($data['orgnodeuuid'] ?? '')));
        $code = trim((string)($data['code'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw new \moodle_exception('positionrequiredfields', 'local_kbacompany');
        }
        if ($DB->record_exists('local_kbacompany_position', ['companyuuid' => $companyuuid, 'code' => $code])) {
            throw new \moodle_exception('positioncodeexists', 'local_kbacompany');
        }
        $now = time();
        $uuid = self::uuid_v4();
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid' => $companyuuid,
            'branchuuid' => (string)$node->branchuuid,
            'orgnodeuuid' => (string)$node->uuid,
            'uuid' => $uuid,
            'code' => $code,
            'name' => $name,
            'purpose' => trim((string)($data['purpose'] ?? '')),
            'ismanager' => empty($data['ismanager']) ? 0 : 1,
            'plannedcount' => max(1, min(999, (int)($data['plannedcount'] ?? 1))),
            'sortorder' => max(0, (int)($data['sortorder'] ?? 0)),
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        return $uuid;
    }

    /** Everyday directory: filtering and paging happen before records leave the database. */
    public static function directory_browse(string $companyuuid, int $actor, array $filters = [], int $page = 0): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $company = company_context_service::context($companyuuid, $actor);
        $manage = self::directory_can_manage($companyuuid, $actor);
        $f = self::directory_filters($filters);
        if (!$manage) { $f['status'] = 'active'; }
        $where = ['p.companyuuid = :company']; $params = ['company' => $companyuuid];
        if ($f['status'] !== 'all') { $where[] = 'p.active = :active'; $params['active'] = $f['status'] === 'active' ? 1 : 0; }
        if ($f['manager'] !== '') { $where[] = 'p.ismanager = :manager'; $params['manager'] = (int)$f['manager']; }
        if ($f['node'] !== '') {
            // Validate the submitted relation in this company, including historical units.
            $DB->get_record('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'uuid' => $f['node']], 'id', MUST_EXIST);
            $where[] = 'p.orgnodeuuid = :node'; $params['node'] = $f['node'];
        }
        foreach (preg_split('/\s+/u', $f['q'], -1, PREG_SPLIT_NO_EMPTY) as $i => $word) {
            $parts = [];
            foreach (['p.code', 'p.name', 'n.name', 'n.code'] as $j => $field) {
                $key = 'q' . $i . '_' . $j; $parts[] = $DB->sql_like($field, ':' . $key, false);
                $params[$key] = '%' . $DB->sql_like_escape($word) . '%';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        $from = self::directory_from() . ' WHERE ' . implode(' AND ', $where);
        $total = (int)$DB->count_records_sql('SELECT COUNT(1)' . $from, $params);
        $page = min(max(0, $page), max(0, (int)ceil($total / 25) - 1));
        $rows = $DB->get_records_sql(self::directory_select() . $from . ' ORDER BY p.sortorder, p.code, p.name, p.id', $params, $page * 25, 25);
        return ['company' => $company, 'positions' => array_map([self::class, 'directory_dto'], array_values($rows)),
            'filters' => $f, 'total' => $total, 'page' => $page, 'pagesize' => 25, 'can_manage' => $manage];
    }

    public static function directory_filters(array $f): array {
        return ['q' => \core_text::substr(trim((string)($f['q'] ?? '')), 0, 200),
            'status' => in_array($f['status'] ?? '', ['active', 'inactive', 'all'], true) ? $f['status'] : 'active',
            'manager' => in_array((string)($f['manager'] ?? ''), ['0', '1'], true) ? (string)$f['manager'] : '',
            'node' => trim((string)($f['node'] ?? ''))];
    }

    /** Preserve the original route's company permission AND explicit capability checks. */
    public static function directory_can_manage(string $companyuuid, int $actor): bool {
        return organizing_board_service::can_manage($companyuuid, $actor) &&
            (is_siteadmin($actor) || has_capability('local/kbacompany:manageorganization', \context_system::instance(), $actor));
    }

    private static function directory_authorise(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid, $actor);
        if (!self::directory_can_manage($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function directory_from(): string {
        return ' FROM {local_kbacompany_position} p
            LEFT JOIN {local_kbacompany_orgnode} n ON n.companyuuid = p.companyuuid AND n.uuid = p.orgnodeuuid
            LEFT JOIN {local_kbacompany_orgbranch} b ON b.companyuuid = p.companyuuid AND b.uuid = p.branchuuid';
    }

    private static function directory_select(): string {
        return 'SELECT p.id, p.uuid, p.code, p.name, p.purpose, p.ismanager, p.sortorder, p.active,
            p.orgnodeuuid, p.branchuuid, p.timecreated, p.timemodified,
            n.code AS nodecode, n.name AS nodename, n.active AS nodeactive, b.name AS branchname';
    }

    private static function directory_dto(\stdClass $p): array {
        return ['id' => (int)$p->id, 'uuid' => (string)$p->uuid, 'code' => (string)$p->code,
            'name' => (string)$p->name, 'purpose' => (string)($p->purpose ?? ''), 'ismanager' => (int)$p->ismanager,
            'sortorder' => (int)$p->sortorder, 'active' => (int)$p->active, 'orgnodeuuid' => (string)$p->orgnodeuuid,
            'branchuuid' => (string)$p->branchuuid, 'nodecode' => (string)($p->nodecode ?? ''),
            'nodename' => (string)($p->nodename ?? ''), 'nodeactive' => (int)($p->nodeactive ?? 0),
            'branchname' => (string)($p->branchname ?? ''), 'timecreated' => (int)$p->timecreated,
            'timemodified' => (int)$p->timemodified];
    }

    public static function directory_record(string $companyuuid, int $actor, string $positionuuid): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $company = company_context_service::context($companyuuid, $actor);
        $manage = self::directory_can_manage($companyuuid, $actor);
        $row = $DB->get_record_sql(self::directory_select() . self::directory_from() .
            ' WHERE p.companyuuid = :company AND p.uuid = :position' . ($manage ? '' : ' AND p.active = 1'),
            ['company' => $companyuuid, 'position' => $positionuuid]);
        if (!$row) { throw new \moodle_exception('pdu_not_found', 'local_kbacompany'); }
        $p = self::directory_dto($row);
        return ['company' => $company, 'position' => $p, 'can_manage' => $manage,
            'editable' => $manage && !empty($p['active']), 'expected' => self::directory_fingerprint($p)];
    }

    /** A bounded picker, independent of the selected/default organizational branch. */
    public static function directory_units(string $companyuuid, int $actor, string $q = '', string $selected = '', bool $activeonly = true): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        $params = ['company' => $companyuuid]; $where = 'companyuuid = :company' . ($activeonly ? ' AND active = 1' : '');
        $q = \core_text::substr(trim($q), 0, 100);
        if ($q !== '') {
            $where .= ' AND (' . $DB->sql_like('name', ':name', false) . ' OR ' . $DB->sql_like('code', ':code', false) . ')';
            $params['name'] = '%' . $DB->sql_like_escape($q) . '%'; $params['code'] = $params['name'];
        }
        $total = (int)$DB->count_records_sql('SELECT COUNT(1) FROM {local_kbacompany_orgnode} WHERE ' . $where, $params);
        $rows = $DB->get_records_select('local_kbacompany_orgnode', $where, $params, 'sortorder, code, name, id', 'id,uuid,code,name,active', 0, 50);
        $options = [];
        foreach ($rows as $row) { $options[$row->uuid] = trim($row->code . ' — ' . $row->name, ' —'); }
        if ($selected !== '' && !isset($options[$selected])) {
            $conditions = ['companyuuid' => $companyuuid, 'uuid' => $selected];
            if ($activeonly) { $conditions['active'] = 1; }
            $row = $DB->get_record('local_kbacompany_orgnode', $conditions, 'id,uuid,code,name');
            if ($row) { $options[$row->uuid] = trim($row->code . ' — ' . $row->name, ' —'); }
        }
        return ['options' => $options, 'total' => $total, 'limited' => $total > 50, 'query' => $q];
    }

    public static function directory_form_errors(string $companyuuid, int $actor, array $values, bool $creating): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        self::directory_authorise($companyuuid, $actor);
        $errors = [];
        $name = trim((string)($values['name'] ?? ''));
        if ($name === '' || \core_text::strlen($name) > 255) { $errors['name'] = get_string('pdu_name_invalid', 'local_kbacompany'); }
        if (\core_text::strlen((string)($values['purpose'] ?? '')) > 20000) { $errors['purpose'] = get_string('pdu_purpose_invalid', 'local_kbacompany'); }
        if (!in_array((string)($values['ismanager'] ?? '0'), ['0', '1'], true)) { $errors['ismanager'] = get_string('pdu_invalid', 'local_kbacompany'); }
        if (!preg_match('/^\d{1,6}$/D', (string)($values['sortorder'] ?? '0'))) { $errors['sortorder'] = get_string('pdu_sort_invalid', 'local_kbacompany'); }
        if ($creating && !$DB->record_exists('local_kbacompany_orgnode', [
                'companyuuid' => $companyuuid, 'uuid' => trim((string)($values['orgnodeuuid'] ?? '')), 'active' => 1])) {
            $errors['orgnodeuuid'] = get_string('pdu_unit_invalid', 'local_kbacompany');
        }
        return $errors;
    }

    private static function directory_fingerprint(array $p): string {
        $values = [];
        foreach (['uuid', 'code', 'name', 'purpose', 'orgnodeuuid', 'branchuuid', 'ismanager', 'sortorder', 'active', 'timemodified'] as $key) {
            $values[$key] = $p[$key];
        }
        return document_request_service::digest($values);
    }

    /** New human code uses the existing allocator. Historical numerical codes remain untouched. */
    private static function directory_next_code(string $companyuuid): string {
        global $DB;
        $minimum = 0;
        $records = $DB->get_recordset_select('local_kbacompany_position',
            'companyuuid = :company AND ' . $DB->sql_like('code', ':prefix'), ['company' => $companyuuid, 'prefix' => 'POS-%'], '', 'id,code');
        try {
            foreach ($records as $p) {
                if (preg_match('/^POS-(\d{1,9})$/D', (string)$p->code, $m)) { $minimum = max($minimum, (int)$m[1]); }
            }
        } finally { $records->close(); }
        return stable_code_service::next($companyuuid, 'position', 'POS', static function(string $code) use ($DB, $companyuuid): bool {
            return !$DB->record_exists('local_kbacompany_position', ['companyuuid' => $companyuuid, 'code' => $code]);
        }, $minimum);
    }

    /** UI command changes only the existing definition. No staffing/competency/role mutations. */
    public static function directory_save(string $companyuuid, int $actor, string $positionuuid, array $values, string $expected = ''): string {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        self::directory_authorise($companyuuid, $actor);
        $creating = $positionuuid === '';
        if ($creating && array_intersect_key($values, array_flip(['code', 'branchuuid', 'active', 'uuid']))) {
            throw new \moodle_exception('pdu_readonly', 'local_kbacompany');
        }
        if (self::directory_form_errors($companyuuid, $actor, $values, $creating)) { throw new \moodle_exception('pdu_invalid', 'local_kbacompany'); }
        // Allocation deliberately precedes the transaction so a failed create does not recycle its code.
        $newcode = $creating ? self::directory_next_code($companyuuid) : '';
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('positiondirectory:' . $companyuuid, 10);
        if (!$lock) { throw new \moodle_exception('pdu_busy', 'local_kbacompany'); }
        try {
            self::directory_authorise($companyuuid, $actor);
            $tx = $DB->start_delegated_transaction();
            try {
                $fields = [];
                if ($creating) {
                    $positionuuid = self::create_position($companyuuid, $actor, [
                        'code' => $newcode, 'orgnodeuuid' => trim((string)$values['orgnodeuuid']),
                        'name' => trim((string)$values['name']), 'purpose' => trim((string)($values['purpose'] ?? '')),
                        'ismanager' => (int)($values['ismanager'] ?? 0), 'sortorder' => (int)($values['sortorder'] ?? 0)]);
                    $fields = ['name', 'purpose', 'orgnodeuuid', 'ismanager'];
                } else {
                    $model = self::directory_record($companyuuid, $actor, $positionuuid);
                    if (!$model['editable'] || $expected === '' || !hash_equals($model['expected'], $expected)) {
                        throw new \moodle_exception('pdu_stale', 'local_kbacompany');
                    }
                    $p = $model['position'];
                    // Structural transfer is a separate workflow: do not silently desynchronise staff lines/slots.
                    foreach (['orgnodeuuid', 'branchuuid', 'code', 'active', 'uuid'] as $key) {
                        if (array_key_exists($key, $values) && (string)$values[$key] !== (string)$p[$key]) {
                            throw new \moodle_exception('pdu_readonly', 'local_kbacompany');
                        }
                    }
                    $update = (object)['id' => $p['id']];
                    foreach (['name', 'purpose', 'ismanager', 'sortorder'] as $key) {
                        $value = in_array($key, ['ismanager', 'sortorder'], true) ? (int)($values[$key] ?? 0) : trim((string)($values[$key] ?? ''));
                        if ((string)$p[$key] !== (string)$value) { $update->$key = $value; $fields[] = $key; }
                    }
                    if ($fields) {
                        $update->timemodified = max(time(), $p['timemodified'] + 1);
                        $DB->update_record('local_kbacompany_position', $update);
                    }
                }
                if ($fields) {
                    $row = $DB->get_record('local_kbacompany_position', ['companyuuid' => $companyuuid, 'uuid' => $positionuuid], 'id', MUST_EXIST);
                    \local_kbacompany\event\position_definition_updated::create([
                        'context' => \context_system::instance(), 'userid' => $actor, 'objectid' => (int)$row->id,
                        'other' => ['companyuuid' => $companyuuid, 'positionuuid' => $positionuuid,
                            'operation' => $creating ? 'created' : 'edited', 'fields' => $fields],
                    ])->trigger();
                }
                $tx->allow_commit();
                return $positionuuid;
            } catch (\Throwable $e) { $tx->rollback($e); throw $e; }
        } finally { $lock->release(); }
    }

    /**
     * Conservative usage inventory for removing an unused definition from the active directory.
     * Historical references also block this operation. This is not a cascade-delete engine.
     * UUID fields are compared exactly; frozen payloads conservatively match a literal UUID.
     * No related content, people, salaries or private task names are returned to the caller.
     */
    private static function directory_usage_rules(): array {
        return [
            'staffing' => [
                'posassign' => ['positionuuid'], 'staffline' => ['positionuuid'], 'staffslot' => ['positionuuid'],
            ],
            'functions' => [
                'function' => ['positionuuid'], 'poswork' => ['positionuuid'],
                'acobj' => ['~payload'], 'wfapprove' => ['~snapshot'],
            ],
            'processes' => [
                'procelem' => ['executoruuid'], 'procraci' => ['partyuuid'],
            ],
            'indicators' => [
                'product' => ['targetuuid'], 'statbind' => ['contextuuid'],
            ],
            'documents' => [
                'doc' => ['ownerpositionuuid', 'sourceuuid'], 'record' => ['subjectuuid', '~payload'],
                'policy' => ['owneruuid', 'scopeuuid'],
            ],
            'management' => [
                'deviation' => ['ownerpositionuuid'], 'bmcitem' => ['ownerpositionuuid'],
                'stratgoal' => ['ownerpositionuuid'], 'adminitem' => ['ownerpositionuuid', 'sourceuuid'],
                'task' => ['contextuuid', 'sourceuuid'], 'bplan' => ['positionuuid'],
                'bptpl' => ['positionuuid', '~payload'], 'bpnorm' => ['positionuuid', '~payload'],
                'mcycle' => ['scopeuuid', '~statepayload'], 'a3std' => ['targetref'],
            ],
            'finance' => [
                'fincenter' => ['ownerpositionuuid', 'targetuuid'], 'finbudline' => ['ownerpositionuuid'],
                'resgate' => ['ownerpositionuuid', 'targetuuid'], 'resitem' => ['ownerpositionuuid', 'referenceuuid'],
            ],
            'people' => [
                'roledna' => ['positionuuid'], 'assess' => ['positionuuid'], '360cycle' => ['positionuuid'],
                'devplan' => ['positionuuid'], 'functxfer' => ['sourcepositionuuid', 'targetpositionuuid'],
            ],
            'structure' => [
                'orgreport' => ['manageruuid'], 'designver' => ['~payload'],
                'flow' => ['fromuuid', 'touuid', '~snapshot'], 'semrel' => ['fromuuid', 'touuid'],
            ],
            'integration' => [
                'extmap' => ['localref'],
            ],
        ];
    }

    private static function directory_usage(string $companyuuid, string $positionuuid): array {
        global $DB;
        $blocked = [];
        foreach (self::directory_usage_rules() as $category => $tables) {
            foreach ($tables as $suffix => $fields) {
                $parts = []; $params = ['company' => $companyuuid];
                foreach ($fields as $i => $field) {
                    $key = 'ref' . $i;
                    if (substr($field, 0, 1) === '~') {
                        $parts[] = $DB->sql_like(substr($field, 1), ':' . $key, false);
                        $params[$key] = '%' . $DB->sql_like_escape($positionuuid) . '%';
                    } else {
                        $parts[] = $field . ' = :' . $key;
                        $params[$key] = $positionuuid;
                    }
                }
                if ($DB->record_exists_select('local_kbacompany_' . $suffix,
                        'companyuuid = :company AND (' . implode(' OR ', $parts) . ')', $params)) {
                    $blocked[] = $category;
                    break;
                }
            }
        }
        return $blocked;
    }

    /** Read-only confirmation model, only after Company and explicit management checks. */
    public static function directory_lifecycle_preview(string $companyuuid, int $actor, string $positionuuid,
            string $operation): array {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        self::directory_authorise($companyuuid, $actor);
        if (!in_array($operation, ['delete', 'restore'], true)) {
            throw new \moodle_exception('pdu_method', 'local_kbacompany');
        }
        $model = self::directory_record($companyuuid, $actor, $positionuuid);
        $model['operation'] = $operation;
        $model['state_matches'] = $operation === 'delete' ? !empty($model['position']['active']) : empty($model['position']['active']);
        $model['blockers'] = $operation === 'delete' && $model['state_matches'] ?
            self::directory_usage($companyuuid, $positionuuid) : [];
        if ($operation === 'restore' && empty($model['position']['nodeactive'])) {
            $model['blockers'][] = 'unit';
        }
        $model['allowed'] = $model['state_matches'] && !$model['blockers'];
        return $model;
    }

    /**
     * Soft deletion and explicit recovery. Keeps the row/UUID/code and all related history.
     * Reuses the directory edit lock; dependencies are checked again inside the transaction.
     * Other domains do not all use this lock: no claim of global cross-domain serialisation.
     */
    public static function directory_lifecycle(string $companyuuid, int $actor, string $positionuuid,
            string $operation, string $expected, string $reason, bool $confirmed): void {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        self::directory_authorise($companyuuid, $actor);
        if (!in_array($operation, ['delete', 'restore'], true)) {
            throw new \moodle_exception('pdu_method', 'local_kbacompany');
        }
        if (!$confirmed) { throw new \moodle_exception('pdl_confirm_required', 'local_kbacompany'); }
        $reasons = $operation === 'delete' ? ['duplicate', 'created_in_error', 'no_longer_needed'] : ['restore'];
        if (!in_array($reason, $reasons, true)) { throw new \moodle_exception('pdl_reason_required', 'local_kbacompany'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('positiondirectory:' . $companyuuid, 10);
        if (!$lock) { throw new \moodle_exception('pdu_busy', 'local_kbacompany'); }
        try {
            self::directory_authorise($companyuuid, $actor);
            $tx = $DB->start_delegated_transaction();
            try {
                $model = self::directory_lifecycle_preview($companyuuid, $actor, $positionuuid, $operation);
                if (!$model['state_matches'] || $expected === '' || !hash_equals($model['expected'], $expected)) {
                    throw new \moodle_exception('pdu_stale', 'local_kbacompany');
                }
                if ($model['blockers']) { throw new \moodle_exception('pdl_in_use', 'local_kbacompany'); }
                $p = $model['position'];
                $DB->execute('UPDATE {local_kbacompany_position} SET active = :active, timemodified = :modified
                    WHERE companyuuid = :company AND uuid = :position AND id = :id',
                    ['active' => $operation === 'delete' ? 0 : 1, 'modified' => max(time(), $p['timemodified'] + 1),
                        'company' => $companyuuid, 'position' => $positionuuid, 'id' => $p['id']]);
                \local_kbacompany\event\position_definition_updated::create([
                    'context' => \context_system::instance(), 'userid' => $actor, 'objectid' => $p['id'],
                    'other' => ['companyuuid' => $companyuuid, 'positionuuid' => $positionuuid,
                        'operation' => $operation === 'delete' ? 'deactivated' : 'restored',
                        'fields' => ['active'], 'reason' => $reason],
                ])->trigger();
                $tx->allow_commit();
            } catch (\Throwable $e) { $tx->rollback($e); throw $e; }
        } finally { $lock->release(); }
    }

    /** Read visible canonical Function revisions for this position; no second function engine. */
    public static function directory_functions(string $companyuuid, int $actor, string $positionuuid, int $page = 0): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        self::directory_record($companyuuid, $actor, $positionuuid);
        $manage = permission_service::can_manage_management($companyuuid, $actor);
        $where = 'f.companyuuid = :company AND f.positionuuid = :position AND f.status <> :archived';
        $params = ['company' => $companyuuid, 'position' => $positionuuid, 'archived' => 'archived'];
        $newer = '(v.revision > f.revision OR (v.revision = f.revision AND v.id > f.id))';
        if (!$manage) {
            $where .= ' AND f.status = :published'; $params['published'] = 'published';
            $where .= " AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_function} ar
                WHERE ar.companyuuid = f.companyuuid AND ar.uuid = f.uuid AND ar.status = 'archived'
                AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_function} nx WHERE nx.companyuuid = ar.companyuuid AND nx.uuid = ar.uuid
                    AND (nx.revision > ar.revision OR (nx.revision = ar.revision AND nx.id > ar.id))))";
        }
        $where .= ' AND NOT EXISTS (SELECT 1 FROM {local_kbacompany_function} v WHERE v.companyuuid = f.companyuuid AND v.uuid = f.uuid AND ' .
            $newer . ($manage ? '' : " AND v.status = 'published'") . ')';
        $from = ' FROM {local_kbacompany_function} f LEFT JOIN {local_kbacompany_product} pr
            ON pr.companyuuid = f.companyuuid AND pr.uuid = f.productuuid WHERE ' . $where;
        $total = (int)$DB->count_records_sql('SELECT COUNT(1)' . $from, $params);
        $page = min(max(0, $page), max(0, (int)ceil($total / 25) - 1));
        $rows = $DB->get_records_sql('SELECT f.id,f.uuid,f.code,f.name,f.status,f.revision,f.producttext,pr.name AS productname' . $from .
            ' ORDER BY f.code,f.name,f.id', $params, $page * 25, 25);
        return ['rows' => array_map(static function($r): array { return (array)$r; }, array_values($rows)),
            'page' => $page, 'pagesize' => 25, 'total' => $total];
    }

    /** Existing approved staff-plan lines, without salary or employee private data. Plans are not summed. */
    public static function directory_staffing(string $companyuuid, int $actor, string $positionuuid, int $page = 0): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        self::directory_record($companyuuid, $actor, $positionuuid);
        $params = ['company' => $companyuuid, 'position' => $positionuuid, 'status' => 'approved'];
        $from = ' FROM {local_kbacompany_staffline} l JOIN {local_kbacompany_staffplan} sp
            ON sp.companyuuid = l.companyuuid AND sp.uuid = l.planuuid
            WHERE l.companyuuid = :company AND l.positionuuid = :position AND sp.status = :status';
        $total = (int)$DB->count_records_sql('SELECT COUNT(1)' . $from, $params);
        $page = min(max(0, $page), max(0, (int)ceil($total / 25) - 1));
        $rows = $DB->get_records_sql('SELECT l.id,l.planuuid,l.plannedfte,sp.name,sp.effectivefrom,sp.effectiveto' .
            $from . ' ORDER BY sp.effectivefrom DESC,l.id DESC', $params, $page * 25, 25);
        return ['rows' => array_map(static function($r): array { return (array)$r; }, array_values($rows)),
            'page' => $page, 'pagesize' => 25, 'total' => $total];
    }

    /** Moodle owns log storage/retention; no fabricated retrospective revision history. */
    public static function directory_history(string $companyuuid, int $actor, string $positionuuid): array {
        global $DB;
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $model = self::directory_record($companyuuid, $actor, $positionuuid);
        $out = ['available' => false, 'rows' => []];
        if (!function_exists('get_log_manager')) { return $out; }
        foreach (get_log_manager()->get_readers('\\core\\log\\sql_reader') as $reader) {
            try {
                $events = $reader->get_events_select('component = :component AND eventname = :event AND objecttable = :tbl AND objectid = :id',
                    ['component' => 'local_kbacompany', 'event' => '\\local_kbacompany\\event\\position_definition_updated',
                        'tbl' => 'local_kbacompany_position', 'id' => $model['position']['id']], 'timecreated DESC,id DESC', 0, 50);
                $candidate = ['available' => true, 'rows' => []];
                foreach ($events as $event) {
                    $data = $event->get_data();
                    if (($data['other']['companyuuid'] ?? '') !== $companyuuid || ($data['other']['positionuuid'] ?? '') !== $positionuuid) {
                        return ['available' => false, 'rows' => []];
                    }
                    // The author ID comes only from an event scoped to this authorised position.
                    $author = $DB->get_record_sql('SELECT u.id,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename
                        FROM {user} u JOIN {local_kbacompany_position} p ON p.companyuuid = :company AND p.uuid = :position
                        WHERE u.id = :actor AND u.deleted = 0',
                        ['company' => $companyuuid, 'position' => $positionuuid, 'actor' => (int)$data['userid']]);
                    $candidate['rows'][] = ['time' => (int)$data['timecreated'], 'actorname' => $author ? fullname($author) : get_string('pdu_actor_unavailable', 'local_kbacompany'),
                        'operation' => (string)($data['other']['operation'] ?? ''), 'fields' => (array)($data['other']['fields'] ?? []),
                        'reason' => (string)($data['other']['reason'] ?? '')];
                }
                return $candidate;
            } catch (\Exception $e) { continue; }
        }
        return $out;
    }

    /** Assign an existing company employee to a position. */
    public static function assign_employee(string $companyuuid, int $userid, string $positionuuid,
            int $employeeid, string $assignmenttype = 'primary'): int {
        global $DB;

        self::require_manage($companyuuid, $userid);
        $position = $DB->get_record('local_kbacompany_position', [
            'companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1,
        ], '*', MUST_EXIST);
        if (!in_array($assignmenttype, self::ASSIGNMENT_TYPES, true)) {
            throw new \moodle_exception('invalidassignmenttype', 'local_kbacompany');
        }
        $organization = organization_service::organization_context($companyuuid, $userid);
        $eligible = array_map('intval', array_column($organization['employees'], 'user_id'));
        if (!in_array($employeeid, $eligible, true)) {
            throw new \moodle_exception('employeenotavailable', 'local_kbacompany');
        }
        $existing = $DB->get_records('local_kbacompany_posassign', [
            'companyuuid' => $companyuuid, 'positionuuid' => $position->uuid,
            'userid' => $employeeid, 'assignmenttype' => $assignmenttype, 'active' => 1,
        ]);
        foreach ($existing as $assignment) {
            if (self::is_current($assignment, time())) {
                return (int)$assignment->id;
            }
        }
        $now = time();
        return (int)$DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid' => $companyuuid,
            'positionuuid' => (string)$position->uuid,
            'slotuuid' => '',
            'userid' => $employeeid,
            'assignmenttype' => $assignmenttype,
            'effectivefrom' => $now,
            'effectiveto' => 0,
            'active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /** End an assignment while retaining its history. */
    public static function end_assignment(string $companyuuid, int $userid, int $assignmentid): void {
        global $DB;

        self::require_manage($companyuuid, $userid);
        $assignment = $DB->get_record('local_kbacompany_posassign', [
            'id' => $assignmentid, 'companyuuid' => $companyuuid, 'active' => 1,
        ], '*', MUST_EXIST);
        $assignment->active = 0;
        $assignment->effectiveto = time();
        $assignment->timemodified = time();
        $DB->update_record('local_kbacompany_posassign', $assignment);
    }

    private static function require_manage(string $companyuuid, int $userid): void {
        if (!organizing_board_service::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function require_node(string $companyuuid, string $orgnodeuuid): \stdClass {
        global $DB;
        if ($orgnodeuuid === '') {
            throw new \moodle_exception('orgnoderequired', 'local_kbacompany');
        }
        return $DB->get_record('local_kbacompany_orgnode', [
            'companyuuid' => $companyuuid, 'uuid' => $orgnodeuuid, 'active' => 1,
        ], '*', MUST_EXIST);
    }

    private static function is_current(\stdClass $assignment, int $now): bool {
        return !empty($assignment->active) &&
            ((int)$assignment->effectivefrom === 0 || (int)$assignment->effectivefrom <= $now) &&
            ((int)$assignment->effectiveto === 0 || (int)$assignment->effectiveto >= $now);
    }

    private static function uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
            substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
