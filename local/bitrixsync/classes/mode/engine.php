<?php
namespace local_bitrixsync\mode;

defined('MOODLE_INTERNAL') || die();

class engine {

    const SHADOW_ONLY = 'shadow_only';
    const PLAN_ONLY = 'plan_only';
    const ACTIVE_TEST = 'active_test';

    public function check(string $mode, array $context = []): array {
        $allowed = false;

        if ($mode === self::ACTIVE_TEST) {
            $allowed = (
                isset($context['deal_id']) &&
                (int)$context['deal_id'] === 3761
            );
        }

        return [
            'requested_mode' => $mode,
            'allowed' => $allowed,
            'context' => $context,
            'writes_enabled' => $allowed,
            'limits' => [
                'deal_id' => 3761,
                'scope' => 'test_only',
            ],
        ];
    }
}
