<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class test_harness {

    public function prepare(array $context): array {
        $allowed = (
            !empty($context['approved']) &&
            !empty($context['guard_ok']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'mode' => 'controlled_test',
            'deal_id' => $context['deal_id'] ?? null,
            'prechecks' => [
                'approval' => !empty($context['approved']),
                'write_guard' => !empty($context['guard_ok']),
                'scope_check' => (($context['deal_id'] ?? 0) == 3761),
            ],
            'execution_ready' => $allowed,
            'execution_started' => false,
            'verification_pending' => true,
            'rollback_ready' => true,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
