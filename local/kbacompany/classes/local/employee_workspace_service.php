<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Employee 2.0 read model.
 *
 * This service only aggregates canonical BMS24 objects. It owns no employee,
 * task, metric, Battle Plan, competency, learning, document or development data.
 */
class employee_workspace_service {
    public const CONTRACT_VERSION = '1.1.0-rc15';

    public static function contract(): array {
        return [
            'contract' => 'bms_employee_2_workspace',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required_before_fetch',
            'rules' => [
                'employee_card_is_read_model_over_canonical_objects',
                'no_task_metric_battle_plan_competency_or_learning_copy',
                'sensitive_employee_tabs_require_self_or_people_management_permission',
                'assignment_history_is_read_from_position_assignments',
                'business_roles_remain_provider_neutral_projection',
                'position_metrics_remain_canonical_indicator_bindings',
                'learning_status_is_read_live_and_not_competency_evidence',
            ],
        ];
    }

    public static function can_inspect(string $companyuuid, int $employeeuserid, int $actorid): bool {
        company_context_service::context($companyuuid, $actorid);
        return $employeeuserid === $actorid || is_siteadmin($actorid) ||
            permission_service::can_manage_people($companyuuid, $actorid) ||
            permission_service::can_manage_organization($companyuuid, $actorid);
    }

    public static function tab_context(string $companyuuid, int $employeeuserid, int $actorid,
            string $tab, array $employee): array {
        company_context_service::context($companyuuid, $actorid);
        $caninspect = self::can_inspect($companyuuid, $employeeuserid, $actorid);
        $base = ['can_inspect' => $caninspect, 'contract' => self::contract()];

        if ($tab === 'overview') {
            return $base + self::overview($companyuuid, $employeeuserid, $actorid, $employee, $caninspect);
        }
        if ($tab === 'assignments') {
            return $base + self::assignments($companyuuid, $employeeuserid, $actorid, $caninspect);
        }
        if ($tab === 'metrics') {
            return $base + self::metrics($companyuuid, $actorid, $employee);
        }
        if ($tab === 'calendar') {
            // Calendar-specific grants are independent of access to private employee HR tabs.
            return $base + ['calendar_self' => $employeeuserid === $actorid];
        }
        if (!$caninspect) {
            return $base + ['restricted' => true];
        }
        if ($tab === 'battleplan') {
            return $base + self::battle_plans($companyuuid, $employeeuserid);
        }
        if ($tab === 'tasks') {
            return $base + self::tasks($companyuuid, $employeeuserid);
        }
        if ($tab === 'competencies') {
            return $base + self::competencies($companyuuid, $employeeuserid, $actorid);
        }
        if ($tab === 'learning') {
            return $base + self::learning($companyuuid, $employeeuserid, $actorid);
        }
        if ($tab === 'development') {
            return $base + self::development($companyuuid, $employeeuserid);
        }
        if ($tab === 'documents') {
            return $base + self::documents($companyuuid, $employeeuserid);
        }
        return $base;
    }

    private static function overview(string $companyuuid, int $employeeuserid, int $actorid,
            array $employee, bool $caninspect): array {
        global $DB;
        $now = time();
        $summary = [
            'assignments' => 0,
            'metrics' => 0,
            'open_tasks' => 0,
            'battle_plan' => '',
            'competency_gaps' => 0,
            'learning_active' => 0,
            'development_active' => 0,
            'documents_pending' => 0,
        ];
        $summary['assignments'] = (int)$DB->count_records_select('local_kbacompany_posassign',
            'companyuuid = :company AND userid = :userid AND active = 1 AND (effectivefrom = 0 OR effectivefrom <= :now1) '
            . 'AND (effectiveto = 0 OR effectiveto >= :now2)',
            ['company' => $companyuuid, 'userid' => $employeeuserid, 'now1' => $now, 'now2' => $now]);

        if (!empty($employee['position']['uuid'])) {
            try {
                $metrics = position_indicator_assignment_service::context($companyuuid, $actorid,
                    (string)$employee['position']['uuid']);
                $summary['metrics'] = count($metrics['rows']);
            } catch (\Throwable $e) {
                $summary['metrics'] = 0;
            }
        }
        if (!$caninspect) {
            return ['summary' => $summary];
        }

        $summary['open_tasks'] = (int)$DB->count_records_select('local_kbacompany_task',
            'companyuuid = :company AND assigneeid = :userid AND status <> :done AND status <> :cancelled AND status <> :deleted',
            ['company' => $companyuuid, 'userid' => $employeeuserid, 'done' => 'done', 'cancelled' => 'cancelled', 'deleted' => 'deleted']);
        $plans = $DB->get_records_sql('SELECT uuid, periodstart, periodend, status FROM {local_kbacompany_bplan} '
            . 'WHERE companyuuid = :company AND userid = :userid ORDER BY periodstart DESC, id DESC',
            ['company' => $companyuuid, 'userid' => $employeeuserid], 0, 1);
        if ($plans) {
            $plan = reset($plans);
            $summary['battle_plan'] = (string)$plan->status;
            $summary['battle_plan_uuid'] = (string)$plan->uuid;
            $summary['battle_plan_period'] = (string)$plan->periodstart . ' — ' . (string)$plan->periodend;
        }
        try {
            $competencies = employee_competency_profile_service::context($companyuuid, $employeeuserid, $actorid);
            $summary['competency_gaps'] = (int)$competencies['summary']['gap'];
        } catch (\Throwable $e) {
            $summary['competency_gaps'] = 0;
        }
        try {
            $learning = self::learning($companyuuid, $employeeuserid, $actorid);
            $summary['learning_active'] = (int)($learning['employee']['active_courses'] ?? 0);
        } catch (\Throwable $e) {
            $summary['learning_active'] = 0;
        }
        $summary['development_active'] = (int)$DB->count_records_select('local_kbacompany_devplan',
            'companyuuid = :company AND userid = :userid AND status <> :completed',
            ['company' => $companyuuid, 'userid' => $employeeuserid, 'completed' => 'completed']);
        $summary['documents_pending'] = (int)$DB->count_records_select('local_kbacompany_docack',
            'companyuuid = :company AND userid = :userid AND status <> :acknowledged',
            ['company' => $companyuuid, 'userid' => $employeeuserid, 'acknowledged' => 'acknowledged']);
        return ['summary' => $summary];
    }

    private static function assignments(string $companyuuid, int $employeeuserid, int $actorid,
            bool $caninspect): array {
        global $DB;
        $now = time();
        $params = ['company' => $companyuuid, 'userid' => $employeeuserid];
        $limit = $caninspect ? 50 : 20;
        $extra = $caninspect ? '' : ' AND pa.active = 1 AND (pa.effectivefrom = 0 OR pa.effectivefrom <= :now1) '
            . 'AND (pa.effectiveto = 0 OR pa.effectiveto >= :now2)';
        if (!$caninspect) { $params['now1'] = $now; $params['now2'] = $now; }
        $rows = $DB->get_records_sql('SELECT pa.*, p.name AS positionname, n.name AS unitname '
            . 'FROM {local_kbacompany_posassign} pa '
            . 'JOIN {local_kbacompany_position} p ON p.companyuuid = pa.companyuuid AND p.uuid = pa.positionuuid '
            . 'LEFT JOIN {local_kbacompany_orgnode} n ON n.companyuuid = p.companyuuid AND n.uuid = p.orgnodeuuid '
            . 'WHERE pa.companyuuid = :company AND pa.userid = :userid' . $extra
            . ' ORDER BY pa.active DESC, pa.effectivefrom DESC, pa.id DESC', $params, 0, $limit);
        $assignments = [];
        foreach ($rows as $row) {
            $assignments[] = [
                'position_uuid' => (string)$row->positionuuid,
                'position' => (string)$row->positionname,
                'unit' => (string)($row->unitname ?? ''),
                'type' => (string)$row->assignmenttype,
                'fte' => (float)$row->assignmentfte,
                'effective_from' => (int)$row->effectivefrom,
                'effective_to' => (int)$row->effectiveto,
                'active' => !empty($row->active),
            ];
        }
        $roles = [];
        try {
            $ctx = business_role_service::business_roles_context($companyuuid, $actorid);
            foreach ($ctx['employees'] as $person) {
                if ((int)$person['user_id'] === $employeeuserid) {
                    $roles = $person['roles'];
                    break;
                }
            }
        } catch (\Throwable $e) {
            $roles = [];
        }
        return ['assignments' => $assignments, 'business_roles' => $roles];
    }

    private static function metrics(string $companyuuid, int $actorid, array $employee): array {
        if (empty($employee['position']['uuid'])) {
            return ['position' => null, 'metrics' => []];
        }
        try {
            $context = position_indicator_assignment_service::context($companyuuid, $actorid,
                (string)$employee['position']['uuid']);
        } catch (\Throwable $e) {
            return ['position' => $employee['position'], 'metrics' => []];
        }
        $rows = [];
        foreach ($context['rows'] as $row) {
            $metric = $row['metric'];
            $norm = $row['norm'];
            $rows[] = [
                'uuid' => (string)$metric['uuid'],
                'code' => (string)$metric['code'],
                'name' => (string)$metric['name'],
                'unit' => (string)$metric['unit'],
                'period' => (string)$metric['periodtype'],
                'direction' => (string)$metric['direction'],
                'primary' => !empty($row['binding']['isprimary']),
                'critical' => !empty($row['binding']['critical']),
                'weight' => (string)$row['binding']['weight'],
                'weekly' => !empty($row['appears_in_weekly_battle_plan']),
                'norm_kind' => $norm ? (string)$norm['normkind'] : '',
                'target' => $norm ? (string)$norm['targetvalue'] : '',
                'min' => $norm ? (string)$norm['minval'] : '',
                'max' => $norm ? (string)$norm['maxval'] : '',
            ];
        }
        return ['position' => $employee['position'], 'metrics' => $rows];
    }

    private static function battle_plans(string $companyuuid, int $employeeuserid): array {
        global $DB;
        $rows = $DB->get_records_sql('SELECT bp.*, p.name AS positionname FROM {local_kbacompany_bplan} bp '
            . 'LEFT JOIN {local_kbacompany_position} p ON p.companyuuid = bp.companyuuid AND p.uuid = bp.positionuuid '
            . 'WHERE bp.companyuuid = :company AND bp.userid = :userid '
            . 'ORDER BY bp.periodstart DESC, bp.id DESC',
            ['company' => $companyuuid, 'userid' => $employeeuserid], 0, 12);
        $plans = [];
        foreach ($rows as $row) {
            $plans[] = [
                'uuid' => (string)$row->uuid,
                'position' => (string)($row->positionname ?? ''),
                'period_start' => (string)$row->periodstart,
                'period_end' => (string)$row->periodend,
                'status' => (string)$row->status,
                'reviewer_id' => (int)$row->reviewerid,
                'observer_id' => (int)$row->observerid,
                'modified' => (int)$row->timemodified,
            ];
        }
        return ['battle_plans' => $plans];
    }

    private static function tasks(string $companyuuid, int $employeeuserid): array {
        global $DB;
        $counts = ['open' => 0, 'doing' => 0, 'review' => 0, 'done' => 0, 'overdue' => 0];
        $rows = $DB->get_records_sql('SELECT status, COUNT(1) AS cnt FROM {local_kbacompany_task} '
            . 'WHERE companyuuid = :company AND assigneeid = :userid GROUP BY status',
            ['company' => $companyuuid, 'userid' => $employeeuserid]);
        foreach ($rows as $row) {
            $status = (string)$row->status;
            if (isset($counts[$status])) { $counts[$status] = (int)$row->cnt; }
        }
        $today = gmdate('Y-m-d');
        $counts['overdue'] = (int)$DB->count_records_select('local_kbacompany_task',
            'companyuuid = :company AND assigneeid = :userid AND duedate <> :empty AND duedate < :today '
            . 'AND status <> :done AND status <> :cancelled AND status <> :deleted',
            ['company' => $companyuuid, 'userid' => $employeeuserid, 'empty' => '', 'today' => $today,
                'done' => 'done', 'cancelled' => 'cancelled', 'deleted' => 'deleted']);
        $items = $DB->get_records_sql('SELECT uuid, title, priority, status, startdate, duedate, planminutes, timemodified '
            . 'FROM {local_kbacompany_task} WHERE companyuuid = :company AND assigneeid = :userid '
            . 'ORDER BY CASE WHEN status = :done THEN 1 ELSE 0 END, duedate ASC, timemodified DESC',
            ['company' => $companyuuid, 'userid' => $employeeuserid, 'done' => 'done'], 0, 20);
        $tasks = [];
        foreach ($items as $row) {
            $tasks[] = [
                'uuid' => (string)$row->uuid,
                'title' => (string)$row->title,
                'priority' => (string)$row->priority,
                'status' => (string)$row->status,
                'start_date' => (string)$row->startdate,
                'due_date' => (string)$row->duedate,
                'plan_minutes' => (int)$row->planminutes,
                'modified' => (int)$row->timemodified,
            ];
        }
        return ['task_counts' => $counts, 'tasks' => $tasks];
    }

    private static function competencies(string $companyuuid, int $employeeuserid, int $actorid): array {
        try {
            return ['competencies' => employee_competency_profile_service::context($companyuuid, $employeeuserid, $actorid)];
        } catch (\Throwable $e) {
            return ['competencies' => null];
        }
    }

    private static function learning(string $companyuuid, int $employeeuserid, int $actorid): array {
        $employee = ['user_id' => $employeeuserid, 'status' => 'not_started', 'active_courses' => 0,
            'completed_courses' => 0, 'corporate_url' => ''];
        try {
            if ($employeeuserid === $actorid) {
                $ctx = learning_contour_service::context($companyuuid, $actorid, false);
                $employee['status'] = (int)$ctx['summary']['my_active_company_courses'] > 0 ? 'in_progress' :
                    ((int)$ctx['summary']['my_completed_company_courses'] > 0 ? 'completed' : 'not_started');
                $employee['active_courses'] = (int)$ctx['summary']['my_active_company_courses'];
                $employee['completed_courses'] = (int)$ctx['summary']['my_completed_company_courses'];
            } else {
                $ctx = learning_contour_service::context($companyuuid, $actorid, true);
                foreach ($ctx['team'] as $person) {
                    if ((int)$person['user_id'] === $employeeuserid) {
                        $employee = $person;
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Learning connector can be unavailable; employee card remains usable.
        }
        return ['employee' => $employee];
    }

    private static function development(string $companyuuid, int $employeeuserid): array {
        global $DB;
        $plans = $DB->get_records_sql('SELECT * FROM {local_kbacompany_devplan} '
            . 'WHERE companyuuid = :company AND userid = :userid ORDER BY timecreated DESC, id DESC',
            ['company' => $companyuuid, 'userid' => $employeeuserid], 0, 20);
        $planrows = [];
        foreach ($plans as $plan) {
            $items = $DB->get_records('local_kbacompany_devitem', [
                'companyuuid' => $companyuuid, 'planuuid' => (string)$plan->uuid,
            ], 'duedate ASC, id ASC');
            $done = 0;
            foreach ($items as $item) {
                if ((string)$item->status === 'completed') { $done++; }
            }
            $planrows[] = [
                'uuid' => (string)$plan->uuid,
                'title' => (string)$plan->title,
                'status' => (string)$plan->status,
                'target_date' => (int)$plan->targetdate,
                'items' => count($items),
                'completed_items' => $done,
            ];
        }
        return ['development_plans' => $planrows];
    }

    private static function documents(string $companyuuid, int $employeeuserid): array {
        global $DB;
        $rows = $DB->get_records_sql('SELECT a.*, d.code, d.title, d.doctype, d.status AS documentstatus '
            . 'FROM {local_kbacompany_docack} a '
            . 'JOIN {local_kbacompany_doc} d ON d.companyuuid = a.companyuuid AND d.uuid = a.documentuuid '
            . 'AND d.revision = a.docrevision '
            . 'WHERE a.companyuuid = :company AND a.userid = :userid '
            . 'ORDER BY CASE WHEN a.status = :required THEN 0 ELSE 1 END, a.timecreated DESC',
            ['company' => $companyuuid, 'userid' => $employeeuserid, 'required' => 'required'], 0, 30);
        $documents = [];
        foreach ($rows as $row) {
            $documents[] = [
                'uuid' => (string)$row->documentuuid,
                'revision' => (int)$row->docrevision,
                'code' => (string)$row->code,
                'title' => (string)$row->title,
                'type' => (string)$row->doctype,
                'status' => (string)$row->status,
                'created' => (int)$row->timecreated,
                'acknowledged' => (int)$row->timeacknowledged,
            ];
        }
        return ['documents' => $documents];
    }
}
