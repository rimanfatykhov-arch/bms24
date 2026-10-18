<?php
namespace local_bitrixsync\history;

defined('MOODLE_INTERNAL') || die();

class lifecycle_history {

    public function build(string $runid, int $dealid): array {
        return [
            'run_id' => $runid,
            'deal_id' => $dealid,
            'lifecycle' => [
                'READY',
                'APPROVED',
                'RUNNING',
                'VERIFYING',
                'COMPLETED',
            ],
            'status' => 'READY',
        ];
    }
}
