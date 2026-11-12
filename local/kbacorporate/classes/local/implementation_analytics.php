<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Aggregates BMS implementation task effectiveness for curators and administrators. */
final class implementation_analytics {
    public const PERIODS = ['all', '30', '90', '365'];

    public static function get_task_rows(int $userid, bool $allcurators, int $companyid = 0,
            int $curatorid = 0, string $period = 'all'): array {
        global $DB;
        $params = [];
        $where = ['p.curatorid > 0', 't.deleted = 0', access::not_revoked_sql('t.companyid', $userid)];
        if (!$allcurators) {
            $where[] = 'p.curatorid = :scopeuserid';
            $params['scopeuserid'] = $userid;
        } else if ($curatorid > 0) {
            $where[] = 'p.curatorid = :curatorid';
            $params['curatorid'] = $curatorid;
        }
        if ($companyid > 0) {
            $where[] = 't.companyid = :companyid';
            $params['companyid'] = $companyid;
        }
        $period = in_array($period, self::PERIODS, true) ? $period : 'all';
        if ($period !== 'all') {
            $where[] = 't.timecreated >= :periodstart';
            $params['periodstart'] = usergetmidnight(time()) - ((int)$period * DAYSECS);
        }
        return array_values($DB->get_records_sql(
            "SELECT t.*, p.curatorid AS programcuratorid, co.name AS companyname,
                    cu.firstname AS curatorfirstname, cu.lastname AS curatorlastname,
                    cu.firstnamephonetic AS curatorfirstnamephonetic,
                    cu.lastnamephonetic AS curatorlastnamephonetic,
                    cu.middlename AS curatormiddlename, cu.alternatename AS curatoralternatename,
                    (SELECT COUNT(1) FROM {local_kbacorp_impl_check} ck
                      WHERE ck.taskid = t.id) AS checklisttotal,
                    (SELECT COUNT(1) FROM {local_kbacorp_impl_check} ck
                      WHERE ck.taskid = t.id AND ck.isdone = 1) AS checklistdone
               FROM {local_kbacorp_impl_program} p
               JOIN {local_kbacorp_impl_task} t ON t.auditid = p.baselineauditid
                         AND t.companyid = p.companyid
               JOIN {local_kbacorp_company} co ON co.id = t.companyid
               JOIN {user} cu ON cu.id = p.curatorid
              WHERE " . implode(' AND ', $where) . "
           ORDER BY co.name ASC, t.timemodified DESC, t.id DESC", $params));
    }

    public static function summarize(array $tasks): \stdClass {
        $summary = (object)[
            'total' => count($tasks), 'active' => 0, 'review' => 0, 'completed' => 0,
            'overdue' => 0, 'completionrate' => 0.0, 'overduerate' => 0.0,
            'averagecycledays' => null, 'checklisttotal' => 0, 'checklistdone' => 0,
            'checklistrate' => 0.0,
        ];
        $today = usergetmidnight(time());
        $cycles = [];
        foreach ($tasks as $task) {
            if (in_array((string)$task->status, ['planned', 'inprogress'], true)) {
                $summary->active++;
            }
            if ((string)$task->status === 'review') {
                $summary->review++;
            }
            if ((string)$task->status === 'completed') {
                $summary->completed++;
                $start = (int)$task->startdate ?: (int)$task->timecreated;
                if ((int)$task->completedat >= $start && $start > 0) {
                    $cycles[] = ((int)$task->completedat - $start) / DAYSECS;
                }
            }
            if ((int)$task->duedate > 0 && (int)$task->duedate < $today &&
                    !in_array((string)$task->status, ['completed', 'cancelled'], true)) {
                $summary->overdue++;
            }
            $summary->checklisttotal += (int)($task->checklisttotal ?? 0);
            $summary->checklistdone += (int)($task->checklistdone ?? 0);
        }
        if ($summary->total) {
            $summary->completionrate = round(($summary->completed * 100) / $summary->total, 1);
            $summary->overduerate = round(($summary->overdue * 100) / $summary->total, 1);
        }
        if ($summary->checklisttotal) {
            $summary->checklistrate = round(($summary->checklistdone * 100) / $summary->checklisttotal, 1);
        }
        if ($cycles) {
            $summary->averagecycledays = round(array_sum($cycles) / count($cycles), 1);
        }
        return $summary;
    }

    public static function group_by_company_curator(array $tasks): array {
        global $DB;
        $groups = [];
        foreach ($tasks as $task) {
            $key = (int)$task->companyid . ':' . (int)$task->programcuratorid;
            if (!isset($groups[$key])) {
                $curator = (object)[
                    'id' => (int)$task->programcuratorid,
                    'firstname' => $task->curatorfirstname,
                    'lastname' => $task->curatorlastname,
                    'firstnamephonetic' => $task->curatorfirstnamephonetic,
                    'lastnamephonetic' => $task->curatorlastnamephonetic,
                    'middlename' => $task->curatormiddlename,
                    'alternatename' => $task->curatoralternatename,
                ];
                $groups[$key] = (object)[
                    'companyid' => (int)$task->companyid,
                    'companyname' => (string)$task->companyname,
                    'curatorid' => (int)$task->programcuratorid,
                    'curatorname' => fullname($curator),
                    'tasks' => [], 'bmsindex' => null, 'previousbmsindex' => null,
                    'bmsindexdelta' => null,
                ];
            }
            $groups[$key]->tasks[] = $task;
        }
        foreach ($groups as $group) {
            $group->summary = self::summarize($group->tasks);
            $audits = array_values($DB->get_records_sql(
                "SELECT id, indexvalue
                   FROM {local_kbacorp_impl_audit}
                  WHERE companyid = :companyid AND status = :status AND indexvalue IS NOT NULL
               ORDER BY auditdate DESC, id DESC",
                ['companyid' => $group->companyid, 'status' => 'confirmed'], 0, 2));
            $group->bmsindex = isset($audits[0]) ? (float)$audits[0]->indexvalue : null;
            $group->previousbmsindex = isset($audits[1]) ? (float)$audits[1]->indexvalue : null;
            if ($group->bmsindex !== null && $group->previousbmsindex !== null) {
                $group->bmsindexdelta = round($group->bmsindex - $group->previousbmsindex, 1);
            }
        }
        uasort($groups, static function($left, $right) {
            $company = strnatcasecmp($left->companyname, $right->companyname);
            return $company ?: strnatcasecmp($left->curatorname, $right->curatorname);
        });
        return array_values($groups);
    }

    public static function monthly_trend(array $tasks, int $months = 12): array {
        $months = max(1, min(24, $months));
        $firstofmonth = strtotime(userdate(time(), '%Y-%m-01 00:00:00'));
        $trend = [];
        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $periodtime = strtotime('-' . $offset . ' months', $firstofmonth);
            $key = userdate($periodtime, '%Y-%m');
            $trend[$key] = (object)[
                'periodtime' => $periodtime, 'created' => 0, 'completed' => 0,
                'cyclesum' => 0.0, 'cyclecount' => 0, 'averagecycledays' => null,
            ];
        }
        foreach ($tasks as $task) {
            $createdkey = userdate((int)$task->timecreated, '%Y-%m');
            if (isset($trend[$createdkey])) {
                $trend[$createdkey]->created++;
            }
            if ((string)$task->status === 'completed' && (int)$task->completedat > 0) {
                $completedkey = userdate((int)$task->completedat, '%Y-%m');
                if (isset($trend[$completedkey])) {
                    $trend[$completedkey]->completed++;
                    $start = (int)$task->startdate ?: (int)$task->timecreated;
                    if ($start > 0 && (int)$task->completedat >= $start) {
                        $trend[$completedkey]->cyclesum += ((int)$task->completedat - $start) / DAYSECS;
                        $trend[$completedkey]->cyclecount++;
                    }
                }
            }
        }
        foreach ($trend as $item) {
            if ($item->cyclecount) {
                $item->averagecycledays = round($item->cyclesum / $item->cyclecount, 1);
            }
        }
        return array_values($trend);
    }
}
