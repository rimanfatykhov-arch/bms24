<?php
namespace local_kbacompany\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Daily reports R5c (owner decision K4, 24.11.2026): morning reminder about an unsent report of a required day.
 * A separate task; existing reminder tasks are unchanged. Sends nothing when the calendar is off or nothing is missing.
 */
final class send_daily_report_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('dailyreports_remind_task', 'local_kbacompany');
    }

    public function execute(): void {
        $result = \local_kbacompany\local\daily_report_reminder_service::run_due();
        mtrace('BMS daily report reminders: ' . json_encode($result));
    }
}
