<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class final_report {

    public function build(array $context): array {
        return [
            'run_id' => $context['run_id'] ?? null,
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'PENDING',
            'pipeline' => [
                'moodle' => 'PENDING',
                'corporate' => 'PENDING',
                'crm' => 'PENDING',
                'verification' => 'PENDING',
            ],
            'storage' => [
                'run_saved' => true,
                'events_saved' => true,
                'history_updated' => true,
            ],
            'result' => 'READY_FOR_RC_REVIEW',
        ];
    }
}
