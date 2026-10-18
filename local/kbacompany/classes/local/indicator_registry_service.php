<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Unified indicator registry: canonical statistics, versioned passports, bindings and norms. */
class indicator_registry_service {
    private const CONTEXT_KINDS = ['company', 'strategygoal', 'orgnode', 'position'];
    private const SOURCE_KINDS = ['manual', 'formula', 'integration', 'checklist'];
    private const AGGREGATIONS = ['direct', 'sum', 'average', 'ratio', 'last', 'formula'];
    private const NORM_KINDS = ['target', 'range'];

    public static function contract(): array {
        return [
            'version' => '0.5.5.2',
            'canonical_indicator' => 'local_kbacompany_stat',
            'history' => 'local_kbacompany_statval',
            'extensions' => [
                'passport' => 'local_kbacompany_statmeta',
                'binding' => 'local_kbacompany_statbind',
                'norm' => 'local_kbacompany_statnorm',
                'condition_policy' => 'local_kbacompany_statpolicy',
                'condition_event' => 'local_kbacompany_stateevt',
            ],
            'tree_projections' => ['strategy', 'organization'],
            'context_kinds' => self::CONTEXT_KINDS,
            'source_kinds' => self::SOURCE_KINDS,
            'aggregations' => self::AGGREGATIONS,
            'rules' => [
                'one_indicator_many_bindings',
                'strategy_and_organization_are_projections_not_duplicate_registries',
                'norms_are_versioned_separately_from_indicator_definition',
                'observations_remain_append_only',
                'legacy_goal_metric_and_product_target_links_are_projected_read_only',
                'battle_plan_does_not_own_indicator_definitions',
                'information_center_is_a_read_model_over_canonical_history',
                'metric_delete_is_archive_preserving_uuid_history_norms_and_bindings',
                'trends_are_derived_from_canonical_fact_history_not_stored_as_parallel_facts',
                'period_performance_status_is_derived_from_plan_or_effective_norm_without_parallel_storage',
                'analytical_events_are_derived_on_read_not_stored',
                'management_condition_belongs_to_indicator_binding_context',
                'condition_steepness_thresholds_are_explicit_versioned_policy_not_hidden_constants',
                'nonexistence_and_powerchange_are_contextual_not_inferred_from_statistic_alone',
                'condition_actions_are_recommendations_and_do_not_create_parallel_tasks',
                'manager_condition_confirmation_is_immutable_governance_event_not_parallel_fact',
                'condition_execution_uses_canonical_task_and_battle_plan_reference',
                'management_exceptions_are_read_model_over_existing_indicator_and_task_layers',
                'management_exceptions_do_not_create_parallel_incident_or_fact_storage',
            ],
        ];
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_indicators($companyuuid, $userid);
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

    private static function text(array $data, string $field, int $max, bool $required = false): string {
        $value = trim((string)($data[$field] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            throw new \moodle_exception('indicator_invalid', 'local_kbacompany');
        }
        return $value;
    }

    private static function latest_rows(string $table, string $companyuuid, string $keyfield): array {
        global $DB;
        $rows = $DB->get_records($table, ['companyuuid' => $companyuuid], $keyfield . ' ASC, revision ASC, id ASC');
        $latest = [];
        foreach ($rows as $row) {
            $latest[(string)$row->{$keyfield}] = $row;
        }
        return $latest;
    }

    private static function latest_meta(string $companyuuid, string $statuuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_statmeta',
            ['companyuuid' => $companyuuid, 'statuuid' => $statuuid], 'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function latest_binding(string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_statbind',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function latest_norm(string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_statnorm',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function latest_norm_for_binding(string $companyuuid, string $binduuid): ?\stdClass {
        $latest = self::latest_rows('local_kbacompany_statnorm', $companyuuid, 'uuid');
        $best = null;
        foreach ($latest as $row) {
            if ((string)$row->binduuid !== $binduuid || (string)$row->status !== 'active') { continue; }
            if ($best === null || (int)$row->timecreated > (int)$best->timecreated ||
                    ((int)$row->timecreated === (int)$best->timecreated && (int)$row->id > (int)$best->id)) {
                $best = $row;
            }
        }
        return $best;
    }

    private static function latest_condition_policy(string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_statpolicy',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function latest_condition_policy_for_binding(string $companyuuid, string $binduuid): ?\stdClass {
        $latest = self::latest_rows('local_kbacompany_statpolicy', $companyuuid, 'uuid');
        $best = null;
        foreach ($latest as $row) {
            if ((string)$row->binduuid !== $binduuid || (string)$row->status !== 'active') { continue; }
            if ($best === null || (int)$row->timecreated > (int)$best->timecreated ||
                    ((int)$row->timecreated === (int)$best->timecreated && (int)$row->id > (int)$best->id)) {
                $best = $row;
            }
        }
        return $best;
    }

    private static function published_goal(string $companyuuid, string $uuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_stratgoal',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) { return null; }
        $row = reset($rows);
        return $row->status === 'published' ? $row : null;
    }

    private static function validate_context(string $companyuuid, string $kind, string $uuid): int {
        global $DB;
        if (!in_array($kind, self::CONTEXT_KINDS, true)) {
            throw new \moodle_exception('indicator_invalidcontext', 'local_kbacompany');
        }
        if ($kind === 'company') {
            if ($uuid !== $companyuuid) { throw new \moodle_exception('indicator_invalidcontext', 'local_kbacompany'); }
            return 0;
        }
        if ($kind === 'strategygoal') {
            $goal = self::published_goal($companyuuid, $uuid);
            if (!$goal) { throw new \moodle_exception('indicator_invalidcontext', 'local_kbacompany'); }
            return (int)$goal->revision;
        }
        $table = $kind === 'orgnode' ? 'local_kbacompany_orgnode' : 'local_kbacompany_position';
        if (!$DB->record_exists($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'active' => 1])) {
            throw new \moodle_exception('indicator_invalidcontext', 'local_kbacompany');
        }
        return 0;
    }

    /** Archive/restore a metric definition without deleting its UUID, facts, norms or bindings. */
    public static function is_metric_active(string $companyuuid, string $statuuid): bool {
        global $DB;
        if (!$DB->record_exists('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $statuuid])) {
            return false;
        }
        $latest = self::latest_meta($companyuuid, $statuuid);
        return !$latest || (string)$latest->status === 'active';
    }

    public static function set_metric_status(string $companyuuid, int $userid, string $statuuid, string $status): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        if (!in_array($status, ['active', 'archived'], true)) {
            throw new \moodle_exception('indicator_invalid', 'local_kbacompany');
        }
        if (!$DB->record_exists('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $statuuid])) {
            throw new \moodle_exception('indicator_invalid', 'local_kbacompany');
        }
        $latest = self::latest_meta($companyuuid, $statuuid);
        if ($latest && (string)$latest->status === $status) {
            return;
        }
        $DB->insert_record('local_kbacompany_statmeta', (object)[
            'companyuuid' => $companyuuid,
            'statuuid' => $statuuid,
            'revision' => $latest ? (int)$latest->revision + 1 : 1,
            'status' => $status,
            'description' => $latest ? (string)($latest->description ?? '') : '',
            'sourcekind' => $latest ? (string)$latest->sourcekind : 'manual',
            'sourceconfig' => $latest ? (string)($latest->sourceconfig ?? '') : '',
            'aggregation' => $latest ? (string)$latest->aggregation : 'direct',
            'createdby' => $userid,
            'timecreated' => time(),
        ]);
        if ($status === 'archived') {
            self::set_main_company_metric($companyuuid, $userid, $statuuid, false);
        }
    }

    public static function save_passport(string $companyuuid, int $userid, string $statuuid, array $data): int {
        global $DB;
        self::require_manage($companyuuid, $userid);
        if (!$DB->record_exists('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $statuuid]) ||
                !self::is_metric_active($companyuuid, $statuuid)) {
            throw new \moodle_exception('indicator_archived_readonly', 'local_kbacompany');
        }
        $sourcekind = self::text($data, 'sourcekind', 16, true);
        $aggregation = self::text($data, 'aggregation', 16, true);
        if (!in_array($sourcekind, self::SOURCE_KINDS, true) || !in_array($aggregation, self::AGGREGATIONS, true)) {
            throw new \moodle_exception('indicator_invalid', 'local_kbacompany');
        }
        $latest = self::latest_meta($companyuuid, $statuuid);
        return (int)$DB->insert_record('local_kbacompany_statmeta', (object)[
            'companyuuid' => $companyuuid,
            'statuuid' => $statuuid,
            'revision' => $latest ? (int)$latest->revision + 1 : 1,
            'status' => 'active',
            'description' => self::text($data, 'description', 12000, false),
            'sourcekind' => $sourcekind,
            'sourceconfig' => self::text($data, 'sourceconfig', 12000, false),
            'aggregation' => $aggregation,
            'createdby' => $userid,
            'timecreated' => time(),
        ]);
    }

    public static function save_binding(string $companyuuid, int $userid, array $data,
            string $uuid = ''): string {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $statuuid = self::text($data, 'statuuid', 36, true);
        if (!$DB->record_exists('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $statuuid]) ||
                !self::is_metric_active($companyuuid, $statuuid)) {
            throw new \moodle_exception('indicator_archived_readonly', 'local_kbacompany');
        }
        $kind = self::text($data, 'contextkind', 16, true);
        $contextuuid = self::text($data, 'contextuuid', 36, true);
        $contextrevision = self::validate_context($companyuuid, $kind, $contextuuid);
        $weight = (string)($data['weight'] ?? '1');
        if (!preg_match('/^\d{1,4}(\.\d{1,3})?$/D', $weight)) {
            throw new \moodle_exception('indicator_invalid', 'local_kbacompany');
        }
        $current = null;
        if ($uuid !== '') {
            $current = self::latest_binding($companyuuid, $uuid);
            if (!$current) { throw new \moodle_exception('indicator_invalid', 'local_kbacompany'); }
        } else {
            $uuid = self::uuid();
        }
        $DB->insert_record('local_kbacompany_statbind', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
            'revision' => $current ? (int)$current->revision + 1 : 1,
            'status' => 'active',
            'statuuid' => $statuuid,
            'contextkind' => $kind,
            'contextuuid' => $contextuuid,
            'contextrevision' => $contextrevision,
            'isprimary' => empty($data['isprimary']) ? 0 : 1,
            'critical' => empty($data['critical']) ? 0 : 1,
            'weight' => $weight,
            'sortorder' => max(0, (int)($data['sortorder'] ?? 0)),
            'createdby' => $userid,
            'timecreated' => time(),
        ]);
        return $uuid;
    }

    /** Toggle the explicit company-level flag used by the Information Center. */
    public static function set_main_company_metric(string $companyuuid, int $userid, string $statuuid, bool $enabled): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        if (!$DB->record_exists('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $statuuid])) {
            throw new \moodle_exception('indicator_invalid', 'local_kbacompany');
        }
        if ($enabled && !self::is_metric_active($companyuuid, $statuuid)) {
            throw new \moodle_exception('indicator_archived_readonly', 'local_kbacompany');
        }
        $current = null;
        foreach (self::effective_bindings($companyuuid) as $binding) {
            if ((string)$binding->statuuid === $statuuid && (string)$binding->contextkind === 'company' &&
                    (string)$binding->contextuuid === $companyuuid) {
                $current = $binding;
                break;
            }
        }
        if (!$current && !$enabled) {
            return;
        }
        if ($current && (bool)$current->isprimary === $enabled) {
            return;
        }
        if (!$current) {
            $DB->insert_record('local_kbacompany_statbind', (object)[
                'companyuuid' => $companyuuid,
                'uuid' => self::uuid(),
                'revision' => 1,
                'status' => 'active',
                'statuuid' => $statuuid,
                'contextkind' => 'company',
                'contextuuid' => $companyuuid,
                'contextrevision' => 0,
                'isprimary' => $enabled ? 1 : 0,
                'critical' => 0,
                'weight' => '1',
                'sortorder' => 0,
                'createdby' => $userid,
                'timecreated' => time(),
            ]);
            return;
        }
        $copy = clone $current;
        unset($copy->id);
        $copy->revision = (int)$current->revision + 1;
        $copy->status = 'active';
        $copy->isprimary = $enabled ? 1 : 0;
        $copy->createdby = $userid;
        $copy->timecreated = time();
        $DB->insert_record('local_kbacompany_statbind', $copy);
    }

    public static function archive_binding(string $companyuuid, int $userid, string $uuid): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $current = self::latest_binding($companyuuid, $uuid);
        if (!$current) { throw new \moodle_exception('indicator_invalid', 'local_kbacompany'); }
        if (!self::is_metric_active($companyuuid, (string)$current->statuuid)) {
            throw new \moodle_exception('indicator_archived_readonly', 'local_kbacompany');
        }
        $copy = clone $current;
        unset($copy->id);
        $copy->revision = (int)$current->revision + 1;
        $copy->status = 'archived';
        $copy->createdby = $userid;
        $copy->timecreated = time();
        $DB->insert_record('local_kbacompany_statbind', $copy);
    }

    /** Enforce metric lifecycle for mutations to its related records. */
    private static function require_active_binding_metric(string $companyuuid, string $binduuid): void {
        $binding = self::latest_binding($companyuuid, $binduuid);
        if (!$binding) {
            throw new \moodle_exception('indicator_invalidbinding', 'local_kbacompany');
        }
        if (!self::is_metric_active($companyuuid, (string)$binding->statuuid)) {
            throw new \moodle_exception('indicator_archived_readonly', 'local_kbacompany');
        }
    }

    private static function decimal_or_null($value): ?string {
        $value = trim((string)$value);
        if ($value === '') { return null; }
        if (!preg_match('/^-?\d{1,12}(\.\d{1,6})?$/D', $value)) {
            throw new \moodle_exception('indicator_invalidnorm', 'local_kbacompany');
        }
        return $value;
    }

    public static function save_norm(string $companyuuid, int $userid, array $data, string $uuid = ''): string {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $binduuid = self::text($data, 'binduuid', 36, true);
        $binding = self::latest_binding($companyuuid, $binduuid);
        if (!$binding || $binding->status !== 'active') {
            throw new \moodle_exception('indicator_invalidbinding', 'local_kbacompany');
        }
        if (!self::is_metric_active($companyuuid, (string)$binding->statuuid)) {
            throw new \moodle_exception('indicator_archived_readonly', 'local_kbacompany');
        }
        $kind = self::text($data, 'normkind', 12, true);
        if (!in_array($kind, self::NORM_KINDS, true)) {
            throw new \moodle_exception('indicator_invalidnorm', 'local_kbacompany');
        }
        $target = self::decimal_or_null($data['targetvalue'] ?? '');
        $min = self::decimal_or_null($data['minval'] ?? '');
        $max = self::decimal_or_null($data['maxval'] ?? '');
        if (($kind === 'target' && $target === null) || ($kind === 'range' && ($min === null || $max === null)) ||
                ($min !== null && $max !== null && (float)$min > (float)$max)) {
            throw new \moodle_exception('indicator_invalidnorm', 'local_kbacompany');
        }
        $from = self::text($data, 'effectivefrom', 10, true);
        $to = self::text($data, 'effectiveto', 10, false);
        self::validate_date($from);
        if ($to !== '') {
            self::validate_date($to);
            if ($to < $from) { throw new \moodle_exception('indicator_invalidnorm', 'local_kbacompany'); }
        }
        $current = null;
        if ($uuid !== '') {
            $current = self::latest_norm($companyuuid, $uuid);
            if (!$current || (string)$current->binduuid !== $binduuid) {
                throw new \moodle_exception('indicator_invalidnorm', 'local_kbacompany');
            }
        } else {
            // One active norm definition stream per binding. A new value is a new revision,
            // not a parallel norm object. Existing legacy streams remain readable.
            $current = self::latest_norm_for_binding($companyuuid, $binduuid);
            $uuid = $current ? (string)$current->uuid : self::uuid();
        }
        $DB->insert_record('local_kbacompany_statnorm', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
            'revision' => $current ? (int)$current->revision + 1 : 1,
            'status' => 'active',
            'binduuid' => $binduuid,
            'bindrevision' => (int)$binding->revision,
            'normkind' => $kind,
            'targetvalue' => $target,
            'minval' => $min,
            'maxval' => $max,
            'effectivefrom' => $from,
            'effectiveto' => $to,
            'createdby' => $userid,
            'timecreated' => time(),
        ]);
        return $uuid;
    }

    public static function archive_norm(string $companyuuid, int $userid, string $uuid): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $current = self::latest_norm($companyuuid, $uuid);
        if (!$current) { throw new \moodle_exception('indicator_invalidnorm', 'local_kbacompany'); }
        self::require_active_binding_metric($companyuuid, (string)$current->binduuid);
        $copy = clone $current;
        unset($copy->id);
        $copy->revision = (int)$current->revision + 1;
        $copy->status = 'archived';
        $copy->createdby = $userid;
        $copy->timecreated = time();
        $DB->insert_record('local_kbacompany_statnorm', $copy);
    }

    public static function save_condition_policy(string $companyuuid, int $userid, array $data,
            string $uuid = ''): string {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $binduuid = self::text($data, 'binduuid', 36, true);
        $binding = self::latest_binding($companyuuid, $binduuid);
        if (!$binding || $binding->status !== 'active') {
            throw new \moodle_exception('indicator_invalidbinding', 'local_kbacompany');
        }
        self::require_active_binding_metric($companyuuid, $binduuid);
        $dangerdecline = self::decimal_or_null($data['dangerdecline'] ?? '');
        $affluencegrowth = self::decimal_or_null($data['affluencegrowth'] ?? '');
        $powerlevel = self::decimal_or_null($data['powerlevel'] ?? '');
        $dangerperiods = max(0, (int)($data['dangerperiods'] ?? 0));
        $powerperiods = max(0, (int)($data['powerperiods'] ?? 0));
        if (($dangerdecline !== null && ((float)$dangerdecline <= 0.0 || (float)$dangerdecline > 100000.0)) ||
                $affluencegrowth === null || (float)$affluencegrowth <= 0.0 || (float)$affluencegrowth > 100000.0 ||
                ($powerlevel !== null && ((float)$powerlevel <= 100.0 || (float)$powerlevel > 100000.0)) ||
                $dangerperiods > 520 || $powerperiods > 520 ||
                (($powerlevel === null && $powerperiods !== 0) || ($powerlevel !== null && $powerperiods < 2))) {
            throw new \moodle_exception('indicator_invalid_condition_policy', 'local_kbacompany');
        }
        $from = self::text($data, 'effectivefrom', 10, true);
        $to = self::text($data, 'effectiveto', 10, false);
        self::validate_date($from);
        if ($to !== '') {
            self::validate_date($to);
            if ($to < $from) { throw new \moodle_exception('indicator_invalid_condition_policy', 'local_kbacompany'); }
        }
        $current = null;
        if ($uuid !== '') {
            $current = self::latest_condition_policy($companyuuid, $uuid);
            if (!$current || (string)$current->binduuid !== $binduuid) {
                throw new \moodle_exception('indicator_invalid_condition_policy', 'local_kbacompany');
            }
        } else {
            $current = self::latest_condition_policy_for_binding($companyuuid, $binduuid);
            $uuid = $current ? (string)$current->uuid : self::uuid();
        }
        $DB->insert_record('local_kbacompany_statpolicy', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $uuid,
            'revision' => $current ? (int)$current->revision + 1 : 1,
            'status' => 'active',
            'binduuid' => $binduuid,
            'bindrevision' => (int)$binding->revision,
            'dangerdecline' => $dangerdecline,
            'dangerperiods' => $dangerperiods,
            'affluencegrowth' => $affluencegrowth,
            'powerlevel' => $powerlevel,
            'powerperiods' => $powerperiods,
            'effectivefrom' => $from,
            'effectiveto' => $to,
            'createdby' => $userid,
            'timecreated' => time(),
        ]);
        return $uuid;
    }

    public static function archive_condition_policy(string $companyuuid, int $userid, string $uuid): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $current = self::latest_condition_policy($companyuuid, $uuid);
        if (!$current) { throw new \moodle_exception('indicator_invalid_condition_policy', 'local_kbacompany'); }
        self::require_active_binding_metric($companyuuid, (string)$current->binduuid);
        $copy = clone $current;
        unset($copy->id);
        $copy->revision = (int)$current->revision + 1;
        $copy->status = 'archived';
        $copy->createdby = $userid;
        $copy->timecreated = time();
        $DB->insert_record('local_kbacompany_statpolicy', $copy);
    }

    private static function validate_date(string $value): void {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new \moodle_exception('indicator_invalidnorm', 'local_kbacompany');
        }
    }

    private static function effective_norms(string $companyuuid): array {
        $latest = self::latest_rows('local_kbacompany_statnorm', $companyuuid, 'uuid');
        return array_values(array_filter($latest, static function($row): bool {
            return $row->status === 'active';
        }));
    }

    /** Full revision history, including archive tombstones, for historical on-read resolution. */
    private static function effective_norm_history(string $companyuuid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacompany_statnorm', ['companyuuid' => $companyuuid],
            'uuid ASC, revision ASC, id ASC'));
    }

    private static function effective_condition_policies(string $companyuuid): array {
        $latest = self::latest_rows('local_kbacompany_statpolicy', $companyuuid, 'uuid');
        return array_values(array_filter($latest, static function($row): bool {
            return $row->status === 'active';
        }));
    }

    /** Full policy revision history, including archive tombstones, for historical on-read resolution. */
    private static function effective_condition_policy_history(string $companyuuid): array {
        global $DB;
        return array_values($DB->get_records('local_kbacompany_statpolicy', ['companyuuid' => $companyuuid],
            'uuid ASC, revision ASC, id ASC'));
    }

    private static function effective_bindings(string $companyuuid): array {
        $latest = self::latest_rows('local_kbacompany_statbind', $companyuuid, 'uuid');
        return array_values(array_filter($latest, static function($row): bool {
            return $row->status === 'active';
        }));
    }

    private static function metric_dto(\stdClass $stat, ?\stdClass $meta, array $series): array {
        return [
            'uuid' => (string)$stat->uuid,
            'code' => (string)$stat->code,
            'name' => (string)$stat->name,
            'unit' => (string)$stat->unit,
            'periodtype' => (string)$stat->periodtype,
            'direction' => (string)$stat->direction,
            'productuuid' => (string)$stat->productuuid,
            'status' => $meta ? (string)$meta->status : 'active',
            'description' => $meta ? (string)($meta->description ?? '') : '',
            'sourcekind' => $meta ? (string)$meta->sourcekind : 'manual',
            'sourceconfig' => $meta ? (string)($meta->sourceconfig ?? '') : '',
            'aggregation' => $meta ? (string)$meta->aggregation : 'direct',
            'passport_revision' => $meta ? (int)$meta->revision : 0,
            'series' => $series,
        ];
    }

    /** Add read-only compatibility bindings from pre-0.5 relations without mutating old rows. */
    private static function compatibility_bindings(string $companyuuid, array $stats): array {
        global $DB;
        $out = [];
        $productmap = [];
        foreach ($DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid]) as $product) {
            $productmap[(string)$product->uuid] = $product;
        }
        foreach ($stats as $stat) {
            $product = $productmap[(string)$stat->productuuid] ?? null;
            if (!$product) { continue; }
            $kind = (string)$product->targettype;
            if ($kind === 'node') { $kind = 'orgnode'; }
            if (in_array($kind, ['company', 'orgnode', 'position'], true)) {
                $out[] = [
                    'uuid' => 'compat-product-' . (string)$stat->uuid,
                    'revision' => 0,
                    'statuuid' => (string)$stat->uuid,
                    'contextkind' => $kind,
                    'contextuuid' => (string)$product->targetuuid,
                    'contextrevision' => 0,
                    'isprimary' => 0,
                    'critical' => 0,
                    'weight' => '1',
                    'sortorder' => 0,
                    'compatibility' => true,
                    'source' => 'product',
                ];
            }
        }
        $goals = self::latest_rows('local_kbacompany_stratgoal', $companyuuid, 'uuid');
        foreach ($goals as $goal) {
            if ($goal->status !== 'published' || (string)$goal->metricuuid === '') { continue; }
            $out[] = [
                'uuid' => 'compat-goal-' . (string)$goal->uuid,
                'revision' => 0,
                'statuuid' => (string)$goal->metricuuid,
                'contextkind' => 'strategygoal',
                'contextuuid' => (string)$goal->uuid,
                'contextrevision' => (int)$goal->revision,
                'isprimary' => 1,
                'critical' => 0,
                'weight' => (string)($goal->weight !== '' ? $goal->weight : '1'),
                'sortorder' => (int)$goal->sortorder,
                'compatibility' => true,
                'source' => 'strategygoal',
            ];
        }
        return $out;
    }

    private static function deduplicate_bindings(array $bindings): array {
        $out = [];
        $seen = [];
        foreach ($bindings as $binding) {
            $row = is_object($binding) ? (array)$binding : $binding;
            $key = $row['statuuid'] . '|' . $row['contextkind'] . '|' . $row['contextuuid'];
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $row['compatibility'] = !empty($row['compatibility']);
            $out[] = $row;
        }
        return $out;
    }

    private static function latest_series(string $companyuuid, string $statuuid, int $limit = 52): array {
        global $DB;
        $dates = $DB->get_records_sql('SELECT DISTINCT periodstart FROM {local_kbacompany_statval}
            WHERE companyuuid = :company AND statuuid = :stat ORDER BY periodstart DESC',
            ['company' => $companyuuid, 'stat' => $statuuid], 0, $limit);
        if (!$dates) { return []; }
        $last = end($dates);
        $history = $DB->get_records_select('local_kbacompany_statval',
            'companyuuid = :company AND statuuid = :stat AND periodstart >= :firstperiod',
            ['company' => $companyuuid, 'stat' => $statuuid, 'firstperiod' => $last->periodstart],
            'periodstart ASC, kind ASC, revision ASC');
        return product_service::series($history);
    }


    /** Backward-compatible facade for the dedicated schema-neutral trend read-model. */
    public static function trend_analysis(array $series, string $direction): array {
        return indicator_trend_service::analyze($series, $direction);
    }

    /** Public read-only series for Information Center and drill-down charts. */
    public static function series_for_metric(string $companyuuid, string $statuuid, int $limit = 52): array {
        global $DB;
        if (!in_array($limit, [13, 26, 52], true)) { $limit = 52; }
        if (!$DB->record_exists('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $statuuid])) {
            return [];
        }
        return self::latest_series($companyuuid, $statuuid, $limit);
    }

    private static function active_org_data(string $companyuuid): array {
        global $DB;
        $nodes = array_values($DB->get_records('local_kbacompany_orgnode',
            ['companyuuid' => $companyuuid, 'active' => 1], 'branchuuid ASC, sortorder ASC, code ASC'));
        $positions = array_values($DB->get_records('local_kbacompany_position',
            ['companyuuid' => $companyuuid, 'active' => 1], 'sortorder ASC, code ASC'));
        return [$nodes, $positions];
    }

    private static function published_strategy_data(string $companyuuid): array {
        $strategies = array_filter(self::latest_rows('local_kbacompany_strategy', $companyuuid, 'uuid'),
            static function($row): bool { return $row->status === 'published'; });
        $goals = array_filter(self::latest_rows('local_kbacompany_stratgoal', $companyuuid, 'uuid'),
            static function($row): bool { return $row->status === 'published'; });
        return [array_values($strategies), array_values($goals)];
    }

    public static function context(string $companyuuid, int $userid, string $selectedstat = ''): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $statrows = array_values($DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid], 'code ASC'));
        $metas = self::latest_rows('local_kbacompany_statmeta', $companyuuid, 'statuuid');
        $metrics = [];
        foreach ($statrows as $stat) {
            $meta = $metas[(string)$stat->uuid] ?? null;
            $series = ($selectedstat === '' || $selectedstat !== (string)$stat->uuid) ? [] :
                self::latest_series($companyuuid, (string)$stat->uuid);
            $metrics[] = self::metric_dto($stat, $meta, $series);
        }
        $bindings = [];
        foreach (self::effective_bindings($companyuuid) as $row) {
            $arr = (array)$row;
            $arr['compatibility'] = false;
            $bindings[] = $arr;
        }
        $bindings = self::deduplicate_bindings(array_merge($bindings,
            self::compatibility_bindings($companyuuid, $statrows)));
        $maincompany = [];
        foreach ($bindings as $binding) {
            if (empty($binding['compatibility']) && $binding['contextkind'] === 'company' &&
                    (string)$binding['contextuuid'] === $companyuuid && !empty($binding['isprimary'])) {
                $maincompany[(string)$binding['statuuid']] = true;
            }
        }
        foreach ($metrics as &$metric) {
            $metric['maincompany'] = !empty($maincompany[(string)$metric['uuid']]);
        }
        unset($metric);
        $norms = array_map(static function($row): array { return (array)$row; }, self::effective_norms($companyuuid));
        $normhistory = array_map(static function($row): array { return (array)$row; }, self::effective_norm_history($companyuuid));
        $policies = array_map(static function($row): array { return (array)$row; }, self::effective_condition_policies($companyuuid));
        $policyhistory = array_map(static function($row): array { return (array)$row; }, self::effective_condition_policy_history($companyuuid));
        list($nodes, $positions) = self::active_org_data($companyuuid);
        list($strategies, $goals) = self::published_strategy_data($companyuuid);
        $products = array_values($DB->get_records('local_kbacompany_product', ['companyuuid' => $companyuuid], 'code ASC'));
        $selected = null;
        if ($selectedstat !== '') {
            foreach ($metrics as $metric) {
                if ($metric['uuid'] === $selectedstat) { $selected = $metric; break; }
            }
        }
        if (!$selected && $metrics) {
            foreach ($metrics as $metric) {
                if (($metric['status'] ?? 'active') === 'active') { $selected = $metric; break; }
            }
            if (!$selected) { $selected = $metrics[0]; }
        }
        if ($selected && !$selected['series']) {
            $selected['series'] = self::latest_series($companyuuid, $selected['uuid']);
        }
        return [
            'company' => $company,
            'metrics' => $metrics,
            'selected' => $selected,
            'bindings' => $bindings,
            'norms' => $norms,
            'normhistory' => $normhistory,
            'condition_policies' => $policies,
            'condition_policy_history' => $policyhistory,
            'nodes' => $nodes,
            'positions' => $positions,
            'strategies' => $strategies,
            'goals' => $goals,
            'products' => $products,
            'can_manage' => self::can_manage($companyuuid, $userid),
            'contract' => self::contract(),
        ];
    }
}
