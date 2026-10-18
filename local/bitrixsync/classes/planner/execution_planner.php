<?php
namespace local_bitrixsync\planner;

defined('MOODLE_INTERNAL') || die();

class execution_planner {

    public function build(array $context): array {
        return [
            'mode' => 'shadow_only',
            'context' => $context,
            'actions' => [
                'create_user' => [
                    'status' => 'planned',
                ],
                'enrol_courses' => [
                    'status' => 'planned',
                    'courses' => $context['courses'] ?? [],
                ],
                'assign_group' => [
                    'status' => 'planned',
                ],
                'assign_license' => [
                    'status' => 'planned',
                ],
            ],
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
