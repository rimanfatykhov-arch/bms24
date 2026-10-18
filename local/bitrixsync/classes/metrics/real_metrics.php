<?php
namespace local_bitrixsync\metrics;

defined('MOODLE_INTERNAL') || die();

class real_metrics {

    public function get(): array {
        global $DB;

        return [
            'source' => 'database',
            'total_runs' => $DB->count_records('local_bitrixsync_runs'),
            'total_events' => $DB->count_records('local_bitrixsync_events'),
            'status' => 'ACTIVE',
        ];
    }
}
