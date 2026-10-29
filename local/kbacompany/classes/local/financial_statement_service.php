<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Financial planning statements over the v0.6.0 scenario / responsibility-centre foundation.
 *
 * This service stores plan/forecast revisions only. Actual accounting facts, bank movements,
 * ERP ledgers and payment execution remain outside local_kbacompany.
 */
class financial_statement_service {
    public const CONTRACT_VERSION = '0.6.1';
    public const STATES = ['draft', 'published', 'archived'];
    public const TYPES = ['bdr', 'bdds', 'balance'];
    public const BDDS_BUCKETS = 4;

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_financial_statements',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['FinancialStatement', 'FinancialStatementLine'],
            'statement_types' => self::TYPES,
            'rules' => [
                'statements_pin_published_financial_scenario_revision',
                'optional_responsibility_centre_link_pins_published_revision',
                'statement_revisions_are_append_only',
                'published_revisions_are_immutable',
                'bdr_is_planned_economic_result_not_cash_movement',
                'bdds_is_planned_cash_movement_not_profit',
                'forecast_balance_must_balance_before_publish',
                'bdds_opening_cash_rolls_forward_from_prior_bucket_closing_cash',
                'no_actual_accounting_or_bank_facts_are_created',
                'no_payment_execution_or_erp_mutation',
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
        throw new \moodle_exception('financialstatement_' . $key, 'local_kbacompany');
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

    private static function date_value(array $data, string $key): string {
        $value = self::text($data, $key, 10, true);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || ($errors !== false && (!empty($errors['warning_count']) || !empty($errors['error_count']))) ||
                $dt->format('Y-m-d') !== $value) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function amount($value, bool $allownegative = false): float {
        if (is_string($value)) {
            $value = str_replace(["\xc2\xa0", ' '], '', trim($value));
            $value = str_replace(',', '.', $value);
        }
        if ($value === '' || $value === null) {
            $value = 0;
        }
        if (!is_numeric($value)) {
            self::fail('amountinvalid');
        }
        $amount = round((float)$value, 2);
        if (!$allownegative && $amount < 0) {
            self::fail('amountinvalid');
        }
        if (abs($amount) > 9999999999999999.99) {
            self::fail('amountinvalid');
        }
        return $amount;
    }

    /** Canonical template rows. Calculated rows are never persisted. */
    public static function template(string $type): array {
        if ($type === 'bdr') {
            return [
                ['code' => 'REV', 'labelkey' => 'financialstatement_line_rev', 'kind' => 'input'],
                ['code' => 'VAR', 'labelkey' => 'financialstatement_line_var', 'kind' => 'input'],
                ['code' => 'CM', 'labelkey' => 'financialstatement_line_cm', 'kind' => 'calc'],
                ['code' => 'CMR', 'labelkey' => 'financialstatement_line_cmr', 'kind' => 'percent'],
                ['code' => 'FIX', 'labelkey' => 'financialstatement_line_fix', 'kind' => 'input'],
                ['code' => 'OP', 'labelkey' => 'financialstatement_line_op', 'kind' => 'calc'],
                ['code' => 'OTH_INC', 'labelkey' => 'financialstatement_line_oth_inc', 'kind' => 'input'],
                ['code' => 'OTH_EXP', 'labelkey' => 'financialstatement_line_oth_exp', 'kind' => 'input'],
                ['code' => 'PBT', 'labelkey' => 'financialstatement_line_pbt', 'kind' => 'calc'],
                ['code' => 'TAX', 'labelkey' => 'financialstatement_line_tax', 'kind' => 'input'],
                ['code' => 'NP', 'labelkey' => 'financialstatement_line_np', 'kind' => 'calc'],
            ];
        }
        if ($type === 'bdds') {
            return [
                ['code' => 'OPENING', 'labelkey' => 'financialstatement_line_opening', 'kind' => 'opening'],
                ['code' => 'CLIENT_IN', 'labelkey' => 'financialstatement_line_client_in', 'kind' => 'input'],
                ['code' => 'OTHER_OP_IN', 'labelkey' => 'financialstatement_line_other_op_in', 'kind' => 'input'],
                ['code' => 'FIN_IN', 'labelkey' => 'financialstatement_line_fin_in', 'kind' => 'input'],
                ['code' => 'TOTAL_IN', 'labelkey' => 'financialstatement_line_total_in', 'kind' => 'calc'],
                ['code' => 'OP_OUT', 'labelkey' => 'financialstatement_line_op_out', 'kind' => 'input'],
                ['code' => 'PAYROLL_TAX', 'labelkey' => 'financialstatement_line_payroll_tax', 'kind' => 'input'],
                ['code' => 'INVEST_OUT', 'labelkey' => 'financialstatement_line_invest_out', 'kind' => 'input'],
                ['code' => 'FIN_OUT', 'labelkey' => 'financialstatement_line_fin_out', 'kind' => 'input'],
                ['code' => 'TOTAL_OUT', 'labelkey' => 'financialstatement_line_total_out', 'kind' => 'calc'],
                ['code' => 'CLOSING', 'labelkey' => 'financialstatement_line_closing', 'kind' => 'calc'],
            ];
        }
        if ($type === 'balance') {
            return [
                ['code' => 'CASH', 'labelkey' => 'financialstatement_line_cash', 'kind' => 'input'],
                ['code' => 'AR', 'labelkey' => 'financialstatement_line_ar', 'kind' => 'input'],
                ['code' => 'INVENTORY', 'labelkey' => 'financialstatement_line_inventory', 'kind' => 'input'],
                ['code' => 'OTHER_CURRENT', 'labelkey' => 'financialstatement_line_other_current', 'kind' => 'input'],
                ['code' => 'FIXED_ASSETS', 'labelkey' => 'financialstatement_line_fixed_assets', 'kind' => 'input'],
                ['code' => 'OTHER_ASSETS', 'labelkey' => 'financialstatement_line_other_assets', 'kind' => 'input'],
                ['code' => 'TOTAL_ASSETS', 'labelkey' => 'financialstatement_line_total_assets', 'kind' => 'calc'],
                ['code' => 'AP', 'labelkey' => 'financialstatement_line_ap', 'kind' => 'input'],
                ['code' => 'DEBT', 'labelkey' => 'financialstatement_line_debt', 'kind' => 'input'],
                ['code' => 'OTHER_LIAB', 'labelkey' => 'financialstatement_line_other_liab', 'kind' => 'input'],
                ['code' => 'EQUITY', 'labelkey' => 'financialstatement_line_equity', 'kind' => 'input'],
                ['code' => 'TOTAL_LIAB_EQUITY', 'labelkey' => 'financialstatement_line_total_liab_equity', 'kind' => 'calc'],
                ['code' => 'BALANCE_GAP', 'labelkey' => 'financialstatement_line_balance_gap', 'kind' => 'calc'],
            ];
        }
        self::fail('typeinvalid');
        return [];
    }

    public static function bucket_count(string $type): int {
        return $type === 'bdds' ? self::BDDS_BUCKETS : 1;
    }

    /** Return the exact persisted cells expected for a statement type. */
    public static function input_cells(string $type): array {
        $out = [];
        foreach (self::template($type) as $row) {
            if ($row['kind'] === 'input') {
                for ($bucket = 1; $bucket <= self::bucket_count($type); $bucket++) {
                    $out[] = [$row['code'], $bucket, false];
                }
            } else if ($row['kind'] === 'opening') {
                $out[] = [$row['code'], 1, true];
            }
        }
        return $out;
    }

    /** Calculate derived statement cells from persisted input values. */
    public static function calculate(string $type, array $values): array {
        $v = static function(array $data, string $code, int $bucket = 1): float {
            return isset($data[$code][$bucket]) ? (float)$data[$code][$bucket] : 0.0;
        };
        $out = $values;
        $summary = [];
        if ($type === 'bdr') {
            $rev = $v($values, 'REV');
            $var = $v($values, 'VAR');
            $cm = $rev - $var;
            $fix = $v($values, 'FIX');
            $op = $cm - $fix;
            $pbt = $op + $v($values, 'OTH_INC') - $v($values, 'OTH_EXP');
            $np = $pbt - $v($values, 'TAX');
            $out['CM'][1] = round($cm, 2);
            $out['CMR'][1] = $rev == 0.0 ? 0.0 : round(($cm / $rev) * 100, 2);
            $out['OP'][1] = round($op, 2);
            $out['PBT'][1] = round($pbt, 2);
            $out['NP'][1] = round($np, 2);
            $summary = ['netprofit' => round($np, 2), 'operatingprofit' => round($op, 2),
                'marginpercent' => $out['CMR'][1]];
        } else if ($type === 'bdds') {
            $opening = $v($values, 'OPENING', 1);
            $minimum = null;
            $negative = 0;
            for ($bucket = 1; $bucket <= self::BDDS_BUCKETS; $bucket++) {
                if ($bucket > 1) {
                    $opening = (float)$out['CLOSING'][$bucket - 1];
                }
                $out['OPENING'][$bucket] = round($opening, 2);
                $totalin = $v($values, 'CLIENT_IN', $bucket) + $v($values, 'OTHER_OP_IN', $bucket) +
                    $v($values, 'FIN_IN', $bucket);
                $totalout = $v($values, 'OP_OUT', $bucket) + $v($values, 'PAYROLL_TAX', $bucket) +
                    $v($values, 'INVEST_OUT', $bucket) + $v($values, 'FIN_OUT', $bucket);
                $closing = $opening + $totalin - $totalout;
                $out['TOTAL_IN'][$bucket] = round($totalin, 2);
                $out['TOTAL_OUT'][$bucket] = round($totalout, 2);
                $out['CLOSING'][$bucket] = round($closing, 2);
                $opening = $closing;
                $minimum = $minimum === null ? $closing : min($minimum, $closing);
                if ($closing < 0) {
                    $negative++;
                }
            }
            $summary = ['closingcash' => (float)$out['CLOSING'][self::BDDS_BUCKETS],
                'minimumcash' => (float)$minimum, 'negativebuckets' => $negative];
        } else if ($type === 'balance') {
            $assets = $v($values, 'CASH') + $v($values, 'AR') + $v($values, 'INVENTORY') +
                $v($values, 'OTHER_CURRENT') + $v($values, 'FIXED_ASSETS') + $v($values, 'OTHER_ASSETS');
            $right = $v($values, 'AP') + $v($values, 'DEBT') + $v($values, 'OTHER_LIAB') +
                $v($values, 'EQUITY');
            $gap = $assets - $right;
            $out['TOTAL_ASSETS'][1] = round($assets, 2);
            $out['TOTAL_LIAB_EQUITY'][1] = round($right, 2);
            $out['BALANCE_GAP'][1] = round($gap, 2);
            $summary = ['totalassets' => round($assets, 2), 'totalliabilitiesequity' => round($right, 2),
                'balancegap' => round($gap, 2), 'cash' => $v($values, 'CASH')];
        } else {
            self::fail('typeinvalid');
        }
        return ['values' => $out, 'summary' => $summary];
    }

    private static function latest(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_finstmt',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    private static function published_ref(string $table, string $companyuuid, string $uuid, bool $required): array {
        global $DB;
        $uuid = trim($uuid);
        if ($uuid === '') {
            if ($required) {
                self::fail('reference');
            }
            return ['', 0];
        }
        $all = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid],
            'revision DESC', 'uuid,revision,status', 0, 1);
        if (!$all || (string)reset($all)->status === 'archived') {
            self::fail('reference');
        }
        $rows = $DB->get_records($table,
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'status' => 'published'],
            'revision DESC', 'uuid,revision', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        $row = reset($rows);
        return [(string)$row->uuid, (int)$row->revision];
    }

    private static function assert_pinned_reference_active(string $table, string $companyuuid,
            string $uuid, int $revision, bool $required): void {
        global $DB;
        if ($uuid === '') {
            if ($required) {
                self::fail('reference');
            }
            return;
        }
        $latestrows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid],
            'revision DESC', 'revision,status', 0, 1);
        if (!$latestrows || (string)reset($latestrows)->status === 'archived' ||
                !$DB->record_exists($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid,
                    'revision' => $revision, 'status' => 'published'])) {
            self::fail('reference');
        }
    }

    private static function current_rows(string $companyuuid): array {
        global $DB;
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_finstmt', ['companyuuid' => $companyuuid],
                'uuid ASC, revision ASC') as $row) {
            $grouped[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($grouped as $versions) {
            $latest = end($versions);
            if ($latest->status !== 'archived') {
                $out[] = $latest;
            }
        }
        return $out;
    }

    private static function published_current(string $table, string $companyuuid): array {
        global $DB;
        $versions = [];
        foreach ($DB->get_records($table, ['companyuuid' => $companyuuid], 'uuid ASC, revision ASC') as $row) {
            $versions[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($versions as $rows) {
            $latest = end($rows);
            if ((string)$latest->status === 'archived') {
                continue;
            }
            foreach (array_reverse($rows) as $row) {
                if ((string)$row->status === 'published') {
                    $out[] = $row;
                    break;
                }
            }
        }
        return $out;
    }

    private static function assert_unique_code(string $companyuuid, string $stableuuid, string $code): void {
        global $DB;
        $uuids = [];
        foreach ($DB->get_records('local_kbacompany_finstmt', ['companyuuid' => $companyuuid, 'code' => $code],
                'uuid ASC, revision DESC') as $row) {
            if (isset($uuids[$row->uuid])) {
                continue;
            }
            $uuids[$row->uuid] = true;
            if ((string)$row->uuid !== $stableuuid && (string)$row->status !== 'archived') {
                self::fail('duplicatecode');
            }
        }
    }

    private static function assert_unique_scope(string $companyuuid, string $stableuuid, string $type,
            string $scenariouuid, int $scenariorevision, string $centeruuid, string $periodstart,
            string $periodend): void {
        global $DB;
        $params = ['companyuuid' => $companyuuid, 'statementtype' => $type, 'scenariouuid' => $scenariouuid,
            'scenariorevision' => $scenariorevision, 'centeruuid' => $centeruuid,
            'periodstart' => $periodstart, 'periodend' => $periodend];
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_finstmt', $params, 'uuid ASC, revision DESC') as $row) {
            if (isset($grouped[$row->uuid])) {
                continue;
            }
            $grouped[$row->uuid] = true;
            if ((string)$row->uuid !== $stableuuid && (string)$row->status !== 'archived') {
                self::fail('duplicatescope');
            }
        }
    }

    private static function persisted_rows(string $type, array $values, array $sources): array {
        $rows = [];
        foreach (self::input_cells($type) as $cell) {
            [$code, $bucket, $allownegative] = $cell;
            $rows[] = [
                'linecode' => $code,
                'bucketno' => $bucket,
                'amount' => self::amount($values[$code][$bucket] ?? 0, $allownegative),
                'sourceinfo' => trim((string)($sources[$code] ?? '')),
            ];
            if (\core_text::strlen((string)($sources[$code] ?? '')) > 2000) {
                self::fail('invalid');
            }
        }
        return $rows;
    }

    private static function rows_to_values(array $rows): array {
        $values = [];
        $sources = [];
        foreach ($rows as $row) {
            $values[(string)$row->linecode][(int)$row->bucketno] = (float)$row->amount;
            if (!isset($sources[(string)$row->linecode]) && trim((string)$row->sourceinfo) !== '') {
                $sources[(string)$row->linecode] = (string)$row->sourceinfo;
            }
        }
        return [$values, $sources];
    }

    private static function line_rows(string $companyuuid, string $statementuuid, int $revision): array {
        global $DB;
        return array_values($DB->get_records('local_kbacompany_finline', [
            'companyuuid' => $companyuuid, 'statementuuid' => $statementuuid,
            'statementrevision' => $revision,
        ], 'linecode ASC, bucketno ASC'));
    }

    private static function insert_lines(string $companyuuid, string $statementuuid, int $revision,
            int $userid, array $rows): void {
        global $DB;
        foreach ($rows as $line) {
            $DB->insert_record('local_kbacompany_finline', (object)[
                'companyuuid' => $companyuuid,
                'statementuuid' => $statementuuid,
                'statementrevision' => $revision,
                'linecode' => $line['linecode'],
                'bucketno' => $line['bucketno'],
                'amount' => $line['amount'],
                'sourceinfo' => $line['sourceinfo'],
                'createdby' => $userid,
                'timecreated' => time(),
            ]);
        }
    }

    public static function save_statement(string $companyuuid, int $userid, array $data, array $values,
            array $sources, string $uuid = '', int $expected = 0): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $type = self::text($data, 'statementtype', 12, true);
        if (!in_array($type, self::TYPES, true)) {
            self::fail('typeinvalid');
        }
        $code = strtoupper(self::text($data, 'code', 50, true));
        $name = self::text($data, 'name', 255, true);
        $periodstart = self::date_value($data, 'periodstart');
        $periodend = self::date_value($data, 'periodend');
        if ($periodend < $periodstart) {
            self::fail('periodinvalid');
        }
        $currency = strtoupper(self::text($data, 'currency', 3, true));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            self::fail('invalid');
        }
        [$scenariouuid, $scenariorevision] = self::published_ref('local_kbacompany_finscenario',
            $companyuuid, self::text($data, 'scenariouuid', 36, true), true);
        [$centeruuid, $centerrevision] = self::published_ref('local_kbacompany_fincenter',
            $companyuuid, self::text($data, 'centeruuid', 36, false), false);
        $scenario = $DB->get_record('local_kbacompany_finscenario', [
            'companyuuid' => $companyuuid, 'uuid' => $scenariouuid, 'revision' => $scenariorevision,
        ], '*', MUST_EXIST);
        if ($periodstart < (string)$scenario->periodstart || $periodend > (string)$scenario->periodend) {
            self::fail('scenariohorizon');
        }
        if (strtoupper((string)$scenario->currency) !== $currency) {
            self::fail('currency');
        }
        $sourceinfo = self::text($data, 'sourceinfo', 4000, false);
        $notes = self::text($data, 'notes', 4000, false);
        $lines = self::persisted_rows($type, $values, $sources);

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('financial_statement:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($uuid !== '') {
                $old = self::latest($companyuuid, $uuid);
                if ((int)$old->revision !== $expected) {
                    self::fail('conflict');
                }
                if ($old->status === 'archived' || (string)$old->statementtype !== $type) {
                    self::fail('state');
                }
            } else if ($expected !== 0) {
                self::fail('conflict');
            }
            $stableuuid = $old ? (string)$old->uuid : self::uuid();
            self::assert_unique_code($companyuuid, $stableuuid, $code);
            self::assert_unique_scope($companyuuid, $stableuuid, $type, $scenariouuid,
                $scenariorevision, $centeruuid, $periodstart, $periodend);
            $revision = $old ? ((int)$old->revision + 1) : 1;
            $now = time();
            $DB->insert_record('local_kbacompany_finstmt', (object)[
                'companyuuid' => $companyuuid, 'uuid' => $stableuuid, 'revision' => $revision,
                'status' => 'draft', 'code' => $code, 'name' => $name, 'statementtype' => $type,
                'scenariouuid' => $scenariouuid, 'scenariorevision' => $scenariorevision,
                'centeruuid' => $centeruuid, 'centerrevision' => $centerrevision,
                'periodstart' => $periodstart, 'periodend' => $periodend, 'currency' => $currency,
                'sourceinfo' => $sourceinfo, 'notes' => $notes,
                'createdby' => $userid, 'modifiedby' => $userid, 'timecreated' => $now, 'timemodified' => $now,
            ]);
            self::insert_lines($companyuuid, $stableuuid, $revision, $userid, $lines);
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

    public static function publish_statement(string $companyuuid, int $userid, string $uuid, int $expected): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('financial_statement:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expected) {
                self::fail('conflict');
            }
            if ((string)$old->status !== 'draft') {
                self::fail('state');
            }
            self::assert_pinned_reference_active('local_kbacompany_finscenario', $companyuuid,
                (string)$old->scenariouuid, (int)$old->scenariorevision, true);
            self::assert_pinned_reference_active('local_kbacompany_fincenter', $companyuuid,
                (string)$old->centeruuid, (int)$old->centerrevision, false);
            $lines = self::line_rows($companyuuid, $uuid, $expected);
            [$values] = self::rows_to_values($lines);
            $calculated = self::calculate((string)$old->statementtype, $values);
            if ((string)$old->statementtype === 'balance' &&
                    abs((float)$calculated['summary']['balancegap']) > 0.01) {
                self::fail('unbalanced');
            }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expected + 1;
            $row->status = 'published';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_finstmt', $row);
            $newlines = [];
            foreach ($lines as $line) {
                $newlines[] = ['linecode' => (string)$line->linecode, 'bucketno' => (int)$line->bucketno,
                    'amount' => (float)$line->amount, 'sourceinfo' => (string)$line->sourceinfo];
            }
            self::insert_lines($companyuuid, $uuid, $expected + 1, $userid, $newlines);
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

    public static function archive_statement(string $companyuuid, int $userid, string $uuid, int $expected): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('financial_statement:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expected) {
                self::fail('conflict');
            }
            if ((string)$old->status === 'archived') {
                self::fail('state');
            }
            $lines = self::line_rows($companyuuid, $uuid, $expected);
            $row = clone $old;
            unset($row->id);
            $row->revision = $expected + 1;
            $row->status = 'archived';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_finstmt', $row);
            $newlines = [];
            foreach ($lines as $line) {
                $newlines[] = ['linecode' => (string)$line->linecode, 'bucketno' => (int)$line->bucketno,
                    'amount' => (float)$line->amount, 'sourceinfo' => (string)$line->sourceinfo];
            }
            self::insert_lines($companyuuid, $uuid, $expected + 1, $userid, $newlines);
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

    private static function ref_dto(\stdClass $row): array {
        return ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
            'code' => (string)$row->code, 'name' => (string)$row->name,
            'status' => (string)$row->status];
    }

    private static function dto(\stdClass $row): array {
        [$values, $sources] = self::rows_to_values(self::line_rows((string)$row->companyuuid,
            (string)$row->uuid, (int)$row->revision));
        $calc = self::calculate((string)$row->statementtype, $values);
        return [
            'uuid' => (string)$row->uuid, 'revision' => (int)$row->revision, 'status' => (string)$row->status,
            'code' => (string)$row->code, 'name' => (string)$row->name,
            'statementtype' => (string)$row->statementtype,
            'scenariouuid' => (string)$row->scenariouuid, 'scenariorevision' => (int)$row->scenariorevision,
            'centeruuid' => (string)$row->centeruuid, 'centerrevision' => (int)$row->centerrevision,
            'periodstart' => (string)$row->periodstart, 'periodend' => (string)$row->periodend,
            'currency' => (string)$row->currency, 'sourceinfo' => (string)$row->sourceinfo,
            'notes' => (string)$row->notes, 'values' => $values, 'sources' => $sources,
            'calculated' => $calc['values'], 'summary' => $calc['summary'],
            'createdby' => (int)$row->createdby, 'modifiedby' => (int)$row->modifiedby,
            'timecreated' => (int)$row->timecreated, 'timemodified' => (int)$row->timemodified,
        ];
    }

    public static function context(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        if (!self::can_view($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $scenarios = array_map([self::class, 'ref_dto'],
            self::published_current('local_kbacompany_finscenario', $companyuuid));
        $centers = array_map([self::class, 'ref_dto'],
            self::published_current('local_kbacompany_fincenter', $companyuuid));
        $statements = [];
        foreach (self::current_rows($companyuuid) as $row) {
            $statements[] = self::dto($row);
        }
        usort($statements, static function(array $a, array $b): int {
            return strcmp($a['code'], $b['code']);
        });
        return [
            'company' => $company, 'can_manage' => self::can_manage($companyuuid, $userid),
            'types' => self::TYPES, 'scenarios' => $scenarios, 'centers' => $centers,
            'statements' => $statements,
        ];
    }
}
