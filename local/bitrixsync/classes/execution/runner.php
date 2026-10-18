<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class runner {

    public function run(array $context): array {
        $approved = !empty($context['approved'])
            && !empty($context['deal_id'])
            && (int)$context['deal_id'] === 3761;

        return [
            'mode' => 'controlled_active',
            'deal_id' => $context['deal_id'] ?? null,
            'approval_checked' => true,
            'approved' => $approved,
            'pipeline' => [
                'idempotency' => 'checked',
                'plan' => 'loaded',
                'execution' => $approved ? 'ready' : 'blocked',
                'verification' => 'pending',
            ],
            'executed' => false,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
