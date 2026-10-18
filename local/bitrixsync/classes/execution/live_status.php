<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class live_status {

    public function get(array $context): array {
        return [
            'run_id' => $context['run_id'] ?? 'PENDING',
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'READY',
            'steps' => [
                'started' => false,
                'moodle' => 'pending',
                'corporate' => 'pending',
                'crm' => 'pending',
                'verification' => 'pending',
            ],
        ];
    }
}
