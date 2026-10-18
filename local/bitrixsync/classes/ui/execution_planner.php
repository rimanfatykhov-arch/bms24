<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class execution_planner {

    public function get(): array {
        return [
            'deal_id' => 3761,
            'company' => 'ООО АЛТРЕЙД',
            'courses' => [8,2,13],
            'corporate_id' => 1,
            'actions' => [
                'moodle_enrolment',
                'license_allocation',
                'crm_feedback',
            ],
            'status' => 'READY_FOR_APPROVAL',
        ];
    }
}
