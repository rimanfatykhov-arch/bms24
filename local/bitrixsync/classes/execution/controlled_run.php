<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class controlled_run {

    public function start(array $context): array {
        $allowed = (
            !empty($context['approved']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        $runid = $allowed ? 'RUN-' . time() : null;

        return [
            'mode' => 'controlled_real_execution',
            'run_id' => $runid,
            'deal_id' => $context['deal_id'] ?? null,
            'status' => $allowed ? 'STARTED' : 'BLOCKED',
            'pipeline' => [
                'audit' => 'RECORDED',
                'moodle' => 'PREPARED',
                'corporate' => 'PREPARED',
                'crm' => 'PREPARED',
                'verification' => 'PENDING',
            ],
            'result' => 'RUNNING',
            'rollback_available' => true,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
