<?php
namespace local_bitrixsync\resolver;

defined('MOODLE_INTERNAL') || die();

class corporate_employee_snapshot {

    public function resolve(int $corporateid): array {
        return [
            'mode' => 'shadow_only',
            'corporate_id' => $corporateid,
            'employees' => [
                'status' => 'pending_read',
                'match_strategy' => [
                    'email',
                    'external_id'
                ],
            ],
            'moodle_match' => [
                'created' => 0,
                'matched' => 0,
                'missing' => 0,
            ],
            'changes' => [
                'database' => 0,
                'corporate' => 0,
                'moodle' => 0,
                'crm' => 0,
            ],
        ];
    }
}
