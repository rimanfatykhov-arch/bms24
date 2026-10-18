<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class lifecycle_commit {

    public function update(string $runid, string $status): array {
        global $DB;

        return [
            'table' => 'local_bitrixsync_runs',
            'run_id' => $runid,
            'new_status' => $status,
            'event_table' => 'local_bitrixsync_events',
            'event' => 'STATUS_CHANGED_' . $status,
            'persisted' => false,
        ];
    }
}
