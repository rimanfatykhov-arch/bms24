<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class full_orchestrator {

    public function prepare(array $context): array {
        $allowed = (
            !empty($context['approved']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'mode' => 'controlled_full_execution',
            'deal_id' => $context['deal_id'] ?? null,
            'allowed' => $allowed,
            'pipeline' => [
                'moodle' => 'READY',
                'corporate' => 'READY',
                'crm' => 'READY',
                'verification' => 'READY',
                'audit' => 'READY',
            ],
            'transaction' => [
                'status' => 'prepared',
                'commit' => false,
                'rollback_available' => true,
            ],
            'result' => 'READY_FOR_CONTROLLED_RUN',
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
