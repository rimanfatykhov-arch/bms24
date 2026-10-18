<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class command_center {

    public function get(): array {
        return [
            'deal_id' => 3761,
            'run_id' => 'RUN-001',
            'mode' => 'CONTROLLED_TEST',
            'status' => 'READY',
            'token' => 'READY',
            'guard' => 'PASSED',
            'actions' => [
                'start',
                'planner',
                'approval',
                'monitor',
                'result',
                'history',
            ],
        ];
    }
}
