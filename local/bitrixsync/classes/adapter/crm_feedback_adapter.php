<?php
namespace local_bitrixsync\adapter;

defined('MOODLE_INTERNAL') || die();

class crm_feedback_adapter {

    public function prepare(array $context): array {
        return [
            'target' => 'crm',
            'deal_id' => $context['deal_id'] ?? null,
            'feedback' => 'pending',
            'status' => 'READY',
            'write_enabled' => false,
        ];
    }
}
