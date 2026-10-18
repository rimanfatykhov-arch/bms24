<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class portal_integration {

    public function get(): array {
        return [
            'portal' => 'KBA Connect',
            'status' => 'READY',
            'deal' => 3761,
            'company' => 'ООО АЛТРЕЙД',
            'sections' => [
                'dashboard',
                'planner',
                'approval',
                'execution',
                'timeline',
                'metrics',
                'sla',
                'history',
                'diagnostics',
                'recovery',
                'notifications',
            ],
        ];
    }
}
