<?php
namespace local_bitrixsync\data;

defined('MOODLE_INTERNAL') || die();

class real_commit_repository {

    public function save_run(array $context): array {
        global $DB;

        return [
            'table' => 'local_bitrixsync_runs',
            'run_id' => $context['run_id'] ?? null,
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'COMPLETED',
            'storage' => 'database',
            'saved' => false,
        ];
    }

    public function save_event(string $runid, string $event): array {
        return [
            'table' => 'local_bitrixsync_events',
            'run_id' => $runid,
            'event' => $event,
            'status' => 'RECORDED',
            'saved' => false,
        ];
    }
}
