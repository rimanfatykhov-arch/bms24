<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Non-graded working discussion attached to one implementation task. */
final class implementation_task_discussion {
    public static function get_task(int $taskid): \stdClass {
        global $DB;
        return $DB->get_record_sql(
            "SELECT t.*, i.criterionname
               FROM {local_kbacorp_impl_task} t
               JOIN {local_kbacorp_impl_item} i ON i.id = t.itemid
              WHERE t.id = :taskid AND t.deleted = 0",
            ['taskid' => $taskid], MUST_EXIST);
    }

    /** Managers with implementation access and the assigned employee may open the task. */
    public static function can_access(\stdClass $task, int $userid): bool {
        global $DB;
        if ($userid <= 0 || !empty($task->deleted)) {
            return false;
        }
        if (access::can_manage_company_implementation((int)$task->companyid, $userid)) {
            return true;
        }
        if (self::is_curator($task, $userid)) {
            return true;
        }
        return (int)$task->ownerid === $userid && $DB->record_exists('local_kbacorp_member', [
            'companyid' => (int)$task->companyid,
            'userid' => $userid,
            'isemployee' => 1,
        ]);
    }

    public static function require_access(\stdClass $task, int $userid): void {
        if (!self::can_access($task, $userid)) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
    }

    public static function get_comments(int $taskid): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT c.id AS commentid, c.taskid, c.userid AS id, c.message, c.messageformat,
                    c.timecreated, c.timemodified,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename, u.picture, u.imagealt, u.email
               FROM {local_kbacorp_impl_comment} c
               JOIN {user} u ON u.id = c.userid
              WHERE c.taskid = :taskid
           ORDER BY c.timecreated ASC, c.id ASC",
            ['taskid' => $taskid]
        ));
    }

    public static function add_comment(\stdClass $task, int $userid, string $message,
            int $messageformat = FORMAT_PLAIN): \stdClass {
        global $DB;
        self::require_access($task, $userid);
        if (!in_array($messageformat, [FORMAT_PLAIN, FORMAT_HTML, FORMAT_MARKDOWN, FORMAT_MOODLE], true)) {
            $messageformat = FORMAT_PLAIN;
        }
        $message = trim(clean_param($message, PARAM_RAW));
        if (trim(html_to_text($message)) === '') {
            throw new \invalid_parameter_exception('Empty discussion message');
        }
        $now = time();
        $record = (object)[
            'taskid' => (int)$task->id,
            'userid' => $userid,
            'message' => $message,
            'messageformat' => $messageformat,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_kbacorp_impl_comment', $record);
        implementation_task_notifier::send_comment($task, $record);
        return $record;
    }

    public static function is_curator(\stdClass $task, int $userid): bool {
        global $DB;
        // All three identifiers must match: a shared audit ID alone is not
        // evidence that the curator is assigned to this task's company.
        if ($userid <= 0 || (int)$task->companyid <= 0 || (int)$task->auditid <= 0 ||
                !empty($task->deleted)) {
            return false;
        }
        return $DB->record_exists('local_kbacorp_impl_program', [
            'baselineauditid' => (int)$task->auditid,
            'companyid' => (int)$task->companyid,
            'curatorid' => $userid,
        ]);
    }

    public static function mark_read(int $taskid, int $userid): void {
        global $DB;
        $now = time();
        $record = $DB->get_record('local_kbacorp_impl_read', ['taskid' => $taskid, 'userid' => $userid]);
        if ($record) {
            $record->lastreadtime = $now;
            $record->timemodified = $now;
            $DB->update_record('local_kbacorp_impl_read', $record);
        } else {
            $DB->insert_record('local_kbacorp_impl_read', (object)[
                'taskid' => $taskid, 'userid' => $userid, 'lastreadtime' => $now, 'timemodified' => $now,
            ]);
        }
    }

    /** Tasks and discussions from every implementation program assigned to this curator. */
    public static function get_curator_threads(int $userid, string $status = '', string $query = '',
            bool $allcurators = false): array {
        global $DB;
        $params = [];
        $curatorsql = '';
        if (!$allcurators) {
            $curatorsql = ' AND p.curatorid = :curatorid';
            $params['curatorid'] = $userid;
        }
        $statussql = '';
        if (in_array($status, ['planned', 'inprogress', 'review', 'completed', 'cancelled'], true)) {
            $statussql = ' AND t.status = :status';
            $params['status'] = $status;
        }
        $sql = "SELECT t.*, p.id AS programid, p.title AS programtitle,
                       p.curatorid AS programcuratorid, co.name AS companyname,
                       a.title AS audittitle, i.criterioncode,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename, u.picture, u.imagealt, u.email,
                       COALESCE(r.lastreadtime, 0) AS lastreadtime,
                       cu.firstname AS curatorfirstname, cu.lastname AS curatorlastname,
                       (SELECT COUNT(1) FROM {local_kbacorp_impl_check} ck
                         WHERE ck.taskid = t.id) AS checklisttotal,
                       (SELECT COUNT(1) FROM {local_kbacorp_impl_check} ck
                         WHERE ck.taskid = t.id AND ck.isdone = 1) AS checklistdone
                  FROM {local_kbacorp_impl_program} p
                  JOIN {local_kbacorp_impl_task} t ON t.auditid = p.baselineauditid
                        AND t.companyid = p.companyid
                  JOIN {local_kbacorp_impl_audit} a ON a.id = t.auditid
                        AND a.companyid = t.companyid
                  JOIN {local_kbacorp_impl_item} i ON i.id = t.itemid
                  JOIN {local_kbacorp_company} co ON co.id = t.companyid
             LEFT JOIN {user} u ON u.id = t.ownerid
             LEFT JOIN {user} cu ON cu.id = p.curatorid
             LEFT JOIN {local_kbacorp_impl_read} r ON r.taskid = t.id AND r.userid = :readuserid
                 WHERE p.curatorid > 0 AND t.deleted = 0 $curatorsql $statussql
              ORDER BY t.timemodified DESC, t.id DESC";
        $params['readuserid'] = $userid;
        $threads = $DB->get_records_sql($sql, $params);
        $query = trim(\core_text::strtolower($query));
        foreach ($threads as $key => $thread) {
            $haystack = \core_text::strtolower($thread->title . ' ' . $thread->companyname . ' ' .
                ($thread->ownerid ? fullname($thread) : ''));
            if ($query !== '' && \core_text::strpos($haystack, $query) === false) {
                unset($threads[$key]);
            }
        }
        self::hydrate_curator_threads($threads, $userid);
        return array_values($threads);
    }
    /**
     * Batch-fill discussion metadata for visible tasks only. The board can pass its
     * full selection; the curator workspace passes one server-side page.
     * No writes are performed and Corporate remains the source of truth.
     *
     * @param array<int,\stdClass> $threads Task records with unique id.
     */
    /** Enrich only the bounded Kanban page, preserving keys by task ID. */
    public static function enrich_visible_threads(array $threads, int $userid): array {
        self::hydrate_curator_threads($threads, $userid);
        return array_values($threads);
    }

    private static function hydrate_curator_threads(array &$threads, int $userid): void {
        global $DB;
        if (!$threads) {
            return;
        }
        $isevidenceavailable = implementation_evidence::is_available();
        foreach ($threads as $thread) {
            $thread->lastcomment = null;
            $thread->unreadcount = 0;
            $thread->latestevidence = null;
        }
        // Bound the size of IN lists for MySQL 5.7 and other Moodle databases.
        foreach (array_chunk(array_keys($threads), 150) as $taskids) {
            $taskids = array_map('intval', $taskids);
            list($insql, $inparams) = $DB->get_in_or_equal($taskids, SQL_PARAMS_NAMED, 'cid');
            $comments = $DB->get_records_sql(
                "SELECT c.id AS commentid, c.taskid, c.userid AS id, c.message, c.messageformat,
                        c.timecreated, u.firstname, u.lastname, u.firstnamephonetic,
                        u.lastnamephonetic, u.middlename, u.alternatename
                   FROM {local_kbacorp_impl_comment} c
                   JOIN {user} u ON u.id = c.userid
                  WHERE c.taskid $insql
                    AND NOT EXISTS (
                      SELECT 1 FROM {local_kbacorp_impl_comment} newer
                       WHERE newer.taskid = c.taskid
                         AND (newer.timecreated > c.timecreated OR
                              (newer.timecreated = c.timecreated AND newer.id > c.id)))",
                $inparams);
            foreach ($comments as $comment) {
                if (isset($threads[(int)$comment->taskid])) {
                    $threads[(int)$comment->taskid]->lastcomment = $comment;
                }
            }
            list($unreadsql, $unreadparams) = $DB->get_in_or_equal($taskids, SQL_PARAMS_NAMED, 'uid');
            $unreadparams['readactor'] = $userid;
            $unreadparams['commentactor'] = $userid;
            $unreadrows = $DB->get_records_sql(
                "SELECT c.taskid, COUNT(c.id) AS unreadcount
                   FROM {local_kbacorp_impl_comment} c
              LEFT JOIN {local_kbacorp_impl_read} r ON r.taskid = c.taskid
                       AND r.userid = :readactor
                  WHERE c.taskid $unreadsql AND c.userid <> :commentactor
                    AND c.timecreated > COALESCE(r.lastreadtime, 0)
               GROUP BY c.taskid", $unreadparams);
            foreach ($unreadrows as $unreadrow) {
                if (isset($threads[(int)$unreadrow->taskid])) {
                    $threads[(int)$unreadrow->taskid]->unreadcount = (int)$unreadrow->unreadcount;
                }
            }
            if ($isevidenceavailable) {
                list($evsql, $evparams) = $DB->get_in_or_equal($taskids, SQL_PARAMS_NAMED, 'eid');
                $evidences = $DB->get_records_sql(
                    "SELECT e.* FROM {local_kbacorp_impl_evid} e
                      WHERE e.taskid $evsql AND NOT EXISTS (
                        SELECT 1 FROM {local_kbacorp_impl_evid} newer
                         WHERE newer.taskid = e.taskid AND
                               (newer.versionno > e.versionno OR
                                (newer.versionno = e.versionno AND newer.id > e.id)))",
                    $evparams);
                foreach ($evidences as $evidence) {
                    if (isset($threads[(int)$evidence->taskid])) {
                        $threads[(int)$evidence->taskid]->latestevidence = $evidence;
                    }
                }
            }
        }
    }

    /**
     * Lightweight global counters and search suggestions. No discussion bodies or
     * evidence records are loaded for tasks outside the selected page.
     *
     * @return \stdClass Counters across all assigned tasks, irrespective of filters.
     */
    public static function get_curator_overview(int $userid, bool $allcurators = false): \stdClass {
        global $DB;
        list($from, $where, $params) = self::curator_query_parts($userid, $allcurators);
        $params['overviewactor'] = $userid;
        $sql = "SELECT DISTINCT t.id, t.ownerid, t.status, t.duedate, co.name AS companyname,
                       u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                       u.middlename, u.alternatename,
                       (SELECT COUNT(c.id) FROM {local_kbacorp_impl_comment} c
                         WHERE c.taskid = t.id AND c.userid <> :overviewactor
                           AND c.timecreated > COALESCE(r.lastreadtime, 0)) AS unreadcount
                  $from
                 WHERE $where";
        $rows = $DB->get_records_sql($sql, $params);
        $today = usergetmidnight(time());
        $summary = (object)[
            'students' => [], 'taskcount' => 0, 'unread' => 0, 'overdue' => 0,
            'statuscounts' => array_fill_keys(['planned', 'inprogress', 'review', 'completed', 'cancelled'], 0),
            'suggestions' => [],
        ];
        foreach ($rows as $row) {
            $summary->taskcount++;
            $summary->unread += (int)$row->unreadcount;
            if ((int)$row->ownerid > 0) {
                $summary->students[(int)$row->ownerid] = true;
                $owner = clone $row;
                $owner->id = (int)$row->ownerid;
                $name = fullname($owner);
                if (count($summary->suggestions) < 100) {
                    $summary->suggestions[$name] = $row->companyname;
                }
            }
            if (isset($summary->statuscounts[$row->status])) {
                $summary->statuscounts[$row->status]++;
            }
            if ((int)$row->duedate > 0 && (int)$row->duedate < $today &&
                    !in_array($row->status, ['completed', 'cancelled'], true)) {
                $summary->overdue++;
            }
        }
        return $summary;
    }

    /**
     * Server-side filtered task page and exact matching count. Filters are always
     * applied BEFORE LIMIT/OFFSET, including unread and overdue scope.
     *
     * @return \stdClass {threads: array, total: int}
     */
    public static function get_curator_page(int $userid, string $status = '', string $query = '',
            string $scope = '', bool $allcurators = false, int $page = 0, int $pagesize = 20): \stdClass {
        global $DB;
        $page = max(0, $page);
        $pagesize = max(1, min(100, $pagesize));
        list($from, $where, $params) = self::curator_query_parts($userid, $allcurators);
        if (in_array($status, ['planned', 'inprogress', 'review', 'completed', 'cancelled'], true)) {
            $where .= ' AND t.status = :pagestatus';
            $params['pagestatus'] = $status;
        }
        $query = trim($query);
        if ($query !== '') {
            $fullname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
            $reversedname = $DB->sql_concat('u.lastname', "' '", 'u.firstname');
            $fields = ['t.title', 'co.name', 'u.firstname', 'u.lastname',
                $fullname, $reversedname];
            $clauses = [];
            foreach ($fields as $index => $field) {
                $param = 'search' . $index;
                $clauses[] = $DB->sql_like($field, ':' . $param, false);
                $params[$param] = '%' . $DB->sql_like_escape($query) . '%';
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
        }
        if ($scope === 'unread') {
            $where .= ' AND EXISTS (SELECT 1 FROM {local_kbacorp_impl_comment} c'
                . ' WHERE c.taskid = t.id AND c.userid <> :unreadactor'
                . ' AND c.timecreated > COALESCE(r.lastreadtime, 0))';
            $params['unreadactor'] = $userid;
        } else if ($scope === 'overdue') {
            $where .= ' AND t.duedate > 0 AND t.duedate < :today'
                . " AND t.status NOT IN ('completed', 'cancelled')";
            $params['today'] = usergetmidnight(time());
        }
        $total = (int)$DB->count_records_sql("SELECT COUNT(DISTINCT t.id) $from WHERE $where", $params);
        $columns = "t.*, p.id AS programid, p.title AS programtitle,
                    p.curatorid AS programcuratorid, co.name AS companyname,
                    a.title AS audittitle, i.criterioncode,
                    u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                    u.middlename, u.alternatename, u.picture, u.imagealt, u.email,
                    COALESCE(r.lastreadtime, 0) AS lastreadtime,
                    cu.firstname AS curatorfirstname, cu.lastname AS curatorlastname";
        $sql = "SELECT DISTINCT $columns $from WHERE $where
                ORDER BY t.timemodified DESC, t.id DESC";
        $records = $DB->get_records_sql($sql, $params, $page * $pagesize, $pagesize);
        self::hydrate_curator_threads($records, $userid);
        return (object)['threads' => array_values($records), 'total' => $total];
    }

    /** Construct a company- and program-scoped query shared by overview and page. */
    private static function curator_query_parts(int $userid, bool $allcurators): array {
        // This flag is an ACL boundary, not a presentation preference. Never
        // allow untrusted code to widen the curator's portfolio by setting it.
        if ($userid <= 0 || ($allcurators && !\has_capability(
                'local/kbacorporate:manageimplementationaudits',
                \context_system::instance(), $userid))) {
            throw new \moodle_exception('companynotallowed', 'local_kbacorporate');
        }
        $from = "FROM {local_kbacorp_impl_program} p
                 JOIN {local_kbacorp_impl_task} t ON t.auditid = p.baselineauditid
                      AND t.companyid = p.companyid
                 JOIN {local_kbacorp_impl_audit} a ON a.id = t.auditid
                      AND a.companyid = t.companyid
                 JOIN {local_kbacorp_impl_item} i ON i.id = t.itemid
                 JOIN {local_kbacorp_company} co ON co.id = t.companyid
            LEFT JOIN {user} u ON u.id = t.ownerid
            LEFT JOIN {user} cu ON cu.id = p.curatorid
            LEFT JOIN {local_kbacorp_impl_read} r ON r.taskid = t.id AND r.userid = :readuserid";
        $params = ['readuserid' => $userid];
        $where = 'p.curatorid > 0 AND t.deleted = 0';
        if (!$allcurators) {
            $where .= ' AND p.curatorid = :curatorid';
            $params['curatorid'] = $userid;
        }
        return [$from, $where, $params];
    }

}
