<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class history {

    public function get(): array {
        return [
            [
                'run_id' => 'RUN-001',
                'deal_id' => 3761,
                'status' => 'READY',
            ],
            [
                'run_id' => 'RUN-000',
                'deal_id' => 3758,
                'status' => 'SUCCESS',
            ],
        ];
    }
}
