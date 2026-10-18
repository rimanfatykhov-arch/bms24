<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class live_execution {

    public function get(): array {
        return [
            'deal_id' => 3761,
            'run_id' => 'RUN-001',
            'mode' => 'CONTROLLED_TEST',
            'progress' => 40,
            'steps' => [
                'start' => 'COMPLETED',
                'moodle' => 'READY',
                'corporate' => 'RUNNING',
                'crm' => 'PENDING',
                'verification' => 'WAITING',
            ],
            'event_log' => 'AVAILABLE',
        ];
    }
}
