<?php
namespace local_kbacorporate\task;

defined('MOODLE_INTERNAL') || die();

/** Daily reminders for upcoming and overdue development-plan actions. */
class send_development_reminders extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('tasksenddevelopmentreminders', 'local_kbacorporate');
    }

    public function execute() {
        $result = \local_kbacorporate\local\development_notifier::run();
        mtrace(get_string('developmentremindertaskresult', 'local_kbacorporate', $result));
    }
}
