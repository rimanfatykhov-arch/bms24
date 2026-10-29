<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Ownership intent, strategy and Balanced Scorecard design. */
class strategy_service {
    public const CONTRACT_VERSION = '0.5.0';
    public const STATES = ['draft', 'published', 'archived'];
    public const PERSPECTIVES = ['financial', 'customer', 'process', 'learning'];
    public const CAUSAL_RELATIONS = ['drives', 'enables'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_ownership_strategy_bsc',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['OwnershipIntent', 'Strategy', 'StrategicGoal', 'StrategicCausalLink'],
            'perspectives' => self::PERSPECTIVES,
            'states' => self::STATES,
            'rules' => [
                'ownership_intent_precedes_strategy',
                'long_term_goal_is_explicit',
                'bsc_goal_belongs_to_one_strategy',
                'strategic_kpi_reuses_existing_metric_definition',
                'strategic_process_link_reuses_existing_process',
                'strategy_business_model_link_is_explicit',
                'causal_map_is_acyclic',
                'published_revisions_are_immutable',
                'no_financial_or_operational_fact_mutation',
                'bsc_goal_codes_are_server_generated_and_immutable',
                'bsc_goal_indicators_reuse_indicator_registry_bindings',
                'bsc_health_is_derived_from_fact_and_explicit_norms',
                'accountability_is_position_first_with_current_employee_projection',
                'strategy_execution_reuses_administrative_scale_and_battle_plan',
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
        throw new \moodle_exception('strategy_' . $key, 'local_kbacompany');
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
        if ($value === '') {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
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

    private static function effective_published(string $table, string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid],
            'revision DESC', '*');
        if (!$rows) { return null; }
        $first = reset($rows);
        if ($first->status === 'archived') { return null; }
        foreach ($rows as $row) {
            if ($row->status === 'published') { return $row; }
        }
        return null;
    }

    private static function published_revision_is_current(string $table, string $companyuuid,
            string $uuid, int $revision): bool {
        $row = self::effective_published($table, $companyuuid, $uuid);
        return $row !== null && (int)$row->revision === $revision;
    }

    private static function current_rows(string $table, string $companyuuid, bool $canmanage): array {
        global $DB;
        $groups = [];
        foreach ($DB->get_records($table, ['companyuuid' => $companyuuid], 'uuid ASC, revision ASC') as $row) {
            $groups[$row->uuid][] = $row;
        }
        $out = [];
        foreach ($groups as $versions) {
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

    private static function ensure_unique_code(string $table, string $companyuuid, string $code,
            string $objectuuid = ''): void {
        global $DB;
        foreach ($DB->get_records($table, ['companyuuid' => $companyuuid, 'code' => $code]) as $row) {
            if ($objectuuid === '' || (string)$row->uuid !== $objectuuid) {
                self::fail('duplicatecode');
            }
        }
    }

    private static function save_versioned(string $table, string $companyuuid, int $userid,
            array $data, string $uuid, int $expected, array $fields, bool $hascode = false): string {
        global $DB;
        self::authorise($companyuuid, $userid);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('strategy:' . $companyuuid, 10);
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
            $stable = $old ? (string)$old->uuid : self::uuid();
            if ($hascode) {
                self::ensure_unique_code($table, $companyuuid, (string)$fields['code'], $stable);
            }
            $row = (object)[
                'companyuuid' => $companyuuid,
                'uuid' => $stable,
                'revision' => $old ? (int)$old->revision + 1 : 1,
                'status' => 'draft',
            ];
            foreach ($fields as $field => $value) {
                $row->$field = $value;
            }
            $now = time();
            $row->createdby = $userid;
            $row->modifiedby = $userid;
            $row->timecreated = $now;
            $row->timemodified = $now;
            $DB->insert_record($table, $row);
            $tx->allow_commit();
            return $stable;
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
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('strategy:' . $companyuuid, 10);
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
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('strategy:' . $companyuuid, 10);
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

    private static function next_code(string $table, string $companyuuid, string $scope, string $prefix): string {
        global $DB;
        $like = $DB->sql_like('code', ':prefix', false, false);
        $codes = $DB->get_fieldset_select($table, 'code',
            'companyuuid = :companyuuid AND ' . $like, [
                'companyuuid' => $companyuuid,
                'prefix' => $prefix . '-%',
            ]);
        $maximum = 0;
        foreach ($codes as $existingcode) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '-([0-9]+)$/', (string)$existingcode, $matches)) {
                $maximum = max($maximum, (int)$matches[1]);
            }
        }
        return stable_code_service::next($companyuuid, $scope, $prefix,
            static function(string $candidate) use ($DB, $table, $companyuuid): bool {
                return !$DB->record_exists($table, ['companyuuid' => $companyuuid, 'code' => $candidate]);
            }, $maximum);
    }

    private static function immutable_code(string $table, string $companyuuid, string $uuid,
            string $scope, string $prefix): string {
        if ($uuid !== '') {
            return (string)self::latest($table, $companyuuid, $uuid)->code;
        }
        return self::next_code($table, $companyuuid, $scope, $prefix);
    }

    public static function save_ownership_intent(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        $code = self::immutable_code('local_kbacompany_ownerintent', $companyuuid, $uuid, 'strategy:ownerintent', 'OWN');
        return self::save_versioned('local_kbacompany_ownerintent', $companyuuid, $userid, $data,
            $uuid, $revision, [
                'code' => $code,
                'name' => self::text($data, 'name', 255, true),
                'ownershippurpose' => self::text($data, 'ownershippurpose', 8000, true),
                'expectations' => self::text($data, 'expectations', 8000, false),
                'constraints' => self::text($data, 'constraints', 8000, false),
                'horizon' => self::text($data, 'horizon', 100, false),
                'longtermgoal' => self::text($data, 'longtermgoal', 8000, true),
            ], true);
    }

    public static function publish_ownership_intent(string $companyuuid, int $userid, string $uuid,
            int $revision): void {
        self::publish_versioned('local_kbacompany_ownerintent', $companyuuid, $userid, $uuid, $revision);
    }

    public static function archive_ownership_intent(string $companyuuid, int $userid, string $uuid,
            int $revision): void {
        foreach (self::current_rows('local_kbacompany_strategy', $companyuuid, true) as $strategy) {
            if ((string)$strategy->ownerintentuuid === $uuid) { self::fail('dependency'); }
        }
        self::archive_versioned('local_kbacompany_ownerintent', $companyuuid, $userid, $uuid, $revision);
    }

    public static function save_strategy(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        $ownerintentuuid = self::text($data, 'ownerintentuuid', 36, true);
        // 0.7.0.5: canonical Owner package is the preferred strategy source. Legacy ownerintent remains readable.
        $ownerset = owner_service::requirement_set($companyuuid, $userid, $ownerintentuuid);
        $intent = null;
        if ($ownerset) {
            $ownerintentrevision = (int)$ownerset['revision'];
        } else {
            $intent = self::effective_published('local_kbacompany_ownerintent', $companyuuid, $ownerintentuuid);
            if (!$intent) { self::fail('dependency'); }
            $ownerintentrevision = (int)$intent->revision;
        }
        $businessmodeluuid = self::text($data, 'businessmodeluuid', 36, false);
        $businessmodelrevision = 0;
        if ($businessmodeluuid !== '') {
            $model = self::effective_published('local_kbacompany_bizmodel', $companyuuid, $businessmodeluuid);
            if (!$model) { self::fail('dependency'); }
            $businessmodelrevision = (int)$model->revision;
        }
        $philosophyuuid = self::text($data, 'philosophyuuid', 36, false);
        $philosophyrevision = 0;
        if ($philosophyuuid !== '') {
            $philosophy = self::effective_published('local_kbacompany_philosophy', $companyuuid, $philosophyuuid);
            if (!$philosophy) { self::fail('dependency'); }
            $philosophyrevision = (int)$philosophy->revision;
        }
        $marketuuid = self::text($data, 'marketuuid', 36, false);
        $marketrevision = 0;
        if ($marketuuid !== '') {
            $market = self::effective_published('local_kbacompany_market', $companyuuid, $marketuuid);
            if (!$market) { self::fail('dependency'); }
            $marketrevision = (int)$market->revision;
        }
        $start = self::date($data, 'horizonstart');
        $end = self::date($data, 'horizonend');
        if ($start !== '' && $end !== '' && $start > $end) {
            self::fail('invalid');
        }
        $code = self::immutable_code('local_kbacompany_strategy', $companyuuid, $uuid, 'strategy:strategy', 'STR');
        return self::save_versioned('local_kbacompany_strategy', $companyuuid, $userid, $data,
            $uuid, $revision, [
                'ownerintentuuid' => $ownerintentuuid,
                'ownerintentrevision' => $ownerintentrevision,
                'code' => $code,
                'name' => self::text($data, 'name', 255, true),
                'thesis' => self::text($data, 'thesis', 8000, true),
                'positioning' => self::text($data, 'positioning', 8000, false),
                'horizonstart' => $start,
                'horizonend' => $end,
                'businessmodeluuid' => $businessmodeluuid,
                'businessmodelrevision' => $businessmodelrevision,
                'philosophyuuid' => $philosophyuuid,
                'philosophyrevision' => $philosophyrevision,
                'marketuuid' => $marketuuid,
                'marketrevision' => $marketrevision,
            ], true);
    }

    public static function publish_strategy(string $companyuuid, int $userid, string $uuid, int $revision): void {
        $strategy = self::latest('local_kbacompany_strategy', $companyuuid, $uuid);
        $ownerset = owner_service::requirement_set($companyuuid, $userid,
            (string)$strategy->ownerintentuuid, (int)$strategy->ownerintentrevision);
        if (!$ownerset && !self::published_revision_is_current('local_kbacompany_ownerintent', $companyuuid,
                (string)$strategy->ownerintentuuid, (int)$strategy->ownerintentrevision)) {
            self::fail('dependency');
        }
        if ($strategy->businessmodeluuid !== '' &&
                !self::published_revision_is_current('local_kbacompany_bizmodel', $companyuuid,
                    (string)$strategy->businessmodeluuid, (int)$strategy->businessmodelrevision)) {
            self::fail('dependency');
        }
        if ($strategy->philosophyuuid !== '' &&
                !self::published_revision_is_current('local_kbacompany_philosophy', $companyuuid,
                    (string)$strategy->philosophyuuid, (int)$strategy->philosophyrevision)) {
            self::fail('dependency');
        }
        if ($strategy->marketuuid !== '' &&
                !self::published_revision_is_current('local_kbacompany_market', $companyuuid,
                    (string)$strategy->marketuuid, (int)$strategy->marketrevision)) {
            self::fail('dependency');
        }
        self::publish_versioned('local_kbacompany_strategy', $companyuuid, $userid, $uuid, $revision);
    }

    public static function archive_strategy(string $companyuuid, int $userid, string $uuid, int $revision): void {
        foreach (self::current_rows('local_kbacompany_stratgoal', $companyuuid, true) as $goal) {
            if ((string)$goal->strategyuuid === $uuid) { self::fail('dependency'); }
        }
        self::archive_versioned('local_kbacompany_strategy', $companyuuid, $userid, $uuid, $revision);
    }

    private static function validate_goal_parent(string $companyuuid, string $strategyuuid,
            string $parentuuid, string $selfuuid = ''): void {
        if ($parentuuid === '') {
            return;
        }
        $seen = [];
        $cursor = $parentuuid;
        while ($cursor !== '') {
            if ($cursor === $selfuuid || isset($seen[$cursor])) {
                self::fail('cycle');
            }
            $seen[$cursor] = true;
            $parent = self::effective_published('local_kbacompany_stratgoal', $companyuuid, $cursor);
            if (!$parent || (string)$parent->strategyuuid !== $strategyuuid) {
                self::fail('dependency');
            }
            $cursor = (string)$parent->parentuuid;
        }
    }

    public static function save_goal(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        global $DB;
        $strategyuuid = self::text($data, 'strategyuuid', 36, true);
        $strategy = self::effective_published('local_kbacompany_strategy', $companyuuid, $strategyuuid);
        if (!$strategy) { self::fail('dependency'); }
        $perspective = self::text($data, 'perspective', 16, true);
        if (!in_array($perspective, self::PERSPECTIVES, true)) {
            self::fail('invalid');
        }
        $parentuuid = self::text($data, 'parentuuid', 36, false);
        self::validate_goal_parent($companyuuid, $strategyuuid, $parentuuid, $uuid);
        $parentrevision = 0;
        if ($parentuuid !== '') {
            $parent = self::effective_published('local_kbacompany_stratgoal', $companyuuid, $parentuuid);
            if (!$parent || (string)$parent->strategyuuid !== $strategyuuid) { self::fail('dependency'); }
            $parentrevision = (int)$parent->revision;
        }
        $existinggoal = $uuid !== '' ? self::latest('local_kbacompany_stratgoal', $companyuuid, $uuid) : null;
        $metricuuid = array_key_exists('metricuuid', $data) ? self::text($data, 'metricuuid', 36, false) :
            ($existinggoal ? (string)$existinggoal->metricuuid : '');
        if ($metricuuid !== '' && !$DB->record_exists('local_kbacompany_stat',
                ['companyuuid' => $companyuuid, 'uuid' => $metricuuid])) {
            self::fail('reference');
        }
        $processuuid = self::text($data, 'processuuid', 36, false);
        $processrevision = 0;
        if ($processuuid !== '') {
            $process = self::effective_published('local_kbacompany_process', $companyuuid, $processuuid);
            if (!$process) { self::fail('dependency'); }
            $processrevision = (int)$process->revision;
        }
        $ownerpositionuuid = self::text($data, 'ownerpositionuuid', 36, false);
        if ($ownerpositionuuid !== '' && !$DB->record_exists('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'uuid' => $ownerpositionuuid, 'active' => 1])) {
            self::fail('reference');
        }
        $targetvalue = array_key_exists('targetvalue', $data) ? self::text($data, 'targetvalue', 32, false) :
            ($existinggoal ? (string)$existinggoal->targetvalue : '');
        if ($targetvalue !== '' && !cascade_service::decimal_valid($targetvalue)) {
            self::fail('invalid');
        }
        if ($targetvalue !== '' && $metricuuid === '') {
            self::fail('metricrequired');
        }
        $targetdate = self::date($data, 'targetdate');
        $weight = self::text($data, 'weight', 12, false);
        if ($weight !== '') {
            if (!cascade_service::decimal_valid($weight) ||
                    cascade_service::decimal_compare($weight, '0') < 0 ||
                    cascade_service::decimal_compare($weight, '100') > 0) {
                self::fail('invalid');
            }
        }
        $code = self::immutable_code('local_kbacompany_stratgoal', $companyuuid, $uuid, 'strategy:goal', 'GOAL');
        return self::save_versioned('local_kbacompany_stratgoal', $companyuuid, $userid, $data,
            $uuid, $revision, [
                'strategyuuid' => $strategyuuid,
                'strategyrevision' => (int)$strategy->revision,
                'code' => $code,
                'name' => self::text($data, 'name', 255, true),
                'perspective' => $perspective,
                'parentuuid' => $parentuuid,
                'parentrevision' => $parentrevision,
                'outcome' => self::text($data, 'outcome', 8000, true),
                'metricuuid' => $metricuuid,
                'targetvalue' => $targetvalue,
                'targetdate' => $targetdate,
                'processuuid' => $processuuid,
                'processrevision' => $processrevision,
                'ownerpositionuuid' => $ownerpositionuuid,
                'weight' => $weight,
                'sortorder' => (int)($data['sortorder'] ?? 0),
            ], true);
    }

    public static function publish_goal(string $companyuuid, int $userid, string $uuid, int $revision): void {
        $goal = self::latest('local_kbacompany_stratgoal', $companyuuid, $uuid);
        if (!self::published_revision_is_current('local_kbacompany_strategy', $companyuuid,
                (string)$goal->strategyuuid, (int)$goal->strategyrevision)) {
            self::fail('dependency');
        }
        if ($goal->parentuuid !== '' &&
                !self::published_revision_is_current('local_kbacompany_stratgoal', $companyuuid,
                    (string)$goal->parentuuid, (int)$goal->parentrevision)) {
            self::fail('dependency');
        }
        if ($goal->processuuid !== '' &&
                !self::published_revision_is_current('local_kbacompany_process', $companyuuid,
                    (string)$goal->processuuid, (int)$goal->processrevision)) {
            self::fail('dependency');
        }
        self::publish_versioned('local_kbacompany_stratgoal', $companyuuid, $userid, $uuid, $revision);
    }

    public static function archive_goal(string $companyuuid, int $userid, string $uuid, int $revision): void {
        foreach (self::current_rows('local_kbacompany_stratgoal', $companyuuid, true) as $goal) {
            if ((string)$goal->parentuuid === $uuid) { self::fail('dependency'); }
        }
        foreach (self::current_rows('local_kbacompany_goallink', $companyuuid, true) as $link) {
            if ((string)$link->fromgoaluuid === $uuid || (string)$link->togoaluuid === $uuid) { self::fail('dependency'); }
        }
        self::archive_versioned('local_kbacompany_stratgoal', $companyuuid, $userid, $uuid, $revision);
    }

    private static function current_causal_edges(string $companyuuid, string $strategyuuid,
            string $excludeuuid = '', bool $publishedonly = false): array {
        $edges = [];
        foreach (self::current_rows('local_kbacompany_goallink', $companyuuid, !$publishedonly) as $row) {
            if ($row->uuid === $excludeuuid || $row->strategyuuid !== $strategyuuid) {
                continue;
            }
            $edges[] = [(string)$row->fromgoaluuid, (string)$row->togoaluuid];
        }
        return $edges;
    }

    private static function would_cycle(array $edges, string $from, string $to): bool {
        $adj = [];
        foreach ($edges as [$a, $b]) {
            $adj[$a][] = $b;
        }
        $adj[$from][] = $to;
        $stack = [$to];
        $seen = [];
        while ($stack) {
            $node = array_pop($stack);
            if ($node === $from) {
                return true;
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($adj[$node] ?? [] as $next) {
                $stack[] = $next;
            }
        }
        return false;
    }

    public static function save_causal_link(string $companyuuid, int $userid, array $data,
            string $uuid = '', int $revision = 0): string {
        $strategyuuid = self::text($data, 'strategyuuid', 36, true);
        $strategy = self::effective_published('local_kbacompany_strategy', $companyuuid, $strategyuuid);
        if (!$strategy) { self::fail('dependency'); }
        $from = self::text($data, 'fromgoaluuid', 36, true);
        $to = self::text($data, 'togoaluuid', 36, true);
        if ($from === $to) {
            self::fail('cycle');
        }
        $fromgoal = self::effective_published('local_kbacompany_stratgoal', $companyuuid, $from);
        $togoal = self::effective_published('local_kbacompany_stratgoal', $companyuuid, $to);
        if (!$fromgoal || !$togoal || (string)$fromgoal->strategyuuid !== $strategyuuid ||
                (string)$togoal->strategyuuid !== $strategyuuid) {
            self::fail('dependency');
        }
        $relation = self::text($data, 'relation', 16, true);
        if (!in_array($relation, self::CAUSAL_RELATIONS, true)) {
            self::fail('invalid');
        }
        foreach (self::current_rows('local_kbacompany_goallink', $companyuuid, true) as $existing) {
            if ($existing->uuid !== $uuid && $existing->strategyuuid === $strategyuuid &&
                    $existing->fromgoaluuid === $from && $existing->togoaluuid === $to &&
                    $existing->relation === $relation) {
                self::fail('duplicate');
            }
        }
        if (self::would_cycle(self::current_causal_edges($companyuuid, $strategyuuid, $uuid), $from, $to)) {
            self::fail('cycle');
        }
        return self::save_versioned('local_kbacompany_goallink', $companyuuid, $userid, $data,
            $uuid, $revision, [
                'strategyuuid' => $strategyuuid,
                'strategyrevision' => (int)$strategy->revision,
                'fromgoaluuid' => $from,
                'fromgoalrevision' => (int)$fromgoal->revision,
                'togoaluuid' => $to,
                'togoalrevision' => (int)$togoal->revision,
                'relation' => $relation,
                'description' => self::text($data, 'description', 4000, false),
            ]);
    }

    public static function publish_causal_link(string $companyuuid, int $userid, string $uuid,
            int $revision): void {
        $link = self::latest('local_kbacompany_goallink', $companyuuid, $uuid);
        if (!self::published_revision_is_current('local_kbacompany_strategy', $companyuuid,
                    (string)$link->strategyuuid, (int)$link->strategyrevision) ||
                !self::published_revision_is_current('local_kbacompany_stratgoal', $companyuuid,
                    (string)$link->fromgoaluuid, (int)$link->fromgoalrevision) ||
                !self::published_revision_is_current('local_kbacompany_stratgoal', $companyuuid,
                    (string)$link->togoaluuid, (int)$link->togoalrevision)) {
            self::fail('dependency');
        }
        if (self::would_cycle(self::current_causal_edges($companyuuid, (string)$link->strategyuuid, $uuid, true),
                (string)$link->fromgoaluuid, (string)$link->togoaluuid)) {
            self::fail('cycle');
        }
        self::publish_versioned('local_kbacompany_goallink', $companyuuid, $userid, $uuid, $revision);
    }

    public static function archive_causal_link(string $companyuuid, int $userid, string $uuid,
            int $revision): void {
        self::archive_versioned('local_kbacompany_goallink', $companyuuid, $userid, $uuid, $revision);
    }

    private static function bsc_fact_status(?float $fact, array $norm, string $direction, bool $critical): string {
        if ($fact === null) { return 'nodata'; }
        $satisfied = null;
        if (($norm['normkind'] ?? '') === 'range' && $norm['minval'] !== null && $norm['maxval'] !== null) {
            $satisfied = $fact >= (float)$norm['minval'] && $fact <= (float)$norm['maxval'];
        } else if (($norm['normkind'] ?? '') === 'target' && $norm['targetvalue'] !== null) {
            $target = (float)$norm['targetvalue'];
            if ($direction === 'down') { $satisfied = $fact <= $target; }
            else if ($direction === 'neutral') { $satisfied = $fact == $target; }
            else { $satisfied = $fact >= $target; }
        }
        if ($satisfied === null) { return 'nodata'; }
        if ($satisfied) { return 'green'; }
        return $critical ? 'red' : 'yellow';
    }

    private static function effective_admin_items(string $companyuuid): array {
        return self::current_rows('local_kbacompany_adminitem', $companyuuid, false);
    }

    private static function execution_for_goal(string $companyuuid, string $goaluuid, array $adminitems): array {
        global $DB;
        $children = [];
        $roots = [];
        foreach ($adminitems as $row) {
            $children[(string)$row->parentuuid][] = $row;
            if ((string)$row->sourcekind === 'strategic_goal' && (string)$row->sourceuuid === $goaluuid) {
                $roots[] = $row;
            }
        }
        $selected = [];
        $stack = $roots;
        while ($stack) {
            $row = array_pop($stack);
            $key = (string)$row->uuid;
            if (isset($selected[$key])) { continue; }
            $selected[$key] = $row;
            foreach ($children[$key] ?? [] as $child) { $stack[] = $child; }
        }
        $counts = [];
        $tasksourceuuids = [];
        foreach ($selected as $row) {
            $kind = (string)$row->kind;
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            if ($kind === 'task') { $tasksourceuuids[] = (string)$row->uuid; }
        }
        $taskstatuses = [];
        $taskcount = 0;
        if ($tasksourceuuids) {
            list($insql, $params) = $DB->get_in_or_equal($tasksourceuuids, SQL_PARAMS_NAMED, 'src');
            $params['companyuuid'] = $companyuuid;
            $params['sourcekind'] = 'adminscale';
            $tasks = $DB->get_records_select('local_kbacompany_task',
                'companyuuid = :companyuuid AND sourcekind = :sourcekind AND sourceuuid ' . $insql,
                $params, 'duedate ASC, title ASC');
            foreach ($tasks as $task) {
                $taskcount++;
                $status = (string)$task->status;
                $taskstatuses[$status] = ($taskstatuses[$status] ?? 0) + 1;
            }
        }
        return [
            'roots' => count($roots),
            'items' => count($selected),
            'counts' => $counts,
            'tasks' => $taskcount,
            'task_statuses' => $taskstatuses,
        ];
    }

    private static function current_assignees(string $companyuuid): array {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_posassign',
            ['companyuuid' => $companyuuid, 'active' => 1], 'positionuuid ASC, assignmenttype ASC, id ASC');
        if (!$rows) { return []; }
        $userids = [];
        foreach ($rows as $row) { $userids[(int)$row->userid] = (int)$row->userid; }
        $users = $userids ? $DB->get_records_list('user', 'id', array_values($userids), '', 'id,firstname,lastname') : [];
        $out = [];
        foreach ($rows as $row) {
            $user = $users[(int)$row->userid] ?? null;
            if (!$user) { continue; }
            $out[(string)$row->positionuuid][] = [
                'userid' => (int)$row->userid,
                'name' => fullname($user),
                'assignmenttype' => (string)$row->assignmenttype,
            ];
        }
        return $out;
    }

    private static function bsc_read_model(string $companyuuid, int $userid, array $goals): array {
        $registry = indicator_registry_service::context($companyuuid, $userid);
        $metricmap = [];
        foreach ($registry['metrics'] as $metric) { $metricmap[$metric['uuid']] = $metric; }
        $normmap = [];
        foreach ($registry['norms'] as $norm) {
            $binduuid = (string)$norm['binduuid'];
            if (!isset($normmap[$binduuid]) || (int)$norm['revision'] > (int)$normmap[$binduuid]['revision']) {
                $normmap[$binduuid] = $norm;
            }
        }
        $bindingsbygoal = [];
        foreach ($registry['bindings'] as $binding) {
            if ((string)$binding['contextkind'] !== 'strategygoal') { continue; }
            $bindingsbygoal[(string)$binding['contextuuid']][] = $binding;
        }
        $assignees = self::current_assignees($companyuuid);
        $adminitems = self::effective_admin_items($companyuuid);
        $seriescache = [];
        $goalmodels = [];
        $perspectives = [];
        foreach (self::PERSPECTIVES as $perspective) {
            $perspectives[$perspective] = ['total' => 0, 'green' => 0, 'yellow' => 0, 'red' => 0, 'nodata' => 0, 'draft' => 0];
        }
        foreach ($goals as $goal) {
            $indicators = [];
            foreach ($bindingsbygoal[$goal['uuid']] ?? [] as $binding) {
                $metric = $metricmap[(string)$binding['statuuid']] ?? null;
                if (!$metric) { continue; }
                if (!isset($seriescache[$metric['uuid']])) {
                    $seriescache[$metric['uuid']] = indicator_registry_service::series_for_metric($companyuuid, $metric['uuid'], 13);
                }
                $series = $seriescache[$metric['uuid']];
                $trend = indicator_trend_service::analyze($series, (string)$metric['direction']);
                $norm = $normmap[(string)$binding['uuid']] ?? [];
                if (!$norm && !empty($binding['compatibility']) && (string)$goal['metricuuid'] === (string)$metric['uuid'] &&
                        (string)$goal['targetvalue'] !== '') {
                    $norm = ['normkind' => 'target', 'targetvalue' => (string)$goal['targetvalue'],
                        'minval' => null, 'maxval' => null, 'revision' => 0];
                }
                $latest = $trend['latest'] ? (float)$trend['latest']['value'] : null;
                $health = self::bsc_fact_status($latest, $norm, (string)$metric['direction'], !empty($binding['critical']));
                $indicators[] = [
                    'binding' => $binding,
                    'metric' => $metric,
                    'norm' => $norm,
                    'latest' => $latest,
                    'latest_period' => $trend['latest'] ? (string)$trend['latest']['period'] : '',
                    'trend' => $trend,
                    'health' => $health,
                ];
            }
            $health = $goal['status'] === 'draft' ? 'draft' : 'nodata';
            if ($goal['status'] !== 'draft' && $indicators) {
                $health = 'green';
                $hasdata = false;
                foreach ($indicators as $indicator) {
                    if ($indicator['health'] !== 'nodata') { $hasdata = true; }
                    if ($indicator['health'] === 'red') { $health = 'red'; break; }
                    if ($indicator['health'] === 'yellow') { $health = 'yellow'; }
                }
                if (!$hasdata) { $health = 'nodata'; }
            }
            $in_target = 0;
            foreach ($indicators as $indicator) { if ($indicator['health'] === 'green') { $in_target++; } }
            $perspective = (string)$goal['perspective'];
            $perspectives[$perspective]['total']++;
            $perspectives[$perspective][$health]++;
            $goalmodels[$goal['uuid']] = [
                'health' => $health,
                'indicators' => $indicators,
                'indicator_count' => count($indicators),
                'in_target' => $in_target,
                'assignees' => $assignees[(string)$goal['ownerpositionuuid']] ?? [],
                'execution' => self::execution_for_goal($companyuuid, (string)$goal['uuid'], $adminitems),
            ];
        }
        return [
            'goals' => $goalmodels,
            'perspectives' => $perspectives,
            'can_manage_indicators' => indicator_registry_service::can_manage($companyuuid, $userid),
        ];
    }

    private static function dto(\stdClass $row): array {
        $result = (array)$row;
        unset($result['id']);
        return $result;
    }

    public static function context(string $companyuuid, int $userid): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $canmanage = self::can_manage($companyuuid, $userid);
        $intents = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_ownerintent', $companyuuid, $canmanage));
        $strategies = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_strategy', $companyuuid, $canmanage));
        $goals = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_stratgoal', $companyuuid, $canmanage));
        $links = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_goallink', $companyuuid, $canmanage));
        $metrics = [];
        foreach ($DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid], 'code ASC') as $row) {
            $metrics[] = ['uuid' => $row->uuid, 'code' => $row->code, 'name' => $row->name,
                'unit' => $row->unit, 'period' => $row->periodtype];
        }
        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position',
                ['companyuuid' => $companyuuid, 'active' => 1], 'code ASC') as $row) {
            $positions[] = ['uuid' => $row->uuid, 'code' => $row->code, 'name' => $row->name];
        }
        $processes = [];
        foreach (process_service::context($companyuuid, $userid)['processes'] as $row) {
            if ($row['status'] !== 'archived') {
                $processes[] = $row;
            }
        }
        $businessmodels = market_model_service::context($companyuuid, $userid)['business_models'];
        $publishedintents = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_ownerintent', $companyuuid, false));
        $publishedownerpackages = owner_service::published_requirement_sets($companyuuid, $userid);
        $publishedstrategies = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_strategy', $companyuuid, false));
        $publishedgoals = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_stratgoal', $companyuuid, false));
        $publishedprocesses = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_process', $companyuuid, false));
        $publishedbusinessmodels = array_map([self::class, 'dto'],
            self::current_rows('local_kbacompany_bizmodel', $companyuuid, false));
        $publishedphilosophies = company_context_domain_service::published_philosophies($companyuuid, $userid);
        $publishedmarkets = company_context_domain_service::published_markets($companyuuid, $userid);
        $bsc = self::bsc_read_model($companyuuid, $userid, $goals);
        return [
            'company' => $company,
            'ownership_intents' => $intents,
            'strategies' => $strategies,
            'goals' => $goals,
            'causal_links' => $links,
            'published_ownership_intents' => $publishedintents,
            'published_owner_packages' => $publishedownerpackages,
            'published_strategies' => $publishedstrategies,
            'published_goals' => $publishedgoals,
            'metrics' => $metrics,
            'positions' => $positions,
            'processes' => $processes,
            'published_processes' => $publishedprocesses,
            'business_models' => $businessmodels,
            'published_business_models' => $publishedbusinessmodels,
            'published_philosophies' => $publishedphilosophies,
            'published_markets' => $publishedmarkets,
            'perspectives' => self::PERSPECTIVES,
            'causal_relations' => self::CAUSAL_RELATIONS,
            'can_manage' => $canmanage,
            'bsc' => $bsc,
        ];
    }
}
