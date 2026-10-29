<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Management-exception read model over the canonical indicator and Task layers.
 *
 * This service stores nothing. It combines already-existing facts, norms, trend/event
 * analytics, management-condition analytics, immutable manager confirmations and
 * canonical Tasks into a manager-oriented attention queue.
 */
class indicator_management_exception_service {
    public const SEVERITIES = ['critical', 'high', 'medium'];
    public const GROUPS = ['condition', 'norm', 'trend', 'data', 'execution', 'configuration', 'event'];

    public static function contract(): array {
        return [
            'version' => '0.5.9',
            'kind' => 'read_model',
            'storage' => 'none',
            'sources' => [
                'indicator' => 'local_kbacompany_stat',
                'history' => 'local_kbacompany_statval',
                'binding' => 'local_kbacompany_statbind',
                'norm' => 'local_kbacompany_statnorm',
                'condition_policy' => 'local_kbacompany_statpolicy',
                'manager_decision' => 'local_kbacompany_stateevt',
                'execution' => 'local_kbacompany_task',
                'management_cycle' => 'indicator_management_cycle_service read-model',
            ],
            'rules' => [
                'one_exception_row_per_current_indicator_binding',
                'multiple_attention_reasons_are_aggregated_not_duplicated',
                'no_hidden_percentage_or_age_thresholds',
                'task_acl_is_not_bypassed',
                'no_exception_table_or_parallel_incident_registry',
                'management_cycle_reuses_stateevt_task_battle_plan_and_statval',
            ],
        ];
    }

    /** Deterministic severity rank for sorting and aggregation. */
    public static function severity_rank(string $severity): int {
        $map = ['critical' => 3, 'high' => 2, 'medium' => 1];
        return $map[$severity] ?? 0;
    }

    /** Whether a derived event is adverse for the indicator direction. */
    public static function adverse_event(string $eventtype, string $direction): bool {
        if ($direction === 'up') {
            return in_array($eventtype, ['reversal_down', 'new_min'], true);
        }
        if ($direction === 'down') {
            return in_array($eventtype, ['reversal_up', 'new_max'], true);
        }
        return false;
    }

    /** Add a reason once, preserving explicit management semantics. */
    private static function add_reason(array &$reasons, string $code, string $severity, string $group): void {
        foreach ($reasons as $reason) {
            if ((string)$reason['code'] === $code) { return; }
        }
        $reasons[] = ['code' => $code, 'severity' => $severity, 'group' => $group];
    }

    /**
     * Pure rules for one binding snapshot. No percentage or age threshold is hidden here.
     * All numeric interpretation remains inside the explicitly versioned condition policy.
     */
    public static function build_reasons(array $snapshot): array {
        $reasons = [];
        $critical = !empty($snapshot['critical']);
        $hasfact = !empty($snapshot['has_fact']);
        $hasnorm = !empty($snapshot['has_norm']);
        $haspolicy = !empty($snapshot['has_policy']);
        $condition = (string)($snapshot['condition'] ?? '');
        $trendquality = (string)($snapshot['trend_quality'] ?? '');
        $direction = (string)($snapshot['direction'] ?? '');
        $confirmed = !empty($snapshot['confirmed']);
        $taskcount = max(0, (int)($snapshot['task_count'] ?? 0));
        $overduecount = max(0, (int)($snapshot['overdue_task_count'] ?? 0));

        if ($critical && !$hasfact) {
            self::add_reason($reasons, 'critical_no_data', 'critical', 'data');
        }
        if ($critical && !$hasnorm) {
            self::add_reason($reasons, 'critical_no_norm', 'medium', 'configuration');
        }
        if ($critical && $hasfact && !$haspolicy && in_array($direction, ['up', 'down'], true)) {
            self::add_reason($reasons, 'critical_no_policy', 'high', 'configuration');
        }

        if ($condition === 'nonexistence') {
            self::add_reason($reasons, 'condition_nonexistence', 'critical', 'condition');
        } else if ($condition === 'danger') {
            self::add_reason($reasons, 'condition_danger', 'critical', 'condition');
        } else if ($condition === 'emergency') {
            self::add_reason($reasons, 'condition_emergency', 'high', 'condition');
        } else if ($condition === 'powerchange') {
            self::add_reason($reasons, 'condition_powerchange', 'high', 'condition');
        }

        if (!empty($snapshot['outside_norm'])) {
            self::add_reason($reasons, 'outside_norm', 'high', 'norm');
        }
        if ($trendquality === 'worsening') {
            self::add_reason($reasons, 'worsening_trend', 'medium', 'trend');
        }
        foreach ((array)($snapshot['event_types'] ?? []) as $eventtype) {
            if (self::adverse_event((string)$eventtype, $direction)) {
                self::add_reason($reasons, 'adverse_event', 'medium', 'event');
                break;
            }
        }

        $attentioncondition = in_array($condition, ['nonexistence', 'danger', 'emergency', 'powerchange'], true);
        if ($attentioncondition && !$confirmed) {
            self::add_reason($reasons, 'condition_unconfirmed', 'medium', 'execution');
        }
        if ($attentioncondition && $confirmed && $taskcount === 0) {
            self::add_reason($reasons, 'confirmed_without_task', 'high', 'execution');
        }
        if ($overduecount > 0) {
            self::add_reason($reasons, 'overdue_task', 'critical', 'execution');
        }
        $followupquality = (string)($snapshot['followup_quality'] ?? '');
        $requiresnewdecision = !empty($snapshot['requires_new_decision']);
        if ($requiresnewdecision && $followupquality === 'worsening') {
            self::add_reason($reasons, 'action_followup_worsened', 'high', 'execution');
        } else if ($requiresnewdecision && $followupquality === 'unchanged') {
            self::add_reason($reasons, 'action_followup_unchanged', 'medium', 'execution');
        }

        usort($reasons, static function(array $a, array $b): int {
            $rank = self::severity_rank((string)$b['severity']) <=> self::severity_rank((string)$a['severity']);
            return $rank !== 0 ? $rank : strcmp((string)$a['code'], (string)$b['code']);
        });
        return $reasons;
    }

    /** Highest severity in one reason set. */
    public static function highest_severity(array $reasons): string {
        $best = '';
        foreach ($reasons as $reason) {
            if (self::severity_rank((string)($reason['severity'] ?? '')) > self::severity_rank($best)) {
                $best = (string)$reason['severity'];
            }
        }
        return $best;
    }

    /** Current immutable manager decision for one binding/period. */
    private static function latest_decision(array $eventsbybindperiod, string $binduuid, string $periodkey): ?\stdClass {
        $key = $binduuid . '|' . $periodkey;
        return $eventsbybindperiod[$key] ?? null;
    }

    /** Human business-context label without creating another context entity. */
    private static function context_label(array $binding, array $registry): string {
        if ((string)$binding['contextkind'] === 'company') {
            return (string)$registry['company']['name'];
        }
        if ((string)$binding['contextkind'] === 'strategygoal') {
            foreach ($registry['goals'] as $row) {
                if ((string)$row->uuid === (string)$binding['contextuuid']) { return (string)$row->code . ' — ' . (string)$row->name; }
            }
        }
        if ((string)$binding['contextkind'] === 'orgnode') {
            foreach ($registry['nodes'] as $row) {
                if ((string)$row->uuid === (string)$binding['contextuuid']) { return (string)$row->code . ' — ' . (string)$row->name; }
            }
        }
        if ((string)$binding['contextkind'] === 'position') {
            foreach ($registry['positions'] as $row) {
                if ((string)$row->uuid === (string)$binding['contextuuid']) { return (string)$row->code . ' — ' . (string)$row->name; }
            }
        }
        return '';
    }

    /** Manager attention queue. No exception records are persisted. */
    public static function context(string $companyuuid, int $actor, string $severity = 'all', string $group = 'all', string $cycle = 'all'): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($severity !== 'all' && !in_array($severity, self::SEVERITIES, true)) { $severity = 'all'; }
        if ($group !== 'all' && !in_array($group, self::GROUPS, true)) { $group = 'all'; }
        if ($cycle !== 'all' && !in_array($cycle, indicator_management_cycle_service::FILTERS, true)) { $cycle = 'all'; }

        $registry = indicator_registry_service::context($companyuuid, $actor);
        $metricmap = [];
        foreach ($registry['metrics'] as $metric) { $metricmap[(string)$metric['uuid']] = $metric; }

        $eventsbybindperiod = [];
        $eventsbyuuid = [];
        foreach ($DB->get_records('local_kbacompany_stateevt', ['companyuuid' => $companyuuid], 'timecreated ASC, id ASC') as $event) {
            $eventsbybindperiod[(string)$event->binduuid . '|' . (string)$event->periodkey] = $event;
            $eventsbyuuid[(string)$event->uuid] = $event;
        }

        $tasksbyevent = [];
        $tasksbybind = [];
        foreach ($DB->get_records('local_kbacompany_task', ['companyuuid' => $companyuuid, 'sourcekind' => 'condition'], 'id ASC') as $task) {
            if ((string)$task->status === 'deleted') { continue; }
            $tasksbyevent[(string)$task->sourceuuid][] = $task;
            $sourceevent = $eventsbyuuid[(string)$task->sourceuuid] ?? null;
            if ($sourceevent) { $tasksbybind[(string)$sourceevent->binduuid][] = $task; }
        }

        $today = gmdate('Y-m-d');
        $seriescache = [];
        $rows = [];
        $summary = ['total' => 0, 'critical' => 0, 'high' => 0, 'medium' => 0, 'without_task' => 0, 'overdue' => 0,
            'needs_decision' => 0, 'in_progress' => 0, 'followup_worsened' => 0, 'followup_improved' => 0];
        foreach ($registry['bindings'] as $binding) {
            if (!empty($binding['compatibility'])) { continue; }
            $metric = $metricmap[(string)$binding['statuuid']] ?? null;
            if (!$metric) { continue; }

            $metricuuid = (string)$metric['uuid'];
            if (!isset($seriescache[$metricuuid])) {
                $seriescache[$metricuuid] = indicator_registry_service::series_for_metric($companyuuid, $metricuuid, 52);
            }
            $series = $seriescache[$metricuuid];
            $facts = indicator_trend_service::facts($series);
            $latest = $facts ? $facts[count($facts) - 1] : null;
            $periodkey = $latest ? (string)$latest['period'] : '';
            $normperiod = $periodkey !== '' ? $periodkey : $today;
            $norm = indicator_event_service::norm_for_period($registry['normhistory'], (string)$binding['uuid'], $normperiod);
            $policy = indicator_condition_service::policy_for_period($registry['condition_policy_history'],
                (string)$binding['uuid'], $normperiod);
            $trend = indicator_trend_service::analyze($series, (string)$metric['direction']);
            $events = indicator_event_service::latest($series, (string)$metric['direction'],
                $registry['normhistory'], (string)$binding['uuid']);
            $analysis = indicator_condition_service::analyze($series, (string)$metric['direction'],
                $registry['normhistory'], $registry['condition_policy_history'], (string)$binding['uuid']);
            $decision = self::latest_decision($eventsbybindperiod, (string)$binding['uuid'], $periodkey);
            $condition = $decision ? (string)$decision->conditioncode : (string)($analysis['condition'] ?? '');

            $outside = false;
            if ($latest && $norm) {
                $outside = !indicator_event_service::in_norm((float)$latest['value'], $norm, (string)$metric['direction']);
            }

            $eventtypes = [];
            foreach ($events as $event) { $eventtypes[] = (string)($event['type'] ?? ''); }
            $currenttasks = $decision ? ($tasksbyevent[(string)$decision->uuid] ?? []) : [];
            $bindingtasks = $tasksbybind[(string)$binding['uuid']] ?? [];
            $visible = [];
            $visibleids = [];
            $overdue = 0;
            $open = 0;
            foreach ($bindingtasks as $task) {
                $isopen = !in_array((string)$task->status, ['done', 'cancelled'], true);
                if ($isopen) {
                    $open++;
                    if ((string)$task->duedate !== '' && (string)$task->duedate < $today) { $overdue++; }
                }
                if ($isopen && task_service::can_view_task($companyuuid, $actor, (string)$task->uuid)) {
                    $visibleids[(string)$task->uuid] = true;
                    $visible[] = [
                        'uuid' => (string)$task->uuid,
                        'title' => (string)$task->title,
                        'status' => (string)$task->status,
                        'due_date' => (string)$task->duedate,
                        'overdue' => (string)$task->duedate !== '' && (string)$task->duedate < $today,
                    ];
                }
            }
            // When no binding-level execution is open, keep the current decision Task visible for traceability.
            if ($open === 0) {
                foreach ($currenttasks as $task) {
                    if (isset($visibleids[(string)$task->uuid]) ||
                            !task_service::can_view_task($companyuuid, $actor, (string)$task->uuid)) { continue; }
                    $visible[] = [
                        'uuid' => (string)$task->uuid,
                        'title' => (string)$task->title,
                        'status' => (string)$task->status,
                        'due_date' => (string)$task->duedate,
                        'overdue' => false,
                    ];
                }
            }
            $executioncount = count($currenttasks) > 0 ? count($currenttasks) : $open;

            $snapshot = [
                'critical' => !empty($binding['critical']),
                'has_fact' => (bool)$latest,
                'has_norm' => (bool)$norm,
                'has_policy' => (bool)$policy,
                'condition' => $condition,
                'trend_quality' => (string)$trend['quality'],
                'direction' => (string)$metric['direction'],
                'outside_norm' => $outside,
                'event_types' => $eventtypes,
                'confirmed' => (bool)$decision,
                'task_count' => $executioncount,
                'overdue_task_count' => $overdue,
            ];
            $basereasons = self::build_reasons($snapshot);
            $needsdecision = false;
            foreach ($basereasons as $reason) {
                if ((string)$reason['code'] === 'condition_unconfirmed') { $needsdecision = true; break; }
            }
            $managementcycle = indicator_management_cycle_service::snapshot($companyuuid, $actor,
                $binding, $metric, $periodkey, $needsdecision);
            $snapshot['followup_quality'] = (string)$managementcycle['observation']['quality'];
            $snapshot['requires_new_decision'] = !empty($managementcycle['requires_new_decision']);
            $reasons = self::build_reasons($snapshot);
            if (!$reasons) { continue; }
            $highest = self::highest_severity($reasons);
            if ($severity !== 'all' && $highest !== $severity) { continue; }
            if ($group !== 'all') {
                $found = false;
                foreach ($reasons as $reason) { if ((string)$reason['group'] === $group) { $found = true; break; } }
                if (!$found) { continue; }
            }
            if ($cycle !== 'all' && (string)$managementcycle['status'] !== $cycle) { continue; }

            $hasmissingtask = false;
            foreach ($reasons as $reason) {
                if ((string)$reason['code'] === 'confirmed_without_task') { $hasmissingtask = true; break; }
            }
            $summary['total']++;
            if (isset($summary[$highest])) { $summary[$highest]++; }
            if ($hasmissingtask) { $summary['without_task']++; }
            if ($overdue > 0) { $summary['overdue']++; }
            if (!empty($managementcycle['requires_new_decision'])) { $summary['needs_decision']++; }
            if ((string)$managementcycle['status'] === 'in_progress') { $summary['in_progress']++; }
            if ((string)$managementcycle['observation']['quality'] === 'worsening') { $summary['followup_worsened']++; }
            if ((string)$managementcycle['observation']['quality'] === 'improving') { $summary['followup_improved']++; }

            $executionstatus = 'none';
            if ($bindingtasks || $currenttasks) {
                if ($overdue > 0) { $executionstatus = 'overdue'; }
                else if ($open > 0) { $executionstatus = 'open'; }
                else { $executionstatus = 'closed'; }
            } else if ($decision) {
                $executionstatus = 'without_task';
            }

            $rows[] = [
                'metric' => $metric,
                'binding' => $binding,
                'context_label' => self::context_label($binding, $registry),
                'latest_fact' => $latest,
                'norm' => $norm,
                'trend' => $trend,
                'events' => $events,
                'condition_analysis' => $analysis,
                'effective_condition' => $condition,
                'decision_uuid' => $decision ? (string)$decision->uuid : '',
                'decision_kind' => $decision ? (string)$decision->eventkind : '',
                'decision_time' => $decision ? (int)$decision->timecreated : 0,
                'decision_comment' => $decision ? (string)$decision->comment : '',
                'tasks' => $visible,
                'task_count' => max(count($currenttasks), $open),
                'visible_task_count' => count($visible),
                'overdue_task_count' => $overdue,
                'execution_status' => $executionstatus,
                'management_cycle' => $managementcycle,
                'reasons' => $reasons,
                'severity' => $highest,
            ];
        }

        usort($rows, static function(array $a, array $b): int {
            $rank = self::severity_rank((string)$b['severity']) <=> self::severity_rank((string)$a['severity']);
            if ($rank !== 0) { return $rank; }
            $critical = (int)!empty($b['binding']['critical']) <=> (int)!empty($a['binding']['critical']);
            if ($critical !== 0) { return $critical; }
            $code = strcmp((string)$a['metric']['code'], (string)$b['metric']['code']);
            return $code !== 0 ? $code : strcmp((string)$a['context_label'], (string)$b['context_label']);
        });

        return [
            'company' => $registry['company'],
            'can_manage' => $registry['can_manage'],
            'severity_filter' => $severity,
            'group_filter' => $group,
            'cycle_filter' => $cycle,
            'summary' => $summary,
            'rows' => $rows,
        ];
    }
}
