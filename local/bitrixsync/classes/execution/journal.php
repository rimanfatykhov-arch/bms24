<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class journal {

    public function dry_run(array $plan): array {
        return [
            'mode' => 'dry_run',
            'status' => 'prepared',
            'plan' => $plan,
            'actions' => [
                'moodle' => [
                    'enrolments' => $plan['courses'] ?? [],
                    'groups' => 'planned',
                    'users' => 'planned',
                ],
                'corporate' => [
                    'licenses' => 'planned',
                    'seats' => 'planned',
                ],
                'crm' => [
                    'feedback' => 'planned',
                ],
            ],
            'executed' => false,
            'changes' => [
                'database' => 0,
                'moodle' => 0,
                'corporate' => 0,
                'crm' => 0,
            ],
        ];
    }
}
