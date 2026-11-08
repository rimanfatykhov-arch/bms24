<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();

/** Cron-safe, idempotent decision reminders via existing BMS inbox and Moodle messaging. */
final class battle_plan_decision_notification_service {
    public static function run_due(int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $stats = ['scanned'=>0, 'claimed'=>0, 'failed'=>0];
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        $lock = $factory->get_lock('battleplan_decision_notifications', 0);
        if (!$lock) { return $stats; }
        try {
            $sql = "SELECT p.* FROM {local_kbacompany_bp_proto} p
                JOIN (SELECT companyuuid, weekstart, taskuuid, MAX(revision) AS revision
                    FROM {local_kbacompany_bp_proto} GROUP BY companyuuid, weekstart, taskuuid) latest
                ON latest.companyuuid=p.companyuuid AND latest.weekstart=p.weekstart
                AND latest.taskuuid=p.taskuuid AND latest.revision=p.revision
                WHERE p.executionstatus IN ('pending','inprogress')";
            $records = $DB->get_recordset_sql($sql);
            try {
                foreach ($records as $decision) {
                    $stats['scanned']++;
                    try { self::deliver($decision, $now, $stats); }
                    catch (\Throwable $e) { $stats['failed']++; mtrace('BMS decision reminder skipped: '.get_class($e)); }
                }
            } finally { $records->close(); }
        } finally { $lock->release(); }
        return $stats;
    }

    private static function deliver(\stdClass $decision, int $now, array &$stats): void {
        global $DB;
        $owner = (int)$decision->owneruserid;
        if ($owner < 1 || !$DB->record_exists('user', ['id'=>$owner, 'deleted'=>0, 'suspended'=>0])) { return; }
        if (!$DB->record_exists('local_kbacompany_member',
                ['companyuuid'=>$decision->companyuuid, 'userid'=>$owner, 'active'=>1]) ||
                !$DB->record_exists('local_kbacompany_company',
                ['uuid'=>$decision->companyuuid, 'active'=>1])) { return; }
        $today = date('Y-m-d', $now);
        $due = (string)$decision->duedate;
        $events = [];
        // Assigned decision notice follows each newly saved revision; once per revision.
        if ((int)$decision->timecreated >= $now - 86400 && (int)$decision->timecreated <= $now) {
            $events[] = ['assigned', (string)$decision->revision,
                get_string('battleplan_notify_assigned', 'local_kbacompany')];
        }
        if ($due !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            $tomorrow = date('Y-m-d', $now + 86400);
            if ($due === $tomorrow) {
                $events[] = ['soon', $due, get_string('battleplan_notify_soon', 'local_kbacompany')];
            } elseif ($due < $today) {
                // One overdue signal per due date; not a daily nag.
                $events[] = ['overdue', $due, get_string('battleplan_notify_overdue', 'local_kbacompany')];
            }
        }
        $url = new \moodle_url('/local/kbacompany/battle_plan_week_coordination.php',
            ['uuid'=>$decision->companyuuid,'week'=>$decision->weekstart]);
        foreach ($events as [$event, $eventkey, $heading]) {
            $key = hash('sha256', implode('|', ['bpdecision', $decision->companyuuid,
                $decision->weekstart, $decision->taskuuid, $owner, $event, $eventkey]));
            $title = \core_text::substr($heading . ': ' . strip_tags((string)$decision->decisiontext), 0, 250);
            if (!communication_service::ensure_system_notification(
                    (string)$decision->companyuuid, $owner, 'bp_decision', $key, $title,
                    (string)$decision->taskuuid)) { continue; }
            $stats['claimed']++;
            try {
                self::send_moodle($owner, $title, $url);
            } catch (\Throwable $e) {
                $stats['failed']++;
                mtrace('BMS decision Moodle message failed: '.get_class($e));
            }
        }
    }
    private static function send_moodle(int $userid, string $title, \moodle_url $url): void {
        global $CFG;
        require_once($CFG->dirroot.'/message/lib.php');
        $message = new \core\message\message();
        $message->component = 'local_kbacompany';
        $message->name = 'battleplan_decision_reminder';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = \core_user::get_user($userid, '*', MUST_EXIST);
        $message->subject = $title;
        $message->fullmessage = $title;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>'.s($title).'</p>';
        $message->smallmessage = $title;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('battleplan_notify_context', 'local_kbacompany');
        message_send($message);
    }
}
