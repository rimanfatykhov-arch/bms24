<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class crm_verification {

    public function check(array $context): array {
        return [
            'target' => 'crm',
            'deal_id' => $context['deal_id'] ?? null,
            'status' => 'pending',
            'event' => 'CRM_VERIFICATION_PENDING',
            'rollback_available' => true,
        ];
    }
}
