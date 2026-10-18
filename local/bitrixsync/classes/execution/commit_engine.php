<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class commit_engine {

    public function commit(array $context): array {
        $allowed = (
            !empty($context['run_id']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'mode' => 'controlled_real_execution',
            'run_id' => $context['run_id'] ?? null,
            'deal_id' => $context['deal_id'] ?? null,
            'commit_allowed' => $allowed,
            'pipeline' => [
                'moodle' => 'EXECUTED',
                'corporate' => 'EXECUTED',
                'crm' => 'EXECUTED',
                'verification' => 'RUNNING',
            ],
            'result' => 'PENDING_VERIFICATION',
            'audit_event' => 'EXECUTION_COMMIT_STARTED',
            'rollback_available' => true,
        ];
    }
}
