<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Financial Model foundation: versioned scenarios and OFS-linked financial responsibility centres.
 *
 * v0.6.0 deliberately stores governance/model definitions only. Financial statements, budgets,
 * payment calendars and actual accounting facts belong to later financial releases or external ERP.
 */
class financial_model_service {
    public const CONTRACT_VERSION = '0.6.0';
    public const STATES = ['draft', 'published', 'archived'];
    public const SCENARIO_TYPES = ['base', 'stretch', 'crisis', 'custom'];
    public const CENTER_TYPES = ['revenue', 'cost', 'profit', 'investment'];
    public const TARGET_TYPES = ['company', 'orgunit', 'position', 'product'];
    public const REVIEW_PERIODS = ['weekly', 'monthly', 'quarterly'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_financial_model',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['FinancialScenario', 'FinancialResponsibilityCenter'],
            'rules' => [
                'scenario_definitions_are_versioned',
                'published_revisions_are_immutable',
                'centres_reference_canonical_ofs_targets_instead_of_copying_org_structure',
                'responsibility_may_reference_canonical_position_and_indicator',
                'strategy_and_business_model_links_are_explicit_and_revision_pinned',
                'no_financial_statement_or_budget_fact_storage_in_v0_6_0',
                'no_erp_or_accounting_fact_mutation',
                'archive_preserves_history',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_management($companyuuid, $userid);
    }

    public static function can_view(string $companyuuid, int $userid): bool {
        // Financial governance is manager/owner scoped until a dedicated finance ACL exists.
        return self::can_manage($companyuuid, $userid);
    }

    private static function authorise(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('financialmodel_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function latest(string $table, string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid],
            'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    private static function current_rows(string $table, string $companyuuid, bool $canmanage): array {
        global $DB;
        $grouped = [];
        foreach ($DB->get_records($table, ['companyuuid' => $companyuuid], 'uuid ASC, revision ASC') as $row) {
            $grouped[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($grouped as $versions) {
            $latest = end($versions);
            if ($latest->status === 'archived') {
                continue;
            }
            if ($canmanage) {
                $out[] = $latest;
                continue;
            }
            foreach (array_reverse($versions) as $candidate) {
                if ($candidate->status === 'published') {
                    $out[] = $candidate;
                    break;
                }
            }
        }
        return $out;
    }


    private static function stable_code(string $table, string $companyuuid, string $uuid,
            string $scope, string $prefix): string {
        global $DB;
        if ($uuid !== '') {
            $rows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid],
                'revision DESC', 'code', 0, 1);
            if ($rows) {
                $existing = trim((string)reset($rows)->code);
                if ($existing !== '') {
                    return $existing;
                }
            }
        }
        $minimum = 0;
        foreach ($DB->get_fieldset_select($table, 'code',
                'companyuuid = :companyuuid AND code <> :empty',
                ['companyuuid' => $companyuuid, 'empty' => '']) as $code) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string)$code, $matches)) {
                $minimum = max($minimum, (int)$matches[1]);
            }
        }
        return stable_code_service::next($companyuuid, $scope, $prefix,
            static function(string $candidate) use ($DB, $table, $companyuuid): bool {
                return !$DB->record_exists($table, [
                    'companyuuid' => $companyuuid, 'code' => $candidate,
                ]);
            }, $minimum);
    }

    private static function latest_rows_filtered(string $table, string $companyuuid, array $filters): array {
        global $DB;
        $params = ['companyuuid' => $companyuuid];
        $where = ['t.companyuuid = :companyuuid',
            't.revision = (SELECT MAX(t2.revision) FROM {' . $table . '} t2 ' .
                'WHERE t2.companyuuid = t.companyuuid AND t2.uuid = t.uuid)'];

        $status = (string)($filters['status'] ?? 'active');
        if ($status === 'active') {
            $where[] = 't.status <> :archivedstatus';
            $params['archivedstatus'] = 'archived';
        } else if (in_array($status, self::STATES, true)) {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $DB->sql_like_escape($q) . '%';
            $where[] = '(' . $DB->sql_like('t.code', ':qcode', false) .
                ' OR ' . $DB->sql_like('t.name', ':qname', false) . ')';
            $params['qcode'] = $like;
            $params['qname'] = $like;
        }

        $typefield = $table === 'local_kbacompany_finscenario' ? 'scenariotype' : 'centertype';
        $type = trim((string)($filters['type'] ?? ''));
        if ($type !== '') {
            $allowed = $table === 'local_kbacompany_finscenario' ? self::SCENARIO_TYPES : self::CENTER_TYPES;
            if (in_array($type, $allowed, true)) {
                $where[] = 't.' . $typefield . ' = :recordtype';
                $params['recordtype'] = $type;
            }
        }

        $sql = 'SELECT t.* FROM {' . $table . '} t WHERE ' . implode(' AND ', $where) .
            ' ORDER BY t.timemodified DESC, t.name ASC';
        return array_values($DB->get_records_sql($sql, $params));
    }

    private static function record_history(string $table, string $companyuuid, string $uuid): array {
        global $DB;
        if ($uuid === '') {
            self::fail('reference');
        }
        return array_values($DB->get_records($table,
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC'));
    }

    private static function published_revision(string $table, string $companyuuid, string $uuid): int {
        global $DB;
        if ($uuid === '') {
            return 0;
        }
        $rows = $DB->get_records($table,
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'status' => 'published'],
            'revision DESC', 'id,revision', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return (int)reset($rows)->revision;
    }

    private static function save_versioned(string $table, string $companyuuid, int $userid, array $data,
            string $uuid, int $expected, array $fields): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        $lock = $factory->get_lock('financial_model:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($uuid !== '') {
                $old = self::latest($table, $companyuuid, $uuid);
                if ((int)$old->revision !== $expected) {
                    self::fail('conflict');
                }
                if ($old->status === 'archived') {
                    self::fail('state');
                }
            } else if ($expected !== 0) {
                self::fail('conflict');
            }
            $stableuuid = $old ? (string)$old->uuid : self::uuid();
            // Versioned active records cannot use a simple DB unique key, so enforce business
            // uniqueness while holding the company-scoped lock to make concurrent saves safe.
            if ($table === 'local_kbacompany_finscenario' || $table === 'local_kbacompany_fincenter') {
                self::assert_unique_code($table, $companyuuid, $stableuuid, (string)$data['code']);
            }
            if ($table === 'local_kbacompany_fincenter') {
                self::assert_unique_center_target($companyuuid, $stableuuid,
                    (string)$data['targettype'], (string)$data['targetuuid']);
            }
            $row = (object)[
                'companyuuid' => $companyuuid,
                'uuid' => $stableuuid,
                'revision' => $old ? ((int)$old->revision + 1) : 1,
                'status' => 'draft',
            ];
            foreach ($fields as $key => $definition) {
                [$max, $required] = $definition;
                $row->$key = self::text($data, $key, $max, $required);
            }
            $now = time();
            $row->createdby = $userid;
            $row->modifiedby = $userid;
            $row->timecreated = $now;
            $row->timemodified = $now;
            $DB->insert_record($table, $row);
            $tx->allow_commit();
            return $stableuuid;
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function publish_versioned(string $table, string $companyuuid, int $userid,
            string $uuid, int $expected): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('financial_model:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($table, $companyuuid, $uuid);
            if ((int)$old->revision !== $expected) {
                self::fail('conflict');
            }
            if ($old->status !== 'draft') {
                self::fail('state');
            }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expected + 1;
            $row->status = 'published';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record($table, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function archive_versioned(string $table, string $companyuuid, int $userid,
            string $uuid, int $expected): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('financial_model:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($table, $companyuuid, $uuid);
            if ((int)$old->revision !== $expected) {
                self::fail('conflict');
            }
            if ($old->status === 'archived') {
                self::fail('state');
            }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expected + 1;
            $row->status = 'archived';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record($table, $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) {
                $tx->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function assert_unique_code(string $table, string $companyuuid, string $uuid, string $code): void {
        foreach (self::current_rows($table, $companyuuid, true) as $row) {
            if ((string)$row->uuid === $uuid || $row->status === 'archived') {
                continue;
            }
            if (\core_text::strtolower((string)$row->code) === \core_text::strtolower($code)) {
                self::fail('duplicatecode');
            }
        }
    }

    private static function validate_date(string $value): void {
        if ($value === '') {
            return;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            self::fail('invalid');
        }
    }

    public static function save_scenario(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        self::authorise($companyuuid, $userid);
        $code = self::stable_code('local_kbacompany_finscenario', $companyuuid, $uuid,
            'financial:scenario', 'FIN');
        $type = self::text($data, 'scenariotype', 12, true);
        if (!in_array($type, self::SCENARIO_TYPES, true)) {
            self::fail('invalid');
        }
        $periodstart = self::text($data, 'periodstart', 10, true);
        $periodend = self::text($data, 'periodend', 10, true);
        self::validate_date($periodstart);
        self::validate_date($periodend);
        if ($periodend < $periodstart) {
            self::fail('period');
        }
        $currency = strtoupper(self::text($data, 'currency', 3, true));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            self::fail('invalid');
        }
        $strategyuuid = self::text($data, 'strategyuuid', 36, false);
        $businessmodeluuid = self::text($data, 'businessmodeluuid', 36, false);
        $mapped = $data;
        $mapped['code'] = $code;
        $mapped['scenariotype'] = $type;
        $mapped['periodstart'] = $periodstart;
        $mapped['periodend'] = $periodend;
        $mapped['currency'] = $currency;
        $mapped['strategyuuid'] = $strategyuuid;
        $mapped['strategyrevision'] = (string)self::published_revision(
            'local_kbacompany_strategy', $companyuuid, $strategyuuid);
        $mapped['businessmodeluuid'] = $businessmodeluuid;
        $mapped['businessmodelrevision'] = (string)self::published_revision(
            'local_kbacompany_bizmodel', $companyuuid, $businessmodeluuid);
        return self::save_versioned('local_kbacompany_finscenario', $companyuuid, $userid, $mapped,
            $uuid, $revision, [
                'code' => [50, true],
                'name' => [255, true],
                'scenariotype' => [12, true],
                'periodstart' => [10, true],
                'periodend' => [10, true],
                'currency' => [3, true],
                'description' => [8000, false],
                'assumptions' => [16000, false],
                'strategyuuid' => [36, false],
                'strategyrevision' => [10, false],
                'businessmodeluuid' => [36, false],
                'businessmodelrevision' => [10, false],
            ]);
    }

    public static function publish_scenario(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::publish_versioned('local_kbacompany_finscenario', $companyuuid, $userid, $uuid, $revision);
    }

    public static function archive_scenario(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::archive_versioned('local_kbacompany_finscenario', $companyuuid, $userid, $uuid, $revision);
    }

    private static function validate_target(string $companyuuid, string $targettype, string $targetuuid): string {
        global $DB;
        if (!in_array($targettype, self::TARGET_TYPES, true)) {
            self::fail('invalid');
        }
        if ($targettype === 'company') {
            return $companyuuid;
        }
        if ($targetuuid === '') {
            self::fail('reference');
        }
        if ($targettype === 'orgunit' && !$DB->record_exists('local_kbacompany_orgnode',
                ['companyuuid' => $companyuuid, 'uuid' => $targetuuid, 'active' => 1])) {
            self::fail('reference');
        }
        if ($targettype === 'position' && !$DB->record_exists('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $targetuuid, 'active' => 1])) {
            self::fail('reference');
        }
        if ($targettype === 'product' && !$DB->record_exists('local_kbacompany_product',
                ['companyuuid' => $companyuuid, 'uuid' => $targetuuid])) {
            self::fail('reference');
        }
        return $targetuuid;
    }

    private static function assert_unique_center_target(string $companyuuid, string $uuid,
            string $targettype, string $targetuuid): void {
        foreach (self::current_rows('local_kbacompany_fincenter', $companyuuid, true) as $row) {
            if ($row->status === 'archived' || (string)$row->uuid === $uuid) {
                continue;
            }
            if ((string)$row->targettype === $targettype && (string)$row->targetuuid === $targetuuid) {
                self::fail('duplicatetarget');
            }
        }
    }

    public static function save_center(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $code = self::stable_code('local_kbacompany_fincenter', $companyuuid, $uuid,
            'financial:center', 'CFO');
        $centertype = self::text($data, 'centertype', 12, true);
        if (!in_array($centertype, self::CENTER_TYPES, true)) {
            self::fail('invalid');
        }
        $targettype = self::text($data, 'targettype', 12, true);
        $targetuuid = self::validate_target($companyuuid, $targettype,
            self::text($data, 'targetuuid', 36, false));

        $ownerpositionuuid = self::text($data, 'ownerpositionuuid', 36, false);
        if ($ownerpositionuuid !== '' && !$DB->record_exists('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $ownerpositionuuid, 'active' => 1])) {
            self::fail('reference');
        }
        $mainstatuuid = self::text($data, 'mainstatuuid', 36, false);
        if ($mainstatuuid !== '' && !$DB->record_exists('local_kbacompany_stat',
                ['companyuuid' => $companyuuid, 'uuid' => $mainstatuuid])) {
            self::fail('reference');
        }
        $reviewperiod = self::text($data, 'reviewperiod', 12, true);
        if (!in_array($reviewperiod, self::REVIEW_PERIODS, true)) {
            self::fail('invalid');
        }
        $mapped = $data;
        $mapped['code'] = $code;
        $mapped['centertype'] = $centertype;
        $mapped['targettype'] = $targettype;
        $mapped['targetuuid'] = $targetuuid;
        $mapped['ownerpositionuuid'] = $ownerpositionuuid;
        $mapped['mainstatuuid'] = $mainstatuuid;
        $mapped['reviewperiod'] = $reviewperiod;
        return self::save_versioned('local_kbacompany_fincenter', $companyuuid, $userid, $mapped,
            $uuid, $revision, [
                'code' => [50, true],
                'name' => [255, true],
                'centertype' => [12, true],
                'targettype' => [12, true],
                'targetuuid' => [36, true],
                'ownerpositionuuid' => [36, false],
                'mainstatuuid' => [36, false],
                'resultdescription' => [8000, false],
                'authorityrule' => [8000, false],
                'reviewperiod' => [12, true],
            ]);
    }

    public static function publish_center(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::publish_versioned('local_kbacompany_fincenter', $companyuuid, $userid, $uuid, $revision);
    }

    public static function archive_center(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::archive_versioned('local_kbacompany_fincenter', $companyuuid, $userid, $uuid, $revision);
    }

    private static function dto(\stdClass $row): array {
        $out = (array)$row;
        unset($out['id']);
        foreach (['revision', 'strategyrevision', 'businessmodelrevision', 'createdby', 'modifiedby',
                'timecreated', 'timemodified'] as $key) {
            if (isset($out[$key])) {
                $out[$key] = (int)$out[$key];
            }
        }
        return $out;
    }

    private static function reference_options(string $companyuuid): array {
        global $DB;
        $nodes = [];
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'active' => 1],
                'code ASC') as $row) {
            $nodes[] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1],
                'code ASC') as $row) {
            $positions[] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'name' => (string)$row->name,
                'orgnodeuuid' => (string)$row->orgnodeuuid];
        }
        $products = [];
        foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'code ASC') as $row) {
            $products[] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        $indicators = [];
        foreach ($DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid], 'code ASC') as $row) {
            $indicators[] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'name' => (string)$row->name,
                'unit' => (string)$row->unit, 'period' => (string)$row->periodtype];
        }
        $strategies = [];
        $seen = [];
        foreach ($DB->get_records('local_kbacompany_strategy',
                ['companyuuid' => $companyuuid, 'status' => 'published'], 'uuid ASC, revision DESC') as $row) {
            if (isset($seen[$row->uuid])) { continue; }
            $seen[$row->uuid] = true;
            $strategies[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        $models = [];
        $seen = [];
        foreach ($DB->get_records('local_kbacompany_bizmodel',
                ['companyuuid' => $companyuuid, 'status' => 'published'], 'uuid ASC, revision DESC') as $row) {
            if (isset($seen[$row->uuid])) { continue; }
            $seen[$row->uuid] = true;
            $models[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'name' => (string)$row->name];
        }
        return compact('nodes', 'positions', 'products', 'indicators', 'strategies', 'models');
    }

    public static function scenario(string $companyuuid, int $userid, string $uuid): array {
        $company = company_context_service::context($companyuuid, $userid);
        if (!self::can_view($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $row = self::latest('local_kbacompany_finscenario', $companyuuid, $uuid);
        $refs = self::reference_options($companyuuid);
        return [
            'company' => $company,
            'scenario' => self::dto($row),
            'history' => array_map([self::class, 'dto'],
                self::record_history('local_kbacompany_finscenario', $companyuuid, $uuid)),
            'can_manage' => self::can_manage($companyuuid, $userid),
            'strategies' => $refs['strategies'],
            'business_models' => $refs['models'],
        ];
    }

    public static function center(string $companyuuid, int $userid, string $uuid): array {
        $company = company_context_service::context($companyuuid, $userid);
        if (!self::can_view($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $row = self::latest('local_kbacompany_fincenter', $companyuuid, $uuid);
        $refs = self::reference_options($companyuuid);
        return [
            'company' => $company,
            'center' => self::dto($row),
            'history' => array_map([self::class, 'dto'],
                self::record_history('local_kbacompany_fincenter', $companyuuid, $uuid)),
            'can_manage' => self::can_manage($companyuuid, $userid),
            'nodes' => $refs['nodes'],
            'positions' => $refs['positions'],
            'products' => $refs['products'],
            'indicators' => $refs['indicators'],
        ];
    }

    public static function registry(string $companyuuid, int $userid, string $entity, array $filters = []): array {
        $company = company_context_service::context($companyuuid, $userid);
        if (!self::can_view($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        if (!in_array($entity, ['scenario', 'center'], true)) {
            self::fail('invalid');
        }
        $table = $entity === 'scenario' ? 'local_kbacompany_finscenario' : 'local_kbacompany_fincenter';
        $rows = array_map([self::class, 'dto'], self::latest_rows_filtered($table, $companyuuid, $filters));
        return [
            'company' => $company,
            'records' => $rows,
            'can_manage' => self::can_manage($companyuuid, $userid),
            'filters' => $filters,
        ];
    }

    public static function context(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        if (!self::can_view($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $canmanage = self::can_manage($companyuuid, $userid);
        $scenarios = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_finscenario', $companyuuid, $canmanage));
        $centers = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_fincenter', $companyuuid, $canmanage));
        $refs = self::reference_options($companyuuid);
        return [
            'company' => $company,
            'scenarios' => $scenarios,
            'centers' => $centers,
            'can_manage' => $canmanage,
            'scenario_types' => self::SCENARIO_TYPES,
            'center_types' => self::CENTER_TYPES,
            'target_types' => self::TARGET_TYPES,
            'review_periods' => self::REVIEW_PERIODS,
            'nodes' => $refs['nodes'],
            'positions' => $refs['positions'],
            'products' => $refs['products'],
            'indicators' => $refs['indicators'],
            'strategies' => $refs['strategies'],
            'business_models' => $refs['models'],
            'contract' => self::contract(),
        ];
    }
}
