<?php
namespace local_bitrixsync\task;

defined('MOODLE_INTERNAL') || die();

class process_sync_queue extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_process_sync_queue', 'local_bitrixsync');
    }

    public function execute() {
        if (!get_config('local_bitrixsync', 'queue_enabled')) {
            return;
        }

        $limit = (int)get_config('local_bitrixsync', 'queue_batch_size');
        if ($limit <= 0) {
            $limit = 20;
        }

        $manager = new \local_bitrixsync\services\sync_manager();
        $stats = $manager->run_batch($limit);

        mtrace(sprintf(
            'KBA Connect queue: processed=%d completed=%d failed=%d',
            $stats['processed'],
            $stats['completed'],
            $stats['failed']
        ));
    }
}
