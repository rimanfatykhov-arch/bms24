<?php
namespace local_bitrixsync\verification;

defined('MOODLE_INTERNAL') || die();

class moodle_verification {

    public function check(array $context): array {
        return [
            'target' => 'moodle',
            'deal_id' => $context['deal_id'] ?? null,
            'courses' => $context['courses'] ?? [],
            'status' => 'pending',
            'event' => 'MOODLE_VERIFICATION_PENDING',
        ];
    }
}
