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
        if (access::can_manage_company_implementation((int)$task->companyid, $userid)) {
            return true;
        }
        if ($DB->record_exists('local_kbacorp_impl_program', [
            'baselineauditid' => (int)$task->auditid,
            'curatorid' => $userid,
        ])) {
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
        return $DB->record_exists('local_kbacorp_impl_program', [
            'baselineauditid' => (int)$task->auditid, 'curatorid' => $userid,
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
                  JOIN {local_kbacorp_impl_audit} a ON a.id = t.auditid
                  JOIN {local_kbacorp_impl_item} i ON i.id = t.itemid
                  JOIN {local_kbacorp_company} co ON co.id = t.companyid
             LEFT JOIN {user} u ON u.id = t.ownerid
             LEFT JOIN {user} cu ON cu.id = p.curatorid
             LEFT JOIN {local_kbacorp_impl_read} r ON r.taskid = t.id AND r.userid = :readuserid
                 WHERE p.curatorid > 0 AND t.deleted = 0 $curatorsql $statussql
              ORDER BY t.timemodified DESC, t.id DESC";
        $params['readuserid'] = $userid;
        $threads = array_values($DB->get_records_sql($sql, $params));
        $query = trim(\core_text::strtolower($query));
        foreach ($threads as $key => $thread) {
            $haystack = \core_text::strtolower($thread->title . ' ' . $thread->companyname . ' ' .
                ($thread->ownerid ? fullname($thread) : ''));
            if ($query !== '' && \core_text::strpos($haystack, $query) === false) {
                unset($threads[$key]);
                continue;
            }
            $thread->lastcomment = $DB->get_record_sql(
                "SELECT c.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                        u.middlename, u.alternatename
                   FROM {local_kbacorp_impl_comment} c
                   JOIN {user} u ON u.id = c.userid
                  WHERE c.taskid = :taskid
               ORDER BY c.timecreated DESC, c.id DESC",
                ['taskid' => (int)$thread->id], IGNORE_MULTIPLE) ?: null;
            $thread->unreadcount = $DB->count_records_select('local_kbacorp_impl_comment',
                'taskid = :taskid AND userid <> :userid AND timecreated > :lastread', [
                    'taskid' => (int)$thread->id, 'userid' => $userid,
                    'lastread' => (int)$thread->lastreadtime,
                ]);
            $thread->latestevidence = implementation_evidence::is_available()
                ? implementation_evidence::get_latest((int)$thread->id) : null;
        }
        return array_values($threads);
    }
}
