<?php
namespace local_bitrixsync\recovery;

defined('MOODLE_INTERNAL') || die();

class recovery_center {

    public function status(): array {
        return [
            'failed_runs' => 0,
            'rollback_available' => true,
            'last_recovery' => null,
            'actions' => [
                'rollback' => 'available',
                'retry' => 'available',
                'error_view' => 'available',
            ],
        ];
    }
}
