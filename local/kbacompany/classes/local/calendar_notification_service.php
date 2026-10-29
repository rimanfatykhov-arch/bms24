<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Durable, idempotent calendar reminder signals delivered through BMS + Moodle messaging. */
class calendar_notification_service {
    public const CONTRACT_VERSION = '1.1.0-beta2';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'owner' => 'calendar_notification_engine',
            'rules' => [
                'canonical_bms_domains_remain_sources_of_truth',
                'scheduled_scan_is_company_scoped_before_fetch',
                'only_active_company_members_are_recipients',
                'task_due_reminders_are_assignee_only',
                'meeting_reminders_are_participant_only',
                'checkpoint_reminders_follow_existing_management_cycle_visibility',
                'deterministic_notification_uuid_prevents_duplicate_bms_notifications',
                'moodle_message_delivery_is_best_effort_after_durable_bms_claim',
                'other_users_personal_moodle_calendars_are_never_read',
                'no_hidden_business_object_mutations',
                'company_defaults_and_personal_overrides_are_applied_before_claim',
                'quiet_hours_suppress_delivery_without_claiming_notifications',
            ],
        ];
    }

    /** One cron iteration. Returns counters for diagnostics/tests. */
    public static function run_due(int $now = 0): array {
        global $DB;
        $now = $now > 0 ? $now : time();
        $stats = ['recipients'=>0,'claimed'=>0,'moodle_sent'=>0,'moodle_failed'=>0,'skipped'=>0];
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_kbacompany_company')) ||
                !$DB->get_manager()->table_exists(new \xmldb_table('local_kbacompany_member'))) {
            return $stats;
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        $lock = $factory->get_lock('calendar_reminder_cron', 0);
        if (!$lock) {
            $stats['skipped']++;
            return $stats;
        }
        try {
            $sql = "SELECT m.id,m.companyuuid,m.userid,u.timezone\n"
                . " FROM {local_kbacompany_member} m\n"
                . " JOIN {local_kbacompany_company} c ON c.uuid=m.companyuuid AND c.active=1\n"
                . " JOIN {user} u ON u.id=m.userid AND u.deleted=0 AND u.suspended=0\n"
                . " WHERE m.active=1\n"
                . " ORDER BY m.companyuuid,m.userid,m.id";
            $seen = [];
            $recordset = $DB->get_recordset_sql($sql);
            try {
                foreach ($recordset as $membership) {
                    $key = (string)$membership->companyuuid . ':' . (int)$membership->userid;
                    if (isset($seen[$key])) { continue; }
                    $seen[$key] = true;
                    $stats['recipients']++;
                    try {
                        self::run_recipient((string)$membership->companyuuid, (int)$membership->userid,
                            (string)$membership->timezone, $now, $stats);
                    } catch (\Throwable $e) {
                        $stats['skipped']++;
                        mtrace('BMS calendar reminder skipped for ' . $key . ': ' . get_class($e));
                    }
                }
            } finally {
                $recordset->close();
            }
        } finally {
            $lock->release();
        }
        return $stats;
    }

    private static function run_recipient(string $companyuuid, int $userid, string $timezone, int $now,
            array &$stats): void {
        company_context_service::context($companyuuid, $userid);
        $tz = self::timezone_object($timezone);
        $localnow = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
        $preferences = calendar_notification_preferences_service::effective($companyuuid, $userid);
        if (empty($preferences['enabled']) || calendar_notification_preferences_service::is_quiet($preferences, $localnow)) {
            return;
        }
        $today = $localnow->format('Y-m-d');
        $tomorrow = $localnow->modify('+1 day')->format('Y-m-d');
        $calendarurl = new \moodle_url('/local/kbacompany/calendar.php', [
            'uuid'=>$companyuuid,'view'=>'day','date'=>$today,'scope'=>'my',
        ]);

        // Meeting reminder: user/company-configured lead with a cron-safe ±15 minute claim window.
        $meetinglead = (int)$preferences['meeting_lead'];
        $meetingfrom = $now + max(0, $meetinglead - 15) * 60;
        $meetingto = $now + ($meetinglead + 15) * 60;
        foreach (!empty($preferences['meeting_enabled'])
                ? meeting_service::calendar_projection($companyuuid, $userid, $meetingfrom, $meetingto) : [] as $meeting) {
            if (in_array((string)$meeting['status'], ['closed','cancelled'], true)) { continue; }
            $start = (int)$meeting['starts_at'];
            $title = get_string('calendarreminder_meeting_title', 'local_kbacompany', (object)[
                'title'=>(string)$meeting['title'], 'time'=>userdate($start, get_string('strftimetime'), $timezone),
                'lead'=>$meetinglead,
            ]);
            $url = new \moodle_url('/local/kbacompany/meetings.php', [
                'uuid'=>$companyuuid,'meeting'=>(string)$meeting['uuid'],
            ]);
            self::claim_and_send($companyuuid,$userid,'calendar_meeting_hour',
                self::key(['meeting',(string)$meeting['uuid'],(string)$start]),$title,$title,$url,'',$stats);
        }

        // Morning signals are generated once during the configured user's local hour.
        if ((int)$localnow->format('G') !== (int)$preferences['morning_hour']) { return; }

        $owntasks = [];
        foreach (task_service::calendar_projection($companyuuid,$userid,$today,$today) as $task) {
            if ((string)$task['date_kind'] !== 'due' || (int)$task['assignee_id'] !== $userid ||
                    in_array((string)$task['status'], ['done','cancelled'], true)) { continue; }
            $owntasks[] = $task;
            if (empty($preferences['task_due'])) { continue; }
            $title = get_string('calendarreminder_task_due_title','local_kbacompany',(object)['title'=>(string)$task['title']]);
            $url = new \moodle_url('/local/kbacompany/tasks.php', [
                'uuid'=>$companyuuid,'task'=>(string)$task['uuid'],'view'=>'detail',
            ]);
            self::claim_and_send($companyuuid,$userid,'calendar_task_due',
                self::key(['task',(string)$task['uuid'],$today]),$title,$title,$url,(string)$task['uuid'],$stats);
        }

        $daystart = (new \DateTimeImmutable($today . ' 00:00:00', $tz))->getTimestamp();
        $dayend = (new \DateTimeImmutable($today . ' 23:59:59', $tz))->getTimestamp();
        $todaymeetings = meeting_service::calendar_projection($companyuuid,$userid,$daystart,$dayend);

        $tomorrowstart = (new \DateTimeImmutable($tomorrow . ' 00:00:00', $tz))->getTimestamp();
        $tomorrowend = (new \DateTimeImmutable($tomorrow . ' 23:59:59', $tz))->getTimestamp();
        $tomorrowcheckpoints = [];
        try {
            $tomorrowcheckpoints = management_cycle_template_service::calendar_projection(
                $companyuuid,$userid,$tomorrowstart,$tomorrowend);
        } catch (\required_capability_exception $e) {
            $tomorrowcheckpoints = [];
        }
        foreach ($tomorrowcheckpoints as $checkpoint) {
            if (empty($preferences['checkpoint_tomorrow']) || (string)$checkpoint['status'] === 'done') { continue; }
            $title = get_string('calendarreminder_checkpoint_title','local_kbacompany',(object)[
                'title'=>(string)$checkpoint['title'],
                'time'=>userdate((int)$checkpoint['planned_at'],get_string('strftimetime'),$timezone),
            ]);
            $url = new \moodle_url('/local/kbacompany/management_cycles.php', [
                'uuid'=>$companyuuid,'cycle'=>(string)$checkpoint['cycle_uuid'],'tab'=>'checkpoints',
            ]);
            self::claim_and_send($companyuuid,$userid,'calendar_checkpoint_tomorrow',
                self::key(['checkpoint',(string)$checkpoint['uuid'],$tomorrow]),$title,$title,$url,'',$stats);
        }

        $summary = get_string('calendarreminder_day_title','local_kbacompany',(object)[
            'tasks'=>count($owntasks),'meetings'=>count($todaymeetings),'checkpoints'=>count($tomorrowcheckpoints),
        ]);
        if (!empty($preferences['morning_digest'])) {
            self::claim_and_send($companyuuid,$userid,'calendar_day',self::key(['day',$companyuuid,(string)$userid,$today]),
                $summary,$summary,$calendarurl,'',$stats);
        }
    }


    /** Resolve one recipient timezone without allowing a malformed profile value to break the cron run. */
    private static function timezone_object(string $timezone): \DateTimeZone {
        try {
            return \core_date::get_user_timezone_object($timezone !== '' ? $timezone : null);
        } catch (\Throwable $e) {
            return \core_date::get_user_timezone_object();
        }
    }

    private static function key(array $parts): string {
        return hash('sha256', implode("\n", $parts));
    }

    private static function claim_and_send(string $companyuuid, int $userid, string $kind, string $key,
            string $title, string $body, \moodle_url $url, string $taskuuid, array &$stats): void {
        $created = communication_service::ensure_system_notification($companyuuid,$userid,$kind,$key,$title,$taskuuid);
        if (!$created) { return; }
        $stats['claimed']++;
        try {
            self::send_moodle($userid,$title,$body,$url);
            $stats['moodle_sent']++;
        } catch (\Throwable $e) {
            // The BMS notification is already durable and must not be deleted: at-most-once
            // Moodle delivery is preferred over duplicate popups after a partial transport failure.
            $stats['moodle_failed']++;
            mtrace('BMS calendar Moodle notification failed for user ' . $userid . ': ' . get_class($e));
        }
    }

    private static function send_moodle(int $userid, string $title, string $body, \moodle_url $url): void {
        global $CFG;
        require_once($CFG->dirroot . '/message/lib.php');
        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $message = new \core\message\message();
        $message->component = 'local_kbacompany';
        $message->name = 'calendar_reminder';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $title;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>' . s($body) . '</p>';
        $message->smallmessage = $title;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('bmscalendar_title','local_kbacompany');
        message_send($message);
    }
}
