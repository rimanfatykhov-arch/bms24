<?php
namespace local_kbacorporate\task;

defined('MOODLE_INTERNAL') || die();

/** Daily reminders for upcoming and overdue BMS implementation tasks. */
class send_implementation_deadline_reminders extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('tasksendimplementationdeadlinereminders', 'local_kbacorporate');
    }

    public function execute() {
        $result = \local_kbacorporate\local\implementation_deadline_notifier::run();
        mtrace(get_string('implementationdeadlineremindertaskresult', 'local_kbacorporate', $result));
    }
}
