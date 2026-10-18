<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class execution_metrics {

    public function get(): array {
        return [
            'run_id' => 'RUN-001',
            'total_time' => '00:02:15',
            'steps' => [
                'moodle' => '00:00:40',
                'corporate' => '00:00:35',
                'crm' => '00:00:20',
                'verification' => '00:00:15',
            ],
            'result' => 'RUNNING',
        ];
    }
}
