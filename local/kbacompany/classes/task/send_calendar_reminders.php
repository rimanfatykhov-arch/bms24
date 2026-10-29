<?php
namespace local_kbacompany\task;

defined('MOODLE_INTERNAL') || die();

class send_calendar_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_send_calendar_reminders', 'local_kbacompany');
    }

    public function execute(): void {
        $stats = \local_kbacompany\local\calendar_notification_service::run_due();
        mtrace('BMS calendar reminders: recipients=' . $stats['recipients'] .
            ' claimed=' . $stats['claimed'] . ' moodle_sent=' . $stats['moodle_sent'] .
            ' moodle_failed=' . $stats['moodle_failed'] . ' skipped=' . $stats['skipped']);
    }
}
