<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class final_verification_report {

    public function build(array $context): array {
        return [
            'run_id' => $context['run_id'] ?? null,
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'PENDING',
            'checks' => [
                'moodle' => 'READY',
                'corporate' => 'PENDING',
                'crm' => 'PENDING',
            ],
            'next' => 'SUCCESS_OR_ROLLBACK',
        ];
    }
}
