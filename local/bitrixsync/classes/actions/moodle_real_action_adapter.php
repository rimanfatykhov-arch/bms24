<?php
namespace local_bitrixsync\actions;

defined('MOODLE_INTERNAL') || die();

class moodle_real_action_adapter {

    public function prepare(array $context): array {
        return [
            'target' => 'moodle',
            'mode' => 'controlled_action',
            'deal_id' => $context['deal_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'courses' => $context['courses'] ?? [],
            'checks' => [
                'user_lookup' => 'pending',
                'enrolment_check' => 'pending',
                'group_check' => 'pending',
            ],
            'execution' => [
                'prepared' => true,
                'executed' => false,
            ],
            'rollback_supported' => true,
        ];
    }
}
