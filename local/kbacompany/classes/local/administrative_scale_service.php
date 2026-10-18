<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Versioned administrative scale from strategic goal to executable task. */
class administrative_scale_service {
    public const CONTRACT_VERSION = '0.5.1';
    public const STATES = ['draft', 'published', 'archived'];

    /** Canonical methodology levels of the Administrative Scale. */
    public const CORE_KINDS = ['longtermgoal', 'goal', 'policy', 'plan', 'program', 'decision', 'idealstate', 'statistic', 'ckp'];

    /** Execution-support objects used to turn programs into Battle Plan work. */
    public const EXECUTION_KINDS = ['programstep', 'project', 'task'];

    /** Stable UI order: methodology first, then execution support near the program it implements. */
    public const KINDS = ['longtermgoal', 'goal', 'policy', 'plan', 'program', 'programstep', 'project', 'decision', 'task',
        'idealstate', 'statistic', 'ckp'];

    /** Stable human-readable code prefixes. UUID remains the canonical identity. */
    public const CODE_PREFIXES = [
        'longtermgoal' => 'LTG',
        'goal' => 'GOAL',
        'policy' => 'POL',
        'plan' => 'PLN',
        'program' => 'PRG',
        'programstep' => 'STEP',
        'project' => 'PRJ',
        'decision' => 'ORD',
        'task' => 'TSK',
        'idealstate' => 'IDL',
        'statistic' => 'STAT',
        'ckp' => 'CKP',
    ];

    /** Parent rules express traceability, not a claim that every methodology level is a strict tree. */
    public const PARENT_KINDS = [
        'longtermgoal' => [],
        'goal' => ['longtermgoal'],
        'policy' => ['goal'],
        'plan' => ['policy'],
        'program' => ['plan'],
        'programstep' => ['program'],
        'project' => ['programstep'],
        'decision' => ['programstep', 'project'],
        'task' => ['programstep', 'project', 'decision'],
        'idealstate' => ['goal', 'plan', 'program'],
        'statistic' => ['goal', 'plan', 'program', 'idealstate'],
        'ckp' => ['goal', 'plan', 'program', 'idealstate'],
    ];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_administrative_scale',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => [
                'LongTermGoal', 'AdministrativeGoal', 'AdministrativePolicy', 'Plan', 'Program',
                'ProgramStep', 'Project', 'DecisionOrOrder', 'AdministrativeTask', 'IdealState',
                'StatisticReference', 'ValuableFinalProductReference',
            ],
            'methodology_levels' => self::CORE_KINDS,
            'execution_support' => self::EXECUTION_KINDS,
            'kinds' => self::KINDS,
            'states' => self::STATES,
            'hierarchy' => self::PARENT_KINDS,
            'rules' => [
                'long_term_goal_pins_published_owner_requirement_set',
                'administrative_goal_pins_published_bsc_goal',
                'administrative_policy_pins_published_policy',
                'plan_records_situation_goal_and_plan_body',
                'program_is_broken_into_program_steps',
                'complex_program_step_may_be_expanded_as_project',
                'decision_or_order_may_execute_program_step_or_project',
                'published_administrative_task_can_be_handed_to_battle_plan',
                'battle_plan_uses_canonical_task_and_keeps_adminscale_provenance',
                'ideal_state_is_realistic_future_state_not_a_kpi',
                'statistic_reuses_canonical_bms_indicator',
                'valuable_final_product_is_not_a_statistic',
                'stable_code_is_server_generated_and_immutable',
                'stable_code_never_reused_after_archive',
                'child_pins_exact_published_parent_revision',
                'published_revisions_are_immutable',
                'full_upward_traceability',
                'scale_is_reconciled_up_and_down_not_only_top_down',
                'legacy_administrative_cascade_is_not_destructively_migrated',
                'no_financial_actual_ledger_mutation',
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

    private static function authorise(string $companyuuid, int $userid): void {
        company_context_service::context($companyuuid, $userid);
        if (!self::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('adminscale_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function date(array $data, string $key): string {
        $value = self::text($data, $key, 10, false);
        if ($value === '') { return ''; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function latest(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_adminitem',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) { self::fail('reference'); }
        return reset($rows);
    }

    private static function exact_revision(string $companyuuid, string $uuid, int $revision): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_adminitem',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }

    /** Effective published revision while a newer draft may exist. */
    private static function effective_published_admin(string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_adminitem',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC');
        if (!$rows) { return null; }
        $latest = reset($rows);
        if ($latest->status === 'archived') { return null; }
        foreach ($rows as $row) {
            if ($row->status === 'published') { return $row; }
        }
        return null;
    }

    private static function effective_published_generic(string $table, string $companyuuid, string $uuid,
            string $archivestatus = 'archived'): ?\stdClass {
        global $DB;
        $rows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC');
        if (!$rows) { return null; }
        $latest = reset($rows);
        if ($latest->status === $archivestatus || $latest->status === 'withdrawn') { return null; }
        foreach ($rows as $row) {
            if ($row->status === 'published') { return $row; }
        }
        return null;
    }

    private static function current_rows(string $companyuuid, bool $canmanage): array {
        global $DB;
        $groups = [];
        foreach ($DB->get_records('local_kbacompany_adminitem', ['companyuuid' => $companyuuid],
                'uuid ASC, revision ASC') as $row) {
            $groups[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($groups as $versions) {
            $latest = end($versions);
            if ($latest->status === 'archived') { continue; }
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
        usort($out, static function($a, $b): int {
            $order = array_flip(self::KINDS);
            return [$order[$a->kind] ?? 99, $a->sortorder, $a->code, $a->uuid] <=>
                [$order[$b->kind] ?? 99, $b->sortorder, $b->code, $b->uuid];
        });
        return $out;
    }

    private static function unique_code(string $companyuuid, string $code, string $uuid = ''): void {
        global $DB;
        foreach ($DB->get_records('local_kbacompany_adminitem',
                ['companyuuid' => $companyuuid, 'code' => $code]) as $row) {
            if ($uuid === '' || (string)$row->uuid !== $uuid) { self::fail('duplicatecode'); }
        }
    }

    private static function active_position(string $companyuuid, string $uuid): void {
        global $DB;
        if ($uuid !== '' && !$DB->record_exists('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'active' => 1])) {
            self::fail('reference');
        }
    }

    private static function simple_reference(string $table, string $companyuuid, string $uuid): void {
        global $DB;
        if ($uuid !== '' && !$DB->record_exists($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid])) {
            self::fail('reference');
        }
    }

    private static function source_reference(string $companyuuid, int $userid, string $kind, string $sourceuuid): array {
        if ($kind === 'longtermgoal') {
            if ($sourceuuid === '') { self::fail('sourceref'); }
            $set = owner_service::requirement_set($companyuuid, $userid, $sourceuuid, 0);
            if (!$set) { self::fail('sourceref'); }
            return ['owner_reqset', (string)$set['uuid'], (int)$set['revision']];
        }
        if ($kind === 'goal') {
            if ($sourceuuid === '') { self::fail('sourceref'); }
            $row = self::effective_published_generic('local_kbacompany_stratgoal', $companyuuid, $sourceuuid);
            if (!$row) { self::fail('sourceref'); }
            return ['strategic_goal', $row->uuid, (int)$row->revision];
        }
        if ($kind === 'policy') {
            if ($sourceuuid === '') { self::fail('sourceref'); }
            $row = self::effective_published_generic('local_kbacompany_policy', $companyuuid, $sourceuuid, 'withdrawn');
            if (!$row || $row->kind !== 'policy') { self::fail('sourceref'); }
            return ['policy', $row->uuid, (int)$row->revision];
        }
        if ($kind === 'decision' && $sourceuuid !== '') {
            $row = self::effective_published_generic('local_kbacompany_policy', $companyuuid, $sourceuuid, 'withdrawn');
            if (!$row || $row->kind !== 'directive') { self::fail('sourceref'); }
            return ['directive', $row->uuid, (int)$row->revision];
        }
        if ($sourceuuid !== '') { self::fail('sourceref'); }
        return ['', '', 0];
    }

    private static function parent_reference(string $companyuuid, string $kind, string $parentuuid): array {
        $allowed = self::PARENT_KINDS[$kind] ?? null;
        if ($allowed === null) { self::fail('invalid'); }
        if (!$allowed) {
            if ($parentuuid !== '') { self::fail('parentref'); }
            return ['', 0];
        }
        if ($parentuuid === '') { self::fail('parentref'); }
        $parent = self::effective_published_admin($companyuuid, $parentuuid);
        if (!$parent || !in_array($parent->kind, $allowed, true)) { self::fail('parentref'); }
        return [$parent->uuid, (int)$parent->revision];
    }

    private static function normalized(string $companyuuid, int $userid, array $data): array {
        $kind = self::text($data, 'kind', 20, true);
        if (!in_array($kind, self::KINDS, true)) { self::fail('invalid'); }
        $name = self::text($data, 'name', 255, true);
        $descriptionrequired = in_array($kind, ['plan', 'idealstate'], true);
        $description = self::text($data, 'description', 12000, $descriptionrequired);
        $situation = self::text($data, 'situation', 12000, $kind === 'plan');
        $plangoal = self::text($data, 'plangoal', 4000, $kind === 'plan');
        $expectedrequired = in_array($kind, ['longtermgoal', 'goal', 'program', 'programstep', 'project', 'decision', 'task', 'ckp'], true);
        $expectedresult = self::text($data, 'expectedresult', 4000, $expectedrequired);
        if ($kind === 'plan') {
            // Compatibility: the plan goal is also the expected management result of the Plan item.
            $expectedresult = $plangoal;
        }
        [$parentuuid, $parentrevision] = self::parent_reference($companyuuid, $kind,
            self::text($data, 'parentuuid', 36, false));
        [$sourcekind, $sourceuuid, $sourcerevision] = self::source_reference($companyuuid, $userid, $kind,
            self::text($data, 'sourceuuid', 36, false));
        $ownerpositionuuid = self::text($data, 'ownerpositionuuid', 36, false);
        self::active_position($companyuuid, $ownerpositionuuid);
        $productuuid = self::text($data, 'productuuid', 36, false);
        $metricuuid = self::text($data, 'metricuuid', 36, false);
        self::simple_reference('local_kbacompany_product', $companyuuid, $productuuid);
        self::simple_reference('local_kbacompany_stat', $companyuuid, $metricuuid);
        $targetvalue = self::text($data, 'targetvalue', 64, false);
        if (($targetvalue !== '' || $kind === 'statistic') && $metricuuid === '') { self::fail('metricref'); }
        $startdate = self::date($data, 'startdate');
        $duedate = self::date($data, 'duedate');
        if ($startdate !== '' && $duedate !== '' && $startdate > $duedate) { self::fail('dates'); }
        $sortorder = (int)($data['sortorder'] ?? 0);
        if ($sortorder < 0 || $sortorder > 1000000) { self::fail('invalid'); }
        return compact('kind', 'name', 'description', 'situation', 'plangoal', 'expectedresult',
            'parentuuid', 'parentrevision', 'sourcekind', 'sourceuuid', 'sourcerevision', 'ownerpositionuuid',
            'productuuid', 'metricuuid', 'targetvalue', 'startdate', 'duedate', 'sortorder');
    }

    private static function next_code(string $companyuuid, string $kind): string {
        global $DB;
        if (!isset(self::CODE_PREFIXES[$kind])) { self::fail('invalid'); }
        $prefix = self::CODE_PREFIXES[$kind];
        $like = $DB->sql_like('code', ':prefix', false, false);
        $codes = $DB->get_fieldset_select('local_kbacompany_adminitem', 'code',
            'companyuuid = :companyuuid AND ' . $like, [
                'companyuuid' => $companyuuid, 'prefix' => $prefix . '-%',
            ]);
        $maximum = 0;
        foreach ($codes as $existingcode) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-([0-9]+)$/', (string)$existingcode, $matches)) {
                $maximum = max($maximum, (int)$matches[1]);
            }
        }
        return stable_code_service::next($companyuuid, 'adminscale:' . $kind, $prefix,
            static function(string $candidate) use ($DB, $companyuuid): bool {
                return !$DB->record_exists('local_kbacompany_adminitem', [
                    'companyuuid' => $companyuuid, 'code' => $candidate,
                ]);
            }, $maximum);
    }

    public static function save(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $expectedrevision = 0): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $values = self::normalized($companyuuid, $userid, $data);
        if ($uuid === '' && $expectedrevision !== 0) { self::fail('conflict'); }
        $generatedcode = $uuid === '' ? self::next_code($companyuuid, $values['kind']) : '';
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('adminscale:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($uuid !== '') {
                $old = self::latest($companyuuid, $uuid);
                if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
                if ($old->status === 'archived') { self::fail('state'); }
                if ($old->kind !== $values['kind']) { self::fail('kindimmutable'); }
            }
            $stable = $old ? (string)$old->uuid : self::uuid();
            // Stable code is assigned once by the server and never changes on later revisions.
            $values['code'] = $old ? (string)$old->code : $generatedcode;
            self::unique_code($companyuuid, $values['code'], $stable);
            $row = (object)array_merge($values, [
                'companyuuid' => $companyuuid,
                'uuid' => $stable,
                'revision' => $old ? (int)$old->revision + 1 : 1,
                'status' => 'draft',
                'createdby' => $userid,
                'modifiedby' => $userid,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            $DB->insert_record('local_kbacompany_adminitem', $row);
            $tx->allow_commit();
            return $stable;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function assert_dependencies_current(string $companyuuid, \stdClass $row): void {
        if ($row->parentuuid !== '') {
            $parent = self::effective_published_admin($companyuuid, $row->parentuuid);
            if (!$parent || (int)$parent->revision !== (int)$row->parentrevision) { self::fail('stale'); }
        }
        if ($row->sourcekind === 'owner_reqset') {
            $source = self::effective_published_generic('local_kbacompany_ownerframe', $companyuuid, $row->sourceuuid);
        } else if ($row->sourcekind === 'strategic_goal') {
            $source = self::effective_published_generic('local_kbacompany_stratgoal', $companyuuid, $row->sourceuuid);
        } else if (in_array($row->sourcekind, ['policy', 'directive'], true)) {
            $source = self::effective_published_generic('local_kbacompany_policy', $companyuuid, $row->sourceuuid, 'withdrawn');
            if ($source && (($row->sourcekind === 'policy' && $source->kind !== 'policy') ||
                    ($row->sourcekind === 'directive' && $source->kind !== 'directive'))) {
                $source = null;
            }
        } else {
            $source = null;
        }
        if ($row->sourceuuid !== '' && (!$source || (int)$source->revision !== (int)$row->sourcerevision)) {
            self::fail('stale');
        }
    }

    public static function publish(string $companyuuid, int $userid, string $uuid, int $expectedrevision): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('adminscale:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status !== 'draft') { self::fail('state'); }
            self::assert_dependencies_current($companyuuid, $old);
            $row = clone $old;
            unset($row->id);
            $row->revision = $expectedrevision + 1;
            $row->status = 'published';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_adminitem', $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public static function archive(string $companyuuid, int $userid, string $uuid, int $expectedrevision): void {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('adminscale:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($companyuuid, $uuid);
            if ((int)$old->revision !== $expectedrevision) { self::fail('conflict'); }
            if ($old->status === 'archived') { self::fail('state'); }
            $latestchildren = [];
            foreach ($DB->get_records('local_kbacompany_adminitem',
                    ['companyuuid' => $companyuuid, 'parentuuid' => $uuid], 'uuid ASC, revision ASC') as $child) {
                $latestchildren[$child->uuid] = $child;
            }
            foreach ($latestchildren as $child) {
                if ($child->status !== 'archived') { self::fail('referenced'); }
            }
            $row = clone $old;
            unset($row->id);
            $row->revision = $expectedrevision + 1;
            $row->status = 'archived';
            $row->modifiedby = $userid;
            $row->timemodified = time();
            $DB->insert_record('local_kbacompany_adminitem', $row);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function source_label(string $companyuuid, \stdClass $row): string {
        global $DB;
        if ($row->sourceuuid === '') { return ''; }
        if ($row->sourcekind === 'owner_reqset') {
            $source = $DB->get_record('local_kbacompany_ownerframe',
                ['companyuuid' => $companyuuid, 'uuid' => $row->sourceuuid, 'revision' => $row->sourcerevision,
                    'status' => 'published']);
            return $source ? get_string('ownermodel_reqpackage', 'local_kbacompany',
                (object)['revision' => (int)$source->revision]) : '';
        }
        if ($row->sourcekind === 'strategic_goal') {
            $source = $DB->get_record('local_kbacompany_stratgoal',
                ['companyuuid' => $companyuuid, 'uuid' => $row->sourceuuid, 'revision' => $row->sourcerevision]);
            return $source ? (string)$source->name : '';
        }
        if (in_array($row->sourcekind, ['policy', 'directive'], true)) {
            $source = $DB->get_record('local_kbacompany_policy',
                ['companyuuid' => $companyuuid, 'uuid' => $row->sourceuuid, 'revision' => $row->sourcerevision]);
            return $source ? (string)$source->title : '';
        }
        return '';
    }

    private static function stale(string $companyuuid, \stdClass $row): bool {
        if ($row->parentuuid !== '') {
            $parent = self::effective_published_admin($companyuuid, $row->parentuuid);
            if (!$parent || (int)$parent->revision !== (int)$row->parentrevision) { return true; }
        }
        if ($row->sourceuuid !== '') {
            if ($row->sourcekind === 'owner_reqset') {
                $source = self::effective_published_generic('local_kbacompany_ownerframe', $companyuuid, $row->sourceuuid);
            } else if ($row->sourcekind === 'strategic_goal') {
                $source = self::effective_published_generic('local_kbacompany_stratgoal', $companyuuid, $row->sourceuuid);
            } else {
                $source = self::effective_published_generic('local_kbacompany_policy', $companyuuid, $row->sourceuuid, 'withdrawn');
            }
            if (!$source || (int)$source->revision !== (int)$row->sourcerevision) { return true; }
        }
        return false;
    }

    private static function path(string $companyuuid, \stdClass $row): array {
        $path = [];
        $cursor = $row;
        $seen = [];
        while ($cursor) {
            if (isset($seen[$cursor->uuid])) { self::fail('cycle'); }
            $seen[$cursor->uuid] = true;
            array_unshift($path, ['uuid' => (string)$cursor->uuid, 'revision' => (int)$cursor->revision,
                'kind' => (string)$cursor->kind, 'code' => (string)$cursor->code, 'name' => (string)$cursor->name]);
            if ($cursor->parentuuid === '') { break; }
            $cursor = self::exact_revision($companyuuid, $cursor->parentuuid, (int)$cursor->parentrevision);
        }
        return $path;
    }

    private static function published_options(string $companyuuid): array {
        global $DB;
        $groups = [];
        foreach ($DB->get_records('local_kbacompany_adminitem', ['companyuuid' => $companyuuid],
                'uuid ASC, revision DESC') as $row) {
            if (!isset($groups[$row->uuid])) { $groups[$row->uuid] = []; }
            $groups[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($groups as $rows) {
            $latest = reset($rows);
            if ($latest->status === 'archived') { continue; }
            foreach ($rows as $row) {
                if ($row->status === 'published') {
                    $out[] = $row;
                    break;
                }
            }
        }
        usort($out, static function($a, $b): int {
            $order = array_flip(self::KINDS);
            return [$order[$a->kind] ?? 99, $a->code] <=> [$order[$b->kind] ?? 99, $b->code];
        });
        return $out;
    }

    private static function current_strategy_goals(string $companyuuid): array {
        global $DB;
        $groups = [];
        foreach ($DB->get_records('local_kbacompany_stratgoal', ['companyuuid' => $companyuuid],
                'uuid ASC, revision DESC') as $row) {
            $groups[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($groups as $rows) {
            $latest = reset($rows);
            if ($latest->status === 'archived') { continue; }
            foreach ($rows as $row) {
                if ($row->status === 'published') { $out[] = $row; break; }
            }
        }
        return $out;
    }

    private static function current_governance(string $companyuuid, string $kind): array {
        global $DB;
        $groups = [];
        foreach ($DB->get_records('local_kbacompany_policy',
                ['companyuuid' => $companyuuid, 'kind' => $kind], 'uuid ASC, revision DESC') as $row) {
            $groups[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($groups as $rows) {
            $latest = reset($rows);
            if ($latest->status === 'withdrawn') { continue; }
            foreach ($rows as $row) {
                if ($row->status === 'published') { $out[] = $row; break; }
            }
        }
        return $out;
    }

    /**
     * Version history for one Administrative Scale record.
     *
     * @param string $companyuuid
     * @param int $userid
     * @param string $uuid
     * @return array
     */
    public static function history(string $companyuuid, int $userid, string $uuid): array {
        global $DB;
        company_context_service::context($companyuuid, $userid);
        $canmanage = self::can_manage($companyuuid, $userid);
        $rows = $DB->get_records('local_kbacompany_adminitem',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC');
        $out = [];
        foreach ($rows as $row) {
            if (!$canmanage && $row->status !== 'published') {
                continue;
            }
            $out[] = [
                'revision' => (int)$row->revision,
                'status' => (string)$row->status,
                'modifiedby' => (int)$row->modifiedby,
                'timemodified' => (int)$row->timemodified,
            ];
        }
        return $out;
    }

    public static function context(string $companyuuid, int $userid): array {
        global $DB;
        require_capability('local/kbacompany:viewowncompany', \context_system::instance(), $userid);
        $company = company_context_service::context($companyuuid, $userid);
        $canmanage = self::can_manage($companyuuid, $userid);
        $rows = self::current_rows($companyuuid, $canmanage);
        $items = [];
        foreach ($rows as $row) {
            $canonicaltaskcount = 0;
            $battleplancount = 0;
            if ((string)$row->kind === 'task' && (string)$row->status === 'published') {
                $tasks = $DB->get_records('local_kbacompany_task', [
                    'companyuuid' => $companyuuid, 'sourcekind' => 'adminscale',
                    'sourceuuid' => (string)$row->uuid, 'sourcerevision' => (int)$row->revision,
                ]);
                $canonicaltaskcount = count($tasks);
                foreach ($tasks as $task) {
                    $battleplancount += $DB->count_records('local_kbacompany_bplanitem', [
                        'companyuuid' => $companyuuid, 'provider' => 'bms', 'externalid' => (string)$task->uuid,
                    ]);
                }
            }
            $items[] = [
                'uuid' => (string)$row->uuid,
                'revision' => (int)$row->revision,
                'status' => (string)$row->status,
                'kind' => (string)$row->kind,
                'code' => (string)$row->code,
                'name' => (string)$row->name,
                'description' => (string)($row->description ?? ''),
                'situation' => (string)($row->situation ?? ''),
                'plangoal' => (string)($row->plangoal ?? ''),
                'expectedresult' => (string)($row->expectedresult ?? ''),
                'parentuuid' => (string)$row->parentuuid,
                'parentrevision' => (int)$row->parentrevision,
                'sourcekind' => (string)$row->sourcekind,
                'sourceuuid' => (string)$row->sourceuuid,
                'sourcerevision' => (int)$row->sourcerevision,
                'sourcelabel' => self::source_label($companyuuid, $row),
                'ownerpositionuuid' => (string)$row->ownerpositionuuid,
                'productuuid' => (string)$row->productuuid,
                'metricuuid' => (string)$row->metricuuid,
                'targetvalue' => (string)$row->targetvalue,
                'startdate' => (string)$row->startdate,
                'duedate' => (string)$row->duedate,
                'sortorder' => (int)$row->sortorder,
                'stale' => self::stale($companyuuid, $row),
                'path' => self::path($companyuuid, $row),
                'canonical_task_count' => $canonicaltaskcount,
                'battle_plan_count' => $battleplancount,
            ];
        }
        $bykind = [];
        foreach (self::KINDS as $kind) { $bykind[$kind] = []; }
        foreach ($items as $item) { $bykind[$item['kind']][] = $item; }

        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'active' => 1], 'name ASC') as $row) {
            $positions[] = ['uuid' => (string)$row->uuid, 'name' => (string)$row->name, 'code' => (string)$row->code];
        }
        $products = [];
        foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'name ASC') as $row) {
            $products[] = ['uuid' => (string)$row->uuid, 'name' => (string)$row->name, 'code' => (string)$row->code];
        }
        $metrics = [];
        foreach ($DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid], 'name ASC') as $row) {
            $metrics[] = ['uuid' => (string)$row->uuid, 'name' => (string)$row->name, 'code' => (string)$row->code];
        }
        $published = self::published_options($companyuuid);
        $parents = [];
        foreach (self::KINDS as $kind) { $parents[$kind] = []; }
        foreach ($published as $row) {
            foreach (self::PARENT_KINDS as $childkind => $allowed) {
                if (in_array($row->kind, $allowed, true)) {
                    $parents[$childkind][] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                        'kind' => (string)$row->kind, 'code' => (string)$row->code, 'name' => (string)$row->name];
                }
            }
        }
        $ownerintents = [];
        foreach (owner_service::published_requirement_sets($companyuuid, $userid) as $set) {
            $ownerintents[] = ['uuid' => (string)$set['uuid'], 'revision' => (int)$set['revision'],
                'code' => 'OWNER', 'name' => (string)$set['label']];
        }
        $stratgoals = [];
        foreach (self::current_strategy_goals($companyuuid) as $row) {
            $stratgoals[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'code' => (string)$row->code, 'name' => (string)$row->name, 'perspective' => (string)$row->perspective];
        }
        $policies = [];
        foreach (self::current_governance($companyuuid, 'policy') as $row) {
            $policies[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'code' => (string)$row->code, 'name' => (string)$row->title];
        }
        $directives = [];
        foreach (self::current_governance($companyuuid, 'directive') as $row) {
            $directives[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'code' => (string)$row->code, 'name' => (string)$row->title];
        }
        $counts = [];
        $missingcore = [];
        $stale = 0;
        foreach (self::KINDS as $kind) { $counts[$kind] = count($bykind[$kind] ?? []); }
        foreach (self::CORE_KINDS as $kind) {
            if (($counts[$kind] ?? 0) === 0) { $missingcore[] = $kind; }
        }
        foreach ($items as $item) { if (!empty($item['stale'])) { $stale++; } }
        $battleplans = $canmanage ? battle_plan_service::draft_plan_options($companyuuid, $userid) : [];

        return [
            'contract' => self::contract(),
            'company' => $company,
            'can_manage' => $canmanage,
            'kinds' => self::KINDS,
            'core_kinds' => self::CORE_KINDS,
            'execution_kinds' => self::EXECUTION_KINDS,
            'items' => $items,
            'counts' => $counts,
            'alignment' => ['missing_core' => $missingcore, 'stale_count' => $stale],
            'by_kind' => $bykind,
            'parent_options' => $parents,
            'ownership_intents' => $ownerintents,
            'strategic_goals' => $stratgoals,
            'policies' => $policies,
            'directives' => $directives,
            'positions' => $positions,
            'products' => $products,
            'metrics' => $metrics,
            'battle_plans' => $battleplans,
            'legacy_cascade_count' => $DB->count_records_select('local_kbacompany_acobj',
                'companyuuid = :company AND status <> :archived', ['company' => $companyuuid, 'archived' => 'archived']),
        ];
    }
}
