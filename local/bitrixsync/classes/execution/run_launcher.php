<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class run_launcher {

    public function start(int $dealid): array {
        return [
            'event' => 'EXECUTION_STARTED',
            'run_id' => 'RUN-' . time(),
            'deal_id' => $dealid,
            'status' => 'RUNNING',
            'next' => 'live_execution',
        ];
    }
}
