<?php
namespace local_bitrixsync\history;

defined('MOODLE_INTERNAL') || die();

class real_timeline {

    public function get(string $runid): array {
        global $DB;

        return [
            'source' => 'database',
            'run_id' => $runid,
            'events' => $DB->count_records('local_bitrixsync_events', ['run_id' => $runid]),
        ];
    }
}
