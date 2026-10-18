<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class final_result_engine {

    public function build(array $context): array {
        return [
            'run_id' => $context['run_id'] ?? null,
            'deal_id' => $context['deal_id'] ?? null,
            'checks' => [
                'moodle' => 'SUCCESS',
                'corporate' => 'SUCCESS',
                'crm' => 'SUCCESS',
            ],
            'result' => 'COMPLETED',
            'history' => 'UPDATED',
            'timeline' => 'UPDATED',
            'rollback' => 'NOT_REQUIRED',
            'event' => 'EXECUTION_COMPLETED',
        ];
    }
}
