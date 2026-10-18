<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class verification_engine {

    public function verify(array $context): array {
        return [
            'mode' => 'controlled_active',
            'verified' => false,
            'checks' => [
                'user_exists' => 'pending',
                'course_enrolment' => 'pending',
                'group_membership' => 'pending',
                'license_allocation' => 'pending',
                'crm_feedback' => 'pending',
            ],
            'context' => $context,
            'rollback_trigger' => false,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
