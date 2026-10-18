<?php
namespace local_bitrixsync\data;

defined('MOODLE_INTERNAL') || die();

class audit_repository {

    public function summary(): array {
        global $DB;

        return [
            'runs' => $DB->count_records('local_bitrixsync_runs'),
            'events' => $DB->count_records('local_bitrixsync_events'),
            'audit' => 'enabled',
            'history' => 'database',
        ];
    }

    public function add_event(string $runid, string $event, string $status): bool {
        global $DB;

        $record = (object)[
            'run_id' => $runid,
            'event' => $event,
            'status' => $status,
            'timecreated' => time(),
        ];

        return (bool)$DB->insert_record('local_bitrixsync_events', $record);
    }
}
