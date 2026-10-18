<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class event_log {

    public function get(array $context): array {
        return [
            'deal_id' => $context['deal_id'] ?? null,
            'run_id' => $context['run_id'] ?? 'PENDING',
            'events' => [
                [
                    'event' => 'APPROVED',
                    'status' => 'completed',
                ],
                [
                    'event' => 'EXECUTION_STARTED',
                    'status' => 'pending',
                ],
                [
                    'event' => 'VERIFICATION',
                    'status' => 'pending',
                ],
            ],
        ];
    }
}
