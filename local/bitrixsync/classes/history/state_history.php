<?php
namespace local_bitrixsync\history;

defined('MOODLE_INTERNAL') || die();

class state_history {

    public function build(string $runid): array {
        return [
            'run_id' => $runid,
            'timeline' => [
                'READY',
                'APPROVED',
                'RUNNING',
                'VERIFYING',
                'COMPLETED',
            ],
            'source' => 'lifecycle_engine',
        ];
    }
}
