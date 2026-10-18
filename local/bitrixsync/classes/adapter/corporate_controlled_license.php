<?php
namespace local_bitrixsync\adapter;

defined('MOODLE_INTERNAL') || die();

class corporate_controlled_license {

    public function execute(array $context): array {
        return [
            'target' => 'corporate',
            'deal_id' => $context['deal_id'] ?? null,
            'corporate_id' => $context['corporate_id'] ?? null,
            'mode' => 'controlled_write',
            'checks' => [
                'license_available' => 'pending',
                'seat_allocation' => 'pending',
                'license_verification' => 'pending',
            ],
            'event' => 'CORPORATE_LICENSE_ACTION_PREPARED',
            'rollback_available' => true,
            'write_committed' => false,
        ];
    }
}
