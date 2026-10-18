<?php
namespace local_bitrixsync\guard;

defined('MOODLE_INTERNAL') || die();

class write_guard {

    public function check(array $context): array {
        $allowed = (
            !empty($context['approved']) &&
            isset($context['deal_id']) &&
            (int)$context['deal_id'] === 3761
        );

        return [
            'allowed' => $allowed,
            'scope' => [
                'deal_id' => 3761,
                'mode' => 'controlled_test',
            ],
            'permissions' => [
                'moodle_enrolment' => $allowed,
                'corporate_license' => $allowed,
                'crm_feedback' => $allowed,
                'bulk_operations' => false,
            ],
            'limits' => [
                'single_deal_only' => true,
                'rollback_required' => true,
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
