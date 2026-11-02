<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read-only mapping of Corporate source tasks into one selected week of BMS Battle Plans. */
final class implementation_project_weekly_service {
    private const SOURCE = 'kbacorporate_impl';
    private const BRIDGE = '\local_kbacorporate\local\bridge';

    public static function can_view_plans(string $uuid, int $actor): bool {
        return permission_service::can_manage_tasks($uuid, $actor) ||
            access_role_service::can($uuid, $actor, 'battle_plan', 'edit');
    }

    /** Pure aggregation. A source task is counted once even if linked to multiple weekly plans. */
    public static function summarize(array $sections, array $source, array $links): array {
        $result = [];
        foreach ($sections as $section) {
            $code = (string)$section['code'];
            $result[$code] = ['planned' => 0, 'unplanned' => 0, 'review' => 0,
                'completed' => 0, 'cancelled' => 0, 'tasks' => []];
        }
        $seen = [];
        foreach ($source as $task) {
            $id = (string)($task['external_id'] ?? '');
            $code = (string)($task['section_code'] ?? '');
            if (!preg_match('/^impltask:[1-9][0-9]*$/D', $id) || !isset($result[$code]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $status = (string)($task['status'] ?? '');
            // A stale link to a different employee must not count as planning this task.
            $plans = array_filter($links[$id] ?? [], static function($plan) use ($task): bool {
                return (int)($plan['owner_id'] ?? 0) > 0 &&
                    (int)$plan['owner_id'] === (int)($task['assignee_id'] ?? 0);
            });
            $item = $task;
            $item['plan_links'] = $plans;
            $result[$code]['tasks'][] = $item;
            if ($status === 'completed') { $result[$code]['completed']++; }
            else if ($status === 'review') { $result[$code]['review']++; }
            else if ($status === 'cancelled') { $result[$code]['cancelled']++; }
            else if (in_array($status, ['planned', 'inprogress'], true)) {
                if ($plans) { $result[$code]['planned']++; }
                else { $result[$code]['unplanned']++; }
            }
        }
        return $result;
    }

    /** ACL comes first from the existing linked-project stage model and Corporate public bridge. */
    public static function model(string $uuid, int $actor, int $programid, string $week = ''): array {
        global $DB;
        $base = implementation_project_stages_service::model($uuid, $actor, $programid);
        $date = $week !== '' ? $week : battle_plan_service::user_today();
        $monday = battle_plan_service::week_start($date);
        $base['week'] = $monday;
        $base['can_view_plans'] = self::can_view_plans($uuid, $actor);
        $base['weekly_complete'] = false;
        $base['weekly_reason'] = '';
        $base['weekly_sections'] = [];
        if (empty($base['stages']['available']) || !$base['can_view_plans']) {
            $base['weekly_reason'] = !$base['can_view_plans'] ? 'restricted' : 'unconfirmed_baseline';
            return $base;
        }
        $bridge = self::BRIDGE;
        if (!class_exists($bridge) || !method_exists($bridge, 'get_implementation_program_task_sections') ||
                version_compare((string)$bridge::API_RELEASE, '1.25', '<')) {
            $base['weekly_reason'] = 'upgrade_corporate';
            return $base;
        }
        $source = $bridge::get_implementation_program_task_sections((int)$base['companyid'], $actor, $programid);
        if (!$source || (int)($source['company_id'] ?? 0) !== (int)$base['companyid'] ||
                (int)($source['program_id'] ?? 0) !== $programid ||
                (int)($source['audit_id'] ?? 0) !== (int)$base['stages']['audit_id'] ||
                empty($source['complete'])) {
            $base['weekly_reason'] = $source['reason'] ?? 'incomplete';
            return $base;
        }
        // One bounded company-and-week query; no per-task plan reads and no Corporate table access.
        $rows = $DB->get_records_sql(
            "SELECT i.id, i.externalid, p.uuid AS planuuid, p.userid, p.status,
                    p.periodstart, p.periodend
               FROM {local_kbacompany_bplanitem} i
               JOIN {local_kbacompany_bplan} p
                 ON p.companyuuid = i.companyuuid AND p.uuid = i.planuuid
              WHERE i.companyuuid = :companyuuid AND p.companyuuid = :plancompany
                AND i.provider = :provider AND p.periodstart = :week
           ORDER BY i.id ASC",
            ['companyuuid'=>$uuid, 'plancompany'=>$uuid,
                'provider'=>self::SOURCE, 'week'=>$monday]);
        $links = [];
        foreach ($rows as $row) {
            $external = (string)$row->externalid;
            $links[$external][(string)$row->planuuid] = [
                'plan_uuid'=>(string)$row->planuuid,
                'owner_id'=>(int)$row->userid, 'status'=>(string)$row->status,
            ];
        }
        $base['weekly_sections'] = self::summarize($base['stages']['sections'], $source['tasks'], $links);
        $base['weekly_complete'] = true;
        return $base;
    }
}
