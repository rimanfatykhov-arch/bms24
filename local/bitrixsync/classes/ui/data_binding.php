<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class data_binding {

    public function dashboard(): array {
        global $DB;

        return [
            'source' => 'database',
            'runs' => $DB->count_records('local_bitrixsync_runs'),
            'events' => $DB->count_records('local_bitrixsync_events'),
            'status' => 'READY',
        ];
    }

    public function history(): array {
        global $DB;

        return [
            'source' => 'database',
            'latest_runs' => $DB->get_records('local_bitrixsync_runs', null, 'timecreated DESC', '*', 0, 10),
        ];
    }
}
