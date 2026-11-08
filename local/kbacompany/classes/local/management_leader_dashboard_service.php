<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RC29/1800: read-only command overview of canonical management facts.
 *
 * Does not create a second workflow or persist derived KPIs. The supplied base
 * is already validated by management_execution_service and each additional
 * provider enforces its own company / actor read permissions.
 */
final class management_leader_dashboard_service {
    public const CONTRACT_VERSION = '1.0.0';
    public const VISIBLE_LIMIT = 16;

    public static function contract(): array {
        return [
            'name' => 'bms24_leader_dashboard',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'exceptions' => 'management_execution_service / indicator_management_exception_service',
                'approval_queue' => 'battle_plan_management_overview_service',
                'tasks' => 'task_service::list_page / canonical task ACL',
                'protocol' => 'battle_plan_protocol_service::decision_queue / explicit manageorganization',
                'acceptance' => 'weekly_management_acceptance_service',
            ],
            'rules' => [
                'company_and_actor_rechecked_before_each_provider_read',
                'all_counts_are_computed_before_display_limit',
                'tasks_are_current_state_not_a_week_end_snapshot',
                'decisions_are_latest_saved_protocol_revisions_only',
                'protocol_unavailable_is_not_displayed_as_zero',
                'no_parallel_workflows_or_SLA_inference',
                'links_are_to_authorized_canonical_controllers',
            ],
        ];
    }

    /**
     * Deterministic, independent-of-Moodle projection for regression tests.
     * Providers must supply pre-authorized rows and canonical URLs.
     * $tasks includes a full SQL total and one bounded page of overdue rows.
     */
    public static function project(array $base, array $tasks, array $protocol,
            bool $protocolavailable, string $today, int $limit = self::VISIBLE_LIMIT): array {
        $queue = (array)($base['overview']['review_queue'] ?? []);
        $exceptions = (array)($base['exceptions']['rows'] ?? []);
        $taskrows = (array)($tasks['tasks'] ?? []);
        $protocolrows = [];
        $decisionoverdue = 0;
        $decisionopen = 0;
        if ($protocolavailable) {
            foreach ($protocol as $row) {
                $status = (string)($row['status'] ?? 'pending');
                if (!in_array($status, ['pending', 'inprogress'], true)) { continue; }
                $decisionopen++;
                $due = (string)($row['due'] ?? '');
                if ($due === '' || $due >= $today) { continue; }
                $decisionoverdue++;
                $protocolrows[] = $row;
            }
        }
        $rows = [];
        foreach ($exceptions as $exception) {
            $severity = (string)($exception['severity'] ?? 'medium');
            $cycle = (array)($exception['management_cycle'] ?? []);
            $needsdecision = !empty($cycle['requires_new_decision']) ||
                (string)($cycle['status'] ?? '') === 'needs_decision';
            if (!$needsdecision && $severity !== 'critical') { continue; }
            $rows[] = [
                'kind' => 'exception', 'title' => (string)($exception['metric']['name'] ?? ''),
                'detail' => (string)($exception['context_label'] ?? ''),
                'note' => $needsdecision ? 'leader_dashboard_reason_decision' : 'leader_dashboard_reason_critical',
                'url' => (string)($exception['dashboard_url'] ?? ''),
                'priority' => $needsdecision ? 10 : 15, 'due' => '',
            ];
        }
        foreach ($queue as $plan) {
            $rows[] = [
                'kind' => 'plan', 'title' => (string)($plan['employee_name'] ?? ''),
                'detail' => (string)($plan['orgnode']['name'] ?? ''),
                'note' => 'leader_dashboard_reason_review',
                'url' => (string)($plan['url'] ?? ''), 'priority' => 20,
                'due' => '',
            ];
        }
        foreach ($protocolrows as $decision) {
            $rows[] = [
                'kind' => 'protocol', 'title' => (string)($decision['title'] ?? ''),
                'detail' => (string)($decision['employee'] ?? ''),
                'note' => 'leader_dashboard_reason_protocol',
                'url' => (string)($decision['url'] ?? ''),
                'priority' => 30, 'due' => (string)($decision['due'] ?? ''),
            ];
        }
        foreach ($taskrows as $task) {
            // Defensive: page contents should already satisfy the canonical SQL signal.
            $status = (string)($task['status'] ?? '');
            $due = (string)($task['due_date'] ?? '');
            if ($due === '' || $due >= $today || in_array($status, ['done', 'cancelled'], true)) { continue; }
            $rows[] = [
                'kind' => 'task', 'title' => (string)($task['title'] ?? ''),
                'detail' => (string)($task['assignee_name'] ?? ''),
                'note' => 'leader_dashboard_reason_task',
                'url' => (string)($task['dashboard_url'] ?? ''),
                'priority' => 40, 'due' => $due,
            ];
        }
        usort($rows, static function(array $a, array $b): int {
            return ($a['priority'] <=> $b['priority']) ?: strcmp($a['due'], $b['due']) ?:
                strcmp($a['title'], $b['title']);
        });
        $totals = [
            'needs_decision' => (int)($base['summary']['needs_decision'] ?? 0),
            'critical_exceptions' => (int)($base['summary']['critical_exceptions'] ?? 0),
            'approval_queue' => count($queue),
            // Canonical SQL count is NOT computed from the first page of task rows.
            'overdue_tasks' => (int)($tasks['counts']['total'] ?? 0),
            'protocol_open' => $protocolavailable ? $decisionopen : null,
            'protocol_overdue' => $protocolavailable ? $decisionoverdue : null,
            'active_plans' => (int)($base['summary']['active_plans'] ?? 0),
        ];
        return [
            'totals' => $totals,
            'items' => array_slice($rows, 0, max(0, $limit)),
            // Queue may be longer than the task page. Provide a lower-bound indicator.
            'shown' => min(count($rows), max(0, $limit)),
            'has_more' => count($rows) > max(0, $limit) ||
                (int)($tasks['counts']['total'] ?? 0) > count($taskrows),
            'protocol_available' => $protocolavailable,
            'acceptance' => (array)($base['acceptance'] ?? []),
            'week_start' => (string)($base['week_start'] ?? ''),
            'week_end' => (string)($base['week_end'] ?? ''),
        ];
    }

    /** Assemble only explicitly authorized read models for the selected company. */
    public static function context(string $companyuuid, int $actor, array $base): array {
        company_context_service::context($companyuuid, $actor);
        if (!permission_service::can_manage_management($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
        $week = battle_plan_service::week_start((string)($base['week_start'] ?? ''));
        $today = gmdate('Y-m-d');
        $url = (new \moodle_url('/local/kbacompany/indicators.php',
            ['uuid'=>$companyuuid, 'tab'=>'exceptions']))->out(false);
        // Preserve provider-proven visibility: no broad direct indicator lookup.
        foreach ($base['exceptions']['rows'] as &$exception) {
            $exception['dashboard_url'] = $url;
        }
        unset($exception);
        $tasks = task_service::list_page($companyuuid, $actor,
            ['scope'=>'all', 'signal'=>'overdue'], 0, self::VISIBLE_LIMIT);
        foreach ($tasks['tasks'] as &$task) {
            $task['dashboard_url'] = (new \moodle_url('/local/kbacompany/tasks.php',
                ['uuid'=>$companyuuid, 'task'=>(string)$task['uuid'], 'view'=>'detail']))->out(false);
        }
        unset($task);
        $protocolavailable = has_capability('local/kbacompany:manageorganization',
            \context_system::instance(), $actor);
        $protocol = [];
        if ($protocolavailable) {
            // Enforces company context AND protocol-visible weekly task ACL internally.
            foreach (battle_plan_protocol_service::decision_queue($companyuuid, $actor, $week, '') as $decision) {
                $protocol[] = [
                    'title' => (string)$decision->decisiontext,
                    'employee' => (string)$decision->employeename,
                    'status' => (string)$decision->executionstatus,
                    'due' => (string)$decision->duedate,
                    'url' => (new \moodle_url('/local/kbacompany/battle_plan_week_coordination.php',
                        ['uuid'=>$companyuuid,'week'=>$week,'decisiontask'=>(string)$decision->taskuuid]))->out(false),
                ];
            }
        }
        return self::project($base, $tasks, $protocol, $protocolavailable, $today);
    }
}
