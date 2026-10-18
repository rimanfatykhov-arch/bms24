<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class final_gate {

    public function check(array $context): array {
        $allowed = (
            !empty($context['simulation_ok']) &&
            !empty($context['approved']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'mode' => 'controlled_active',
            'deal_id' => $context['deal_id'] ?? null,
            'simulation_verified' => !empty($context['simulation_ok']),
            'approved' => !empty($context['approved']),
            'execution_token' => $allowed ? sha1(json_encode($context)) : null,
            'execution_allowed' => $allowed,
            'single_run_lock' => $allowed,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
