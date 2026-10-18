<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Notify task participants about a new discussion message. */
final class implementation_task_notifier {
    /** Notify participants when the workflow status changes. */
    public static function send_status(\stdClass $task, string $from, string $to, int $actorid,
            string $comment = ''): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/messagelib.php');
        $recipients = [];
        if ((int)$task->ownerid > 0) {
            $recipients[(int)$task->ownerid] = (int)$task->ownerid;
        }
        foreach ($DB->get_fieldset_select('local_kbacorp_member', 'userid',
                'companyid = :companyid AND ismanager = 1', ['companyid' => (int)$task->companyid]) as $managerid) {
            if (access::can_manage_company_implementation((int)$task->companyid, (int)$managerid)) {
                $recipients[(int)$managerid] = (int)$managerid;
            }
        }
        $program = implementation_programs::get_for_audit((int)$task->auditid);
        if ($program && (int)$program->curatorid > 0) {
            $recipients[(int)$program->curatorid] = (int)$program->curatorid;
        }
        unset($recipients[$actorid]);
        $actor = \core_user::get_user($actorid) ?: \core_user::get_noreply_user();
        $company = $DB->get_record('local_kbacorp_company', ['id' => (int)$task->companyid], 'id,name');
        $url = new \moodle_url('/local/kbacorporate/implementation_task.php', ['id' => (int)$task->id]);
        $data = (object)[
            'task' => format_string($task->title),
            'actor' => fullname($actor),
            'company' => $company ? format_string($company->name) : '—',
            'from' => get_string('implementationtaskstatus' . $from, 'local_kbacorporate'),
            'to' => get_string('implementationtaskstatus' . $to, 'local_kbacorporate'),
            'comment' => trim($comment),
        ];
        foreach ($recipients as $userid) {
            $recipient = \core_user::get_user($userid);
            if (!$recipient || !empty($recipient->deleted) || !empty($recipient->suspended)) {
                continue;
            }
            try {
                $message = new \core\message\message();
                $message->component = 'local_kbacorporate';
                $message->name = 'implementationtaskstatus';
                $message->userfrom = $actor;
                $message->userto = $recipient;
                $message->subject = get_string('taskstatusnotificationsubject', 'local_kbacorporate', $data);
                $message->fullmessage = get_string('taskstatusnotificationbody', 'local_kbacorporate', $data) .
                    "\n\n" . $url->out(false);
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = \html_writer::tag('p', s(get_string(
                    'taskstatusnotificationbody', 'local_kbacorporate', $data))) .
                    \html_writer::tag('p', \html_writer::link($url,
                        get_string('openimplementationtask', 'local_kbacorporate')));
                $message->smallmessage = $message->subject;
                $message->notification = 1;
                $message->contexturl = $url->out(false);
                $message->contexturlname = get_string('openimplementationtask', 'local_kbacorporate');
                $message->courseid = SITEID;
                message_send($message);
            } catch (\Throwable $exception) {
                debugging('KBA task status notification failed for task ' . (int)$task->id .
                    ': ' . $exception->getMessage(), DEBUG_NORMAL);
            }
        }
    }

    /** Notify task participants about owner, deadline or priority changes made on the Kanban. */
    public static function send_kanban_update(\stdClass $task, int $actorid,
            array $changes, int $oldownerid = 0): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/messagelib.php');

        $recipients = [];
        if ((int)$task->ownerid > 0) {
            $recipients[(int)$task->ownerid] = (int)$task->ownerid;
        }
        if ($oldownerid > 0) {
            $recipients[$oldownerid] = $oldownerid;
        }
        foreach ($DB->get_fieldset_select('local_kbacorp_member', 'userid',
                'companyid = :companyid AND ismanager = 1',
                ['companyid' => (int)$task->companyid]) as $managerid) {
            if (access::can_manage_company_implementation((int)$task->companyid, (int)$managerid)) {
                $recipients[(int)$managerid] = (int)$managerid;
            }
        }
        $program = implementation_programs::get_for_audit((int)$task->auditid);
        if ($program && (int)$program->curatorid > 0) {
            $recipients[(int)$program->curatorid] = (int)$program->curatorid;
        }
        unset($recipients[$actorid]);

        $actor = \core_user::get_user($actorid) ?: \core_user::get_noreply_user();
        $company = $DB->get_record('local_kbacorp_company',
            ['id' => (int)$task->companyid], 'id,name');
        $url = new \moodle_url('/local/kbacorporate/implementation_task.php',
            ['id' => (int)$task->id]);
        $data = (object)[
            'task' => format_string($task->title),
            'actor' => fullname($actor),
            'company' => $company ? format_string($company->name) : '—',
            'changes' => implementation_kanban::describe_changes($changes),
        ];

        foreach ($recipients as $userid) {
            $recipient = \core_user::get_user($userid);
            if (!$recipient || !empty($recipient->deleted) || !empty($recipient->suspended)) {
                continue;
            }
            try {
                $message = new \core\message\message();
                $message->component = 'local_kbacorporate';
                $message->name = 'implementationtaskkanban';
                $message->userfrom = $actor;
                $message->userto = $recipient;
                $message->subject = get_string('kanbanupdatenotificationsubject',
                    'local_kbacorporate', $data);
                $message->fullmessage = get_string('kanbanupdatenotificationbody',
                    'local_kbacorporate', $data) . "\n\n" . $url->out(false);
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = \html_writer::tag('p', s(get_string(
                    'kanbanupdatenotificationbody', 'local_kbacorporate', $data))) .
                    \html_writer::tag('p', \html_writer::link($url,
                        get_string('openimplementationtask', 'local_kbacorporate')));
                $message->smallmessage = $message->subject;
                $message->notification = 1;
                $message->contexturl = $url->out(false);
                $message->contexturlname = get_string('openimplementationtask', 'local_kbacorporate');
                $message->courseid = SITEID;
                message_send($message);
            } catch (\Throwable $exception) {
                debugging('KBA Kanban update notification failed for task ' . (int)$task->id .
                    ': ' . $exception->getMessage(), DEBUG_NORMAL);
            }
        }
    }

    public static function send_comment(\stdClass $task, \stdClass $comment): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/messagelib.php');
        $recipients = [];
        if ((int)$task->ownerid > 0) {
            $recipients[(int)$task->ownerid] = (int)$task->ownerid;
        }
        $managerids = $DB->get_fieldset_select('local_kbacorp_member', 'userid',
            'companyid = :companyid AND ismanager = 1', ['companyid' => (int)$task->companyid]);
        foreach ($managerids as $managerid) {
            if (access::can_manage_company_implementation((int)$task->companyid, (int)$managerid)) {
                $recipients[(int)$managerid] = (int)$managerid;
            }
        }
        $program = implementation_programs::get_for_audit((int)$task->auditid);
        if ($program && (int)$program->curatorid > 0) {
            $recipients[(int)$program->curatorid] = (int)$program->curatorid;
        }
        unset($recipients[(int)$comment->userid]);
        $author = \core_user::get_user((int)$comment->userid);
        $company = $DB->get_record('local_kbacorp_company', ['id' => (int)$task->companyid], 'id,name');
        $student = (int)$task->ownerid > 0 ? \core_user::get_user((int)$task->ownerid) : null;
        $url = new \moodle_url('/local/kbacorporate/implementation_task.php', ['id' => (int)$task->id]);
        $data = (object)[
            'task' => format_string($task->title),
            'author' => $author ? fullname($author) : get_string('unknownuser'),
            'student' => $student ? fullname($student) : get_string('notassigned', 'local_kbacorporate'),
            'company' => $company ? format_string($company->name) : '—',
            'message' => shorten_text(html_to_text((string)$comment->message), 160),
        ];
        foreach ($recipients as $userid) {
            $recipient = \core_user::get_user($userid);
            if (!$recipient || !empty($recipient->deleted) || !empty($recipient->suspended)) {
                continue;
            }
            try {
                $message = new \core\message\message();
                $message->component = 'local_kbacorporate';
                $message->name = 'implementationtaskdiscussion';
                $message->userfrom = $author ?: \core_user::get_noreply_user();
                $message->userto = $recipient;
                $message->subject = get_string('taskdiscussionnotificationsubject', 'local_kbacorporate', $data);
                $message->fullmessage = get_string('taskdiscussionnotificationbody',
                    'local_kbacorporate', $data) . "\n\n" . $url->out(false);
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = \html_writer::tag('p', s(get_string(
                    'taskdiscussionnotificationbody', 'local_kbacorporate', $data))) .
                    \html_writer::tag('p', \html_writer::link($url,
                        get_string('opentaskdiscussion', 'local_kbacorporate')));
                $message->smallmessage = $message->subject;
                $message->notification = 1;
                $message->contexturl = $url->out(false);
                $message->contexturlname = get_string('opentaskdiscussion', 'local_kbacorporate');
                $message->courseid = SITEID;
                message_send($message);
            } catch (\Throwable $exception) {
                debugging('KBA task discussion notification failed for task ' . (int)$task->id .
                    ': ' . $exception->getMessage(), DEBUG_NORMAL);
            }
        }
    }
}
