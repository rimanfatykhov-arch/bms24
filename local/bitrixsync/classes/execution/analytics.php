<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class analytics {

    public function get(): array {
        return [
            'runs' => 2,
            'success' => 1,
            'failed' => 0,
            'rollback' => 0,
            'average_time' => 0,
            'systems' => [
                'moodle' => 1,
                'corporate' => 1,
                'crm' => 1,
            ],
        ];
    }
}
