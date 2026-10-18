<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class control_actions {

    public function start_test(array $context): array {
        $allowed = (
            !empty($context['approved']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'action' => 'start_test',
            'allowed' => $allowed,
            'deal_id' => $context['deal_id'] ?? null,
            'status' => $allowed ? 'execution_started' : 'blocked',
            'run_id' => $allowed ? sha1(json_encode($context) . microtime(true)) : null,
            'audit_event' => 'execution_start_requested',
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }

    public function rollback(array $context): array {
        return [
            'action' => 'rollback',
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'rollback_requested',
            'audit_event' => 'rollback_requested',
        ];
    }
}
