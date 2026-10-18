<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class execution_result {

    public function get(): array {
        return [
            'deal_id' => 3761,
            'run_id' => 'RUN-001',
            'result' => 'WAITING',
            'summary' => [
                'moodle_users' => 0,
                'courses_assigned' => 0,
                'corporate_licenses' => 0,
                'crm_feedback' => 'PENDING',
            ],
            'errors' => [],
            'event_log' => 'AVAILABLE',
        ];
    }
}
