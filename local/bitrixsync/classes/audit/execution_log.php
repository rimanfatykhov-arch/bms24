<?php
namespace local_bitrixsync\audit;

defined('MOODLE_INTERNAL') || die();

class execution_log {

    public function create(array $context): array {
        return [
            'run_id' => sha1(json_encode($context) . microtime(true)),
            'deal_id' => $context['deal_id'] ?? null,
            'created_by' => $context['created_by'] ?? null,
            'created_at' => time(),
            'status' => 'prepared',
            'plan_snapshot' => $context['plan'] ?? [],
            'executed' => false,
        ];
    }
}
