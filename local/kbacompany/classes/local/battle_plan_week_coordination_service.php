<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Weekly management coordination agenda over existing Battle Plan read-models.
 *
 * This service persists nothing. It composes the canonical management overview,
 * readiness and coverage projections into one action-first agenda for managers.
 */
class battle_plan_week_coordination_service {
    public const CONTRACT_VERSION = '0.8.3';

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan_week_coordination',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'overview' => 'battle_plan_management_overview_service',
                'readiness' => 'battle_plan_week_readiness_service',
                'coverage' => 'battle_plan_coverage_service',
                'previous_week_overview' => 'battle_plan_management_overview_service',
                'management_history' => 'battle_plan_management_overview_service (bounded 4/8-week read)',
                'management_patterns' => 'transparent factual rules over management_history',
                'pattern_actions' => 'canonical Task Center GET-prefill + read-only live task feedback; Task Center POST remains authoritative',
                'pattern_result_feedback' => 'completed canonical Task + bounded management_history before/after read-model',
                'unit_patterns' => 'explicit recurring rules over existing company-scoped weekly unit projections',
                'ceo_overview' => 'read-only rollup of accessible current by_unit rows and unit_patterns',
            ],
            'rules' => [
                'agenda_is_a_read_model_not_a_decision_registry',
                'existing_plan_task_indicator_acl_is_not_bypassed',
                'no_composite_employee_rating',
                'no_hidden_thresholds',
                'actions_link_to_existing_canonical_controllers',
                'previous_week_followup_uses_live_canonical_task_status',
                'week_over_week_change_is_observation_not_causality',
                'management_history_is_bounded_to_4_or_8_weeks',
                'history_uses_weighted_source_counts_not_employee_ranking',
                'patterns_use_explicit_factual_rules_only',
                'patterns_are_not_scores_or_causal_claims',
                'pattern_task_action_requires_one_accessible_employee_scope',
                'pattern_action_never_creates_a_task_on_GET',
                'pattern_task_feedback_reuses_canonical_task_visibility',
                'open_pattern_action_suppresses_duplicate_create_prompt',
                'pattern_result_feedback_is_factual_before_after_delta_only',
                'pattern_result_uses_canonical_task_completed_at',
                'pattern_result_requires_subsequent_available_week_data',
                'pattern_result_never_claims_causality',
                'pattern_result_creates_no_snapshot_or_score',
                'unit_pattern_signals_do_not_assign_tasks_or_rank_units',
                'ceo_overview_uses_only_existing_scoped_unit_data',
                'requested_inaccessible_employee_filter_never_falls_back_to_ceo_view',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function history_point(array $overview, int $userid = 0, ?string $unituuid = null): array {
        $tasktotal = 0; $taskdone = 0;
        $metricok = 0; $metricevaluated = 0; $metrictotal = 0;
        $manualopen = 0; $manualdone = 0;
        $needsdecision = 0; $overdue = 0; $critical = 0; $plans = 0;
        foreach ((array)($overview['rows'] ?? []) as $row) {
            if ($userid > 0 && (int)($row['employee_id'] ?? 0) !== $userid) { continue; }
            if ($unituuid !== null && (string)($row['orgnode']['uuid'] ?? '') !== $unituuid) { continue; }
            $plans++;
            $tasktotal += (int)($row['tasks']['total'] ?? 0);
            $taskdone += (int)($row['tasks']['done'] ?? 0);
            $manualopen += (int)($row['tasks']['manual_open'] ?? 0);
            $manualdone += (int)($row['tasks']['manual_done'] ?? 0);
            $metricok += (int)($row['performance']['metric_counts']['ok'] ?? 0);
            $metricevaluated += (int)($row['performance']['metric_evaluated'] ?? 0);
            $metrictotal += (int)($row['performance']['metric_total'] ?? 0);
            $needsdecision += (int)($row['needs_decision'] ?? 0);
            $overdue += (int)($row['tasks']['overdue'] ?? 0);
            $critical += (int)($row['critical_exceptions'] ?? 0);
        }
        return [
            'week_start'=>(string)($overview['week_start'] ?? ''),
            'week_end'=>(string)($overview['week_end'] ?? ''),
            'plans'=>$plans,
            'productivity'=>$tasktotal > 0 ? (int)round(($taskdone / $tasktotal) * 100) : null,
            'effectiveness'=>$metricevaluated > 0 ? (int)round(($metricok / $metricevaluated) * 100) : null,
            'coverage'=>$metrictotal > 0 ? (int)round(($metricevaluated / $metrictotal) * 100) : null,
            'management_tasks'=>[
                'total'=>$manualopen + $manualdone,
                'open'=>$manualopen,
                'done'=>$manualdone,
                'completion'=>($manualopen + $manualdone) > 0
                    ? (int)round(($manualdone / ($manualopen + $manualdone)) * 100) : null,
            ],
            'attention'=>[
                'needs_decision'=>$needsdecision,
                'overdue_tasks'=>$overdue,
                'critical_exceptions'=>$critical,
            ],
        ];
    }

    /**
     * Detect transparent recurring management patterns from already-accessible weekly facts.
     *
     * Rules are intentionally explicit and bounded. They are not a score, ranking or diagnosis.
     */
    private static function management_patterns(array $history): array {
        $rows = array_values($history);
        $last4 = array_slice($rows, -4);
        $last2 = array_slice($rows, -2);
        $patterns = [];

        $count = static function(array $items, callable $predicate): int {
            $n = 0;
            foreach ($items as $item) {
                if ($predicate($item)) { $n++; }
            }
            return $n;
        };
        $weeks = static function(array $items, callable $predicate): array {
            $out = [];
            foreach ($items as $item) {
                if ($predicate($item)) { $out[] = (string)($item['week_start'] ?? ''); }
            }
            return $out;
        };
        $push = static function(string $key, string $kind, string $rule, array $matchedweeks, array $facts = []) use (&$patterns): void {
            $patterns[] = [
                'key'=>$key,
                'kind'=>$kind,
                'rule'=>$rule,
                'matched_weeks'=>$matchedweeks,
                'facts'=>$facts,
            ];
        };

        // Rule 1: critical KPI exceptions were present in at least 3 of the latest 4 weeks.
        $pred = static function(array $r): bool { return (int)($r['attention']['critical_exceptions'] ?? 0) > 0; };
        $hits = $count($last4, $pred);
        if (count($last4) >= 3 && $hits >= 3) {
            $push('critical_kpi_repeat', 'critical', 'critical_3_of_4', $weeks($last4, $pred), ['hits'=>$hits, 'window'=>count($last4)]);
        }

        // Rule 2: overdue tasks exist in both of the latest 2 weeks.
        $pred = static function(array $r): bool { return (int)($r['attention']['overdue_tasks'] ?? 0) > 0; };
        $hits = $count($last2, $pred);
        if (count($last2) === 2 && $hits === 2) {
            $push('overdue_two_weeks', 'execution', 'overdue_latest_2', $weeks($last2, $pred), ['hits'=>$hits, 'window'=>2]);
        }

        // Rule 3: unresolved management tasks exist in both of the latest 2 weeks.
        $pred = static function(array $r): bool { return (int)($r['management_tasks']['open'] ?? 0) > 0; };
        $hits = $count($last2, $pred);
        if (count($last2) === 2 && $hits === 2) {
            $push('management_tasks_open_two_weeks', 'followup', 'management_open_latest_2', $weeks($last2, $pred), ['hits'=>$hits, 'window'=>2]);
        }

        // Rule 4: data coverage is below 80% in at least 3 of the latest 4 weeks with evaluable data.
        $eligible = array_values(array_filter($last4, static function(array $r): bool { return ($r['coverage'] ?? null) !== null; }));
        $pred = static function(array $r): bool { return ($r['coverage'] ?? null) !== null && (int)$r['coverage'] < 80; };
        $hits = $count($eligible, $pred);
        if (count($eligible) >= 3 && $hits >= 3) {
            $push('coverage_below_80_repeat', 'data', 'coverage_below_80_3_of_4', $weeks($eligible, $pred), ['hits'=>$hits, 'window'=>count($eligible), 'threshold'=>80]);
        }

        // Rule 5: pending management decisions exist in both of the latest 2 weeks.
        $pred = static function(array $r): bool { return (int)($r['attention']['needs_decision'] ?? 0) > 0; };
        $hits = $count($last2, $pred);
        if (count($last2) === 2 && $hits === 2) {
            $push('decisions_pending_two_weeks', 'decision', 'decision_latest_2', $weeks($last2, $pred), ['hits'=>$hits, 'window'=>2]);
        }

        return $patterns;
    }

    /**
     * Factual before/after movement after one completed canonical management task.
     *
     * Weekly history is already scoped by the same company/unit/employee visibility.
     * This helper creates no snapshot and makes no causal inference.
     */
    private static function factual_result_after_task(array $task, array $history): ?array {
        if ((string)($task['status'] ?? '') !== 'done' || (int)($task['completed_at'] ?? 0) <= 0) {
            return null;
        }
        $completedat = (int)$task['completed_at'];
        $completeddate = gmdate('Y-m-d', $completedat);
        $completionweek = battle_plan_service::week_start($completeddate);
        $hasdata = static function(array $row): bool {
            return ($row['productivity'] ?? null) !== null || ($row['effectiveness'] ?? null) !== null ||
                ($row['coverage'] ?? null) !== null;
        };
        $before = null;
        $after = null;
        foreach (array_values($history) as $row) {
            $weekstart = (string)($row['week_start'] ?? '');
            if ($weekstart === '' || !$hasdata($row)) { continue; }
            if ($weekstart <= $completionweek) {
                $before = $row;
            } else {
                // History is chronological; retaining each match gives the latest subsequent week.
                $after = $row;
            }
        }
        $result = [
            'state'=>'ready',
            'task_uuid'=>(string)($task['uuid'] ?? ''),
            'completed_at'=>$completedat,
            'completed_date'=>$completeddate,
            'completion_week'=>$completionweek,
            'before'=>$before,
            'after'=>$after,
            'metrics'=>[],
        ];
        if (!$before) {
            $result['state'] = 'insufficient_before';
            return $result;
        }
        if (!$after) {
            $result['state'] = 'insufficient_after';
            return $result;
        }
        foreach (['productivity','effectiveness','coverage'] as $key) {
            $beforevalue = $before[$key] ?? null;
            $aftervalue = $after[$key] ?? null;
            $result['metrics'][$key] = [
                'before'=>$beforevalue,
                'after'=>$aftervalue,
                'delta'=>$beforevalue !== null && $aftervalue !== null
                    ? (int)$aftervalue - (int)$beforevalue : null,
            ];
        }
        return $result;
    }

    public static function context(string $companyuuid, int $actor, string $week = '',
            string $orgnodeuuid = '', int $historyuserid = 0, int $historyweeks = 8): array {
        // Readiness/coverage both enforce company-wide management access.
        $overview = battle_plan_management_overview_service::context(
            $companyuuid, $actor, $week, $orgnodeuuid, 0, 'all');
        $readiness = battle_plan_week_readiness_service::context(
            $companyuuid, $actor, $overview['week_start'], $orgnodeuuid, 'all');
        $coverage = battle_plan_coverage_service::context(
            $companyuuid, $actor, $overview['week_start'], $orgnodeuuid, 'all');
        $previousweek = (new \DateTimeImmutable((string)$overview['week_start'], new \DateTimeZone('UTC')))
            ->modify('-7 days')->format('Y-m-d');
        $previousoverview = battle_plan_management_overview_service::context(
            $companyuuid, $actor, $previousweek, $orgnodeuuid, 0, 'all');

        $summary = [
            'submitted' => (int)($readiness['summary']['submitted'] ?? 0),
            'blocked' => (int)($readiness['summary']['blocked'] ?? 0),
            'missing_plans' => (int)($coverage['summary']['missing_plans'] ?? 0),
            'needs_decision' => (int)($overview['summary']['needs_decision'] ?? 0),
            'overdue_tasks' => (int)($overview['summary']['overdue_tasks'] ?? 0),
            'critical_exceptions' => (int)($overview['summary']['critical_exceptions'] ?? 0),
        ];
        $summary['requires_attention'] = $summary['submitted'] + $summary['blocked'] +
            $summary['missing_plans'] + $summary['needs_decision'] +
            $summary['overdue_tasks'] + $summary['critical_exceptions'];

        $agenda = [];
        $seen = [];
        $push = static function(string $key, string $kind, string $title, string $meta,
                string $url, int $priority, array $actions = []) use (&$agenda, &$seen): void {
            if ($key === '' || isset($seen[$key])) { return; }
            $seen[$key] = true;
            $agenda[] = [
                'kind'=>$kind, 'title'=>$title, 'meta'=>$meta, 'url'=>$url, 'priority'=>$priority,
                'actions'=>$actions,
            ];
        };

        $employeeurl = static function(int $userid) use ($companyuuid): string {
            return (new \moodle_url('/local/kbacompany/employee_view.php', [
                'uuid'=>$companyuuid, 'userid'=>$userid,
            ]))->out(false);
        };
        $taskurl = static function(array $row) use ($companyuuid, $overview): string {
            if (!in_array((string)($row['status'] ?? ''), ['draft','active'], true)) { return ''; }
            $params = [
                'uuid'=>$companyuuid, 'view'=>'create', 'assigneeid'=>(int)$row['employee_id'],
                'startdate'=>(string)$overview['week_start'], 'duedate'=>(string)$overview['week_end'],
                'battleplanuuid'=>(string)$row['plan_uuid'],
            ];
            if (!empty($row['reviewer_id'])) { $params['reviewerid']=(int)$row['reviewer_id']; }
            return (new \moodle_url('/local/kbacompany/tasks.php', $params))->out(false);
        };

        // 1. Existing management decisions and overdue execution work.
        foreach ($overview['rows'] as $row) {
            $base = (string)$row['employee_name'] . ' — ' . (string)$row['position']['name'];
            $unit = (string)($row['orgnode']['name'] ?? '');
            if ((int)$row['needs_decision'] > 0) {
                $actions = [
                    ['label'=>'battleplan_weekcoord_action_open_plan', 'url'=>(string)$row['url'], 'primary'=>false],
                    ['label'=>'battleplan_weekcoord_action_open_metrics',
                        'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php', [
                            'uuid'=>$companyuuid, 'plan'=>(string)$row['plan_uuid'], 'tab'=>'indicators',
                        ]))->out(false), 'primary'=>true],
                    ['label'=>'battleplan_weekcoord_action_employee', 'url'=>$employeeurl((int)$row['employee_id']), 'primary'=>false],
                ];
                $managementtask = $taskurl($row);
                if ($managementtask !== '') {
                    $actions[] = ['label'=>'battleplan_weekcoord_action_create_task', 'url'=>$managementtask, 'primary'=>true];
                }
                $push('decision:'.$row['plan_uuid'], 'decision', $base,
                    get_string('battleplan_weekcoord_decision_meta','local_kbacompany', (int)$row['needs_decision']) .
                        ($unit !== '' ? ' · '.$unit : ''), (string)$row['url'], 100, $actions);
            }
            if ((int)$row['tasks']['overdue'] > 0) {
                $actions = [
                    ['label'=>'battleplan_weekcoord_action_open_tasks',
                        'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php', [
                            'uuid'=>$companyuuid, 'plan'=>(string)$row['plan_uuid'], 'tab'=>'tasks',
                        ]))->out(false), 'primary'=>true],
                    ['label'=>'battleplan_weekcoord_action_employee', 'url'=>$employeeurl((int)$row['employee_id']), 'primary'=>false],
                ];
                $managementtask = $taskurl($row);
                if ($managementtask !== '') {
                    $actions[] = ['label'=>'battleplan_weekcoord_action_create_task', 'url'=>$managementtask, 'primary'=>false];
                }
                $push('overdue:'.$row['plan_uuid'], 'overdue', $base,
                    get_string('battleplan_weekcoord_overdue_meta','local_kbacompany', (int)$row['tasks']['overdue']) .
                        ($unit !== '' ? ' · '.$unit : ''), (string)$row['url'], 90, $actions);
            }
            if ((int)$row['critical_exceptions'] > 0) {
                $metricurl = (new \moodle_url('/local/kbacompany/battle_plan.php', [
                    'uuid'=>$companyuuid, 'plan'=>(string)$row['plan_uuid'], 'tab'=>'indicators',
                ]))->out(false);
                $actions = [
                    ['label'=>'battleplan_weekcoord_action_open_metrics', 'url'=>$metricurl, 'primary'=>true],
                    ['label'=>'battleplan_weekcoord_action_employee', 'url'=>$employeeurl((int)$row['employee_id']), 'primary'=>false],
                ];
                $managementtask = $taskurl($row);
                if ($managementtask !== '') {
                    $actions[] = ['label'=>'battleplan_weekcoord_action_create_task', 'url'=>$managementtask, 'primary'=>false];
                }
                $push('critical:'.$row['plan_uuid'], 'critical', $base,
                    get_string('battleplan_weekcoord_critical_meta','local_kbacompany', (int)$row['critical_exceptions']) .
                        ($unit !== '' ? ' · '.$unit : ''), $metricurl, 80, $actions);
            }
        }

        // 2. Missing plans from canonical coverage projection.
        foreach ($coverage['rows'] as $row) {
            if ((string)$row['type'] !== 'missing_plan') { continue; }
            $push('missing:'.(int)$row['employee_id'].':'.(string)$row['position']['uuid'], 'missing_plan',
                (string)$row['employee_name'] . ' — ' . (string)$row['position']['name'],
                get_string('battleplan_weekcoord_missing_meta','local_kbacompany') .
                    ((string)$row['orgnode']['name'] !== '' ? ' · '.(string)$row['orgnode']['name'] : ''),
                (string)$row['action_url'], 70, [
                    ['label'=>'battleplan_weekcoord_action_create_plan', 'url'=>(string)$row['action_url'], 'primary'=>true],
                    ['label'=>'battleplan_weekcoord_action_employee', 'url'=>$employeeurl((int)$row['employee_id']), 'primary'=>false],
                ]);
        }

        // 3. Submitted/blocked plans from lifecycle readiness projection.
        foreach ($readiness['rows'] as $row) {
            $base = (string)$row['employee_name'] . ' — ' . (string)$row['position']['name'];
            $unit = (string)($row['orgnode']['name'] ?? '');
            if ((string)$row['status'] === 'submitted') {
                $reviewurl = (new \moodle_url('/local/kbacompany/battle_plan.php', [
                    'uuid'=>$companyuuid, 'plan'=>(string)$row['uuid'], 'tab'=>'review',
                ]))->out(false);
                $push('submitted:'.$row['uuid'], 'submitted', $base,
                    get_string('battleplan_weekcoord_submitted_meta','local_kbacompany') .
                        ($unit !== '' ? ' · '.$unit : ''), $reviewurl, 95, [
                    ['label'=>'battleplan_weekcoord_action_review', 'url'=>$reviewurl, 'primary'=>true],
                    ['label'=>'battleplan_weekcoord_action_employee', 'url'=>$employeeurl((int)$row['user_id']), 'primary'=>false],
                ]);
            }
            if ((string)$row['readiness'] === 'blocked') {
                $actions = [
                    ['label'=>'battleplan_weekcoord_action_fix_plan', 'url'=>(string)$row['url'], 'primary'=>true],
                    ['label'=>'battleplan_weekcoord_action_employee', 'url'=>$employeeurl((int)$row['user_id']), 'primary'=>false],
                ];
                if (in_array((string)$row['status'], ['draft','active'], true)) {
                    $taskparams = [
                        'uuid'=>$companyuuid, 'view'=>'create', 'assigneeid'=>(int)$row['user_id'],
                        'startdate'=>(string)$overview['week_start'], 'duedate'=>(string)$overview['week_end'],
                        'battleplanuuid'=>(string)$row['uuid'],
                    ];
                    if (!empty($row['reviewer_id'])) { $taskparams['reviewerid']=(int)$row['reviewer_id']; }
                    $actions[] = ['label'=>'battleplan_weekcoord_action_create_task',
                        'url'=>(new \moodle_url('/local/kbacompany/tasks.php',$taskparams))->out(false), 'primary'=>false];
                }
                $push('blocked:'.$row['uuid'], 'blocked', $base,
                    get_string('battleplan_weekcoord_blocked_meta','local_kbacompany', count($row['blockers'])) .
                        ($unit !== '' ? ' · '.$unit : ''), (string)$row['url'], 85, $actions);
            }
        }

        usort($agenda, static function(array $a, array $b): int {
            if ((int)$a['priority'] !== (int)$b['priority']) { return (int)$b['priority'] <=> (int)$a['priority']; }
            return strcmp((string)$a['title'], (string)$b['title']);
        });

        // Previous-week follow-up over canonical manual tasks attached to the previous Battle Plan.
        // Task status is read live from Task Center, so completed/open is the current factual state.
        // Performance deltas are factual week-over-week movement and never treated as causal proof.
        $currentmap = [];
        foreach ($overview['rows'] as $row) {
            $currentmap[(int)$row['employee_id'].'|'.(string)$row['position']['uuid']] = $row;
        }
        $followup = [];
        $followupsummary = ['total'=>0, 'open'=>0, 'done'=>0, 'people'=>0];
        foreach ($previousoverview['rows'] as $row) {
            $manualopen = (int)($row['tasks']['manual_open'] ?? 0);
            $manualdone = (int)($row['tasks']['manual_done'] ?? 0);
            $manualtotal = $manualopen + $manualdone;
            if ($manualtotal <= 0) { continue; }
            $key = (int)$row['employee_id'].'|'.(string)$row['position']['uuid'];
            $current = $currentmap[$key] ?? null;
            $delta = $current ? (array)($current['performance']['delta'] ?? []) : [];
            $previousplanurl = (new \moodle_url('/local/kbacompany/battle_plan.php', [
                'uuid'=>$companyuuid, 'plan'=>(string)$row['plan_uuid'], 'tab'=>'tasks',
            ]))->out(false);
            $actions = [[
                'label'=>'battleplan_weekcoord_followup_prev_tasks', 'url'=>$previousplanurl, 'primary'=>true,
            ]];
            if ($current) {
                $actions[] = [
                    'label'=>'battleplan_weekcoord_followup_current_plan',
                    'url'=>(string)$current['url'], 'primary'=>false,
                ];
            }
            $followup[] = [
                'employee_id'=>(int)$row['employee_id'],
                'employee_name'=>(string)$row['employee_name'],
                'position'=>(string)$row['position']['name'],
                'orgnode'=>(string)($row['orgnode']['name'] ?? ''),
                'plan_uuid'=>(string)$row['plan_uuid'],
                'open'=>$manualopen,
                'done'=>$manualdone,
                'total'=>$manualtotal,
                'open_items'=>(array)($row['tasks']['manual_items'] ?? []),
                'delta'=>[
                    'productivity'=>$delta['productivity'] ?? null,
                    'effectiveness'=>$delta['effectiveness'] ?? null,
                    'coverage'=>$delta['coverage'] ?? null,
                ],
                'actions'=>$actions,
            ];
            $followupsummary['total'] += $manualtotal;
            $followupsummary['open'] += $manualopen;
            $followupsummary['done'] += $manualdone;
            $followupsummary['people']++;
        }
        usort($followup, static function(array $a, array $b): int {
            $cmp = (int)$b['open'] <=> (int)$a['open'];
            if ($cmp !== 0) { return $cmp; }
            return strcmp((string)$a['employee_name'], (string)$b['employee_name']);
        });

        // Bounded management history for the current company/unit scope or one accessible employee.
        // Every point is recomputed from the same canonical overview counts; no historical snapshot table
        // and no synthetic employee score/ranking is introduced.
        $historyweeks = in_array($historyweeks, [4, 8], true) ? $historyweeks : 8;
        $historyusers = [];
        foreach ((array)$overview['rows'] as $row) {
            $historyusers[(int)$row['employee_id']] = (string)$row['employee_name'];
        }
        foreach ((array)$previousoverview['rows'] as $row) {
            $historyusers[(int)$row['employee_id']] = (string)$row['employee_name'];
        }
        $history = [];
        // Per-unit patterns reuse the same weekly overview reads; no extra database queries.
        $unitseries = [];
        $unitnames = [];
        foreach ((array)$overview['orgnodes'] as $node) {
            $unitnames[(string)$node['uuid']] = (string)$node['name'];
            $unitseries[(string)$node['uuid']] = [];
        }
        $unitseries[''] = []; // Plans with no canonical organizational unit.
        $currentweek = (string)$overview['week_start'];
        $weekcursor = new \DateTimeImmutable($currentweek, new \DateTimeZone('UTC'));
        for ($offset = $historyweeks - 1; $offset >= 0; $offset--) {
            $historyweek = $weekcursor->modify('-'.($offset * 7).' days')->format('Y-m-d');
            if ($historyweek === $currentweek) {
                $historyoverview = $overview;
            } else if ($historyweek === $previousweek) {
                $historyoverview = $previousoverview;
            } else {
                $historyoverview = battle_plan_management_overview_service::context(
                    $companyuuid, $actor, $historyweek, $orgnodeuuid, 0, 'all');
            }
            foreach ((array)$historyoverview['rows'] as $row) {
                $historyusers[(int)$row['employee_id']] = (string)$row['employee_name'];
            }
            $point = self::history_point($historyoverview, $historyuserid);
            $params = ['uuid'=>$companyuuid, 'week'=>$historyweek];
            if ($orgnodeuuid !== '') { $params['orgnode'] = $orgnodeuuid; }
            $point['url'] = (new \moodle_url('/local/kbacompany/battle_plan_overview.php', $params))->out(false);
            $history[] = $point;
            $presentunits = [];
            foreach ((array)$historyoverview['rows'] as $row) {
                $key = (string)($row['orgnode']['uuid'] ?? '');
                // Include unattributed positions in their own honest, unassigned group.
                $presentunits[$key] = true;
            }
            // Pad weeks for all known units so recurring-pattern windows stay aligned.
            foreach ($presentunits as $key => $_) {
                if (!array_key_exists($key, $unitseries)) { $unitseries[$key] = []; }
            }
            foreach ($unitseries as $key => &$series) {
                $unitpoint = self::history_point($historyoverview, 0, (string)$key);
                $series[] = $unitpoint;
            }
            unset($series);
        }
        $unitpatterns = [];
        foreach ($unitseries as $key => $series) {
            // A row for a unit with no plans in a week must not be treated as measured coverage.
            $unitpatterns[$key] = self::management_patterns($series);
        }
        // CEO/management summary is purely a presentation projection over the same scoped unit rows.
        // No new permission grant, query, separate score, or persisted entity.
        $ceosummary = [
            'units'=>count((array)$overview['by_unit']),
            'units_with_patterns'=>0, 'recurring_signals'=>0,
            'management_open'=>0, 'management_done'=>0,
            'needs_decision'=>0, 'overdue_tasks'=>0, 'critical_exceptions'=>0,
        ];
        foreach ((array)$overview['by_unit'] as $unitrow) {
            $unitkey = (string)($unitrow['uuid'] ?? '');
            $countpatterns = count((array)($unitpatterns[$unitkey] ?? []));
            if ($countpatterns > 0) { $ceosummary['units_with_patterns']++; }
            $ceosummary['recurring_signals'] += $countpatterns;
            $ceosummary['management_open'] += (int)($unitrow['management_tasks']['open'] ?? 0);
            $ceosummary['management_done'] += (int)($unitrow['management_tasks']['done'] ?? 0);
            foreach (['needs_decision', 'overdue_tasks', 'critical_exceptions'] as $key) {
                $ceosummary[$key] += (int)($unitrow[$key] ?? 0);
            }
        }
        asort($historyusers, SORT_NATURAL | SORT_FLAG_CASE);
        $selectedhistoryuser = $historyuserid > 0 && isset($historyusers[$historyuserid]) ? $historyuserid : 0;
        $invalidhistoryuser = $historyuserid > 0 && $selectedhistoryuser === 0;
        if ($historyuserid > 0 && $selectedhistoryuser === 0) {
            // Never expose a requested user outside the accessible overview scope.
            $history = [];
        }
        $patterns = self::management_patterns($history);

        // Pattern -> Action. A recurring signal may prefill the canonical Task Center only when
        // the manager has explicitly narrowed history to one accessible employee. Aggregate
        // company/unit patterns remain descriptive because there is no unambiguous assignee.
        $patternemployee = null;
        if ($selectedhistoryuser > 0) {
            foreach ((array)$overview['rows'] as $row) {
                if ((int)($row['employee_id'] ?? 0) !== $selectedhistoryuser) { continue; }
                // Prefer a current editable/active plan so the task can be linked canonically.
                if ($patternemployee === null || in_array((string)($row['status'] ?? ''), ['draft','active'], true)) {
                    $patternemployee = $row;
                    if (in_array((string)($row['status'] ?? ''), ['draft','active'], true)) { break; }
                }
            }
        }
        foreach ($patterns as &$pattern) {
            $pattern['action'] = ['available'=>false, 'url'=>'', 'plan_linked'=>false];
            if ($selectedhistoryuser <= 0 || !$patternemployee) { continue; }
            $key = (string)($pattern['key'] ?? '');
            $titlekey = 'battleplan_weekcoord_pattern_'.$key;
            $rulekey = 'battleplan_weekcoord_pattern_rule_'.$key;
            $matchedweeks = array_values(array_filter(array_map('strval', (array)($pattern['matched_weeks'] ?? []))));
            $title = get_string('battleplan_weekcoord_pattern_task_title', 'local_kbacompany',
                get_string($titlekey, 'local_kbacompany'));
            $trace = '[BMS24_PATTERN:'.strtoupper($key).':USER:'.$selectedhistoryuser.']';
            $description = get_string('battleplan_weekcoord_pattern_task_description', 'local_kbacompany', (object)[
                'signal'=>get_string($titlekey, 'local_kbacompany'),
                'rule'=>get_string($rulekey, 'local_kbacompany'),
                'weeks'=>implode(', ', $matchedweeks),
                'employee'=>(string)($patternemployee['employee_name'] ?? ''),
                'position'=>(string)($patternemployee['position']['name'] ?? ''),
            ])."\n\n".$trace;
            $linkedtasks = task_service::manual_tasks_by_trace_marker(
                $companyuuid, $actor, $trace, $selectedhistoryuser, $title, 5);
            // The feedback belongs to the latest visible linked task only. Never attach
            // an older completed task's facts to a newer active/cancelled action.
            $latestlinked = $linkedtasks ? $linkedtasks[0] : null;
            $completedlinked = is_array($latestlinked) &&
                (string)($latestlinked['status'] ?? '') === 'done' &&
                (int)($latestlinked['completed_at'] ?? 0) > 0 ? $latestlinked : null;
            $pattern['task_feedback'] = [
                'marker'=>$trace,
                'count'=>count($linkedtasks),
                'latest'=>$linkedtasks ? $linkedtasks[0] : null,
                'items'=>$linkedtasks,
                'factual_result'=>$completedlinked ? self::factual_result_after_task($completedlinked, $history) : null,
            ];
            $taskparams = [
                'uuid'=>$companyuuid, 'view'=>'create', 'assigneeid'=>$selectedhistoryuser,
                'startdate'=>(string)$overview['week_start'], 'duedate'=>(string)$overview['week_end'],
                'title'=>$title, 'description'=>$description,
            ];
            if (!empty($patternemployee['reviewer_id'])) {
                $taskparams['reviewerid'] = (int)$patternemployee['reviewer_id'];
            }
            if (in_array((string)($patternemployee['status'] ?? ''), ['draft','active'], true) &&
                    !empty($patternemployee['plan_uuid'])) {
                $taskparams['battleplanuuid'] = (string)$patternemployee['plan_uuid'];
                $pattern['action']['plan_linked'] = true;
            }
            $lateststatus = is_array($latestlinked) ? (string)($latestlinked['status'] ?? '') : '';
            // Avoid prompting a duplicate action while the latest linked canonical task is still active.
            $pattern['action']['available'] = !$latestlinked || in_array($lateststatus, ['done','cancelled'], true);
            $pattern['action']['url'] = (new \moodle_url('/local/kbacompany/tasks.php', $taskparams))->out(false);
        }
        unset($pattern);

        return [
            'company'=>$overview['company'],
            'week_start'=>$overview['week_start'],
            'week_end'=>$overview['week_end'],
            'orgnode_filter'=>$orgnodeuuid,
            'invalid_history_user_filter'=>$invalidhistoryuser,
            'orgnodes'=>$overview['orgnodes'],
            'summary'=>$summary,
            'performance'=>$overview['summary']['performance'],
            'by_unit'=>$overview['by_unit'],
            'unit_patterns'=>$unitpatterns,
            'ceo_overview'=>$ceosummary,
            'agenda'=>$agenda,
            'previous_week'=>$previousweek,
            'previous_week_followup'=>['summary'=>$followupsummary, 'rows'=>$followup],
            'management_history'=>[
                'weeks'=>$historyweeks,
                'userid'=>$selectedhistoryuser,
                'users'=>$historyusers,
                'rows'=>$history,
            ],
            'management_patterns'=>[
                'scope_userid'=>$selectedhistoryuser,
                'history_weeks'=>$historyweeks,
                'items'=>$patterns,
            ],
            'contract'=>self::contract(),
        ];
    }
}
