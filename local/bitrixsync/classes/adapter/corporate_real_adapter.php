<?php
namespace local_bitrixsync\adapter;

defined('MOODLE_INTERNAL') || die();

class corporate_real_adapter {

    public function prepare(array $context): array {
        return [
            'target' => 'corporate',
            'corporate_id' => $context['corporate_id'] ?? null,
            'license' => 'pending',
            'status' => 'READY',
            'write_enabled' => false,
        ];
    }
}
