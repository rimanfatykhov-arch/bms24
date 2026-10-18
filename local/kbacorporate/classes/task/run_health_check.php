<?php
namespace local_kbacorporate\task;

defined('MOODLE_INTERNAL') || die();

/** Daily Corporate Dashboard integration health check. */
class run_health_check extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('taskhealthcheck', 'local_kbacorporate');
    }

    public function execute() {
        $result = \local_kbacorporate\local\health::run('scheduled', true);
        mtrace('[KBA Corporate Health] status=' . $result['status'] . ' warnings=' . $result['warnings'] . ' errors=' . $result['errors']);
    }
}
