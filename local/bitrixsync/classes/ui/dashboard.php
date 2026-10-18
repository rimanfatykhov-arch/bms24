<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class dashboard {

    public function get(): array {
        return [
            'deal' => [
                'id' => 3761,
                'status' => 'READY',
            ],
            'company' => 'ООО АЛТРЕЙД',
            'courses' => [8,2,13],
            'corporate_id' => 1,
            'system_status' => 'READY',
            'actions' => [
                'plan',
                'approval',
                'monitor',
                'rollback',
            ],
        ];
    }
}
