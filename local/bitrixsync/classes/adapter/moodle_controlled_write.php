<?php
namespace local_bitrixsync\adapter;

defined('MOODLE_INTERNAL') || die();

class moodle_controlled_write {

    public function execute(array $context): array {
        return [
            'target' => 'moodle',
            'deal_id' => $context['deal_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'courses' => $context['courses'] ?? [],
            'mode' => 'controlled_write',
            'checks' => [
                'user_exists' => 'pending',
                'enrolment_before' => 'pending',
                'enrolment_action' => 'prepared',
                'verification' => 'pending',
            ],
            'event' => 'MOODLE_ACTION_PREPARED',
            'rollback_available' => true,
            'write_committed' => false,
        ];
    }
}
