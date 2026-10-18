<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class navigation_hub {

    public function get(): array {
        return [
            'status' => 'READY',
            'last_run' => 'RUN-001',
            'sections' => [
                'dashboard',
                'approval',
                'monitor',
                'report',
                'control',
                'diagnostics',
                'recovery',
                'notifications',
            ],
        ];
    }
}
