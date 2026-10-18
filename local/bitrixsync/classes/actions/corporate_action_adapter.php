<?php
namespace local_bitrixsync\actions;

defined('MOODLE_INTERNAL') || die();

class corporate_action_adapter {

    public function plan(array $context): array {
        return [
            'target' => 'corporate',
            'mode' => 'planned',
            'corporate_id' => $context['corporate_id'] ?? null,
            'license' => $context['license'] ?? null,
            'seat_allocation' => 'planned',
            'executed' => false,
        ];
    }
}
