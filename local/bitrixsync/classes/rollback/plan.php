<?php
namespace local_bitrixsync\rollback;

defined('MOODLE_INTERNAL') || die();

class plan {

    public function build(array $actions): array {
        return [
            'rollback_available' => true,
            'actions' => $actions,
            'rollback_steps' => [
                'remove_enrolments',
                'release_allocations',
                'restore_state',
            ],
            'executed' => false,
        ];
    }
}
