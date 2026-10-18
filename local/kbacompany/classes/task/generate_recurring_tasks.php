<?php
namespace local_kbacompany\task;

defined('MOODLE_INTERNAL') || die();

/** Scheduled generator for idempotent recurring task occurrences. */
class generate_recurring_tasks extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_generate_recurring_name', 'local_kbacompany');
    }

    public function execute() {
        $result = \local_kbacompany\local\recurring_task_service::generate_due_all(gmdate('Y-m-d'));
        mtrace('BMS recurring tasks: checked=' . $result['checked'] . ', due=' . $result['due'] .
            ', generated=' . $result['generated'] . ', errors=' . $result['errors']);
    }
}
