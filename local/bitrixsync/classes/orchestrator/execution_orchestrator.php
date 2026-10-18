<?php
namespace local_bitrixsync\orchestrator;

defined('MOODLE_INTERNAL') || die();

class execution_orchestrator {

    public function prepare(array $context): array {
        return [
            'run_id' => sha1(json_encode($context) . microtime(true)),
            'mode' => 'controlled_active',
            'deal_id' => $context['deal_id'] ?? null,
            'pipeline' => [
                'audit' => 'ready',
                'validate' => 'ready',
                'dry_run' => 'ready',
                'confirm' => 'required',
                'execute' => 'blocked',
                'verify' => 'pending',
            ],
            'execution' => [
                'started' => false,
                'completed' => false,
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
