<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class action_executor {

    public function execute(array $context): array {
        $allowed = (
            !empty($context['approved']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'mode' => 'controlled_active',
            'deal_id' => $context['deal_id'] ?? null,
            'allowed' => $allowed,
            'actions' => [
                'moodle' => [
                    'enrolments' => 'prepared',
                    'groups' => 'prepared',
                    'verification' => 'pending',
                ],
                'corporate' => [
                    'license_allocation' => 'prepared',
                ],
                'crm' => [
                    'feedback' => 'prepared',
                ],
            ],
            'executed' => false,
            'rollback_ready' => true,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
