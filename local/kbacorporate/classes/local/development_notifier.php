<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Sends one daily reminder for active plans with due or overdue actions. */
class development_notifier {
    public const UPCOMING_DAYS = 7;

    public static function run(?int $now = null): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/messagelib.php');
        $now = $now ?? time();
        $today = usergetmidnight($now);
        $limit = $today + ((self::UPCOMING_DAYS + 1) * DAYSECS) - 1;
        $stats = (object)['plans' => 0, 'recipients' => 0, 'sent' => 0, 'errors' => 0];
        $sql = "SELECT DISTINCT p.*
                  FROM {local_kbacorp_devplan} p
                  JOIN {local_kbacorp_devitem} i ON i.planid = p.id
                 WHERE p.status = :active
                       AND p.lastremindersent < :today
                       AND i.status <> :completed
                       AND i.duedate > 0
                       AND i.duedate <= :duedatelimit";
        $plans = $DB->get_records_sql($sql, [
            'active' => development_plans::STATUS_ACTIVE,
            'today' => $today,
            'completed' => development_plans::ITEM_COMPLETED,
            'duedatelimit' => $limit,
        ]);
        foreach ($plans as $plan) {
            $items = self::due_items(development_plans::get_items((int)$plan->id), $today);
            if (!$items) {
                continue;
            }
            $stats->plans++;
            $recipients = self::get_recipients($plan, $items);
            $planerrors = 0;
            $notifiedrecipients = 0;
            foreach ($recipients as $recipient) {
                $recipientitems = self::items_visible_to_recipient($plan, $recipient, $items);
                if (!$recipientitems) {
                    continue;
                }
                $notifiedrecipients++;
                try {
                    $message = self::build_message($plan, $recipient, $recipientitems, $today);
                    if (message_send($message) === false) {
                        $planerrors++;
                        $stats->errors++;
                    } else {
                        $stats->sent++;
                    }
                } catch (\Throwable $exception) {
                    $planerrors++;
                    $stats->errors++;
                    debugging('KBA development reminder failed for plan ' . (int)$plan->id .
                        ', user ' . (int)$recipient->id . ': ' . $exception->getMessage(), DEBUG_NORMAL);
                }
            }
            $stats->recipients += $notifiedrecipients;
            if ($notifiedrecipients > 0 && $planerrors === 0) {
                $DB->set_field('local_kbacorp_devplan', 'lastremindersent', $now, ['id' => (int)$plan->id]);
            }
        }
        return $stats;
    }

    /** Return unfinished actions that are overdue or due within the reminder window. */
    public static function due_items(array $items, int $today): array {
        $limit = $today + ((self::UPCOMING_DAYS + 1) * DAYSECS) - 1;
        return array_values(array_filter($items, static function($item) use ($limit) {
            return (string)$item->status !== development_plans::ITEM_COMPLETED &&
                (int)$item->duedate > 0 && (int)$item->duedate <= $limit;
        }));
    }

    /** Employee, assigned mentors and authorised company/department managers, without duplicates. */
    private static function get_recipients(\stdClass $plan, array $items): array {
        $recipients = [];
        $adduser = static function(int $userid) use (&$recipients): void {
            if ($userid <= 0 || isset($recipients[$userid])) {
                return;
            }
            $user = \core_user::get_user($userid);
            if ($user && empty($user->deleted) && empty($user->suspended)) {
                $recipients[$userid] = $user;
            }
        };
        $adduser((int)$plan->userid);
        $planmentorid = (int)($plan->mentorid ?? 0);
        if (development_plans::is_valid_mentor_assignment((int)$plan->companyid, $planmentorid)) {
            $adduser($planmentorid);
        }
        foreach ($items as $item) {
            $itemmentorid = (int)($item->mentorid ?? 0);
            if (development_plans::is_valid_mentor_assignment((int)$plan->companyid, $itemmentorid)) {
                $adduser($itemmentorid);
            }
        }
        foreach (attention_notifier::get_recipients((int)$plan->companyid) as $manager) {
            if (access::can_view_employee((int)$plan->companyid, (int)$manager->id, (int)$plan->userid)) {
                $adduser((int)$manager->id);
            }
        }
        return array_values($recipients);
    }

    /** Limit action-level mentors to the actions assigned to them. */
    private static function items_visible_to_recipient(\stdClass $plan, \stdClass $recipient,
            array $items): array {
        $recipientid = (int)$recipient->id;
        if ($recipientid === (int)$plan->userid ||
                $recipientid === (int)($plan->mentorid ?? 0) ||
                access::can_view_employee((int)$plan->companyid, $recipientid, (int)$plan->userid)) {
            return $items;
        }
        return array_values(array_filter($items, static function($item) use ($recipientid) {
            return (int)($item->mentorid ?? 0) === $recipientid;
        }));
    }

    private static function build_message(\stdClass $plan, \stdClass $recipient, array $items, int $today): \core\message\message {
        $url = new \moodle_url('/local/kbacorporate/development_plan.php', ['id' => (int)$plan->id]);
        $overdue = 0;
        $lines = [];
        $htmlitems = [];
        foreach ($items as $item) {
            $isoverdue = (int)$item->duedate < $today;
            $overdue += $isoverdue ? 1 : 0;
            $line = format_string($item->competencyname) . ' — ' .
                userdate((int)$item->duedate, get_string('strftimedate', 'langconfig')) .
                ($isoverdue ? ' (' . get_string('overdue', 'local_kbacorporate') . ')' : '');
            $lines[] = '• ' . $line;
            $htmlitems[] = \html_writer::tag('li', s($line));
        }
        $data = (object)['title' => format_string($plan->title), 'count' => count($items), 'overdue' => $overdue];
        $message = new \core\message\message();
        $message->component = 'local_kbacorporate';
        $message->name = 'developmentreminder';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $recipient;
        $message->subject = get_string('developmentremindersubject', 'local_kbacorporate', $data);
        $message->fullmessage = get_string('developmentreminderintro', 'local_kbacorporate', $data) . "\n\n" .
            implode("\n", $lines) . "\n\n" . $url->out(false);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s(get_string('developmentreminderintro',
            'local_kbacorporate', $data))) . \html_writer::tag('ul', implode('', $htmlitems)) .
            \html_writer::tag('p', \html_writer::link($url, get_string('opendevelopmentplan', 'local_kbacorporate')));
        $message->smallmessage = get_string('developmentremindershort', 'local_kbacorporate', $data);
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('opendevelopmentplan', 'local_kbacorporate');
        $message->courseid = SITEID;
        return $message;
    }
}
