<?php
namespace local_bitrixsync\task;

defined('MOODLE_INTERNAL') || die();

/** Process durable BMS integration commands without affecting Deal enrolment. */
class process_bms_bridge extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_process_bms_bridge', 'local_bitrixsync');
    }

    public function execute() {
        if (!get_config('local_bitrixsync', 'bms_bridge_enabled')) {
            return;
        }
        $limit = (int)get_config('local_bitrixsync', 'bms_bridge_batch_size');
        if ($limit <= 0) {
            $limit = 20;
        }
        $stats = (new \local_bitrixsync\services\bms_bridge_service())
            ->process_pending_commands($limit);
        mtrace(sprintf(
            'KBA Connect BMS bridge: processed=%d completed=%d failed=%d deferred=%d',
            $stats['processed'], $stats['completed'], $stats['failed'], $stats['deferred']
        ));
    }
}
