<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class layout_framework {

    public function get(): array {
        return [
            'header' => [
                'status' => 'READY',
                'deal' => 3761,
            ],
            'sidebar' => [
                'dashboard',
                'execution',
                'operations',
                'system',
            ],
            'content' => 'portal',
        ];
    }
}
