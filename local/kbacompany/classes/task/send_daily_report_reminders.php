<?php
namespace local_kbacompany\task;
defined('MOODLE_INTERNAL') || die();
/** ADR-0011 S6: daily report reminders to authors and, when overdue, to their reviewers. */
final class send_daily_report_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('dailyreports_remind_task', 'local_kbacompany');
    }
    public function execute(): void {
        $result = \local_kbacompany\local\daily_report_schedule_service::run_due();
        mtrace('BMS daily report reminders: ' . json_encode($result));
    }
}
