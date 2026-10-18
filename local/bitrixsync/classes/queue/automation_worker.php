<?php
namespace local_bitrixsync\queue;

defined('MOODLE_INTERNAL') || die();

class automation_worker {

    public function process(int $limit = 10): array {
        global $DB;

        $processed = 0;
        $failed = 0;

        $items = $DB->get_records(
            'local_bitrixsync_syncqueue',
            ['status' => 'pending'],
            'id ASC',
            '*',
            0,
            $limit
        );

        foreach ($items as $item) {
            $processed++;
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'status' => 'completed',
        ];
    }
}
