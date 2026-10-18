<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class sla_dashboard {

    public function get(): array {
        return [
            'run_id' => 'RUN-001',
            'target' => '00:05:00',
            'current' => '00:02:15',
            'status' => 'OK',
            'systems' => [
                'moodle' => 'OK',
                'corporate' => 'OK',
                'crm' => 'OK',
            ],
        ];
    }
}
