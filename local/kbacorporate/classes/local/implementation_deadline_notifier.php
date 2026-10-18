<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Daily upcoming-deadline reminders and overdue escalation for implementation tasks. */
final class implementation_deadline_notifier {
    public static function run(?int $now = null): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/messagelib.php');
        $enabled = get_config('local_kbacorporate', 'implementationremindersenabled');
        $stats = (object)['tasks' => 0, 'recipients' => 0, 'sent' => 0, 'errors' => 0];
        if ($enabled !== false && !(int)$enabled) {
            return $stats;
        }
        $days = get_config('local_kbacorporate', 'implementationreminderdays');
        $days = $days === false ? 3 : max(1, min(30, (int)$days));
        $now = $now ?? time();
        $today = usergetmidnight($now);
        $limit = $today + (($days + 1) * DAYSECS) - 1;
        $tasks = $DB->get_records_sql(
            "SELECT t.*, c.name AS companyname
               FROM {local_kbacorp_impl_task} t
               JOIN {local_kbacorp_company} c ON c.id = t.companyid
              WHERE t.deleted = 0
                AND t.status IN (:planned, :inprogress, :review)
                AND t.duedate > 0 AND t.duedate <= :limit
           ORDER BY t.duedate, t.id",
            ['planned' => 'planned', 'inprogress' => 'inprogress', 'review' => 'review', 'limit' => $limit]);
        foreach ($tasks as $task) {
            $type = (int)$task->duedate < $today ? 'overdue' : 'upcoming';
            if ((int)($task->lastreminderdue ?? 0) === (int)$task->duedate &&
                    (string)($task->lastremindertype ?? '') === $type) {
                continue;
            }
            $recipients = self::get_recipients($task, $type === 'overdue');
            if (!$recipients) {
                continue;
            }
            $stats->tasks++;
            $stats->recipients += count($recipients);
            $errors = 0;
            foreach ($recipients as $recipient) {
                try {
                    $message = self::build_message($task, $recipient, $type);
                    if (message_send($message) === false) {
                        $errors++; $stats->errors++;
                    } else {
                        $stats->sent++;
                    }
                } catch (\Throwable $exception) {
                    $errors++; $stats->errors++;
                    debugging('KBA implementation deadline reminder failed for task ' . (int)$task->id .
                        ', user ' . (int)$recipient->id . ': ' . $exception->getMessage(), DEBUG_NORMAL);
                }
            }
            if ($errors === 0) {
                $DB->update_record('local_kbacorp_impl_task', (object)[
                    'id' => (int)$task->id,
                    'lastremindersent' => $now,
                    'lastreminderdue' => (int)$task->duedate,
                    'lastremindertype' => $type,
                ]);
            }
        }
        return $stats;
    }

    private static function get_recipients(\stdClass $task, bool $escalate): array {
        global $DB;
        $ids = [];
        if ((int)$task->ownerid > 0) { $ids[(int)$task->ownerid] = (int)$task->ownerid; }
        if ($escalate) {
            $program = implementation_programs::get_for_audit((int)$task->auditid);
            if ($program && (int)$program->curatorid > 0) {
                $ids[(int)$program->curatorid] = (int)$program->curatorid;
            }
            foreach ($DB->get_fieldset_select('local_kbacorp_member', 'userid',
                    'companyid = :companyid AND ismanager = 1', ['companyid' => (int)$task->companyid]) as $id) {
                if (access::can_manage_company_implementation((int)$task->companyid, (int)$id)) {
                    $ids[(int)$id] = (int)$id;
                }
            }
        }
        $users = [];
        foreach ($ids as $id) {
            $user = \core_user::get_user($id);
            if ($user && empty($user->deleted) && empty($user->suspended)) { $users[] = $user; }
        }
        return $users;
    }

    private static function build_message(\stdClass $task, \stdClass $recipient,
            string $type): \core\message\message {
        $url = new \moodle_url('/local/kbacorporate/implementation_task.php', ['id' => (int)$task->id]);
        $data = (object)[
            'task' => format_string($task->title),
            'company' => format_string($task->companyname),
            'date' => userdate((int)$task->duedate, get_string('strftimedatefullshort')),
        ];
        $message = new \core\message\message();
        $message->component = 'local_kbacorporate';
        $message->name = 'implementationtaskdeadline';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $recipient;
        $message->subject = get_string('implementationdeadline' . $type . 'subject',
            'local_kbacorporate', $data);
        $body = get_string('implementationdeadline' . $type . 'body', 'local_kbacorporate', $data);
        $message->fullmessage = $body . "\n\n" . $url->out(false);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s($body)) . \html_writer::tag('p',
            \html_writer::link($url, get_string('openimplementationtask', 'local_kbacorporate')));
        $message->smallmessage = $message->subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('openimplementationtask', 'local_kbacorporate');
        $message->courseid = SITEID;
        return $message;
    }
}
