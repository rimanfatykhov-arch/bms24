<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Position-indicator projection for one weekly Battle Plan.
 *
 * Boundaries:
 * - indicator definitions stay in local_kbacompany_stat;
 * - facts stay append-only in local_kbacompany_statval;
 * - position ownership is read from canonical indicator bindings;
 * - norms, trends, conditions and manager confirmations are resolved on-read;
 * - canonical Task remains the only execution object.
 */
class battle_plan_indicator_service {
    public const CONTRACT_VERSION = '0.5.10';

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan_indicator_projection',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'canonical_indicator' => 'local_kbacompany_stat',
            'canonical_history' => 'local_kbacompany_statval',
            'canonical_task' => 'local_kbacompany_task',
            'rules' => [
                'battle_plan_projects_position_bound_indicators_without_copying',
                'current_week_fact_appends_to_canonical_statval',
                'norm_is_resolved_for_binding_and_plan_period',
                'trend_and_condition_are_historical_read_models_as_of_plan_period',
                'manager_confirmation_overrides_automatic_condition_only_for_same_fact_period',
                'condition_execution_links_to_canonical_task_without_copying',
                'management_action_from_indicator_row_uses_historical_plan_snapshot',
                'task_control_reads_canonical_task_status_result_and_checklist_progress',
                'next_week_compares_fact_after_action_without_claiming_causality',
                'weekly_battle_plan_shows_weekly_and_daily_position_indicators',
                'daily_values_are_summed_into_read_only_weekly_projection',
                'period_plan_precedes_effective_norm_for_performance_status',
                'performance_status_is_derived_read_only_without_copying_metric_data',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('battleplan_indicator_' . $key, 'local_kbacompany');
    }

    /** Latest canonical plan/fact series ending at the selected Battle Plan period. */
    private static function series_until(string $companyuuid, string $statuuid, string $period, int $limit = 52): array {
        global $DB;
        $dates = $DB->get_records_sql('SELECT DISTINCT periodstart FROM {local_kbacompany_statval}
            WHERE companyuuid = :company AND statuuid = :stat AND periodstart <= :period
            ORDER BY periodstart DESC', [
                'company' => $companyuuid, 'stat' => $statuuid, 'period' => $period,
            ], 0, $limit);
        if (!$dates) { return []; }
        $last = end($dates);
        $history = $DB->get_records_select('local_kbacompany_statval',
            'companyuuid = :company AND statuuid = :stat AND periodstart >= :firstperiod AND periodstart <= :period',
            [
                'company' => $companyuuid, 'stat' => $statuuid,
                'firstperiod' => (string)$last->periodstart, 'period' => $period,
            ], 'periodstart ASC, kind ASC, revision ASC');
        return product_service::series($history);
    }

    /** Sum daily observations for a calendar week without creating weekly statistic copies.
     * Missing days remain missing; partial sums are never presented as a complete week.
     */
    private static function daily_week_values(string $companyuuid, string $statuuid, string $week): array {
        global $DB;
        $end = (new \DateTimeImmutable($week, new \DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
        $history = $DB->get_records_select('local_kbacompany_statval',
            'companyuuid = :company AND statuuid = :stat AND periodstart >= :start AND periodstart < :end',
            ['company'=>$companyuuid, 'stat'=>$statuuid, 'start'=>$week, 'end'=>$end],
            'periodstart ASC, kind ASC, revision ASC');
        $series = product_service::series($history);
        $sums = ['plan'=>0.0, 'fact'=>0.0];
        $counts = ['plan'=>0, 'fact'=>0];
        $matchedplan = 0.0;
        $matchedfact = 0.0;
        $matcheddays = 0;
        $factwithoutplan = false;
        foreach ($series as $entry) {
            foreach (['plan','fact'] as $kind) {
                if ($entry[$kind] !== null) { $sums[$kind] += (float)$entry[$kind]; $counts[$kind]++; }
            }
            if ($entry['fact'] !== null) {
                if ($entry['plan'] === null) {
                    $factwithoutplan = true;
                } else {
                    $matchedfact += (float)$entry['fact'];
                    $matchedplan += (float)$entry['plan'];
                    $matcheddays++;
                }
            }
        }
        // Comparable progress is calculated on the SAME observed dates only.
        // A planned day with no actual is NOT counted as zero and is not silently assessed.
        return [
            'plan'=>$counts['plan'] ? $sums['plan'] : null,
            'fact'=>$counts['fact'] ? $sums['fact'] : null,
            'fact_days'=>$counts['fact'], 'plan_days'=>$counts['plan'],
            'days'=>$series,
            'comparable'=>($counts['fact'] > 0 && !$factwithoutplan),
            'comparable_plan'=>($counts['fact'] > 0 && !$factwithoutplan ? $matchedplan : null),
            'comparable_fact'=>($counts['fact'] > 0 && !$factwithoutplan ? $matchedfact : null),
            'comparable_days'=>$matcheddays,
            'aligned'=>($counts['fact'] > 0 && $counts['plan'] > 0 &&
                count(array_filter($series, static function(array $entry): bool {
                    return ($entry['fact'] !== null) !== ($entry['plan'] !== null);
                })) === 0),
        ];
    }

    private static function fact_for_period(array $series, string $period): ?float {
        foreach ($series as $row) {
            if ((string)($row['period'] ?? '') !== $period) { continue; }
            if (array_key_exists('fact', $row) && $row['fact'] !== null) { return (float)$row['fact']; }
            return null;
        }
        return null;
    }

    private static function previous_week(string $period): string {
        return (new \DateTimeImmutable($period, new \DateTimeZone('UTC')))
            ->modify('-7 days')->format('Y-m-d');
    }

    private static function norm_label(?array $norm): string {
        if (!$norm) { return ''; }
        if ((string)($norm['normkind'] ?? '') === 'range') {
            return (string)$norm['minval'] . ' — ' . (string)$norm['maxval'];
        }
        return (string)($norm['targetvalue'] ?? '');
    }

    /** Resolve canonical position-binding revisions as they existed by the end of the selected week. */
    private static function position_bindings_for_period(string $companyuuid, string $positionuuid,
            string $periodstart, array $contextbindings): array {
        global $DB;
        $end = (new \DateTimeImmutable($periodstart, new \DateTimeZone('UTC')))->modify('+7 days')->getTimestamp();
        $versions = $DB->get_records('local_kbacompany_statbind', [
            'companyuuid' => $companyuuid, 'contextkind' => 'position', 'contextuuid' => $positionuuid,
        ], 'uuid ASC, revision ASC, id ASC');
        $grouped = [];
        foreach ($versions as $row) {
            if ((int)$row->timecreated > 0 && (int)$row->timecreated >= $end) { continue; }
            $grouped[(string)$row->uuid][] = $row;
        }
        $out = [];
        $canonicalstats = [];
        foreach ($grouped as $rows) {
            $latest = end($rows);
            if ((string)$latest->status !== 'active') { continue; }
            $arr = (array)$latest;
            $arr['compatibility'] = false;
            $out[] = $arr;
            $canonicalstats[(string)$latest->statuuid] = true;
        }
        // Pre-0.5 compatibility links have no revision history. Keep them only as a current fallback
        // when no canonical position binding exists for the same indicator.
        foreach ($contextbindings as $binding) {
            if (empty($binding['compatibility']) || (string)($binding['contextkind'] ?? '') !== 'position' ||
                    (string)($binding['contextuuid'] ?? '') !== $positionuuid ||
                    isset($canonicalstats[(string)($binding['statuuid'] ?? '')])) { continue; }
            $out[] = $binding;
        }
        return $out;
    }

    /** Latest manager decision for exactly the analytical fact period and binding revision. */
    private static function latest_decision(string $companyuuid, string $binduuid, int $bindrevision,
            string $period): ?\stdClass {
        global $DB;
        if ($period === '' || $binduuid === '' || strpos($binduuid, 'compat-') === 0) { return null; }
        $rows = $DB->get_records('local_kbacompany_stateevt', [
            'companyuuid' => $companyuuid, 'binduuid' => $binduuid, 'bindrevision' => $bindrevision, 'periodkey' => $period,
        ], 'timecreated DESC, id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /** Canonical Task connected to the selected decision, respecting task ACL. */
    private static function decision_task(string $companyuuid, int $actor, ?\stdClass $decision,
            string $planuuid): array {
        global $DB;
        $base = ['exists' => false, 'visible' => false, 'uuid' => '', 'title' => '', 'status' => '',
            'in_plan' => false, 'url' => '', 'due_date' => '', 'result' => '', 'completed_at' => 0,
            'checklist_done' => 0, 'checklist_total' => 0, 'checklist_percent' => null];
        if (!$decision) { return $base; }
        $tasks = $DB->get_records('local_kbacompany_task', [
            'companyuuid' => $companyuuid, 'sourcekind' => 'condition', 'sourceuuid' => (string)$decision->uuid,
        ], 'id ASC');
        foreach ($tasks as $task) {
            if ((string)$task->status === 'deleted') { continue; }
            $base['exists'] = true;
            if (!task_service::can_view_task($companyuuid, $actor, (string)$task->uuid)) {
                // Existence may be used to avoid duplicate execution, but protected task details stay hidden.
                continue;
            }
            $base['visible'] = true;
            $base['uuid'] = (string)$task->uuid;
            $base['title'] = (string)$task->title;
            $base['status'] = (string)$task->status;
            $base['due_date'] = (string)$task->duedate;
            $base['result'] = (string)$task->result;
            $base['completed_at'] = (int)$task->completedat;
            $base['url'] = (new \moodle_url('/local/kbacompany/tasks.php', [
                'uuid' => $companyuuid, 'task' => (string)$task->uuid,
            ]))->out(false);
            if ($planuuid !== '') {
                $base['in_plan'] = $DB->record_exists('local_kbacompany_bplanitem', [
                    'companyuuid' => $companyuuid, 'planuuid' => $planuuid,
                    'provider' => 'bms', 'externalid' => (string)$task->uuid,
                ]);
            }
            $base['checklist_total'] = $DB->count_records('local_kbacompany_taskcheck', [
                'companyuuid' => $companyuuid, 'taskuuid' => (string)$task->uuid,
            ]);
            $base['checklist_done'] = $DB->count_records('local_kbacompany_taskcheck', [
                'companyuuid' => $companyuuid, 'taskuuid' => (string)$task->uuid, 'done' => 1,
            ]);
            if ($base['checklist_total'] > 0) {
                $base['checklist_percent'] = ($base['checklist_done'] / $base['checklist_total']) * 100;
            }
            break;
        }
        return $base;
    }

    /** Latest management decision with canonical execution for one exact historical week. */
    private static function decision_for_period(string $companyuuid, string $binduuid, string $period): ?\stdClass {
        global $DB;
        if ($binduuid === '' || strpos($binduuid, 'compat-') === 0 || $period === '') { return null; }
        $rows = $DB->get_records('local_kbacompany_stateevt', [
            'companyuuid' => $companyuuid, 'binduuid' => $binduuid, 'periodkey' => $period,
        ], 'timecreated DESC, id DESC');
        foreach ($rows as $row) {
            if ($DB->record_exists_select('local_kbacompany_task',
                    'companyuuid = :company AND sourcekind = :kind AND sourceuuid = :source AND status <> :deleted', [
                        'company' => $companyuuid, 'kind' => 'condition', 'source' => (string)$row->uuid, 'deleted' => 'deleted',
                    ])) {
                return $row;
            }
        }
        return null;
    }

    /** Exact next-week observation after a management action. No causality is inferred. */
    private static function post_action_observation(string $companyuuid, string $statuuid, string $direction,
            string $actionperiod, string $selectedperiod): array {
        $base = [
            'action_period' => $actionperiod, 'followup_period' => '', 'baseline_fact' => null,
            'followup_fact' => null, 'change' => 'insufficient', 'quality' => 'insufficient',
        ];
        if ($actionperiod === '') { return $base; }
        $followup = (new \DateTimeImmutable($actionperiod, new \DateTimeZone('UTC')))
            ->modify('+7 days')->format('Y-m-d');
        $base['followup_period'] = $followup;
        if ($followup > $selectedperiod) { return $base; }
        $series = self::series_until($companyuuid, $statuuid, $followup, 2);
        $baseline = self::fact_for_period($series, $actionperiod);
        $after = self::fact_for_period($series, $followup);
        $base['baseline_fact'] = $baseline;
        $base['followup_fact'] = $after;
        if ($baseline === null || $after === null) { return $base; }
        $change = indicator_trend_service::compare($baseline, $after);
        $quality = 'neutral';
        if ($change === 'flat') {
            $quality = 'unchanged';
        } else if ($direction === 'up') {
            $quality = $change === 'up' ? 'improving' : 'worsening';
        } else if ($direction === 'down') {
            $quality = $change === 'down' ? 'improving' : 'worsening';
        }
        $base['change'] = $change;
        $base['quality'] = $quality;
        return $base;
    }

    /**
     * Read weekly canonical indicators bound to one position as of one Battle Plan week.
     */
    public static function projection(string $companyuuid, int $actor, string $positionuuid,
            string $periodstart, string $planuuid = ''): array {
        company_context_service::context($companyuuid, $actor);
        $periodstart = battle_plan_service::week_start($periodstart);
        $previousperiod = self::previous_week($periodstart);
        $ctx = indicator_registry_service::context($companyuuid, $actor);
        $metricmap = [];
        foreach ($ctx['metrics'] as $metric) { $metricmap[(string)$metric['uuid']] = $metric; }

        $rows = [];
        $positionbindings = self::position_bindings_for_period($companyuuid, $positionuuid, $periodstart,
            $ctx['bindings']);
        foreach ($positionbindings as $binding) {
            $metric = $metricmap[(string)($binding['statuuid'] ?? '')] ?? null;
            if (!$metric || !in_array((string)$metric['periodtype'], ['weekly', 'daily'], true)) { continue; }
            if ((string)$metric['periodtype'] === 'daily') {
                $statuuid = (string)$metric['uuid'];
                $now = self::daily_week_values($companyuuid, $statuuid, $periodstart);
                $before = self::daily_week_values($companyuuid, $statuuid, $previousperiod);
                $fact = $now['fact'];
                $planvalue = $now['plan'];
                // Day-level norm is not a weekly target; do not multiply it automatically.
                $performance = ['status'=>'nodata','variance'=>null,'variance_percent'=>null,'source'=>''];
                // Partial evaluation uses the target for exactly the observed dates.
                // The full weekly target remains read-only and distinct from this comparison.
                if ($now['comparable']) {
                    $performance = indicator_performance_status_service::assess(
                        $now['comparable_fact'], $now['comparable_plan'], null, (string)$metric['direction'],
                        !empty($binding['critical']), 'insufficient');
                }
                $rows[] = [
                    'bind_uuid'=>(string)$binding['uuid'], 'bind_revision'=>(int)($binding['revision']??0),
                    'compatibility'=>!empty($binding['compatibility']),
                    'stat_uuid'=>$statuuid, 'code'=>(string)$metric['code'],
                    'name'=>(string)$metric['name'], 'unit'=>(string)$metric['unit'],
                    'direction'=>(string)$metric['direction'], 'critical'=>!empty($binding['critical']),
                    'weight'=>(float)($binding['weight']??1), 'sort_order'=>(int)($binding['sortorder']??0),
                    'previous_fact'=>$before['fact'], 'previous_fact_days'=>$before['fact_days'],
                    'current_plan'=>$planvalue,
                    'current_fact'=>$fact, 'performance'=>$performance, 'norm'=>null, 'norm_label'=>'',
                    'trend'=>['movement'=>'insufficient','quality'=>'insufficient'],
                    'automatic_condition'=>'', 'condition_reason'=>'insufficient',
                    'condition_period'=>'', 'decision_period'=>$periodstart, 'condition'=>'',
                    'condition_confirmed'=>false, 'decision_uuid'=>'', 'decision_comment'=>'',
                    'task'=>['exists'=>false,'visible'=>false], 'previous_action'=>['exists'=>false],
                    'daily_aggregate'=>true, 'fact_days'=>$now['fact_days'], 'plan_days'=>$now['plan_days'],
                    'daily_days'=>$now['days'], 'daily_aligned'=>$now['aligned'],
                    'daily_comparable'=>$now['comparable'],
                    'comparison_plan'=>$now['comparable_plan'],
                    'comparison_fact'=>$now['comparable_fact'],
                    'comparison_days'=>$now['comparable_days'],
                    'indicator_url'=>(new \moodle_url('/local/kbacompany/indicators.php',
                        ['uuid'=>$companyuuid,'stat'=>$statuuid]))->out(false),
                ];
                continue;
            }
            $statuuid = (string)$metric['uuid'];
            $binduuid = (string)$binding['uuid'];
            $series = self::series_until($companyuuid, $statuuid, $periodstart, 52);
            $trend = indicator_trend_service::analyze($series, (string)$metric['direction']);
            $norm = indicator_event_service::norm_for_period($ctx['normhistory'], $binduuid, $periodstart);
            $periodvalues = indicator_performance_status_service::period_values($series, $periodstart);
            $performance = indicator_performance_status_service::assess(
                $periodvalues['fact'], $periodvalues['plan'], $norm, (string)$metric['direction'],
                !empty($binding['critical']), (string)($trend['quality'] ?? 'insufficient')
            );
            $condition = ['condition'=>'', 'source'=>'undetermined', 'reason'=>'binding_required', 'norm'=>null];
            if (empty($binding['compatibility'])) {
                $condition = indicator_condition_service::analyze($series, (string)$metric['direction'],
                    $ctx['normhistory'], $ctx['condition_policy_history'], $binduuid);
            }
            $facts = indicator_trend_service::facts($series);
            $conditionperiod = $facts ? (string)$facts[count($facts) - 1]['period'] : '';
            // Governance needs an explicit period even when no fact exists yet. When analytics has a fact,
            // the manager decision belongs to that exact fact period; otherwise it belongs to the Battle Plan week.
            $decisionperiod = $conditionperiod !== '' ? $conditionperiod : $periodstart;
            $decision = self::latest_decision($companyuuid, $binduuid, (int)($binding['revision'] ?? 0), $decisionperiod);
            $effectivecondition = (string)($condition['condition'] ?? '');
            $confirmed = false;
            $decisionuuid = '';
            if ($decision) {
                $effectivecondition = (string)$decision->conditioncode;
                $confirmed = true;
                $decisionuuid = (string)$decision->uuid;
            }
            $task = self::decision_task($companyuuid, $actor, $decision, $planuuid);
            $previousdecision = self::decision_for_period($companyuuid, $binduuid, $previousperiod);
            $previousactiontask = self::decision_task($companyuuid, $actor, $previousdecision, $planuuid);
            $previousaction = [
                'exists' => $previousdecision !== null,
                'event_uuid' => $previousdecision ? (string)$previousdecision->uuid : '',
                'condition' => $previousdecision ? (string)$previousdecision->conditioncode : '',
                'comment' => $previousdecision ? (string)$previousdecision->comment : '',
                'task' => $previousactiontask,
                'observation' => $previousdecision ? self::post_action_observation($companyuuid, $statuuid,
                    (string)$metric['direction'], (string)$previousdecision->periodkey, $periodstart) :
                    self::post_action_observation($companyuuid, $statuuid, (string)$metric['direction'], '', $periodstart),
            ];
            $rows[] = [
                'bind_uuid' => $binduuid,
                'bind_revision' => (int)($binding['revision'] ?? 0),
                'compatibility' => !empty($binding['compatibility']),
                'stat_uuid' => $statuuid,
                'code' => (string)$metric['code'],
                'name' => (string)$metric['name'],
                'unit' => (string)$metric['unit'],
                'direction' => (string)$metric['direction'],
                'critical' => !empty($binding['critical']),
                'weight' => (float)($binding['weight'] ?? 1),
                'sort_order' => (int)($binding['sortorder'] ?? 0),
                'previous_fact' => self::fact_for_period($series, $previousperiod),
                'current_plan' => $periodvalues['plan'],
                'current_fact' => $periodvalues['fact'],
                'performance' => $performance,
                'norm' => $norm,
                'norm_label' => self::norm_label($norm),
                'trend' => $trend,
                'automatic_condition' => (string)($condition['condition'] ?? ''),
                'condition_reason' => (string)($condition['reason'] ?? 'insufficient'),
                'condition_period' => $conditionperiod,
                'decision_period' => $decisionperiod,
                'condition' => $effectivecondition,
                'condition_confirmed' => $confirmed,
                'decision_uuid' => $decisionuuid,
                'decision_comment' => $decision ? (string)$decision->comment : '',
                'task' => $task,
                'previous_action' => $previousaction,
                'indicator_url' => (new \moodle_url('/local/kbacompany/indicators.php', [
                    'uuid' => $companyuuid, 'stat' => $statuuid,
                ]))->out(false),
            ];
        }
        usort($rows, static function(array $a, array $b): int {
            if ($a['sort_order'] !== $b['sort_order']) { return $a['sort_order'] <=> $b['sort_order']; }
            if ($a['critical'] !== $b['critical']) { return $a['critical'] ? -1 : 1; }
            return strcmp($a['code'], $b['code']);
        });
        return $rows;
    }

    /** Enter a daily or weekly fact from the employee's Battle Plan, never creating a separate metric. */
    public static function record_fact(string $companyuuid, int $actor, string $planuuid,
            string $binduuid, string $amount, string $reason = '', string $factdate = ''): int {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $data = battle_plan_service::context($companyuuid, $actor, $planuuid,
            0, '', '', ['load_plans'=>false, 'load_sources'=>false]);
        $plan = $data['selected'] ?? null;
        if (!$plan || empty($data['selected_can_edit'])) { self::fail('permission'); }
        if ($plan['status'] === 'closed') { self::fail('state'); }
        $metric = null;
        foreach (($data['position_indicators'] ?? []) as $row) {
            if ((string)$row['bind_uuid'] === $binduuid) { $metric = $row; break; }
        }
        if (!$metric) { self::fail('binding'); }
        $date = $factdate !== '' ? $factdate : (string)$plan['period_start'];
        if (empty($metric['daily_aggregate']) && $date !== $plan['period_start']) {
            self::fail('invalid');
        }
        if (!empty($metric['daily_aggregate']) &&
                ($date < $plan['period_start'] || $date > $plan['period_end'])) {
            self::fail('invalid');
        }
        $value = str_replace(',', '.', trim($amount));
        if (!preg_match('/^-?\d{1,12}(\.\d{1,6})?$/D', $value)) { self::fail('invalid'); }
        // Same append-only writer, lock, active-metric check and correction rules as
        // the canonical metric registry. Plan scope is rechecked at the write boundary.
        try {
            return product_service::record_value($companyuuid, $actor, (string)$metric['stat_uuid'],
                $date, 'fact', $value, trim($reason), '', $planuuid);
        } catch (\moodle_exception $e) {
            // Preserve the established Battle Plan error contract for legacy callers.
            if ($e->errorcode === 'productcorrectionreason') { self::fail('correctionreason'); }
            throw $e;
        }
    }
    /** Canonical decimal equality without floating-point rounding of 12-digit amounts. */
    private static function same_decimal(string $a, string $b): bool {
        $normalise = static function(string $number): string {
            $negative = isset($number[0]) && $number[0] === '-';
            if ($negative) { $number = substr($number, 1); }
            $parts = explode('.', $number, 2);
            $integer = ltrim($parts[0], '0');
            if ($integer === '') { $integer = '0'; }
            $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';
            $nonzero = $integer !== '0' || $fraction !== '';
            return ($negative && $nonzero ? '-' : '') . $integer .
                ($fraction !== '' ? '.' . $fraction : '');
        };
        return $normalise($a) === $normalise($b);
    }

    /** Assign a weekly target or several daily targets to the canonical append-only plan history. */
    public static function record_plan(string $companyuuid, int $actor, string $planuuid,
            string $binduuid, array $dayamounts, string $reason = ''): int {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!indicator_registry_service::can_manage($companyuuid, $actor)) {
            self::fail('permission');
        }
        $data = battle_plan_service::context($companyuuid, $actor, $planuuid,
            0, '', '', ['load_plans'=>false, 'load_sources'=>false]);
        $plan = $data['selected'] ?? null;
        if (!$plan) { self::fail('permission'); }
        if ($plan['status'] === 'closed') { self::fail('state'); }
        $metric = null;
        foreach (($data['position_indicators'] ?? []) as $candidate) {
            if ((string)$candidate['bind_uuid'] === $binduuid) { $metric = $candidate; break; }
        }
        if (!$metric) { self::fail('binding'); }
        $daily = !empty($metric['daily_aggregate']);
        if (count($dayamounts) > ($daily ? 7 : 1)) { self::fail('invalid'); }
        $start = new \DateTimeImmutable($plan['period_start'], new \DateTimeZone('UTC'));
        $allowed = [];
        for ($d = 0; $d < ($daily ? 7 : 1); $d++) {
            $allowed[$start->modify('+' . $d . ' days')->format('Y-m-d')] = true;
        }
        $parsed = [];
        foreach ($dayamounts as $day => $value) {
            if (!is_string($day) || !isset($allowed[$day]) || !is_scalar($value)) { self::fail('invalid'); }
            $number = str_replace(',', '.', trim((string)$value));
            // Blank means 'leave as is', never delete a previously approved target.
            if ($number === '') { continue; }
            if (!preg_match('/^-?\d{1,12}(\.\d{1,6})?$/D', $number)) { self::fail('invalid'); }
            $parsed[$day] = $number;
        }
        if (!$parsed || \core_text::strlen($reason) > 3600) { self::fail('invalid'); }
        // Validate existing revisions before the first write. If any write fails,
        // the delegated transaction rolls back every change to this week's target.
        $tx = $DB->start_delegated_transaction();
        try {
            $changes = [];
            foreach ($parsed as $day => $amount) {
                $rows = $DB->get_records('local_kbacompany_statval', [
                    'companyuuid'=>$companyuuid, 'statuuid'=>$metric['stat_uuid'],
                    'periodstart'=>$day, 'kind'=>'plan',
                ], 'revision DESC, id DESC', '*', 0, 1);
                $latest = $rows ? reset($rows) : null;
                if ($latest && self::same_decimal((string)$latest->amount, $amount)) { continue; }
                if ($latest && trim($reason) === '') { self::fail('correctionreason'); }
                $changes[$day] = $amount;
            }
            foreach ($changes as $day => $amount) {
                // Canonical writer still validates permission, activity, precision,
                // period and revision under its metric-level lock.
                product_service::record_value($companyuuid, $actor, (string)$metric['stat_uuid'],
                    $day, 'plan', $amount, $reason);
            }
            $tx->allow_commit();
            return count($changes);
        } catch (\Throwable $e) {
            $tx->rollback($e);
        }
    }

}
