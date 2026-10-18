<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class portal_actions {

    public function get(): array {
        return [
            'actions' => [
                [
                    'name' => 'plan',
                    'title' => 'Execution Planner',
                    'url' => 'planner.php',
                    'status' => 'available',
                ],
                [
                    'name' => 'approval',
                    'title' => 'Approval UI',
                    'url' => 'approval.php',
                    'status' => 'available',
                ],
                [
                    'name' => 'monitor',
                    'title' => 'Live Monitor',
                    'url' => 'live_status.php',
                    'status' => 'available',
                ],
                [
                    'name' => 'rollback',
                    'title' => 'Recovery Center',
                    'url' => 'recovery.php',
                    'status' => 'available',
                ],
            ],
        ];
    }
}
