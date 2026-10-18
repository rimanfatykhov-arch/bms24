<?php
namespace local_bitrixsync\data;

defined('MOODLE_INTERNAL') || die();

class run_repository {

    public function create(int $dealid, string $runid): int {
        global $DB;

        return $DB->insert_record('local_bitrixsync_runs', (object)[
            'deal_id' => $dealid,
            'run_id' => $runid,
            'status' => 'READY',
            'timecreated' => time(),
        ]);
    }
}
