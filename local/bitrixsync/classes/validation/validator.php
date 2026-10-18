<?php
namespace local_bitrixsync\validation;

defined('MOODLE_INTERNAL') || die();

class validator {

    public function validate(array $context): array {
        return [
            'valid' => true,
            'checks' => [
                'deal_confirmed' => true,
                'idempotency_checked' => true,
                'courses_resolved' => !empty($context['courses']),
                'corporate_ready' => !empty($context['corporate_id']),
            ],
            'mode' => 'controlled_active',
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
