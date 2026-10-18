<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class simulator {

    public function simulate(array $context): array {
        return [
            'mode' => 'controlled_test_simulation',
            'deal_id' => $context['deal_id'] ?? null,
            'simulation' => [
                'moodle' => [
                    'user_check' => 'passed',
                    'course_enrolment' => 'planned',
                    'group_assignment' => 'planned',
                ],
                'corporate' => [
                    'license_check' => 'passed',
                    'allocation' => 'planned',
                ],
                'crm' => [
                    'feedback' => 'planned',
                ],
            ],
            'result' => 'ready_for_execution',
            'executed' => false,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
