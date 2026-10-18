<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class real_run_persistence {

    public function start(int $dealid, string $runid): array {
        global $DB;

        return [
            'run' => [
                'deal_id' => $dealid,
                'run_id' => $runid,
                'status' => 'RUNNING',
                'timecreated' => time(),
            ],
            'events' => [
                [
                    'event' => 'RUN_CREATED',
                    'status' => 'RECORDED',
                ],
                [
                    'event' => 'EXECUTION_STARTED',
                    'status' => 'RECORDED',
                ],
            ],
            'storage' => 'database_ready',
            'commit' => false,
        ];
    }
}
