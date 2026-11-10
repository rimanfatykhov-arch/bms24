<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only, SQL-filtered Kanban projection. Task state is always owned by Corporate.
 * One page is fetched per status, so a large portfolio does not hydrate every card.
 */
final class implementation_board_query {
    public const PAGE_SIZE = 15;
    public const STATUSES = ['planned', 'inprogress', 'review', 'completed', 'cancelled'];

    /** Normalise page numbers before using them in database offsets. */
    public static function page(int $page, int $count, int $size = self::PAGE_SIZE): int {
        $size = max(1, $size);
        $last = max(0, (int)ceil(max(0, $count) / $size) - 1);
        return max(0, min($page, $last));
    }

    /** Build the same restricted portfolio for facets, counts and cards. */
    private static function scope(int $userid, bool $allcurators, array $filters = []): array {
        global $DB;
        if ($userid <= 0) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        if ($allcurators && !has_capability('local/kbacorporate:manageimplementationaudits',
                \context_system::instance(), $userid)) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $params = [];
        $where = ['p.curatorid > 0', 't.deleted = 0'];
        // Avoid repeated program/audit joins from duplicating a task during LIMIT/OFFSET.
        $programscope = 'p2.curatorid > 0';
        if (!$allcurators) {
            $where[] = 'p.curatorid = :actorcurator';
            $params['actorcurator'] = $userid;
            $programscope = 'p2.curatorid = :subcurator';
            $params['subcurator'] = $userid;
        } else if (!empty($filters['curatorid'])) {
            $where[] = 'p.curatorid = :filtercurator';
            $params['filtercurator'] = (int)$filters['curatorid'];
            $programscope = 'p2.curatorid = :subfiltercurator';
            $params['subfiltercurator'] = (int)$filters['curatorid'];
        }
        $where[] = "p.id = (SELECT MIN(p2.id) FROM {local_kbacorp_impl_program} p2
                    WHERE p2.companyid = t.companyid AND p2.baselineauditid = t.auditid
                      AND $programscope)";
        $from = "FROM {local_kbacorp_impl_program} p
            JOIN {local_kbacorp_impl_task} t ON t.auditid = p.baselineauditid
                 AND t.companyid = p.companyid
            JOIN {local_kbacorp_impl_audit} a ON a.id = t.auditid
                 AND a.companyid = t.companyid
            JOIN {local_kbacorp_impl_item} i ON i.id = t.itemid
            JOIN {local_kbacorp_company} co ON co.id = t.companyid
       LEFT JOIN {user} u ON u.id = t.ownerid
       LEFT JOIN {user} cu ON cu.id = p.curatorid";

        foreach (['companyid' => 't.companyid', 'auditid' => 't.auditid'] as $key => $field) {
            if (!empty($filters[$key])) {
                $where[] = $field . ' = :' . $key;
                $params[$key] = (int)$filters[$key];
            }
        }
        $ownerid = (int)($filters['ownerid'] ?? 0);
        if ($ownerid === -1) {
            $where[] = 't.ownerid = 0';
        } else if ($ownerid > 0) {
            $where[] = 't.ownerid = :ownerid';
            $params['ownerid'] = $ownerid;
        }
        if (!empty($filters['priority']) && in_array($filters['priority'],
                implementation_kanban::PRIORITIES, true)) {
            $where[] = 't.priority = :priority';
            $params['priority'] = $filters['priority'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
            $where[] = 't.status = :taskstatus';
            $params['taskstatus'] = $filters['status'];
        }
        $today = usergetmidnight(time());
        if (($filters['scope'] ?? '') === 'overdue') {
            $where[] = "t.duedate > 0 AND t.duedate < :overduetoday
                         AND t.status NOT IN ('completed', 'cancelled')";
            $params['overduetoday'] = $today;
        } else if (($filters['scope'] ?? '') === 'due7') {
            $where[] = "t.duedate >= :duestart AND t.duedate < :dueend
                         AND t.status NOT IN ('completed', 'cancelled')";
            $params['duestart'] = $today;
            $params['dueend'] = $today + (8 * DAYSECS);
        }
        $period = (string)($filters['period'] ?? 'all');
        if ($period !== 'all' && in_array($period, implementation_analytics::PERIODS, true)) {
            $where[] = 't.timecreated >= :periodstart';
            $params['periodstart'] = $today - (int)$period * DAYSECS;
        }
        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $fields = ['t.title', 'co.name', 'a.title', 'i.criterioncode', 'i.criterionname',
                'u.firstname', 'u.lastname', $DB->sql_concat('u.firstname', "' '", 'u.lastname'),
                $DB->sql_concat('u.lastname', "' '", 'u.firstname')];
            $like = [];
            foreach ($fields as $index => $field) {
                $key = 'search' . $index;
                $like[] = $DB->sql_like($field, ':' . $key, false);
                $params[$key] = '%' . $DB->sql_like_escape($query) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }
        $evidence = (string)($filters['evidence'] ?? '');
        if ($evidence === 'none') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM {local_kbacorp_impl_evid} ev'
                . ' WHERE ev.taskid = t.id)';
        } else if (in_array($evidence, ['submitted', 'accepted', 'rejected'], true)) {
            $where[] = "EXISTS (SELECT 1 FROM {local_kbacorp_impl_evid} ev
                 WHERE ev.taskid = t.id AND ev.status = :evidencestatus
                   AND NOT EXISTS (SELECT 1 FROM {local_kbacorp_impl_evid} newer
                     WHERE newer.taskid = ev.taskid AND (newer.versionno > ev.versionno OR
                         (newer.versionno = ev.versionno AND newer.id > ev.id))))";
            $params['evidencestatus'] = $evidence;
        }
        return [$from, implode(' AND ', $where), $params];
    }

    /** Options include the full ACL-visible portfolio, not just the displayed pages. */
    public static function facets(int $userid, bool $allcurators): array {
        global $DB;
        [$from, $where, $params] = self::scope($userid, $allcurators);
        $fields = [
            'companies' => 't.companyid AS id, co.name AS label',
            'curators' => 'p.curatorid AS id, cu.firstname, cu.lastname',
            'audits' => 't.auditid AS id, a.title, co.name AS companyname, t.companyid',
            'owners' => 't.ownerid AS id, u.firstname, u.lastname, u.firstnamephonetic, '
                . 'u.lastnamephonetic, u.middlename, u.alternatename',
        ];
        $result = [];
        foreach ($fields as $group => $columns) {
            $rows = $DB->get_records_sql('SELECT DISTINCT ' . $columns . ' ' . $from
                . ' WHERE ' . $where, $params);
            $result[$group] = $rows;
        }
        return $result;
    }

    /** Accurate counts, independent of column pagination. */
    public static function summary(int $userid, bool $allcurators, array $filters): array {
        global $DB;
        [$from, $where, $params] = self::scope($userid, $allcurators, $filters);
        $params['summarytoday'] = usergetmidnight(time());
        $sql = "SELECT t.status, COUNT(t.id) AS taskcount,
               SUM(CASE WHEN t.ownerid = 0 AND t.status NOT IN ('completed','cancelled')
                    THEN 1 ELSE 0 END) AS unassigned,
               SUM(CASE WHEN t.duedate > 0 AND t.duedate < :summarytoday
                    AND t.status NOT IN ('completed','cancelled')
                    THEN 1 ELSE 0 END) AS overdue,
               SUM(CASE WHEN EXISTS (SELECT 1 FROM {local_kbacorp_impl_evid} ev
                       WHERE ev.taskid = t.id AND ev.status = 'submitted'
                         AND NOT EXISTS (SELECT 1 FROM {local_kbacorp_impl_evid} newer
                           WHERE newer.taskid = ev.taskid AND
                             (newer.versionno > ev.versionno OR
                              (newer.versionno = ev.versionno AND newer.id > ev.id))))
                    THEN 1 ELSE 0 END) AS evidencepending
             $from WHERE $where GROUP BY t.status";
        $rows = $DB->get_records_sql($sql, $params);
        $counts = array_fill_keys(self::STATUSES, 0);
        $result = ['total' => 0, 'overdue' => 0, 'unassigned' => 0,
            'review' => 0, 'evidencepending' => 0, 'counts' => []];
        foreach ($rows as $row) {
            $status = (string)$row->status;
            if (!array_key_exists($status, $counts)) {
                continue;
            }
            $number = (int)$row->taskcount;
            $counts[$status] = $number;
            $result['total'] += $number;
            $result['overdue'] += (int)$row->overdue;
            $result['unassigned'] += (int)$row->unassigned;
            $result['evidencepending'] += (int)$row->evidencepending;
        }
        $result['review'] = $counts['review'];
        $result['counts'] = $counts;
        return $result;
    }

    /** Return only visible tasks, enriched in bounded batches. */
    public static function column(int $userid, bool $allcurators, array $filters,
            string $status, int $page = 0, int $size = self::PAGE_SIZE): array {
        global $DB;
        if (!in_array($status, self::STATUSES, true)) {
            return [];
        }
        [$from, $where, $params] = self::scope($userid, $allcurators, $filters);
        $params['columnstatus'] = $status;
        $params['readuserid'] = $userid;
        $sql = "SELECT t.*, p.id AS programid, p.title AS programtitle,
                       p.curatorid AS programcuratorid, co.name AS companyname,
                       a.title AS audittitle, i.criterioncode, i.criterionname,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.picture, u.imagealt, u.email,
                       COALESCE(r.lastreadtime, 0) AS lastreadtime,
                       cu.firstname AS curatorfirstname, cu.lastname AS curatorlastname,
                       (SELECT COUNT(1) FROM {local_kbacorp_impl_check} ck
                         WHERE ck.taskid = t.id) AS checklisttotal,
                       (SELECT COUNT(1) FROM {local_kbacorp_impl_check} ck
                         WHERE ck.taskid = t.id AND ck.isdone = 1) AS checklistdone
                 $from
            LEFT JOIN {local_kbacorp_impl_read} r ON r.taskid = t.id
                   AND r.userid = :readuserid
                WHERE $where AND t.status = :columnstatus
             ORDER BY CASE WHEN t.duedate = 0 THEN 1 ELSE 0 END ASC,
                      t.duedate ASC, t.timemodified DESC, t.id DESC";
        $rows = $DB->get_records_sql($sql, $params,
            max(0, $page) * max(1, $size), max(1, min(50, $size)));
        return implementation_task_discussion::enrich_visible_threads($rows, $userid);
    }
}
