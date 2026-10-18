<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class launch_package {

    public function build(array $context): array {
        return [
            'status' => 'ready_for_review',
            'deal_id' => $context['deal_id'] ?? null,
            'run_scope' => 'single_deal_test',
            'checks' => [
                'courses' => $context['courses'] ?? [],
                'corporate_id' => $context['corporate_id'] ?? null,
                'confirmation_required' => true,
            ],
            'execution_limits' => [
                'only_deal' => 3761,
                'writes' => 'disabled_until_approval',
            ],
            'rollback' => [
                'prepared' => true,
            ],
        ];
    }
}
