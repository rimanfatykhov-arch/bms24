<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Explicit valuable final products and append-only operational measurements. */
class product_service {
    public static function contract(): array {
        return [
            'version' => '0.5.1.3',
            'objects' => ['ValuableFinalProduct', 'StatisticDefinition', 'StatisticObservation'],
            'target_types' => ['company', 'node', 'position'],
            'periods' => ['daily', 'weekly', 'monthly', 'quarterly', 'annual'],
            'kinds' => ['fact', 'plan'],
            'rules' => ['missing_is_null_not_zero', 'corrections_append_revisions',
                'no_automatic_competency_or_maturity_change', 'correlation_is_not_causation',
                'definitions_immutable', 'no_formula_execution', 'company_scope_required',
                'company_ckp_supported', 'indicator_product_link_optional'],
        ];
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_organization($companyuuid, $userid);
    }

    private static function require_manage(string $companyuuid, int $userid): void {
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function uuid(): string {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3) .
            '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20);
    }

    private static function required_text(array $data, string $field, int $max): string {
        $value = trim((string)($data[$field] ?? ''));
        if ($value === '' || \core_text::strlen($value) > $max) {
            throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        return $value;
    }

    public static function create_product(string $companyuuid, int $userid, array $data): string {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $type = (string)($data['targettype'] ?? '');
        $targetuuid = (string)($data['targetuuid'] ?? '');
        if (!in_array($type, ['company', 'node', 'position'], true)) {
            throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        if ($type === 'company') {
            if ($targetuuid !== $companyuuid) {
                throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
            }
            // Resolve through Company Core so a foreign or unavailable company can never be used as a CKP target.
            company_context_service::context($companyuuid, $userid);
            $validatedtargetuuid = $companyuuid;
        } else {
            $target = $DB->get_record($type === 'node' ? 'local_kbacompany_orgnode' : 'local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $targetuuid, 'active' => 1], '*', MUST_EXIST);
            $validatedtargetuuid = (string)$target->uuid;
        }
        $productuuid = self::uuid();
        // Stable server-generated code, independent of concurrent company creations.
        $autocode = 'CKP-' . strtoupper(substr(str_replace('-', '', $productuuid), 0, 12));
        $record = (object)[
            'companyuuid' => $companyuuid, 'uuid' => $productuuid,
            'code' => $autocode,
            'name' => self::required_text($data, 'name', 255),
            'acceptance' => self::required_text($data, 'acceptance', 4000),
            'targettype' => $type, 'targetuuid' => $validatedtargetuuid, 'status' => 'active', 'timecreated' => time(),
        ];
        if ($DB->record_exists('local_kbacompany_product',
                ['companyuuid' => $companyuuid, 'code' => $record->code])) {
            throw new \moodle_exception('productduplicatecode', 'local_kbacompany');
        }
        $DB->insert_record('local_kbacompany_product', $record);
        return $record->uuid;
    }

    /** Update the canonical VFP while preserving its UUID, stable code and related statistics. */
    public static function update_product(string $companyuuid, int $userid, string $productuuid, array $data): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $record = $DB->get_record('local_kbacompany_product',
            ['companyuuid' => $companyuuid, 'uuid' => $productuuid], '*', MUST_EXIST);
        if ((string)$record->status !== 'active') {
            throw new \moodle_exception('productarchivedreadonly', 'local_kbacompany');
        }
        $type = (string)($data['targettype'] ?? '');
        $targetuuid = (string)($data['targetuuid'] ?? '');
        if (!in_array($type, ['company', 'node', 'position'], true)) {
            throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        if ($type === 'company') {
            if ($targetuuid !== $companyuuid) {
                throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
            }
        } else {
            $DB->get_record($type === 'node' ? 'local_kbacompany_orgnode' : 'local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $targetuuid, 'active' => 1], '*', MUST_EXIST);
        }
        $record->name = self::required_text($data, 'name', 255);
        $record->acceptance = self::required_text($data, 'acceptance', 4000);
        $record->targettype = $type;
        $record->targetuuid = $targetuuid;
        $DB->update_record('local_kbacompany_product', $record);
    }

    /** Soft archive or restore a CKP; never mutate its statistics or their history. */
    public static function set_product_archived(string $companyuuid, int $userid,
            string $productuuid, bool $archived): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $record = $DB->get_record('local_kbacompany_product',
            ['companyuuid' => $companyuuid, 'uuid' => $productuuid], '*', MUST_EXIST);
        $expected = $archived ? 'active' : 'archived';
        if ((string)$record->status !== $expected) {
            throw new \moodle_exception('productinvalidtransition', 'local_kbacompany');
        }
        $record->status = $archived ? 'archived' : 'active';
        $DB->update_record('local_kbacompany_product', $record);
    }

    public static function create_statistic(string $companyuuid, int $userid, array $data): string {
        global $DB;
        if (!permission_service::can_manage_indicators($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $productuuid = trim((string)($data['productuuid'] ?? ''));
        if ($productuuid !== '' && !$DB->record_exists('local_kbacompany_product',
                ['companyuuid' => $companyuuid, 'uuid' => $productuuid, 'status' => 'active'])) {
            throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        $period = (string)($data['periodtype'] ?? '');
        $direction = (string)($data['direction'] ?? '');
        if (!in_array($period, ['daily', 'weekly', 'monthly', 'quarterly', 'annual'], true) ||
                !in_array($direction, ['up', 'down', 'neutral'], true)) {
            throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        $record = (object)[
            'companyuuid' => $companyuuid, 'uuid' => self::uuid(), 'productuuid' => $productuuid,
            'code' => trim((string)($data['code'] ?? '')) !== '' ? self::required_text($data, 'code', 50) : ('MET-' . strtoupper(bin2hex(random_bytes(6)))),
            'name' => self::required_text($data, 'name', 255),
            'unit' => self::required_text($data, 'unit', 50),
            'periodtype' => $period, 'direction' => $direction, 'timecreated' => time(),
        ];
        if ($DB->record_exists('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'code' => $record->code])) {
            throw new \moodle_exception('productduplicatecode', 'local_kbacompany');
        }
        $DB->insert_record('local_kbacompany_stat', $record);
        return $record->uuid;
    }

    /** Update the canonical metric definition without replacing its UUID or history. */
    public static function update_statistic(string $companyuuid, int $userid, string $statuuid, array $data): void {
        global $DB;
        if (!permission_service::can_manage_indicators($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        if (!indicator_registry_service::is_metric_active($companyuuid, $statuuid)) {
            throw new \moodle_exception('indicator_archived_readonly', 'local_kbacompany');
        }
        $record = $DB->get_record('local_kbacompany_stat',
            ['companyuuid' => $companyuuid, 'uuid' => $statuuid], '*', MUST_EXIST);
        $productuuid = trim((string)($data['productuuid'] ?? ''));
        if ($productuuid !== '' && $productuuid !== (string)$record->productuuid &&
                !$DB->record_exists('local_kbacompany_product',
                ['companyuuid' => $companyuuid, 'uuid' => $productuuid, 'status' => 'active'])) {
            throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        $period = (string)($data['periodtype'] ?? '');
        $direction = (string)($data['direction'] ?? '');
        if (!in_array($period, ['daily', 'weekly', 'monthly', 'quarterly', 'annual'], true) ||
                !in_array($direction, ['up', 'down', 'neutral'], true)) {
            throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        // BMS-UX-CRUD-001: Stable Code is immutable, including legacy direct POST/API callers.
        // Some older routes submit the code; validate it but never use it to update identity.
        $originalcode = (string)$record->code;
        if (array_key_exists('code', $data) && (string)$data['code'] !== $originalcode) {
            throw new \moodle_exception('indicator_invalid', 'local_kbacompany');
        }
        $name = self::required_text($data, 'name', 255);
        $unit = self::required_text($data, 'unit', 50);
        if ($DB->record_exists('local_kbacompany_statval', ['companyuuid' => $companyuuid, 'statuuid' => $statuuid]) &&
                ($period !== (string)$record->periodtype || $unit !== (string)$record->unit)) {
            throw new \moodle_exception('indicator_definition_history_locked', 'local_kbacompany');
        }
        // The code remains unchanged; never accept a replacement through legacy routes.
        $record->productuuid = $productuuid;
        $record->name = $name;
        $record->unit = $unit;
        $record->periodtype = $period;
        $record->direction = $direction;
        $DB->update_record('local_kbacompany_stat', $record);
    }

    /** Validate canonical calendar period starts; weekly periods begin on Monday. */
    public static function validate_period(string $date, string $period): void {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$parsed || $parsed->format('Y-m-d') !== $date ||
                !in_array($period, ['daily', 'weekly', 'monthly', 'quarterly', 'annual'], true) ||
                ($period === 'weekly' && $parsed->format('N') !== '1') ||
                ($period === 'monthly' && $parsed->format('d') !== '01') ||
                ($period === 'quarterly' && ($parsed->format('d') !== '01' ||
                    !in_array((int)$parsed->format('n'), [1, 4, 7, 10], true))) ||
                ($period === 'annual' && $parsed->format('m-d') !== '01-01')) {
            throw new \moodle_exception('productinvalidperiod', 'local_kbacompany');
        }
    }

    public static function record_value(string $companyuuid, int $userid, string $statuuid,
            string $date, string $kind, string $amount, string $reason = '', string $idempotencykey = '',
            string $battleplanuuid = ''): int {
        global $DB;
        // The optional plan scope permits fact entry ONLY on the editable employee's
        // position indicators for that exact weekly Battle Plan. It does not grant
        // permission to define metrics, set targets, or edit unrelated observations.
        if ($battleplanuuid !== '') {
            if ($kind !== 'fact') {
                throw new \moodle_exception('battleplan_indicator_permission', 'local_kbacompany');
            }
            $plancontext = battle_plan_service::context($companyuuid, $userid, $battleplanuuid,
                0, '', '', ['load_plans' => false, 'load_sources' => false]);
            $plan = $plancontext['selected'] ?? null;
            if (!$plan || empty($plancontext['selected_can_edit']) || $plan['status'] === 'closed') {
                throw new \moodle_exception('battleplan_indicator_permission', 'local_kbacompany');
            }
            $bound = false;
            foreach (($plancontext['position_indicators'] ?? []) as $indicator) {
                if ((string)$indicator['stat_uuid'] === $statuuid) { $bound = true; break; }
            }
            if (!$bound) {
                throw new \moodle_exception('battleplan_indicator_binding', 'local_kbacompany');
            }
        } else if (!permission_service::can_manage_indicators($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $stat = $DB->get_record('local_kbacompany_stat',
            ['companyuuid' => $companyuuid, 'uuid' => $statuuid], '*', MUST_EXIST);
        if ($battleplanuuid !== '') {
            $validdate = $stat->periodtype === 'daily'
                ? ($date >= $plan['period_start'] && $date <= $plan['period_end'])
                : ($stat->periodtype === 'weekly' && $date === $plan['period_start']);
            if (!$validdate) {
                throw new \moodle_exception('productinvalidperiod', 'local_kbacompany');
            }
        }
        if (!indicator_registry_service::is_metric_active($companyuuid, $statuuid)) {
            throw new \moodle_exception('indicator_archived_readonly', 'local_kbacompany');
        }
        self::validate_period($date, $stat->periodtype);
        // Fixed decimal input, at most 12 integer and 6 fractional digits; zero is a real observation.
        $idempotencykey = trim($idempotencykey);
        $humanreason = trim($reason);
        if (!in_array($kind, ['fact', 'plan'], true) ||
                !preg_match('/^-?\d{1,12}(\.\d{1,6})?$/D', $amount) ||
                \core_text::strlen($reason) > 3600 ||
                ($idempotencykey !== '' && !preg_match('/^[a-f0-9]{64}$/D', $idempotencykey))) {
            throw new \moodle_exception('productinvalidinput', 'local_kbacompany');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('statistic:' . $statuuid, 10);
        if (!$lock) {
            throw new \moodle_exception('staffinglockfailed', 'local_kbacompany');
        }
        try {
            $conditions = ['companyuuid' => $companyuuid, 'statuuid' => $statuuid,
                'periodstart' => $date, 'kind' => $kind];
            if ($idempotencykey !== '') {
                $marker = '[bms-idem:' . $idempotencykey . ']';
                $like = $DB->sql_like('reason', ':idemmarker', false, false);
                $duplicate = $DB->get_record_select('local_kbacompany_statval',
                    'companyuuid = :idemcompany AND statuuid = :idemstat AND periodstart = :idemperiod ' .
                    'AND kind = :idemkind AND ' . $like, [
                        'idemcompany' => $companyuuid, 'idemstat' => $statuuid,
                        'idemperiod' => $date, 'idemkind' => $kind, 'idemmarker' => '%' . $marker . '%',
                    ], 'id', IGNORE_MULTIPLE);
                if ($duplicate) {
                    return (int)$duplicate->id;
                }
                $reason = $humanreason . ($humanreason === '' ? '' : ' ') . $marker;
            }
            $previous = $DB->get_records('local_kbacompany_statval', $conditions, 'revision DESC', '*', 0, 1);
            $previous = reset($previous);
            if ($previous && $humanreason === '') {
                throw new \moodle_exception('productcorrectionreason', 'local_kbacompany');
            }
            return (int)$DB->insert_record('local_kbacompany_statval', (object)array_merge($conditions, [
                'amount' => $amount, 'revision' => $previous ? (int)$previous->revision + 1 : 1,
                'reason' => trim($reason), 'writerid' => $userid, 'timecreated' => time(),
            ]));
        } finally {
            $lock->release();
        }
    }

    /** Latest revisions per period; missing plan/fact stays null, including around zero. */
    public static function series(array $records): array {
        $periods = [];
        foreach ($records as $record) {
            $key = $record->periodstart;
            if (!isset($periods[$key])) {
                $periods[$key] = ['period' => $key, 'fact' => null, 'plan' => null,
                    'fact_revision' => 0, 'plan_revision' => 0];
            }
            $kind = $record->kind;
            if ((int)$record->revision > $periods[$key][$kind . '_revision']) {
                $periods[$key][$kind] = (string)$record->amount;
                $periods[$key][$kind . '_revision'] = (int)$record->revision;
            }
        }
        ksort($periods);
        return array_values($periods);
    }

    public static function context(string $companyuuid, int $userid, string $statuuid = ''): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $products = $DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'code ASC');
        $stats = $DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid], 'code ASC');
        $selected = $statuuid === '' ? reset($stats) : $DB->get_record('local_kbacompany_stat',
            ['companyuuid' => $companyuuid, 'uuid' => $statuuid], '*', MUST_EXIST);
        $history = [];
        if ($selected) {
            // Bound the period window, then fetch every revision in that window. Never split corrections.
            $dates = $DB->get_records_sql('SELECT DISTINCT periodstart FROM {local_kbacompany_statval}
                WHERE companyuuid = :company AND statuuid = :stat ORDER BY periodstart DESC',
                ['company' => $companyuuid, 'stat' => $selected->uuid], 0, 52);
            if ($dates) {
                $last = end($dates);
                $history = $DB->get_records_select('local_kbacompany_statval',
                    'companyuuid = :company AND statuuid = :stat AND periodstart >= :firstperiod',
                    ['company' => $companyuuid, 'stat' => $selected->uuid, 'firstperiod' => $last->periodstart],
                    'periodstart DESC, kind ASC, revision DESC');
            }
        }
        return [
            'company' => $company, 'products' => array_values($products), 'statistics' => array_values($stats),
            'selected' => $selected ?: null, 'series' => self::series($history),
            'history' => array_values($history), 'can_manage' => self::can_manage($companyuuid, $userid),
            'window_periods' => 52,
        ];
    }
}
