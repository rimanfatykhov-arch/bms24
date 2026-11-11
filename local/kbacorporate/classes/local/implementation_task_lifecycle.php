<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Controlled status transitions and audit history for implementation tasks. */
final class implementation_task_lifecycle {
    public static function transition(\stdClass $task, int $userid, string $action, string $comment = ''): \stdClass {
        global $DB;
        implementation_task_discussion::require_access($task, $userid);
        $isowner = (int)$task->ownerid === $userid;
        $isreviewer = implementation_task_discussion::is_curator($task, $userid) ||
            access::can_manage_company_implementation((int)$task->companyid, $userid);
        $transitions = [
            'start' => ['from' => 'planned', 'to' => 'inprogress', 'allowed' => $isowner],
            'submit' => ['from' => 'inprogress', 'to' => 'review', 'allowed' => $isowner],
            'approve' => ['from' => 'review', 'to' => 'completed', 'allowed' => $isreviewer],
            'return' => ['from' => 'review', 'to' => 'inprogress', 'allowed' => $isreviewer],
        ];
        if (!isset($transitions[$action]) || !$transitions[$action]['allowed'] ||
                $task->status !== $transitions[$action]['from']) {
            throw new \moodle_exception('implementationtransitionnotallowed', 'local_kbacorporate');
        }
        if ($action === 'submit' && trim((string)$task->completioncomment) === '') {
            throw new \moodle_exception('taskresultrequired', 'local_kbacorporate');
        }
        if (implementation_evidence::has_records((int)$task->id)) {
            $latest = implementation_evidence::get_latest((int)$task->id);
            if ($action === 'submit' && (!$latest ||
                    (string)$latest->status !== implementation_evidence::STATUS_SUBMITTED)) {
                throw new \moodle_exception('implevidenceworkflowrequired', 'local_kbacorporate');
            }
            if ($action === 'approve' && (!$latest ||
                    (string)$latest->status !== implementation_evidence::STATUS_ACCEPTED)) {
                throw new \moodle_exception('implevidenceworkflowrequired', 'local_kbacorporate');
            }
            if ($action === 'return' && (!$latest ||
                    (string)$latest->status !== implementation_evidence::STATUS_REJECTED)) {
                throw new \moodle_exception('implevidenceworkflowrequired', 'local_kbacorporate');
            }
        }
        $from = $task->status;
        $to = $transitions[$action]['to'];
        $now = time();
        $update = (object)['id' => (int)$task->id, 'status' => $to, 'modifiedby' => $userid,
            'timemodified' => $now];
        if ($action === 'start' && !(int)$task->startdate) { $update->startdate = $now; }
        if ($action === 'approve') { $update->completedat = $now; }
        $DB->update_record('local_kbacorp_impl_task', $update);
        self::record((int)$task->id, $userid, $from, $to, $comment);
        $task = implementation_task_discussion::get_task((int)$task->id);
        implementation_task_notifier::send_status($task, $from, $to, $userid, $comment);
        return $task;
    }

    public static function record(int $taskid, int $userid, string $from, string $to, string $comment = ''): void {
        if ($from === $to) {
            return;
        }
        self::insert_log($taskid, $userid, 'status', $from, $to, $comment, []);
    }

    /**
     * Soft-delete an implementation task in the same record-oriented style as SuiteCRM.
     *
     * The task row and related history/evidence remain intact for audit/privacy,
     * while all operational surfaces filter deleted = 0. The deletionkey frees
     * the active item/route uniqueness slot so a later replacement task can be created.
     */
    public static function delete_task(\stdClass $task, int $userid): void {
        global $DB;
        if (!access::can_manage_company_implementation((int)$task->companyid, $userid)) {
            throw new \moodle_exception('implementationtaskdeleteforbidden', 'local_kbacorporate');
        }
        if (!empty($task->deleted)) {
            return;
        }
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        self::insert_log((int)$task->id, $userid, 'delete',
            (string)$task->status, (string)$task->status, '', []);
        $DB->update_record('local_kbacorp_impl_task', (object)[
            'id' => (int)$task->id,
            'deleted' => 1,
            'deletedat' => $now,
            'deletionkey' => (int)$task->id,
            'modifiedby' => $userid,
            'timemodified' => $now,
        ]);
        $transaction->allow_commit();
    }

    /** Record a Kanban card metadata change without pretending that status changed. */
    public static function record_kanban_update(int $taskid, int $userid, string $status,
            array $changes): void {
        self::insert_log($taskid, $userid, 'kanban', $status, $status, '', $changes);
    }

    /** Insert one lifecycle/audit event. */
    private static function insert_log(int $taskid, int $userid, string $eventtype,
            string $from, string $to, string $comment, array $details): void {
        global $DB;
        $record = (object)[
            'taskid' => $taskid,
            'userid' => $userid,
            'fromstatus' => $from,
            'tostatus' => $to,
            'eventtype' => $eventtype,
            'comment' => trim(clean_param($comment, PARAM_TEXT)) ?: null,
            'detailsjson' => $details
                ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'timecreated' => time(),
        ];
        $DB->insert_record('local_kbacorp_impl_tasklog', $record);
    }

    public static function get_history(int $taskid): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT l.id AS logid, l.userid AS id, l.taskid, l.fromstatus, l.tostatus,
                    l.eventtype, l.comment, l.detailsjson, l.timecreated,
                    u.firstname, u.lastname, u.firstnamephonetic,
                    u.lastnamephonetic, u.middlename, u.alternatename
               FROM {local_kbacorp_impl_tasklog} l
               JOIN {user} u ON u.id = l.userid
              WHERE l.taskid = :taskid
           ORDER BY l.timecreated DESC, l.id DESC", ['taskid' => $taskid]));
    }

    public static function get_user_tasks(int $userid, string $status = ''): array {
        global $DB;
        $revocationwhere = access::not_revoked_sql('t.companyid', $userid);
        $params = ['ownerid' => $userid]; $statussql = '';
        if ($status === 'duesoon') {
            $days = get_config('local_kbacorporate', 'implementationreminderdays');
            $days = $days === false ? 3 : max(1, min(30, (int)$days));
            $today = usergetmidnight(time());
            $statussql = ' AND t.duedate >= :today AND t.duedate < :limit' .
                ' AND t.status NOT IN (:completed, :cancelled)';
            $params += ['today' => $today, 'limit' => $today + (($days + 1) * DAYSECS),
                'completed' => 'completed', 'cancelled' => 'cancelled'];
        } else if ($status === 'overdue') {
            $statussql = ' AND t.duedate > 0 AND t.duedate < :now' .
                ' AND t.status NOT IN (:completed, :cancelled)';
            $params += ['now' => usergetmidnight(time()), 'completed' => 'completed', 'cancelled' => 'cancelled'];
        } else if ($status === 'active') {
            $statussql = " AND t.status IN ('planned', 'inprogress')";
        } else if (in_array($status, ['planned', 'inprogress', 'review', 'completed', 'cancelled'], true)) {
            $statussql = ' AND t.status = :status'; $params['status'] = $status;
        }
        return array_values($DB->get_records_sql(
            "SELECT t.*, c.name AS companyname,
                    (SELECT COUNT(1) FROM {local_kbacorp_impl_check} ck
                      WHERE ck.taskid = t.id) AS checklisttotal,
                    (SELECT COUNT(1) FROM {local_kbacorp_impl_check} ck
                      WHERE ck.taskid = t.id AND ck.isdone = 1) AS checklistdone
              FROM {local_kbacorp_impl_task} t
              JOIN {local_kbacorp_company} c ON c.id = t.companyid
              WHERE t.ownerid = :ownerid AND {$revocationwhere}
                AND t.deleted = 0
                AND EXISTS (SELECT 1 FROM {local_kbacorp_member} m
                             WHERE m.companyid = t.companyid AND m.userid = t.ownerid
                               AND m.isemployee = 1)
                    $statussql
           ORDER BY CASE WHEN t.status = 'review' THEN 0 WHEN t.status = 'inprogress' THEN 1
                         WHEN t.status = 'planned' THEN 2 ELSE 3 END,
                    CASE WHEN t.duedate = 0 THEN 1 ELSE 0 END, t.duedate ASC, t.timemodified DESC", $params));
    }
}
