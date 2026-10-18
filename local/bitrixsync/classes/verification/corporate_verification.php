<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class corporate_verification {

    public function check(array $context): array {
        return [
            'target' => 'corporate',
            'corporate_id' => $context['corporate_id'] ?? null,
            'status' => 'pending',
            'event' => 'CORPORATE_VERIFICATION_PENDING',
            'rollback_available' => true,
        ];
    }
}
