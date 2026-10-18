<?php
namespace local_bitrixsync\adapter;

defined('MOODLE_INTERNAL') || die();

class moodle_enrolment_adapter {

    public function check(array $context): array {
        return [
            'target' => 'moodle',
            'deal_id' => $context['deal_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'courses' => $context['courses'] ?? [],
            'checks' => [
                'user_exists' => 'pending',
                'enrolment_exists' => 'pending',
                'course_access' => 'pending',
            ],
            'action' => 'enrolment_prepare',
            'write_enabled' => false,
            'rollback_supported' => true,
        ];
    }
}
