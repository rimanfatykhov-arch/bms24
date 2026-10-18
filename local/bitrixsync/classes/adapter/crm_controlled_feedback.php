<?php
namespace local_bitrixsync\adapter;

defined('MOODLE_INTERNAL') || die();

class crm_controlled_feedback {

    public function execute(array $context): array {
        return [
            'target' => 'crm',
            'deal_id' => $context['deal_id'] ?? null,
            'mode' => 'controlled_write',
            'checks' => [
                'deal_exists' => 'pending',
                'feedback_payload' => 'pending',
                'crm_verification' => 'pending',
            ],
            'event' => 'CRM_FEEDBACK_ACTION_PREPARED',
            'rollback_available' => true,
            'write_committed' => false,
        ];
    }
}
