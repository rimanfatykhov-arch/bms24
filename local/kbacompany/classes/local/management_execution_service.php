<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Unified management implementation read-model.
 *
 * This service stores nothing. It composes existing canonical management
 * projections into one execution workspace.
 */
class management_execution_service {
    public const CONTRACT_VERSION = '0.8.4';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_management_execution_workspace',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'indicators' => 'indicator_management_exception_service',
                'battle_plans' => 'battle_plan_management_overview_service',
                'weekly_acceptance' => 'weekly_management_acceptance_service',
                'tasks' => 'canonical local_kbacompany_task via existing projections',
            ],
            'rules' => [
                'no_parallel_kpi_engine',
                'no_parallel_task_engine',
                'management_home_is_computed_not_persisted',
                'indicator_exception_to_task_to_battle_plan_remains_canonical_chain',
                'weekly_acceptance_is_a_gate_not_a_new_state_machine',
                'decision_loop_is_a_projection_over_existing_decisions_tasks_and_facts',
                'followup_never_claims_causality',
                'management_review_is_a_read_model_over_canonical_decisions_tasks_and_facts',
                'closure_readiness_is_derived_not_a_parallel_lifecycle',
                'decision_history_reuses_append_only_state_events_and_canonical_execution_links',
                'recurrence_is_descriptive_and_does_not_claim_root_cause_or_causality',
                'management_learning_is_a_read_model_not_a_knowledge_lifecycle',
                'recurring_problem_patterns_are_descriptive_signals_not_root_cause_claims',
                'patterns_reuse_decision_history_and_do_not_create_a_parallel_problem_engine',
                'all_reads_are_company_scoped',
            ],
        ];
    }

    /**
     * Management decision-loop projection over the existing exception read model.
     * No lifecycle is stored here; stages are derived from canonical decision/task/fact state.
     */
    private static function decision_loops(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $cycle = (array)($row['management_cycle'] ?? []);
            $task = (array)($cycle['task'] ?? []);
            $observation = (array)($cycle['observation'] ?? []);
            $decisionuuid = (string)($cycle['action_event_uuid'] ?? $row['decision_uuid'] ?? '');
            $stage = 'decision';
            if ($decisionuuid === '') {
                $stage = 'decision';
            } else if (empty($task['exists'])) {
                $stage = 'action';
            } else if (!in_array((string)($task['status'] ?? ''), ['done', 'cancelled'], true)) {
                $stage = !empty($task['overdue']) ? 'control' : 'execution';
            } else if ((string)($observation['quality'] ?? 'insufficient') === 'insufficient') {
                $stage = 'followup';
            } else if (!empty($cycle['requires_new_decision'])) {
                $stage = 'repeat';
            } else {
                $stage = 'closed';
            }
            $out[] = [
                'bind_uuid' => (string)($row['binding']['uuid'] ?? ''),
                'metric_name' => (string)($row['metric']['name'] ?? ''),
                'metric_code' => (string)($row['metric']['code'] ?? ''),
                'context_label' => (string)($row['context_label'] ?? ''),
                'severity' => (string)($row['severity'] ?? 'medium'),
                'stage' => $stage,
                'decision_uuid' => $decisionuuid,
                'decision_comment' => (string)($row['decision_comment'] ?? ''),
                'decision_time' => (int)($row['decision_time'] ?? 0),
                'decision_period' => (string)($cycle['action_period'] ?? ''),
                'task_exists' => !empty($task['exists']),
                'task_visible' => !empty($task['visible']),
                'task_title' => (string)($task['title'] ?? ''),
                'task_status' => (string)($task['status'] ?? ''),
                'task_url' => (string)($task['url'] ?? ''),
                'task_overdue' => !empty($task['overdue']),
                'followup_period' => (string)($observation['followup_period'] ?? ''),
                'baseline_fact' => $observation['baseline_fact'] ?? null,
                'followup_fact' => $observation['followup_fact'] ?? null,
                'followup_quality' => (string)($observation['quality'] ?? 'insufficient'),
                'requires_new_decision' => !empty($cycle['requires_new_decision']),
            ];
        }
        return $out;
    }

    /** Management review/closure readiness projection. No additional lifecycle is stored. */
    private static function review_rows(array $loops): array {
        $out = [];
        foreach ($loops as $row) {
            $state = 'active';
            if ((string)$row['stage'] === 'closed') {
                $state = 'closure_ready';
            } else if ((string)$row['stage'] === 'repeat') {
                $state = 'repeat_required';
            } else if ((string)$row['stage'] === 'followup') {
                $state = 'awaiting_fact';
            }
            $out[] = $row + [
                'review_state' => $state,
                'review_outcome' => (string)$row['followup_quality'],
                'review_complete' => $state === 'closure_ready',
            ];
        }
        return $out;
    }

    /**
     * Historical management decisions and descriptive recurrence summary.
     *
     * This is deliberately a read-model over the append-only state event history. A repeated
     * binding means the same indicator/context binding has multiple management decisions; it
     * does not prove the same root cause or the effectiveness of any prior action.
     */
    private static function decision_history(string $companyuuid, int $actor): array {
        $history = indicator_condition_execution_service::history($companyuuid, $actor, '', 300);
        $registry = indicator_registry_service::context($companyuuid, $actor);

        $metrics = [];
        foreach ((array)($registry['metrics'] ?? []) as $metric) {
            $metrics[(string)$metric['uuid']] = $metric;
        }
        $bindings = [];
        foreach ((array)($registry['bindings'] ?? []) as $binding) {
            if (!empty($binding['compatibility'])) { continue; }
            $bindings[(string)$binding['uuid']] = $binding;
        }

        $counts = [];
        foreach ($history as $event) {
            $binduuid = (string)($event['bind_uuid'] ?? '');
            if ($binduuid !== '') {
                $counts[$binduuid] = ($counts[$binduuid] ?? 0) + 1;
            }
        }

        $rows = [];
        $withactions = 0;
        $withoutactions = 0;
        foreach ($history as $event) {
            $metricuuid = (string)($event['stat_uuid'] ?? '');
            $metric = $metrics[$metricuuid] ?? [];
            $binduuid = (string)($event['bind_uuid'] ?? '');
            $binding = $bindings[$binduuid] ?? [];
            $tasks = (array)($event['tasks'] ?? []);
            $taskcount = count($tasks);
            if ($taskcount > 0) { $withactions++; } else { $withoutactions++; }
            $completed = 0;
            foreach ($tasks as $task) {
                if ((string)($task['status'] ?? '') === 'done') { $completed++; }
            }
            $rows[] = [
                'uuid' => (string)($event['uuid'] ?? ''),
                'bind_uuid' => $binduuid,
                'period' => (string)($event['period_key'] ?? ''),
                'time_created' => (int)($event['time_created'] ?? 0),
                'metric_code' => (string)($metric['code'] ?? ''),
                'metric_name' => (string)($metric['name'] ?? ''),
                'context_kind' => (string)($binding['contextkind'] ?? ''),
                'condition' => (string)($event['condition'] ?? ''),
                'event_kind' => (string)($event['event_kind'] ?? ''),
                'comment' => (string)($event['comment'] ?? ''),
                'actor_name' => (string)($event['actor_name'] ?? ''),
                'task_count' => $taskcount,
                'completed_task_count' => $completed,
                'tasks' => $tasks,
                'repeat_count' => (int)($counts[$binduuid] ?? 0),
                'is_repeated' => (int)($counts[$binduuid] ?? 0) > 1,
            ];
        }

        $repeatbindings = 0;
        foreach ($counts as $count) {
            if ($count > 1) { $repeatbindings++; }
        }
        return [
            'rows' => $rows,
            'summary' => [
                'decisions' => count($rows),
                'repeat_bindings' => $repeatbindings,
                'with_actions' => $withactions,
                'without_actions' => $withoutactions,
            ],
        ];
    }

    /** Management decision history entry point; computed only on the history screen. */
    public static function history_context(string $companyuuid, int $actor): array {
        company_context_service::context($companyuuid, $actor);
        return self::decision_history($companyuuid, $actor);
    }

    /**
     * Descriptive recurring-problem patterns built from decision history.
     *
     * A pattern means repeated management decisions for the same canonical indicator/context binding.
     * It does not prove an identical root cause, nor the effectiveness or ineffectiveness of a prior action.
     */
    public static function patterns_context(string $companyuuid, int $actor): array {
        company_context_service::context($companyuuid, $actor);
        $history = self::decision_history($companyuuid, $actor);
        $groups = [];
        foreach ((array)$history['rows'] as $row) {
            $key = (string)($row['bind_uuid'] ?? '');
            if ($key === '') {
                $key = (string)($row['metric_code'] ?? '') . '|' . (string)($row['metric_name'] ?? '') . '|' .
                    (string)($row['context_kind'] ?? '');
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $row;
        }

        $patterns = [];
        $withoutactions = 0;
        $aftercompleted = 0;
        $totaloccurrences = 0;
        foreach ($groups as $key => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            usort($rows, static function(array $a, array $b): int {
                return ((int)$a['time_created']) <=> ((int)$b['time_created']);
            });
            $first = $rows[0];
            $last = $rows[count($rows) - 1];
            $withactions = 0;
            $completedactions = 0;
            $noactions = 0;
            foreach ($rows as $row) {
                if ((int)$row['task_count'] > 0) {
                    $withactions++;
                    if ((int)$row['completed_task_count'] > 0) {
                        $completedactions++;
                    }
                } else {
                    $noactions++;
                }
            }
            $recursaftercompleted = false;
            for ($i = 0; $i < count($rows) - 1; $i++) {
                if ((int)$rows[$i]['completed_task_count'] > 0) {
                    $recursaftercompleted = true;
                    break;
                }
            }
            if ($noactions > 0) { $withoutactions++; }
            if ($recursaftercompleted) { $aftercompleted++; }
            $totaloccurrences += count($rows);
            $patterns[] = [
                'key' => $key,
                'metric_code' => (string)$last['metric_code'],
                'metric_name' => (string)$last['metric_name'],
                'context_kind' => (string)$last['context_kind'],
                'occurrences' => count($rows),
                'first_period' => (string)$first['period'],
                'last_period' => (string)$last['period'],
                'with_actions' => $withactions,
                'without_actions' => $noactions,
                'completed_actions' => $completedactions,
                'recurs_after_completed_action' => $recursaftercompleted,
                'last_comment' => (string)$last['comment'],
                'last_actor_name' => (string)$last['actor_name'],
            ];
        }
        usort($patterns, static function(array $a, array $b): int {
            if ((int)$a['occurrences'] === (int)$b['occurrences']) {
                return strcmp((string)$b['last_period'], (string)$a['last_period']);
            }
            return ((int)$b['occurrences']) <=> ((int)$a['occurrences']);
        });

        return [
            'rows' => $patterns,
            'summary' => [
                'patterns' => count($patterns),
                'occurrences' => $totaloccurrences,
                'without_actions' => $withoutactions,
                'after_completed_action' => $aftercompleted,
            ],
        ];
    }

    public static function context(string $companyuuid, int $actor, string $week = ''): array {
        company_context_service::context($companyuuid, $actor);
        if (!permission_service::can_manage_management($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }

        $exceptions = indicator_management_exception_service::context($companyuuid, $actor);
        $overview = battle_plan_management_overview_service::context($companyuuid, $actor, $week);
        $acceptance = weekly_management_acceptance_service::context($companyuuid, $actor, $week);

        $summary = [
            'needs_decision' => (int)($exceptions['summary']['needs_decision'] ?? 0),
            'critical_exceptions' => (int)($exceptions['summary']['critical'] ?? 0),
            'overdue_tasks' => (int)($overview['summary']['overdue_tasks'] ?? 0),
            'open_tasks' => (int)($overview['summary']['open_tasks'] ?? 0),
            'active_plans' => (int)($overview['summary']['active_plans'] ?? 0),
            'critical_indicators' => (int)($overview['summary']['critical_indicators'] ?? 0),
            'hard_gates' => (int)($acceptance['hard_count'] ?? 0),
            'attention_gates' => (int)($acceptance['attention_count'] ?? 0),
        ];

        $attention = [];
        foreach (array_slice((array)($exceptions['rows'] ?? []), 0, 8) as $row) {
            $attention[] = [
                'kind' => 'exception',
                'severity' => (string)($row['severity'] ?? 'medium'),
                'title' => (string)($row['metric']['name'] ?? ''),
                'context' => (string)($row['context_label'] ?? ''),
                'needs_decision' => !empty($row['management_cycle']['requires_new_decision']),
                'overdue' => !empty($row['tasks']['overdue']),
            ];
        }

        $loops = self::decision_loops((array)($exceptions['rows'] ?? []));
        $reviews = self::review_rows($loops);
        $reviewsummary = ['closure_ready'=>0,'repeat_required'=>0,'awaiting_fact'=>0,'active'=>0];
        foreach ($reviews as $review) {
            $key = (string)$review['review_state'];
            if (isset($reviewsummary[$key])) { $reviewsummary[$key]++; }
        }

        return [
            'company' => company_context_service::context($companyuuid, $actor),
            'week_start' => (string)$acceptance['week_start'],
            'week_end' => (string)$acceptance['week_end'],
            'acceptance' => $acceptance,
            'exceptions' => $exceptions,
            'overview' => $overview,
            'summary' => $summary,
            'attention' => $attention,
            'decision_loops' => $loops,
            'review_rows' => $reviews,
            'review_summary' => $reviewsummary,
            'contract' => self::contract(),
        ];
    }
}
