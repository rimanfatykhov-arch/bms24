<?php
namespace local_bitrixsync\dashboard;

defined('MOODLE_INTERNAL') || die();

class real_dashboard {

    public function get(): array {
        global $DB;

        return [
            'source' => 'database',
            'latest_run' => $DB->get_record_sql(
                "SELECT * FROM {local_bitrixsync_runs} ORDER BY timecreated DESC",
                []
            ),
            'runs' => $DB->count_records('local_bitrixsync_runs'),
            'events' => $DB->count_records('local_bitrixsync_events'),
        ];
    }
}
