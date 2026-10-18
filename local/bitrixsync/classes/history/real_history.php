<?php
namespace local_bitrixsync\history;

defined('MOODLE_INTERNAL') || die();

class real_history {

    public function get(array $context): array {
        global $DB;

        return [
            'source' => 'database',
            'run_id' => $context['run_id'] ?? null,
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'COMPLETED',
            'records' => $DB->count_records('local_bitrixsync_runs'),
        ];
    }
}
