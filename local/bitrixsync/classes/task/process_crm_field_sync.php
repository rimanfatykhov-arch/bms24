<?php
namespace local_bitrixsync\task;

defined('MOODLE_INTERNAL') || die();

class process_crm_field_sync extends \core\task\scheduled_task {
    public function get_name() {
        return get_string(
            'task_process_crm_field_sync',
            'local_bitrixsync'
        );
    }

    public function execute() {
        $factory = \core\lock\lock_config::get_lock_factory(
            'local_bitrixsync'
        );

        $lock = $factory->get_lock(
            'crm_field_sync',
            0
        );

        if (!$lock) {
            mtrace(
                'KBA Connect CRM fields: another worker is already running'
            );
            return;
        }

        try {
            $stats = (
                new \local_bitrixsync\services\crm_field_sync_service()
            )->process_one();

            if (!empty($stats['processed'])) {
                mtrace(sprintf(
                    'KBA Connect CRM fields: processed=%d completed=%d failed=%d',
                    $stats['processed'],
                    $stats['completed'],
                    $stats['failed']
                ));
            }
        } finally {
            $lock->release();
        }
    }
}
