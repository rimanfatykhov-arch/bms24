<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class test_report {

    public function build(array $context): array {
        return [
            'mode' => 'controlled_test',
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'ready_for_execution_review',
            'plan' => [
                'courses' => $context['courses'] ?? [],
                'corporate_id' => $context['corporate_id'] ?? null,
            ],
            'checks' => [
                'approval' => true,
                'write_guard' => true,
                'idempotency' => true,
                'rollback' => true,
            ],
            'execution' => [
                'started' => false,
                'completed' => false,
            ],
            'result' => 'pending',
        ];
    }
}
