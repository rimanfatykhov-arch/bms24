<?php
namespace local_bitrixsync\rules;

defined('MOODLE_INTERNAL') || die();

class rule_engine {

    public function evaluate(array $deal): array {
        $rules = [
            [
                'name' => 'Paid course enrollment',
                'stage' => 'C3:EXECUTING',
                'actions' => [
                    'moodle_user',
                    'course_enrollment',
                    'corporate_license',
                    'crm_update',
                ],
                'active' => true,
            ],
        ];

        $matched = [];

        foreach ($rules as $rule) {
            if (!empty($rule['active']) &&
                ($deal['STAGE_ID'] ?? '') === $rule['stage']) {
                $matched[] = $rule;
            }
        }

        return [
            'deal_id' => $deal['ID'] ?? 0,
            'matched' => $matched,
            'count' => count($matched),
        ];
    }
}
