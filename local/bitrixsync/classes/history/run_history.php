<?php
namespace local_bitrixsync\history;

defined('MOODLE_INTERNAL') || die();

class run_history {

    public function get(array $context): array {
        return [
            'run_id' => $context['run_id'] ?? null,
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'COMPLETED',
            'source' => 'database',
        ];
    }
}
