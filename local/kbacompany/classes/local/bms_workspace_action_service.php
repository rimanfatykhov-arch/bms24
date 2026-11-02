<?php
// RC29/1809: read-only, actor-scoped personal action queue.
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Each source is authorised before being passed to project(). The projection is
 * deliberately pure: no state transitions, guessed duties or duplicate counts.
 */
final class bms_workspace_action_service {
    public const LIMIT = 8;

    /**
     * Read only bounded actor-owned/reviewed plans. The caller has already
     * resolved the company membership; repeat the guard at the data boundary.
     */
    public static function plans(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $sql = "SELECT p.id, p.uuid, p.userid, p.reviewerid, p.status, p.periodstart,
                       p.periodend, p.statepayload, u.firstname, u.lastname
                  FROM {local_kbacompany_bplan} p
                  JOIN {user} u ON u.id = p.userid
                 WHERE p.companyuuid = :company
                   AND ((p.reviewerid = :reviewer AND p.status = 'submitted')
                     OR (p.userid = :owner AND p.status = 'draft'))
              ORDER BY p.periodstart DESC, p.id DESC";
        // Limit query work; high-priority review / returns may be on older weeks.
        return array_values($DB->get_records_sql($sql,
            ['company' => $companyuuid, 'reviewer' => $actor, 'owner' => $actor], 0, 60));
    }

    /** Returns actor-actionable canonical task reviews, already ACL-filtered. */
    public static function task_reviews(string $companyuuid, int $actor): array {
        $page = task_service::list_page($companyuuid, $actor,
            ['scope' => 'review'], 0, 20);
        return $page['tasks'];
    }

    /**
     * @param array $tasks Company/actor-scoped personal canonical tasks
     * @param array $reviews Company/actor-scoped review tasks
     * @param array $plans Company/actor-scoped plan rows (object or array)
     * @param array $exceptions Capability-filtered manager exception rows
     * @return array Items: ID, category, title, action, meta, URL, priority.
     */
    public static function project(string $companyuuid, int $actor, array $tasks, array $reviews,
            array $plans, array $exceptions, string $today, int $limit = self::LIMIT): array {
        $items = [];
        $put = static function(string $id, int $rank, string $kind, string $title, string $action,
                string $meta, string $url) use (&$items): void {
            if ($id === '' || $url === '' || $title === '') { return; }
            if (isset($items[$id]) && $items[$id]['rank'] <= $rank) { return; }
            $items[$id] = ['id' => $id, 'rank' => $rank, 'kind' => $kind,
                'title' => $title, 'action' => $action, 'meta' => $meta, 'url' => $url];
        };
        $taskurl = static function(string $uuid) use ($companyuuid): string {
            return (new \moodle_url('/local/kbacompany/tasks.php',
                ['uuid' => $companyuuid, 'task' => $uuid]))->out(false);
        };
        // Only BMS canonical rows: provider URLs from other applications must not
        // be exposed as unvalidated navigation targets in this queue.
        foreach ($tasks as $task) {
            $id = (string)($task['uuid'] ?? '');
            $status = (string)($task['status'] ?? '');
            if ($id === '' || ($task['provider'] ?? 'bms') !== 'bms' ||
                    in_array($status, ['done', 'cancelled', 'deleted'], true) ||
                    (int)($task['effective_assignee_id'] ?? $task['assignee_id'] ?? 0) !== $actor) {
                continue;
            }
            $due = (string)($task['due_date'] ?? '');
            $title = (string)($task['title'] ?? '');
            if ($status === 'returned') {
                $put('task:' . $id, 10, 'task', $title, 'bmsaction_fix_task',
                    $due, $taskurl($id));
            } else if ($due !== '' && $due < $today) {
                $put('task:' . $id, 20, 'task', $title, 'bmsaction_open_overdue',
                    $due, $taskurl($id));
            } else if ($due !== '' && $due === $today) {
                $put('task:' . $id, 40, 'task', $title, 'bmsaction_open_today',
                    $due, $taskurl($id));
            }
        }
        foreach ($reviews as $task) {
            $id = (string)($task['uuid'] ?? '');
            if ($id === '' || (string)($task['status'] ?? '') !== 'review' ||
                    (int)($task['reviewer_id'] ?? 0) !== $actor) { continue; }
            $put('task:' . $id, 15, 'task', (string)($task['title'] ?? ''),
                'bmsaction_review_task', (string)($task['due_date'] ?? ''), $taskurl($id));
        }
        foreach ($plans as $plan) {
            $p = (array)$plan;
            $id = (string)($p['uuid'] ?? '');
            $status = (string)($p['status'] ?? '');
            if ($id === '') { continue; }
            $action = '';
            $rank = 0;
            if ($status === 'submitted' && (int)($p['reviewerid'] ?? 0) === $actor) {
                $action = 'bmsaction_review_plan'; $rank = 12;
            } else if ($status === 'draft' && (int)($p['userid'] ?? 0) === $actor) {
                // Ordinary drafts are not automatically urgent. Only a recorded
                // latest return creates a duty to revise the plan.
                $history = battle_plan_review_cycle_service::project((string)($p['statepayload'] ?? ''));
                $events = (array)($history['rows'] ?? []);
                $last = $events ? end($events) : null;
                if ($last && (string)$last['type'] === 'returned') {
                    $action = 'bmsaction_fix_plan'; $rank = 11;
                }
            }
            if ($action === '') { continue; }
            $url = (new \moodle_url('/local/kbacompany/battle_plan.php',
                ['uuid' => $companyuuid, 'plan' => $id, 'view' => 'detail']))->out(false);
            $ownername = trim((string)($p['firstname'] ?? '') . ' ' . (string)($p['lastname'] ?? ''));
            $meta = trim((string)($p['periodstart'] ?? '') . ($ownername !== '' ? ' · ' . $ownername : ''));
            $put('plan:' . $id, $rank, 'plan', 'bmsaction_battle_plan', $action, $meta, $url);
        }
        // These rows are passed *only* from the existing management capability
        // checked read model. Non-actionable anomaly rows are not listed.
        foreach ($exceptions as $row) {
            if (empty($row['management_cycle']['requires_new_decision'])) { continue; }
            $metric = $row['metric'] ?? [];
            $id = (string)($row['binding']['uuid'] ?? '');
            $metricid = (string)($metric['uuid'] ?? '');
            if ($id === '' || $metricid === '') { continue; }
            $name = trim((string)($metric['code'] ?? '') . ' — ' . (string)($metric['name'] ?? ''), ' —');
            $url = (new \moodle_url('/local/kbacompany/indicators.php',
                ['uuid' => $companyuuid, 'tab' => 'exceptions', 'stat' => $metricid]))->out(false);
            $rank = (string)($row['severity'] ?? '') === 'critical' ? 5 : 25;
            $put('indicator:' . $id, $rank, 'indicator', $name, 'bmsaction_decide_indicator',
                (string)($row['context_label'] ?? ''), $url);
        }
        $rows = array_values($items);
        usort($rows, static function(array $a, array $b): int {
            return ($a['rank'] <=> $b['rank']) ?: strcmp($a['id'], $b['id']);
        });
        return array_slice($rows, 0, max(0, min(30, $limit)));
    }
}
