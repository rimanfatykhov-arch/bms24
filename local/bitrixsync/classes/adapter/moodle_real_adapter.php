<?php
namespace local_bitrixsync\adapter;

defined('MOODLE_INTERNAL') || die();

class moodle_real_adapter {

    public function prepare(array $context): array {
        return [
            'target' => 'moodle',
            'user_id' => $context['user_id'] ?? null,
            'courses' => $context['courses'] ?? [],
            'checks' => [
                'user_lookup' => 'pending',
                'enrolment_check' => 'pending',
                'group_check' => 'pending',
            ],
            'status' => 'READY',
            'write_enabled' => false,
        ];
    }
}
