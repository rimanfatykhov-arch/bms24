<?php
namespace local_bitrixsync\actions;

defined('MOODLE_INTERNAL') || die();

class moodle_action_adapter {

    public function plan(array $context): array {
        return [
            'target' => 'moodle',
            'mode' => 'planned',
            'user' => $context['user_id'] ?? null,
            'courses' => $context['courses'] ?? [],
            'groups' => $context['groups'] ?? [],
            'checks' => [
                'user_exists' => 'required',
                'enrolment_exists' => 'required',
                'group_membership_exists' => 'required',
            ],
            'executed' => false,
        ];
    }
}
