<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class persistence_run {

    public function create(int $dealid, string $runid): array {
        return [
            'table' => 'local_bitrixsync_runs',
            'deal_id' => $dealid,
            'run_id' => $runid,
            'status' => 'RUNNING',
            'event' => 'RUN_CREATED',
            'persisted' => false,
        ];
    }

    public function event(string $runid, string $event): array {
        return [
            'table' => 'local_bitrixsync_events',
            'run_id' => $runid,
            'event' => $event,
            'status' => 'RECORDED',
            'persisted' => false,
        ];
    }
}
