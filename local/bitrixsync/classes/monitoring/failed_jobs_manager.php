<?php
namespace local_bitrixsync\monitoring;

defined('MOODLE_INTERNAL') || die();

class failed_jobs_manager {

    public function summary(): array {
        global $DB;

        return [
            'failed' => $DB->count_records('local_bitrixsync_failed_jobs'),
            'status' => 'active',
        ];
    }

    public function retry(int $id): array {
        return [
            'jobid' => $id,
            'action' => 'retry',
            'status' => 'queued',
        ];
    }
}
