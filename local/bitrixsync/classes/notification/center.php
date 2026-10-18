<?php
namespace local_bitrixsync\notification;

defined('MOODLE_INTERNAL') || die();

class center {

    public function get(): array {
        return [
            [
                'type' => 'INFO',
                'message' => 'Execution review required',
            ],
            [
                'type' => 'SUCCESS',
                'message' => 'Last controlled check completed',
            ],
            [
                'type' => 'WARNING',
                'message' => 'No active execution runs',
            ],
        ];
    }
}
