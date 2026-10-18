<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class real_execution_engine {

    public function prepare(array $context): array {
        $allowed = (
            !empty($context['approved']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'mode' => 'controlled_real_execution',
            'deal_id' => $context['deal_id'] ?? null,
            'allowed' => $allowed,
            'run_created' => $allowed,
            'transaction' => [
                'status' => 'started',
                'commit' => false,
                'rollback_available' => true,
            ],
            'adapters' => [
                'moodle' => 'READY',
                'corporate' => 'READY',
                'crm' => 'READY',
            ],
            'events' => [
                'EXECUTION_PREPARED'
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
