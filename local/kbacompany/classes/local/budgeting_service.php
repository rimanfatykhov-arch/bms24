<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * v0.6.2 Budgeting over Financial Scenario / Responsibility Centre and Administrative Scale.
 *
 * Budget facts are aggregate management snapshots only. This service does not store accounting
 * postings, bank transactions, invoices or treasury execution and does not mutate an ERP.
 */
class budgeting_service {
    public const CONTRACT_VERSION = '0.6.2';
    public const STATES = ['draft', 'submitted', 'approved', 'rejected', 'archived'];
    public const BUDGET_TYPES = ['goal', 'program', 'project', 'orgunit'];
    public const LINE_TYPES = ['revenue', 'expense', 'investment', 'financing', 'reserve', 'other'];
    public const FACT_SOURCE_KINDS = ['manual', 'external'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_budgeting',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['Budget', 'BudgetLine', 'BudgetFactSnapshot'],
            'budget_types' => self::BUDGET_TYPES,
            'rules' => [
                'budget_pins_published_financial_scenario_revision',
                'optional_responsibility_centre_link_pins_published_revision',
                'goal_program_project_budget_pins_published_administrative_scale_revision',
                'orgunit_budget_references_canonical_active_orgunit',
                'budget_revisions_are_append_only',
                'approved_revision_is_immutable',
                'budget_lines_are_immutable_per_budget_revision',
                'spend_limits_are_explicit_and_checked_before_approval',
                'approval_is_explicit_submitted_to_approved_or_rejected_transition',
                'plan_fact_uses_aggregate_management_snapshots_not_ledger_transactions',
                'raw_accounting_bank_invoice_and_payment_execution_remain_external',
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
        throw new \moodle_exception('budgeting_' . $key, 'local_kbacompany');
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

    private static function date_value(array $data, string $key, bool $required = true): string {
        $value = self::text($data, $key, 10, $required);
        if ($value === '' && !$required) {
            return '';
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$dt || ($errors !== false && (!empty($errors['warning_count']) || !empty($errors['error_count']))) ||
                $dt->format('Y-m-d') !== $value) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function amount($value): float {
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
        if ($amount < 0 || $amount > 9999999999999999.99) {
            self::fail('amountinvalid');
        }
        return $amount;
    }

    private static function latest(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_finbudget',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    private static function exact_budget(string $companyuuid, string $uuid, int $revision): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_finbudget',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision]);
        if (!$row) {
            self::fail('reference');
        }
        return $row;
    }

    private static function latest_published(string $table, string $companyuuid, string $uuid,
            string $kind = ''): \stdClass {
        global $DB;
        $params = ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'status' => 'published'];
        if ($kind !== '') {
            $params['kind'] = $kind;
        }
        unset($params['status']);
        $rows = $DB->get_records($table, $params, 'revision DESC');
        if (!$rows) {
            self::fail('reference');
        }
        $latest = reset($rows);
        if ((string)$latest->status === 'archived' || (string)$latest->status === 'withdrawn') {
            self::fail('reference');
        }
        foreach ($rows as $row) {
            if ((string)$row->status === 'published') {
                return $row;
            }
        }
        self::fail('reference');
        return $latest;
    }

    private static function validate_scenario(string $companyuuid, string $uuid): \stdClass {
        return self::latest_published('local_kbacompany_finscenario', $companyuuid, $uuid);
    }

    private static function validate_center(string $companyuuid, string $uuid): ?\stdClass {
        if ($uuid === '') {
            return null;
        }
        return self::latest_published('local_kbacompany_fincenter', $companyuuid, $uuid);
    }

    private static function validate_target(string $companyuuid, string $type, string $uuid): array {
        global $DB;
        if (!in_array($type, self::BUDGET_TYPES, true) || $uuid === '') {
            self::fail('reference');
        }
        if ($type === 'orgunit') {
            if (!$DB->record_exists('local_kbacompany_orgnode',
                    ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'active' => 1])) {
                self::fail('reference');
            }
            return [$uuid, 0];
        }
        $row = self::latest_published('local_kbacompany_adminitem', $companyuuid, $uuid, $type);
        return [(string)$row->uuid, (int)$row->revision];
    }

    private static function validate_position(string $companyuuid, string $uuid): void {
        global $DB;
        if ($uuid !== '' && !$DB->record_exists('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'active' => 1])) {
            self::fail('reference');
        }
    }

    private static function validate_indicator(string $companyuuid, string $uuid): void {
        global $DB;
        if ($uuid !== '' && !$DB->record_exists('local_kbacompany_stat',
                ['companyuuid' => $companyuuid, 'uuid' => $uuid])) {
            self::fail('reference');
        }
    }

    private static function normalise_lines(string $companyuuid, array $lines,
            string $periodstart, string $periodend): array {
        $out = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                self::fail('invalid');
            }
            $code = strtoupper(self::text($line, 'linecode', 50, true));
            if (!preg_match('/^[A-Z0-9_.-]+$/', $code) || isset($seen[$code])) {
                self::fail('duplicateline');
            }
            $seen[$code] = true;
            $name = self::text($line, 'name', 255, true);
            $linetype = self::text($line, 'linetype', 16, true);
            if (!in_array($linetype, self::LINE_TYPES, true)) {
                self::fail('invalid');
            }
            $linestart = self::date_value($line, 'periodstart', false);
            $lineend = self::date_value($line, 'periodend', false);
            if ($linestart === '') { $linestart = $periodstart; }
            if ($lineend === '') { $lineend = $periodend; }
            if ($lineend < $linestart || $linestart < $periodstart || $lineend > $periodend) {
                self::fail('periodinvalid');
            }
            $plan = self::amount($line['planamount'] ?? 0);
            $limit = self::amount($line['limitamount'] ?? 0);
            if (in_array($linetype, ['expense', 'investment', 'financing', 'reserve'], true) && $limit <= 0) {
                $limit = $plan;
            }
            $ownerpositionuuid = self::text($line, 'ownerpositionuuid', 36, false);
            $indicatoruuid = self::text($line, 'indicatoruuid', 36, false);
            self::validate_position($companyuuid, $ownerpositionuuid);
            self::validate_indicator($companyuuid, $indicatoruuid);
            $out[] = [
                'linecode' => $code,
                'name' => $name,
                'linetype' => $linetype,
                'periodstart' => $linestart,
                'periodend' => $lineend,
                'planamount' => $plan,
                'limitamount' => $limit,
                'ownerpositionuuid' => $ownerpositionuuid,
                'indicatoruuid' => $indicatoruuid,
                'sourceinfo' => self::text($line, 'sourceinfo', 4000, false),
            ];
        }
        if (!$out) {
            self::fail('nolines');
        }
        return $out;
    }

    private static function current_rows(string $companyuuid): array {
        global $DB;
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_finbudget', ['companyuuid' => $companyuuid],
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
        usort($out, static function($a, $b): int {
            return [$a->periodstart, $a->code, $a->uuid] <=> [$b->periodstart, $b->code, $b->uuid];
        });
        return $out;
    }

    private static function assert_unique_code(string $companyuuid, string $uuid, string $code): void {
        foreach (self::current_rows($companyuuid) as $row) {
            if ((string)$row->uuid !== $uuid && strcasecmp((string)$row->code, $code) === 0) {
                self::fail('duplicatecode');
            }
        }
    }

    private static function write_lines(string $companyuuid, int $userid, string $budgetuuid,
            int $budgetrevision, array $lines): void {
        global $DB;
        $now = time();
        foreach ($lines as $line) {
            $row = (object)$line;
            $row->companyuuid = $companyuuid;
            $row->budgetuuid = $budgetuuid;
            $row->budgetrevision = $budgetrevision;
            $row->createdby = $userid;
            $row->timecreated = $now;
            $DB->insert_record('local_kbacompany_finbudline', $row);
        }
    }

    private static function copy_lines(string $companyuuid, int $userid, string $budgetuuid,
            int $fromrevision, int $torevision): void {
        global $DB;
        $lines = $DB->get_records('local_kbacompany_finbudline', [
            'companyuuid' => $companyuuid, 'budgetuuid' => $budgetuuid, 'budgetrevision' => $fromrevision,
        ], 'id ASC');
        $now = time();
        foreach ($lines as $line) {
            unset($line->id);
            $line->budgetrevision = $torevision;
            $line->createdby = $userid;
            $line->timecreated = $now;
            $DB->insert_record('local_kbacompany_finbudline', $line);
        }
    }

    public static function save_budget(string $companyuuid, int $userid, array $data, array $lines,
            string $uuid = '', int $expectedrevision = 0): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $type = self::text($data, 'budgettype', 12, true);
        if (!in_array($type, self::BUDGET_TYPES, true)) {
            self::fail('invalid');
        }
        $periodstart = self::date_value($data, 'periodstart');
        $periodend = self::date_value($data, 'periodend');
        if ($periodend < $periodstart) {
            self::fail('periodinvalid');
        }
        $scenario = self::validate_scenario($companyuuid, self::text($data, 'scenariouuid', 36, true));
        if ($periodstart < $scenario->periodstart || $periodend > $scenario->periodend) {
            self::fail('scenariohorizon');
        }
        $currency = strtoupper(self::text($data, 'currency', 3, true));
        if (!preg_match('/^[A-Z]{3}$/', $currency) || $currency !== strtoupper((string)$scenario->currency)) {
            self::fail('currency');
        }
        $center = self::validate_center($companyuuid, self::text($data, 'centeruuid', 36, false));
        list($targetuuid, $targetrevision) = self::validate_target($companyuuid, $type,
            self::text($data, 'targetuuid', 36, true));
        $normlines = self::normalise_lines($companyuuid, $lines, $periodstart, $periodend);
        $code = strtoupper(self::text($data, 'code', 50, true));
        if (!preg_match('/^[A-Z0-9_.-]+$/', $code)) {
            self::fail('invalid');
        }

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('budgeting:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($uuid !== '') {
                $old = self::latest($companyuuid, $uuid);
                if ((int)$old->revision !== $expectedrevision) {
                    self::fail('conflict');
                }
                if (in_array($old->status, ['submitted', 'archived'], true)) {
                    self::fail('state');
                }
            } else if ($expectedrevision !== 0) {
                self::fail('conflict');
            }
            $stableuuid = $old ? (string)$old->uuid : self::uuid();
            self::assert_unique_code($companyuuid, $stableuuid, $code);
            $revision = $old ? ((int)$old->revision + 1) : 1;
            $now = time();
            $row = (object)[
                'companyuuid' => $companyuuid,
                'uuid' => $stableuuid,
                'revision' => $revision,
                'status' => 'draft',
                'code' => $code,
                'name' => self::text($data, 'name', 255, true),
                'budgettype' => $type,
                'scenariouuid' => (string)$scenario->uuid,
                'scenariorevision' => (int)$scenario->revision,
                'centeruuid' => $center ? (string)$center->uuid : '',
                'centerrevision' => $center ? (int)$center->revision : 0,
                'targetuuid' => $targetuuid,
                'targetrevision' => $targetrevision,
                'periodstart' => $periodstart,
                'periodend' => $periodend,
                'currency' => $currency,
                'approvalrule' => self::text($data, 'approvalrule', 8000, false),
                'notes' => self::text($data, 'notes', 8000, false),
                'submittedby' => 0,
                'approvedby' => 0,
                'decisionnote' => '',
                'createdby' => $userid,
                'modifiedby' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('local_kbacompany_finbudget', $row);
            self::write_lines($companyuuid, $userid, $stableuuid, $revision, $normlines);
            $tx->allow_commit();
            return $stableuuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function transition(string $companyuuid, int $userid, string $uuid, int $expectedrevision,
            string $from, string $to, string $note = ''): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('budgeting:' . $companyuuid, 10);
        if (!$lock) {
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) {
                self::fail('conflict');
            }
            if ((string)$old->status !== $from) {
                self::fail('state');
            }
            $lines = $DB->get_records('local_kbacompany_finbudline', [
                'companyuuid' => $companyuuid, 'budgetuuid' => $uuid, 'budgetrevision' => $expectedrevision,
            ]);
            if (!$lines) {
                self::fail('nolines');
            }
            if ($to === 'submitted' || $to === 'approved') {
                foreach ($lines as $line) {
                    if (in_array($line->linetype, ['expense', 'investment', 'financing', 'reserve'], true) &&
                            (float)$line->limitamount + 0.0001 < (float)$line->planamount) {
                        self::fail('limitexceeded');
                    }
                }
            }
            if ($to === 'rejected' && trim($note) === '') {
                self::fail('decisionrequired');
            }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expectedrevision + 1;
            $row->status = $to;
            if ($to === 'submitted') {
                $row->submittedby = $userid;
                $row->approvedby = 0;
                $row->decisionnote = '';
            } else if ($to === 'approved') {
                $row->approvedby = $userid;
                $row->decisionnote = trim($note);
            } else if ($to === 'rejected') {
                $row->approvedby = $userid;
                $row->decisionnote = trim($note);
            }
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_finbudget', $row);
            self::copy_lines($companyuuid, $userid, $uuid, $expectedrevision, (int)$row->revision);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function submit_budget(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::transition($companyuuid, $userid, $uuid, $revision, 'draft', 'submitted');
    }

    public static function approve_budget(string $companyuuid, int $userid, string $uuid, int $revision,
            string $note = ''): void {
        self::transition($companyuuid, $userid, $uuid, $revision, 'submitted', 'approved', $note);
    }

    public static function reject_budget(string $companyuuid, int $userid, string $uuid, int $revision,
            string $note): void {
        self::transition($companyuuid, $userid, $uuid, $revision, 'submitted', 'rejected', $note);
    }

    public static function archive_budget(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::authorise($companyuuid, $userid);
        $latest = self::latest($companyuuid, $uuid);
        if ((int)$latest->revision !== $revision || $latest->status === 'submitted' || $latest->status === 'archived') {
            self::fail('state');
        }
        self::transition($companyuuid, $userid, $uuid, $revision, (string)$latest->status, 'archived');
    }

    public static function record_fact(string $companyuuid, int $userid, string $budgetuuid, int $budgetrevision,
            string $linecode, array $data): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $budget = self::exact_budget($companyuuid, $budgetuuid, $budgetrevision);
        if ($budget->status !== 'approved') {
            self::fail('factstate');
        }
        $linecode = strtoupper(trim($linecode));
        $line = $DB->get_record('local_kbacompany_finbudline', [
            'companyuuid' => $companyuuid, 'budgetuuid' => $budgetuuid,
            'budgetrevision' => $budgetrevision, 'linecode' => $linecode,
        ]);
        if (!$line) {
            self::fail('reference');
        }
        $asofdate = self::date_value($data, 'asofdate');
        if ($asofdate < $line->periodstart) {
            self::fail('periodinvalid');
        }
        $sourcekind = self::text($data, 'sourcekind', 20, true);
        if (!in_array($sourcekind, self::FACT_SOURCE_KINDS, true)) {
            self::fail('invalid');
        }
        $amount = self::amount($data['actualamount'] ?? 0);
        $sourceinfo = self::text($data, 'sourceinfo', 4000, true);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('budgeting:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $DB->insert_record('local_kbacompany_finbudfact', (object)[
                'companyuuid' => $companyuuid,
                'budgetuuid' => $budgetuuid,
                'budgetrevision' => $budgetrevision,
                'linecode' => $linecode,
                'asofdate' => $asofdate,
                'actualamount' => $amount,
                'sourcekind' => $sourcekind,
                'sourceinfo' => $sourceinfo,
                'reportedby' => $userid,
                'timecreated' => time(),
            ]);
        } finally {
            $lock->release();
        }
    }

    private static function latest_facts(string $companyuuid, string $budgetuuid, int $revision): array {
        global $DB;
        $latest = [];
        foreach ($DB->get_records('local_kbacompany_finbudfact', [
                'companyuuid' => $companyuuid, 'budgetuuid' => $budgetuuid, 'budgetrevision' => $revision,
            ], 'linecode ASC, asofdate ASC, timecreated ASC') as $fact) {
            $latest[$fact->linecode] = $fact;
        }
        return $latest;
    }

    private static function dto(\stdClass $row): array {
        $out = (array)$row;
        unset($out['id']);
        foreach (['revision','scenariorevision','centerrevision','targetrevision','submittedby','approvedby',
                'createdby','modifiedby','timecreated','timemodified'] as $key) {
            if (isset($out[$key])) { $out[$key] = (int)$out[$key]; }
        }
        return $out;
    }

    private static function line_dto(\stdClass $row, ?\stdClass $fact): array {
        $plan = (float)$row->planamount;
        $actual = $fact ? (float)$fact->actualamount : null;
        $variance = $actual === null ? null : round($actual - $plan, 2);
        $variancepct = ($actual === null || abs($plan) < 0.000001) ? null : round(($variance / $plan) * 100, 2);
        return [
            'linecode' => (string)$row->linecode,
            'name' => (string)$row->name,
            'linetype' => (string)$row->linetype,
            'periodstart' => (string)$row->periodstart,
            'periodend' => (string)$row->periodend,
            'planamount' => $plan,
            'limitamount' => (float)$row->limitamount,
            'ownerpositionuuid' => (string)$row->ownerpositionuuid,
            'indicatoruuid' => (string)$row->indicatoruuid,
            'sourceinfo' => (string)$row->sourceinfo,
            'actualamount' => $actual,
            'asofdate' => $fact ? (string)$fact->asofdate : '',
            'factsourcekind' => $fact ? (string)$fact->sourcekind : '',
            'factsourceinfo' => $fact ? (string)$fact->sourceinfo : '',
            'variance' => $variance,
            'variancepct' => $variancepct,
        ];
    }

    public static function budget(string $companyuuid, int $userid, string $uuid, int $revision = 0): array {
        global $DB;
        self::authorise($companyuuid, $userid);
        $row = $revision > 0 ? self::exact_budget($companyuuid, $uuid, $revision) : self::latest($companyuuid, $uuid);
        $facts = self::latest_facts($companyuuid, $uuid, (int)$row->revision);
        $lines = [];
        $summary = ['plan' => 0.0, 'actual' => 0.0, 'limit' => 0.0, 'facts' => 0];
        foreach ($DB->get_records('local_kbacompany_finbudline', [
                'companyuuid' => $companyuuid, 'budgetuuid' => $uuid, 'budgetrevision' => $row->revision,
            ], 'periodstart ASC, linecode ASC') as $line) {
            $fact = $facts[$line->linecode] ?? null;
            $dto = self::line_dto($line, $fact);
            $lines[] = $dto;
            $summary['plan'] += $dto['planamount'];
            $summary['limit'] += $dto['limitamount'];
            if ($dto['actualamount'] !== null) {
                $summary['actual'] += $dto['actualamount'];
                $summary['facts']++;
            }
        }
        return ['budget' => self::dto($row), 'lines' => $lines, 'summary' => $summary];
    }

    private static function published_options(string $table, string $companyuuid, string $kind = ''): array {
        global $DB;
        $params = ['companyuuid' => $companyuuid];
        if ($kind !== '') { $params['kind'] = $kind; }
        $grouped = [];
        foreach ($DB->get_records($table, $params, 'uuid ASC, revision DESC') as $row) {
            $grouped[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($grouped as $versions) {
            $latest = reset($versions);
            if ((string)$latest->status === 'archived' || (string)$latest->status === 'withdrawn') {
                continue;
            }
            foreach ($versions as $row) {
                if ((string)$row->status === 'published') {
                    $out[] = $row;
                    break;
                }
            }
        }
        return $out;
    }

    private static function effective_approved_revision(string $companyuuid, string $uuid): int {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_finbudget',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'status' => 'approved'], 'revision DESC', 'id,revision', 0, 1);
        return $rows ? (int)reset($rows)->revision : 0;
    }

    public static function context(string $companyuuid, int $userid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        if (!self::can_view($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $budgets = [];
        foreach (self::current_rows($companyuuid) as $row) {
            $dto = self::dto($row);
            $dto['effectiveapprovedrevision'] = self::effective_approved_revision($companyuuid, (string)$row->uuid);
            $detailrevision = $dto['effectiveapprovedrevision'] > 0 ? $dto['effectiveapprovedrevision'] : (int)$row->revision;
            $detail = self::budget($companyuuid, $userid, (string)$row->uuid, $detailrevision);
            $dto['summary'] = $detail['summary'];
            $budgets[] = $dto;
        }
        $scenarios = array_map([self::class, 'dto'], self::published_options('local_kbacompany_finscenario', $companyuuid));
        $centers = array_map([self::class, 'dto'], self::published_options('local_kbacompany_fincenter', $companyuuid));
        $targets = ['goal' => [], 'program' => [], 'project' => [], 'orgunit' => []];
        foreach (['goal', 'program', 'project'] as $kind) {
            foreach (self::published_options('local_kbacompany_adminitem', $companyuuid, $kind) as $row) {
                $targets[$kind][] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                    'code' => (string)$row->code, 'name' => (string)$row->name];
            }
        }
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'active' => 1],
                'code ASC') as $row) {
            $targets['orgunit'][] = ['uuid' => (string)$row->uuid, 'revision' => 0,
                'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1],
                'code ASC') as $row) {
            $positions[] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        $indicators = [];
        foreach ($DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid], 'code ASC') as $row) {
            $indicators[] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        return [
            'company' => $company,
            'budgets' => $budgets,
            'scenarios' => $scenarios,
            'centers' => $centers,
            'targets' => $targets,
            'positions' => $positions,
            'indicators' => $indicators,
            'budget_types' => self::BUDGET_TYPES,
            'line_types' => self::LINE_TYPES,
            'fact_source_kinds' => self::FACT_SOURCE_KINDS,
            'can_manage' => true,
            'contract' => self::contract(),
        ];
    }
}
