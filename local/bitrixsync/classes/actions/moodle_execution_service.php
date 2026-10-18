<?php
namespace local_bitrixsync\actions;

defined('MOODLE_INTERNAL') || die();

class moodle_execution_service {

    public function status(array $context): array {
        return [
            'deal_id' => $context['deal_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'courses' => $context['courses'] ?? [],
            'mode' => 'controlled_action',
            'executed' => false,
            'rollback_available' => true,
        ];
    }
}
