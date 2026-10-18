<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class approval_action {

    public function get(): array {
        return [
            'deal_id' => 3761,
            'company' => 'ООО АЛТРЕЙД',
            'plan' => [
                'courses' => [8,2,13],
                'corporate_id' => 1,
            ],
            'checks' => [
                'validation' => 'PASSED',
                'rollback' => 'READY',
            ],
            'status' => 'WAITING_CONFIRMATION',
        ];
    }
}
