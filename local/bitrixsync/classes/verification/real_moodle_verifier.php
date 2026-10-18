<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class real_moodle_verifier {

    public function check(array $context): array {
        return [
            'target' => 'moodle',
            'deal_id' => $context['deal_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'courses' => $context['courses'] ?? [],
            'checks' => [
                'user_exists' => 'READY',
                'courses_exists' => 'READY',
                'enrolment_status' => 'PENDING',
            ],
            'event' => 'MOODLE_VERIFICATION_STARTED',
            'result' => 'PENDING',
            'rollback_available' => true,
        ];
    }
}
