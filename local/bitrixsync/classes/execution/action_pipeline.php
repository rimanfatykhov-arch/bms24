<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class action_pipeline {

    public function prepare(array $context): array {
        return [
            'deal_id' => $context['deal_id'] ?? null,
            'mode' => 'controlled_execution',
            'actions' => [
                'moodle' => [
                    'user_lookup' => 'pending',
                    'enrolment' => 'pending',
                    'verification' => 'pending',
                ],
                'corporate' => [
                    'license' => 'pending',
                ],
                'crm' => [
                    'feedback' => 'pending',
                ],
            ],
            'verification' => [
                'status' => 'pending',
                'rollback_available' => true,
            ],
            'result' => 'READY',
        ];
    }
}
