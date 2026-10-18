<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * v0.6.3 Resource Gate: launch-readiness assessment for goals, programs and projects.
 *
 * This service does not create parallel budget, staffing, competency, document or asset registries.
 * It pins existing canonical/reconciled objects and stores only the assessment, evidence and decision.
 */
class resource_gate_service {
    public const CONTRACT_VERSION = '0.6.3';
    public const STATES = ['draft', 'submitted', 'ready', 'blocked', 'archived'];
    public const TARGET_TYPES = ['goal', 'program', 'project'];
    public const RESOURCE_KINDS = ['money', 'people', 'competency', 'time', 'technology', 'information', 'infrastructure'];
    public const ITEM_STATES = ['pending', 'pass', 'block', 'waived'];
    public const REFERENCE_KINDS = ['none', 'budget', 'position', 'business_role', 'document', 'custom'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_resource_gate',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['ResourceGate', 'ResourceGateItem'],
            'resource_kinds' => self::RESOURCE_KINDS,
            'rules' => [
                'gate_pins_published_administrative_scale_target_revision',
                'optional_scenario_link_pins_published_financial_scenario_revision',
                'money_can_reference_existing_approved_budget_without_copying_it',
                'people_reference_canonical_positions_and_staffing_without_copying_memberships',
                'competency_references_existing_read_only_business_role_projection',
                'technology_information_and_infrastructure_use_evidence_not_parallel_asset_registry',
                'all_seven_resource_dimensions_are_explicit_before_submission',
                'ready_requires_every_required_item_to_pass_or_be_explicitly_waived',
                'waiver_requires_evidence',
                'submitted_and_decided_revisions_are_immutable',
                'new_revision_does_not_replace_previous_ready_revision_until_new_ready_decision',
                'archive_preserves_history',
                'resource_gate_does_not_execute_payments_staffing_training_procurement_or_erp_actions',
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
        throw new \moodle_exception('resourcegate_' . $key, 'local_kbacompany');
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

    private static function date_value(array $data, string $key, bool $required = false): string {
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

    private static function latest(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_resgate',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) {
            self::fail('reference');
        }
        return reset($rows);
    }

    private static function exact_gate(string $companyuuid, string $uuid, int $revision): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_resgate',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision]);
        if (!$row) {
            self::fail('reference');
        }
        return $row;
    }

    private static function latest_published(string $table, string $companyuuid, string $uuid,
            string $kind = ''): \stdClass {
        global $DB;
        $params = ['companyuuid' => $companyuuid, 'uuid' => $uuid];
        if ($kind !== '') {
            $params['kind'] = $kind;
        }
        $rows = $DB->get_records($table, $params, 'revision DESC');
        if (!$rows) {
            self::fail('reference');
        }
        $latest = reset($rows);
        if (in_array((string)$latest->status, ['archived', 'withdrawn', 'cancelled'], true)) {
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

    private static function validate_target(string $companyuuid, string $type, string $uuid): \stdClass {
        if (!in_array($type, self::TARGET_TYPES, true) || $uuid === '') {
            self::fail('reference');
        }
        return self::latest_published('local_kbacompany_adminitem', $companyuuid, $uuid, $type);
    }

    private static function validate_scenario(string $companyuuid, string $uuid): ?\stdClass {
        if ($uuid === '') {
            return null;
        }
        return self::latest_published('local_kbacompany_finscenario', $companyuuid, $uuid);
    }

    private static function validate_position(string $companyuuid, string $uuid): void {
        global $DB;
        if ($uuid !== '' && !$DB->record_exists('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'active' => 1])) {
            self::fail('reference');
        }
    }

    private static function effective_approved_budget(string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $latestrows = $DB->get_records('local_kbacompany_finbudget',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', 'id,status', 0, 1);
        if (!$latestrows || (string)reset($latestrows)->status === 'archived') {
            return null;
        }
        $rows = $DB->get_records('local_kbacompany_finbudget',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'status' => 'approved'], 'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function validate_reference(string $companyuuid, int $userid, string $kind, string $uuid, int $revision,
            string $targettype, string $targetuuid, ?\stdClass $scenario): array {
        global $DB;
        if (!in_array($kind, self::REFERENCE_KINDS, true)) {
            self::fail('reference');
        }
        if ($kind === 'none' || $kind === 'custom') {
            return ['', 0];
        }
        if ($uuid === '') {
            self::fail('reference');
        }
        if ($kind === 'budget') {
            $effective = self::effective_approved_budget($companyuuid, $uuid);
            $budget = $revision > 0 ? $DB->get_record('local_kbacompany_finbudget', [
                'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision, 'status' => 'approved',
            ]) : $effective;
            if (!$effective || !$budget || (int)$budget->revision !== (int)$effective->revision ||
                    (string)$budget->budgettype !== $targettype || (string)$budget->targetuuid !== $targetuuid) {
                self::fail('budgetreference');
            }
            if ($scenario && ((string)$budget->scenariouuid !== (string)$scenario->uuid ||
                    (int)$budget->scenariorevision !== (int)$scenario->revision)) {
                self::fail('budgetscenario');
            }
            return [(string)$budget->uuid, (int)$budget->revision];
        }
        if ($kind === 'position') {
            self::validate_position($companyuuid, $uuid);
            return [$uuid, 0];
        }
        if ($kind === 'business_role') {
            $ctx = business_role_service::business_roles_context($companyuuid, $userid);
            foreach ($ctx['roles'] as $role) {
                if ((string)$role['uuid'] === $uuid) {
                    return [$uuid, 0];
                }
            }
            self::fail('reference');
        }
        if ($kind === 'document') {
            $rows = $DB->get_records('local_kbacompany_doc', ['companyuuid' => $companyuuid, 'uuid' => $uuid],
                'revision DESC');
            if (!$rows) {
                self::fail('reference');
            }
            $selected = null;
            if ($revision > 0) {
                foreach ($rows as $row) {
                    if ((int)$row->revision === $revision && in_array((string)$row->status, ['published', 'effective'], true)) {
                        $selected = $row; break;
                    }
                }
            } else {
                foreach ($rows as $row) {
                    if ((string)$row->status === 'effective') { $selected = $row; break; }
                }
                if (!$selected) {
                    foreach ($rows as $row) {
                        if ((string)$row->status === 'published') { $selected = $row; break; }
                    }
                }
            }
            if (!$selected) {
                self::fail('reference');
            }
            return [(string)$selected->uuid, (int)$selected->revision];
        }
        self::fail('reference');
        return ['', 0];
    }

    private static function normalise_items(string $companyuuid, int $userid, array $items,
            string $targettype, string $targetuuid, ?\stdClass $scenario): array {
        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                self::fail('invalid');
            }
            $code = strtoupper(self::text($item, 'itemcode', 50, true));
            if (!preg_match('/^[A-Z0-9_.-]+$/', $code) || isset($seen[$code])) {
                self::fail('duplicateitem');
            }
            $seen[$code] = true;
            $resourcekind = self::text($item, 'resourcekind', 20, true);
            $status = self::text($item, 'status', 12, true);
            if (!in_array($resourcekind, self::RESOURCE_KINDS, true) || !in_array($status, self::ITEM_STATES, true)) {
                self::fail('invalid');
            }
            $required = !empty($item['required']) ? 1 : 0;
            $evidence = self::text($item, 'evidence', 8000, false);
            if ($status === 'waived' && $evidence === '') {
                self::fail('waiverevidence');
            }
            $referencekind = self::text($item, 'referencekind', 20, false);
            if ($referencekind === '') { $referencekind = 'none'; }
            $referenceuuid = self::text($item, 'referenceuuid', 36, false);
            $referencerevision = max(0, (int)($item['referencerevision'] ?? 0));
            list($referenceuuid, $referencerevision) = self::validate_reference($companyuuid, $userid, $referencekind,
                $referenceuuid, $referencerevision, $targettype, $targetuuid, $scenario);
            $ownerpositionuuid = self::text($item, 'ownerpositionuuid', 36, false);
            self::validate_position($companyuuid, $ownerpositionuuid);
            $out[] = [
                'itemcode' => $code,
                'resourcekind' => $resourcekind,
                'name' => self::text($item, 'name', 255, true),
                'required' => $required,
                'status' => $status,
                'referencekind' => $referencekind,
                'referenceuuid' => $referenceuuid,
                'referencerevision' => $referencerevision,
                'expected' => self::text($item, 'expected', 8000, false),
                'available' => self::text($item, 'available', 8000, false),
                'gap' => self::text($item, 'gap', 8000, false),
                'evidence' => $evidence,
                'evidenceurl' => self::text($item, 'evidenceurl', 1000, false),
                'ownerpositionuuid' => $ownerpositionuuid,
                'duedate' => self::date_value($item, 'duedate', false),
            ];
        }
        if (!$out) {
            self::fail('noitems');
        }
        return $out;
    }

    private static function write_items(string $companyuuid, int $userid, string $gateuuid,
            int $revision, array $items): void {
        global $DB;
        $now = time();
        foreach ($items as $item) {
            $DB->insert_record('local_kbacompany_resitem', (object)array_merge($item, [
                'companyuuid' => $companyuuid,
                'gateuuid' => $gateuuid,
                'gaterevision' => $revision,
                'createdby' => $userid,
                'timecreated' => $now,
            ]));
        }
    }

    private static function copy_items(string $companyuuid, int $userid, string $gateuuid,
            int $fromrevision, int $torevision): void {
        global $DB;
        $now = time();
        foreach ($DB->get_records('local_kbacompany_resitem', [
                'companyuuid' => $companyuuid, 'gateuuid' => $gateuuid, 'gaterevision' => $fromrevision,
            ], 'id ASC') as $item) {
            unset($item->id);
            $item->gaterevision = $torevision;
            $item->createdby = $userid;
            $item->timecreated = $now;
            $DB->insert_record('local_kbacompany_resitem', $item);
        }
    }

    private static function current_rows(string $companyuuid): array {
        global $DB;
        $grouped = [];
        foreach ($DB->get_records('local_kbacompany_resgate', ['companyuuid' => $companyuuid],
                'uuid ASC, revision ASC') as $row) {
            $grouped[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($grouped as $versions) {
            $latest = end($versions);
            if ((string)$latest->status !== 'archived') {
                $out[] = $latest;
            }
        }
        usort($out, static function($a, $b): int {
            return [$a->plannedstart, $a->code, $a->uuid] <=> [$b->plannedstart, $b->code, $b->uuid];
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

    private static function coverage(array $items): array {
        $seen = array_fill_keys(self::RESOURCE_KINDS, 0);
        $requiredpending = 0;
        $requiredblocked = 0;
        $requiredready = 0;
        foreach ($items as $item) {
            $kind = is_array($item) ? (string)$item['resourcekind'] : (string)$item->resourcekind;
            $required = is_array($item) ? (int)$item['required'] : (int)$item->required;
            $status = is_array($item) ? (string)$item['status'] : (string)$item->status;
            if (isset($seen[$kind])) { $seen[$kind]++; }
            if ($required) {
                if ($status === 'block') { $requiredblocked++; }
                else if ($status === 'pending') { $requiredpending++; }
                else if ($status === 'pass' || $status === 'waived') { $requiredready++; }
            }
        }
        $missing = [];
        foreach ($seen as $kind => $count) {
            if ($count === 0) { $missing[] = $kind; }
        }
        return ['missing' => $missing, 'required_pending' => $requiredpending,
            'required_blocked' => $requiredblocked, 'required_ready' => $requiredready];
    }

    public static function save_gate(string $companyuuid, int $userid, array $data, array $items,
            string $uuid = '', int $expectedrevision = 0): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $targettype = self::text($data, 'targettype', 12, true);
        $target = self::validate_target($companyuuid, $targettype, self::text($data, 'targetuuid', 36, true));
        $scenario = self::validate_scenario($companyuuid, self::text($data, 'scenariouuid', 36, false));
        $ownerpositionuuid = self::text($data, 'ownerpositionuuid', 36, false);
        self::validate_position($companyuuid, $ownerpositionuuid);
        $normitems = self::normalise_items($companyuuid, $userid, $items, $targettype,
            (string)$target->uuid, $scenario);
        $code = strtoupper(self::text($data, 'code', 50, true));
        if (!preg_match('/^[A-Z0-9_.-]+$/', $code)) {
            self::fail('invalid');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->
            get_lock('resourcegate:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($uuid !== '') {
                $old = self::latest($companyuuid, $uuid);
                if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
                if (in_array((string)$old->status, ['submitted', 'archived'], true)) { self::fail('state'); }
            } else if ($expectedrevision !== 0) {
                self::fail('conflict');
            }
            $stableuuid = $old ? (string)$old->uuid : self::uuid();
            self::assert_unique_code($companyuuid, $stableuuid, $code);
            $revision = $old ? ((int)$old->revision + 1) : 1;
            $now = time();
            $DB->insert_record('local_kbacompany_resgate', (object)[
                'companyuuid' => $companyuuid,
                'uuid' => $stableuuid,
                'revision' => $revision,
                'status' => 'draft',
                'code' => $code,
                'name' => self::text($data, 'name', 255, true),
                'targettype' => $targettype,
                'targetuuid' => (string)$target->uuid,
                'targetrevision' => (int)$target->revision,
                'scenariouuid' => $scenario ? (string)$scenario->uuid : '',
                'scenariorevision' => $scenario ? (int)$scenario->revision : 0,
                'plannedstart' => self::date_value($data, 'plannedstart', false),
                'ownerpositionuuid' => $ownerpositionuuid,
                'submittedby' => 0,
                'decidedby' => 0,
                'decisionnote' => '',
                'createdby' => $userid,
                'modifiedby' => $userid,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
            self::write_items($companyuuid, $userid, $stableuuid, $revision, $normitems);
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
            get_lock('resourcegate:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ((string)$old->status !== $from) { self::fail('state'); }
            $items = $DB->get_records('local_kbacompany_resitem', [
                'companyuuid' => $companyuuid, 'gateuuid' => $uuid, 'gaterevision' => $expectedrevision,
            ], 'itemcode ASC');
            if (!$items) { self::fail('noitems'); }
            $coverage = self::coverage(array_values($items));
            if ($to === 'submitted' && $coverage['missing']) { self::fail('coverage'); }
            if ($to === 'ready') {
                if ($coverage['missing'] || $coverage['required_pending'] > 0 || $coverage['required_blocked'] > 0) {
                    self::fail('notready');
                }
            }
            if ($to === 'blocked' && trim($note) === '') { self::fail('decisionrequired'); }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expectedrevision + 1;
            $row->status = $to;
            if ($to === 'submitted') {
                $row->submittedby = $userid;
                $row->decidedby = 0;
                $row->decisionnote = '';
            } else if ($to === 'ready' || $to === 'blocked') {
                $row->decidedby = $userid;
                $row->decisionnote = trim($note);
            }
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_resgate', $row);
            self::copy_items($companyuuid, $userid, $uuid, $expectedrevision, (int)$row->revision);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function submit_gate(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::transition($companyuuid, $userid, $uuid, $revision, 'draft', 'submitted');
    }

    public static function mark_ready(string $companyuuid, int $userid, string $uuid, int $revision,
            string $note = ''): void {
        self::transition($companyuuid, $userid, $uuid, $revision, 'submitted', 'ready', $note);
    }

    public static function mark_blocked(string $companyuuid, int $userid, string $uuid, int $revision,
            string $note): void {
        self::transition($companyuuid, $userid, $uuid, $revision, 'submitted', 'blocked', $note);
    }

    public static function archive_gate(string $companyuuid, int $userid, string $uuid, int $revision): void {
        self::authorise($companyuuid, $userid);
        $latest = self::latest($companyuuid, $uuid);
        if ((int)$latest->revision !== $revision || $latest->status === 'submitted' || $latest->status === 'archived') {
            self::fail('state');
        }
        self::transition($companyuuid, $userid, $uuid, $revision, (string)$latest->status, 'archived');
    }

    private static function dto(\stdClass $row): array {
        $out = (array)$row;
        unset($out['id']);
        foreach (['revision', 'targetrevision', 'scenariorevision', 'submittedby', 'decidedby',
                'createdby', 'modifiedby', 'timecreated', 'timemodified'] as $key) {
            if (isset($out[$key])) { $out[$key] = (int)$out[$key]; }
        }
        return $out;
    }

    private static function item_dto(\stdClass $row): array {
        $out = (array)$row;
        unset($out['id']);
        foreach (['gaterevision', 'required', 'referencerevision', 'createdby', 'timecreated'] as $key) {
            if (isset($out[$key])) { $out[$key] = (int)$out[$key]; }
        }
        return $out;
    }

    private static function effective_ready_revision(string $companyuuid, string $uuid): int {
        global $DB;
        $latest = self::latest($companyuuid, $uuid);
        if ((string)$latest->status === 'ready') {
            return (int)$latest->revision;
        }
        if (in_array((string)$latest->status, ['blocked', 'archived'], true)) {
            return 0;
        }
        $rows = $DB->get_records('local_kbacompany_resgate',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'status' => 'ready'], 'revision DESC', 'id,revision', 0, 1);
        return $rows ? (int)reset($rows)->revision : 0;
    }

    public static function gate(string $companyuuid, int $userid, string $uuid, int $revision = 0): array {
        global $DB;
        self::authorise($companyuuid, $userid);
        $row = $revision > 0 ? self::exact_gate($companyuuid, $uuid, $revision) : self::latest($companyuuid, $uuid);
        $items = [];
        foreach ($DB->get_records('local_kbacompany_resitem', [
                'companyuuid' => $companyuuid, 'gateuuid' => $uuid, 'gaterevision' => $row->revision,
            ], 'resourcekind ASC, itemcode ASC') as $item) {
            $items[] = self::item_dto($item);
        }
        return ['gate' => self::dto($row), 'items' => $items, 'coverage' => self::coverage($items)];
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
            if (in_array((string)$latest->status, ['archived', 'withdrawn', 'cancelled'], true)) { continue; }
            foreach ($versions as $row) {
                if ((string)$row->status === 'published') { $out[] = $row; break; }
            }
        }
        return $out;
    }

    public static function context(string $companyuuid, int $userid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        if (!self::can_view($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $gates = [];
        foreach (self::current_rows($companyuuid) as $row) {
            $dto = self::dto($row);
            $dto['effectivereadyrevision'] = self::effective_ready_revision($companyuuid, (string)$row->uuid);
            $detailrevision = $dto['effectivereadyrevision'] > 0 ? $dto['effectivereadyrevision'] : (int)$row->revision;
            $detail = self::gate($companyuuid, $userid, (string)$row->uuid, $detailrevision);
            $dto['coverage'] = $detail['coverage'];
            $gates[] = $dto;
        }
        $targets = ['goal' => [], 'program' => [], 'project' => []];
        foreach (self::TARGET_TYPES as $kind) {
            foreach (self::published_options('local_kbacompany_adminitem', $companyuuid, $kind) as $row) {
                $targets[$kind][] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                    'code' => (string)$row->code, 'name' => (string)$row->name];
            }
        }
        $scenarios = [];
        foreach (self::published_options('local_kbacompany_finscenario', $companyuuid) as $row) {
            $scenarios[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        $budgets = [];
        $budgetuuids = [];
        foreach ($DB->get_records('local_kbacompany_finbudget', ['companyuuid' => $companyuuid],
                'uuid ASC, revision DESC', 'id,uuid') as $row) {
            $budgetuuids[(string)$row->uuid] = true;
        }
        foreach (array_keys($budgetuuids) as $budgetuuid) {
            $row = self::effective_approved_budget($companyuuid, $budgetuuid);
            if (!$row) { continue; }
            $budgets[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'code' => (string)$row->code, 'name' => (string)$row->name, 'budgettype' => (string)$row->budgettype,
                'targetuuid' => (string)$row->targetuuid];
        }
        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1], 'code ASC') as $row) {
            $positions[] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        $rolesctx = business_role_service::business_roles_context($companyuuid, $userid);
        $roles = [];
        foreach ($rolesctx['roles'] as $role) {
            $roles[] = ['uuid' => (string)$role['uuid'], 'code' => (string)$role['code'], 'name' => (string)$role['name']];
        }
        $documents = [];
        $docgroup = [];
        foreach ($DB->get_records('local_kbacompany_doc', ['companyuuid' => $companyuuid], 'uuid ASC, revision DESC') as $row) {
            $docgroup[$row->uuid][] = $row;
        }
        foreach ($docgroup as $versions) {
            foreach ($versions as $row) {
                if (in_array((string)$row->status, ['effective', 'published'], true)) {
                    $documents[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                        'code' => (string)$row->code, 'name' => (string)$row->title];
                    break;
                }
            }
        }
        return [
            'company' => $company,
            'gates' => $gates,
            'targets' => $targets,
            'scenarios' => $scenarios,
            'budgets' => $budgets,
            'positions' => $positions,
            'business_roles' => $roles,
            'documents' => $documents,
            'target_types' => self::TARGET_TYPES,
            'resource_kinds' => self::RESOURCE_KINDS,
            'item_states' => self::ITEM_STATES,
            'reference_kinds' => self::REFERENCE_KINDS,
            'can_manage' => true,
            'contract' => self::contract(),
        ];
    }
}
