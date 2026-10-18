<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class controlled_execute_engine {

    public function execute(array $context): array {
        $allowed = (
            !empty($context['execution_token']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'mode' => 'controlled_active',
            'deal_id' => $context['deal_id'] ?? null,
            'token_received' => !empty($context['execution_token']),
            'execution_allowed' => $allowed,
            'execution' => [
                'started' => $allowed,
                'completed' => false,
                'result' => 'pending_verification',
            ],
            'audit' => [
                'status' => 'execution_started',
            ],
            'rollback' => [
                'available' => true,
                'triggered' => false,
            ],
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
