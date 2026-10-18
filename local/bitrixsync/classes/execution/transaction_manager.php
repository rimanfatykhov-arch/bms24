<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class transaction_manager {

    public function start(array $context): array {
        return [
            'transaction_id' => sha1(json_encode($context) . microtime(true)),
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'started',
            'steps' => [
                'moodle_action' => 'pending',
                'corporate_action' => 'pending',
                'crm_feedback' => 'pending',
                'verification' => 'pending',
            ],
            'commit' => false,
            'rollback_available' => true,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }

    public function rollback(array $transaction): array {
        return [
            'transaction_id' => $transaction['transaction_id'] ?? null,
            'status' => 'rollback_ready',
            'executed' => false,
        ];
    }
}
