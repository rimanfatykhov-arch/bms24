<?php
namespace local_bitrixsync\data;

defined('MOODLE_INTERNAL') || die();

class repository {

    public function summary(): array {
        global $DB;

        return [
            'runs' => $DB->count_records('local_bitrixsync_runs'),
            'events' => $DB->count_records('local_bitrixsync_events'),
            'storage' => 'database',
            'mode' => 'real_data',
        ];
    }
}
