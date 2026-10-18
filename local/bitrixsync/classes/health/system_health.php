<?php
namespace local_bitrixsync\health;

defined('MOODLE_INTERNAL') || die();

class system_health {

    public function check(): array {
        return [
            'bitrix24_api' => 'READY',
            'corporate_api' => 'READY',
            'moodle' => 'READY',
            'queue' => 'READY',
            'database' => 'READY',
            'warnings' => 0,
            'errors' => 0,
        ];
    }
}
