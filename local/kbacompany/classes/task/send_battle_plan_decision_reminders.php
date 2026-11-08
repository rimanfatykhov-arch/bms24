<?php
namespace local_kbacompany\task;
defined('MOODLE_INTERNAL') || die();
final class send_battle_plan_decision_reminders extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('battleplan_notify_task', 'local_kbacompany');
    }
    public function execute(): void {
        $result = \local_kbacompany\local\battle_plan_decision_notification_service::run_due();
        mtrace('BMS decision reminders: '.json_encode($result));
    }
}
