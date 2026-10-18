<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class corporate_crm_verifier {

    public function check(array $context): array {
        return [
            'deal_id' => $context['deal_id'] ?? null,
            'checks' => [
                'moodle' => 'READY',
                'corporate' => [
                    'license' => 'PENDING',
                    'seat_allocation' => 'PENDING',
                ],
                'crm' => [
                    'deal_exists' => 'READY',
                    'feedback' => 'PENDING',
                ],
            ],
            'events' => [
                'MOODLE_VERIFICATION_COMPLETED',
                'CORPORATE_VERIFICATION_STARTED',
                'CRM_VERIFICATION_STARTED',
            ],
            'result' => 'PENDING',
            'rollback_available' => true,
        ];
    }
}
