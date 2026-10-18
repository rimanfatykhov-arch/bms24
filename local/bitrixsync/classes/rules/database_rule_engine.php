<?php
namespace local_bitrixsync\rules;

defined('MOODLE_INTERNAL') || die();

class database_rule_engine {

    public function match(array $deal): array {
        global $DB;

        $rules = $DB->get_records('local_bitrixsync_rules', ['active' => 1]);

        $matched = [];

        foreach ($rules as $rule) {
            $conditions = $DB->get_records(
                'local_bitrixsync_rule_conditions',
                ['ruleid' => $rule->id]
            );

            $ok = true;

            foreach ($conditions as $condition) {
                $value = $deal[$condition->field] ?? null;

                if ($condition->operator === '=' &&
                    (string)$value !== (string)$condition->value) {
                    $ok = false;
                }
            }

            if ($ok) {
                $matched[] = $rule;
            }
        }

        return [
            'deal_id' => $deal['ID'] ?? 0,
            'matched_rules' => $matched,
            'count' => count($matched),
        ];
    }
}
