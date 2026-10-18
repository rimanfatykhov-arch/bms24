<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Versioned Battle Plan templates, quota sets and state calculation. */
class battle_plan_template_service {
    public const CONTRACT_VERSION = '0.4.10.3';
    public const STATES = ['draft', 'published', 'archived'];
    public const CONDITIONS = ['nonexistence', 'danger', 'emergency', 'normal', 'affluence', 'power', 'powerchange'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan_templates_and_quotas',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['BattlePlanTemplate', 'BattlePlanQuotaSet', 'BattlePlanCondition'],
            'rules' => [
                'templates_and_quota_sets_are_separate_versioned_objects',
                'published_revisions_are_immutable',
                'delete_is_archive_revision',
                'weekly_plan_pins_exact_template_and_quota_revisions',
                'quota_metrics_reference_canonical_weekly_statistics',
                'metric_fact_is_append_only_canonical_statistic_observation',
                'missing_fact_is_not_zero',
                'legacy_battle_plan_quota_condition_delegates_to_central_indicator_condition_service',
                'legacy_battle_plan_power_band_is_preserved_only_for_pinned_quota_compatibility',
                'power_change_is_manual_only_and_requires_comment',
                'manual_condition_override_requires_comment_and_is_audited',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('battleplan_setup_' . $key, 'local_kbacompany');
    }

    private static function require_manage(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid, $actor);
        if (!task_service::can_manage($companyuuid, $actor)) { self::fail('permission'); }
    }

    private static function uuid(): string {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) { self::fail('invalid'); }
        return $value;
    }

    private static function number(array $data, string $key, float $default = 0.0): float {
        $raw = trim((string)($data[$key] ?? ''));
        if ($raw === '') { return $default; }
        $raw = str_replace(',', '.', $raw);
        if (!preg_match('/^-?\d{1,12}(\.\d{1,6})?$/D', $raw)) { self::fail('invalid'); }
        return (float)$raw;
    }

    private static function latest(string $table, string $companyuuid, string $uuid): \stdClass {
        global $DB;
        $rows = $DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid], 'revision DESC', '*', 0, 1);
        if (!$rows) { self::fail('reference'); }
        return reset($rows);
    }

    private static function exact(string $table, string $companyuuid, string $uuid, int $revision): ?\stdClass {
        global $DB;
        if ($uuid === '' || $revision <= 0) { return null; }
        $row = $DB->get_record($table, ['companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision]);
        return $row ?: null;
    }

    private static function decode(string $json): array {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function audit(string $companyuuid, string $uuid, int $actor, string $action, array $newvalue = []): void {
        global $DB;
        $DB->insert_record('local_kbacompany_aclog', (object)[
            'companyuuid' => $companyuuid, 'objectuuid' => $uuid, 'action' => $action,
            'actorid' => $actor, 'oldvalue' => '',
            'newvalue' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    private static function position_exists(string $companyuuid, string $positionuuid): bool {
        global $DB;
        return $positionuuid !== '' && $DB->record_exists('local_kbacompany_position', [
            'companyuuid' => $companyuuid, 'uuid' => $positionuuid, 'active' => 1,
        ]);
    }

    private static function template_payload(array $data): array {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $title = self::text($data, 'row_title_' . $i, 255, false);
            $product = self::text($data, 'row_product_' . $i, 4000, false);
            if ($title === '' && $product === '') { continue; }
            if ($title === '' || $product === '') { self::fail('template_row'); }
            $stream = self::text($data, 'row_stream_' . $i, 16, true);
            $priority = self::text($data, 'row_priority_' . $i, 10, false);
            if ($priority === '') { $priority = 'normal'; }
            if (!in_array($stream, task_service::STREAMS, true) || !in_array($priority, task_service::PRIORITIES, true)) {
                self::fail('template_row');
            }
            $minutes = max(0, min(10080, (int)($data['row_minutes_' . $i] ?? 0)));
            $day = self::text($data, 'row_day_' . $i, 40, false);
            $rows[] = [
                'stream' => $stream, 'title' => $title, 'product' => $product,
                'plan_minutes' => $minutes, 'day' => $day, 'priority' => $priority,
            ];
        }
        if (!$rows) { self::fail('template_empty'); }
        return ['rows' => $rows];
    }

    private static function norm_payload(string $companyuuid, array $data): array {
        global $DB;
        $metrics = [];
        for ($i = 1; $i <= 20; $i++) {
            $statuuid = self::text($data, 'metric_' . $i, 36, false);
            if ($statuuid === '') { continue; }
            $stat = $DB->get_record('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $statuuid]);
            if (!$stat || (string)$stat->periodtype !== 'weekly') { self::fail('metric'); }
            $quota = self::number($data, 'quota_' . $i, 0.0);
            $weight = self::number($data, 'weight_' . $i, 1.0);
            if ($weight <= 0 || $weight > 1000) { self::fail('invalid'); }
            $comparison = self::text($data, 'comparison_' . $i, 8, false);
            if ($comparison === '') { $comparison = (string)$stat->direction; }
            if (!in_array($comparison, ['up', 'down'], true)) { self::fail('comparison'); }
            $metrics[] = [
                'stat_uuid' => $statuuid, 'quota' => $quota, 'weight' => $weight,
                'critical' => !empty($data['critical_' . $i]) ? 1 : 0,
                'comparison' => $comparison,
            ];
        }
        if (!$metrics) { self::fail('norm_empty'); }
        $thresholds = [
            'danger_below' => self::number($data, 'danger_below', 70.0),
            'emergency_below' => self::number($data, 'emergency_below', 90.0),
            'normal_high' => self::number($data, 'normal_high', 110.0),
            'affluence_high' => self::number($data, 'affluence_high', 130.0),
            'danger_trend' => self::number($data, 'danger_trend', -20.0),
        ];
        if (!($thresholds['danger_below'] < $thresholds['emergency_below'] &&
                $thresholds['emergency_below'] <= $thresholds['normal_high'] &&
                $thresholds['normal_high'] < $thresholds['affluence_high'])) {
            self::fail('thresholds');
        }
        return ['metrics' => $metrics, 'thresholds' => $thresholds];
    }

    private static function save_versioned(string $table, string $companyuuid, int $actor, array $data,
            string $uuid, int $expected, array $payload): string {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $positionuuid = self::text($data, 'positionuuid', 36, true);
        if (!self::position_exists($companyuuid, $positionuuid)) { self::fail('position'); }
        $title = self::text($data, 'title', 255, true);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock($table . ':' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = null;
            if ($uuid !== '') {
                $old = self::latest($table, $companyuuid, $uuid);
                if ((int)$old->revision !== $expected || (string)$old->status === 'archived') { self::fail('conflict'); }
            } else if ($expected !== 0) { self::fail('conflict'); }
            $now = time();
            $row = (object)[
                'companyuuid' => $companyuuid, 'uuid' => $old ? (string)$old->uuid : self::uuid(),
                'revision' => $old ? ((int)$old->revision + 1) : 1, 'status' => 'draft',
                'positionuuid' => $positionuuid, 'title' => $title,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timecreated' => $now, 'timemodified' => $now,
            ];
            $DB->insert_record($table, $row);
            self::audit($companyuuid, (string)$row->uuid, $actor, 'save_' . $table, ['revision' => (int)$row->revision]);
            $tx->allow_commit();
            return (string)$row->uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function save_template(string $companyuuid, int $actor, array $data, string $uuid = '', int $expected = 0): string {
        return self::save_versioned('local_kbacompany_bptpl', $companyuuid, $actor, $data, $uuid, $expected,
            self::template_payload($data));
    }

    public static function save_norm(string $companyuuid, int $actor, array $data, string $uuid = '', int $expected = 0): string {
        return self::save_versioned('local_kbacompany_bpnorm', $companyuuid, $actor, $data, $uuid, $expected,
            self::norm_payload($companyuuid, $data));
    }

    private static function transition(string $table, string $companyuuid, int $actor, string $uuid, int $expected, string $target): void {
        global $DB;
        self::require_manage($companyuuid, $actor);
        if (!in_array($target, ['published', 'archived'], true)) { self::fail('state'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock($table . ':' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $old = self::latest($table, $companyuuid, $uuid);
            if ((int)$old->revision !== $expected || (string)$old->status === 'archived') { self::fail('conflict'); }
            if ($target === 'published' && (string)$old->status !== 'draft') { self::fail('state'); }
            $row = clone $old; unset($row->id);
            $row->revision = $expected + 1; $row->status = $target; $row->timemodified = time();
            $DB->insert_record($table, $row);
            self::audit($companyuuid, $uuid, $actor, $target . '_' . $table, ['revision' => (int)$row->revision]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function publish_template(string $companyuuid, int $actor, string $uuid, int $expected): void {
        self::transition('local_kbacompany_bptpl', $companyuuid, $actor, $uuid, $expected, 'published');
    }
    public static function archive_template(string $companyuuid, int $actor, string $uuid, int $expected): void {
        self::transition('local_kbacompany_bptpl', $companyuuid, $actor, $uuid, $expected, 'archived');
    }
    public static function publish_norm(string $companyuuid, int $actor, string $uuid, int $expected): void {
        self::transition('local_kbacompany_bpnorm', $companyuuid, $actor, $uuid, $expected, 'published');
    }
    public static function archive_norm(string $companyuuid, int $actor, string $uuid, int $expected): void {
        self::transition('local_kbacompany_bpnorm', $companyuuid, $actor, $uuid, $expected, 'archived');
    }

    private static function latest_objects(string $table, string $companyuuid): array {
        global $DB;
        $rows = $DB->get_records($table, ['companyuuid' => $companyuuid], 'uuid ASC, revision DESC');
        $out = [];
        foreach ($rows as $row) {
            if (isset($out[$row->uuid])) { continue; }
            $row->payload_data = self::decode((string)$row->payload);
            $out[$row->uuid] = $row;
        }
        return array_values($out);
    }

    public static function published_options(string $companyuuid, string $positionuuid): array {
        global $DB;
        $templates = []; $norms = [];
        foreach ([['local_kbacompany_bptpl', 'templates'], ['local_kbacompany_bpnorm', 'norms']] as $spec) {
            $table = $spec[0]; $bucket = $spec[1];
            $latest = [];
            foreach ($DB->get_records($table, ['companyuuid' => $companyuuid], 'uuid ASC, revision DESC') as $row) {
                if (!isset($latest[$row->uuid])) { $latest[$row->uuid] = $row; }
            }
            $published = [];
            foreach ($DB->get_records($table, ['companyuuid' => $companyuuid, 'status' => 'published'], 'uuid ASC, revision DESC') as $row) {
                if (isset($published[$row->uuid])) { continue; }
                if (isset($latest[$row->uuid]) && (string)$latest[$row->uuid]->status === 'archived') { continue; }
                if ((string)$row->positionuuid !== $positionuuid) { continue; }
                $published[(string)$row->uuid] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                    'title' => (string)$row->title];
            }
            if ($bucket === 'templates') { $templates = $published; } else { $norms = $published; }
        }
        return ['templates' => $templates, 'norms' => $norms];
    }

    public static function exact_template(string $companyuuid, string $uuid, int $revision): ?array {
        $row = self::exact('local_kbacompany_bptpl', $companyuuid, $uuid, $revision);
        if (!$row) { return null; }
        return ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision, 'status' => (string)$row->status,
            'position_uuid' => (string)$row->positionuuid, 'title' => (string)$row->title,
            'payload' => self::decode((string)$row->payload)];
    }

    public static function exact_norm(string $companyuuid, string $uuid, int $revision): ?array {
        $row = self::exact('local_kbacompany_bpnorm', $companyuuid, $uuid, $revision);
        if (!$row) { return null; }
        return ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision, 'status' => (string)$row->status,
            'position_uuid' => (string)$row->positionuuid, 'title' => (string)$row->title,
            'payload' => self::decode((string)$row->payload)];
    }

    private static function latest_fact(string $companyuuid, string $statuuid, string $periodstart): ?float {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_statval', [
            'companyuuid' => $companyuuid, 'statuuid' => $statuuid, 'periodstart' => $periodstart, 'kind' => 'fact',
        ], 'revision DESC', '*', 0, 1);
        if (!$rows) { return null; }
        return (float)reset($rows)->amount;
    }

    private static function performance(float $fact, float $quota, string $comparison): ?float {
        if ($comparison === 'up') {
            if ($quota == 0.0) { return $fact == 0.0 ? 100.0 : ($fact > 0 ? 200.0 : 0.0); }
            return ($fact / $quota) * 100.0;
        }
        if ($fact == 0.0) { return 200.0; }
        if ($quota == 0.0) { return $fact <= 0.0 ? 100.0 : 0.0; }
        return ($quota / $fact) * 100.0;
    }

    private static function trend(?float $previous, float $current, string $comparison): ?float {
        if ($previous === null || $previous == 0.0) { return null; }
        if ($comparison === 'down') { return (($previous - $current) / abs($previous)) * 100.0; }
        return (($current - $previous) / abs($previous)) * 100.0;
    }

    public static function automatic_condition(?float $previous, ?float $fact, float $quota, string $comparison,
            bool $critical, array $thresholds): array {
        // Compatibility only for already-published Battle Plan quota revisions.
        // Canonical v0.5.3 indicator conditions are calculated by indicator_condition_service::analyze().
        return indicator_condition_service::legacy_quota_condition(
            $previous, $fact, $quota, $comparison, $critical, $thresholds);
    }

    public static function plan_metrics(string $companyuuid, \stdClass $plan): array {
        global $DB;
        if ((string)$plan->normuuid === '' || (int)$plan->normrevision <= 0) { return []; }
        $norm = self::exact_norm($companyuuid, (string)$plan->normuuid, (int)$plan->normrevision);
        if (!$norm) { return []; }
        $statepayload = self::decode((string)($plan->statepayload ?? ''));
        $overrides = isset($statepayload['overrides']) && is_array($statepayload['overrides']) ? $statepayload['overrides'] : [];
        $previousweek = (new \DateTimeImmutable((string)$plan->periodstart, new \DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
        $out = [];
        foreach (($norm['payload']['metrics'] ?? []) as $item) {
            $statuuid = (string)($item['stat_uuid'] ?? '');
            $stat = $DB->get_record('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'uuid' => $statuuid]);
            if (!$stat) { continue; }
            $previous = self::latest_fact($companyuuid, $statuuid, $previousweek);
            $fact = self::latest_fact($companyuuid, $statuuid, (string)$plan->periodstart);
            $calc = self::automatic_condition($previous, $fact, (float)$item['quota'], (string)$item['comparison'],
                !empty($item['critical']), (array)($norm['payload']['thresholds'] ?? []));
            $override = isset($overrides[$statuuid]) && is_array($overrides[$statuuid]) ? $overrides[$statuuid] : [];
            $condition = !empty($override['condition']) ? (string)$override['condition'] : (string)$calc['condition'];
            $out[] = [
                'stat_uuid' => $statuuid, 'code' => (string)$stat->code, 'name' => (string)$stat->name,
                'unit' => (string)$stat->unit, 'quota' => (float)$item['quota'], 'weight' => (float)$item['weight'],
                'critical' => !empty($item['critical']), 'comparison' => (string)$item['comparison'],
                'previous_fact' => $previous, 'fact' => $fact, 'performance' => $calc['performance'], 'trend' => $calc['trend'],
                'automatic_condition' => (string)$calc['condition'], 'condition' => $condition,
                'override_comment' => (string)($override['comment'] ?? ''),
            ];
        }
        return $out;
    }

    public static function overall_condition(array $metrics, array $thresholds = []): array {
        $weighted = 0.0; $weights = 0.0; $allpower = true; $count = 0;
        foreach ($metrics as $metric) {
            if ($metric['performance'] === null) { continue; }
            $weight = (float)$metric['weight'];
            $weighted += (float)$metric['performance'] * $weight; $weights += $weight; $count++;
            if ((string)($metric['automatic_condition'] ?? '') !== 'power') { $allpower = false; }
        }
        if ($weights <= 0.0) { return ['performance' => null, 'condition' => '']; }
        $performance = $weighted / $weights;
        $danger = (float)($thresholds['danger_below'] ?? 70.0);
        $emergency = (float)($thresholds['emergency_below'] ?? 90.0);
        $normalhigh = (float)($thresholds['normal_high'] ?? 110.0);
        $affluencehigh = (float)($thresholds['affluence_high'] ?? 130.0);
        if ($performance < $danger) { $condition = 'danger'; }
        else if ($performance < $emergency) { $condition = 'emergency'; }
        else if ($performance <= $normalhigh) { $condition = 'normal'; }
        else if ($performance < $affluencehigh) { $condition = 'affluence'; }
        else { $condition = ($count > 0 && $allpower) ? 'power' : 'affluence'; }
        return ['performance' => $performance, 'condition' => $condition];
    }

    public static function record_fact(string $companyuuid, int $actor, string $planuuid, string $statuuid,
            string $amount, string $reason = ''): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $plan = $DB->get_record('local_kbacompany_bplan', ['companyuuid' => $companyuuid, 'uuid' => $planuuid], '*', MUST_EXIST);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        if (!$canmanage && (int)$plan->userid !== $actor) { self::fail('permission'); }
        if ((string)$plan->status === 'closed') { self::fail('state'); }
        $metrics = self::plan_metrics($companyuuid, $plan);
        $allowed = false;
        foreach ($metrics as $metric) { if ($metric['stat_uuid'] === $statuuid) { $allowed = true; break; } }
        if (!$allowed) { self::fail('metric'); }
        $value = str_replace(',', '.', trim($amount));
        if (!preg_match('/^-?\d{1,12}(\.\d{1,6})?$/D', $value) || \core_text::strlen($reason) > 4000) { self::fail('invalid'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('statistic:' . $statuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $conditions = ['companyuuid' => $companyuuid, 'statuuid' => $statuuid,
                'periodstart' => (string)$plan->periodstart, 'kind' => 'fact'];
            $previous = $DB->get_records('local_kbacompany_statval', $conditions, 'revision DESC', '*', 0, 1);
            $previous = $previous ? reset($previous) : null;
            if ($previous && trim($reason) === '') { self::fail('correctionreason'); }
            $DB->insert_record('local_kbacompany_statval', (object)array_merge($conditions, [
                'amount' => $value, 'revision' => $previous ? ((int)$previous->revision + 1) : 1,
                'reason' => trim($reason), 'writerid' => $actor, 'timecreated' => time(),
            ]));
            self::audit($companyuuid, $planuuid, $actor, 'battleplan_metric_fact', ['stat_uuid' => $statuuid, 'amount' => $value]);
        } finally { $lock->release(); }
    }

    public static function set_condition_override(string $companyuuid, int $actor, string $planuuid, string $statuuid,
            string $condition, string $comment): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($condition !== '' && !in_array($condition, self::CONDITIONS, true)) { self::fail('invalid'); }
        $comment = trim($comment);
        if ($condition !== '' && $comment === '') { self::fail('overridecomment'); }
        if (\core_text::strlen($comment) > 4000) { self::fail('invalid'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('battleplan_state:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $plan = $DB->get_record('local_kbacompany_bplan', ['companyuuid' => $companyuuid, 'uuid' => $planuuid], '*', MUST_EXIST);
            $canmanage = task_service::can_manage($companyuuid, $actor);
            if (!$canmanage && (int)$plan->reviewerid !== $actor) { self::fail('permission'); }
            if ((string)$plan->status === 'closed') { self::fail('state'); }
            $metrics = self::plan_metrics($companyuuid, $plan); $allowed = false;
            foreach ($metrics as $metric) { if ($metric['stat_uuid'] === $statuuid) { $allowed = true; break; } }
            if (!$allowed) { self::fail('metric'); }
            $payload = self::decode((string)($plan->statepayload ?? ''));
            if (!isset($payload['overrides']) || !is_array($payload['overrides'])) { $payload['overrides'] = []; }
            if ($condition === '') { unset($payload['overrides'][$statuuid]); }
            else { $payload['overrides'][$statuuid] = ['condition' => $condition, 'comment' => $comment]; }
            $plan->statepayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $plan->timemodified = time();
            $DB->update_record('local_kbacompany_bplan', $plan);
            self::audit($companyuuid, $planuuid, $actor, 'battleplan_condition_override', [
                'stat_uuid' => $statuuid, 'condition' => $condition, 'comment' => $comment,
            ]);
        } finally { $lock->release(); }
    }

    public static function context(string $companyuuid, int $actor, string $kind = 'template', string $selected = ''): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1], 'name ASC') as $row) {
            $positions[(string)$row->uuid] = (string)$row->name;
        }
        $stats = [];
        foreach ($DB->get_records('local_kbacompany_stat', ['companyuuid' => $companyuuid, 'periodtype' => 'weekly'], 'code ASC') as $row) {
            $stats[(string)$row->uuid] = ['uuid' => (string)$row->uuid, 'code' => (string)$row->code,
                'name' => (string)$row->name, 'unit' => (string)$row->unit, 'direction' => (string)$row->direction];
        }
        $table = $kind === 'norm' ? 'local_kbacompany_bpnorm' : 'local_kbacompany_bptpl';
        $objects = self::latest_objects($table, $companyuuid);
        $current = null;
        foreach ($objects as $row) { if ((string)$row->uuid === $selected) { $current = $row; break; } }
        $history = [];
        if ($current) { $history = array_values($DB->get_records($table, ['companyuuid' => $companyuuid, 'uuid' => $current->uuid], 'revision DESC')); }
        return ['positions' => $positions, 'statistics' => $stats, 'objects' => $objects, 'selected' => $current, 'history' => $history];
    }
}
