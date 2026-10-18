<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class execution_verifier {

    public function verify(array $context): array {
        return [
            'run_id' => $context['run_id'] ?? null,
            'deal_id' => $context['deal_id'] ?? null,
            'checks' => [
                'moodle' => 'PENDING',
                'corporate' => 'PENDING',
                'crm' => 'PENDING',
            ],
            'status' => 'PENDING',
            'rollback_required' => false,
        ];
    }
}
