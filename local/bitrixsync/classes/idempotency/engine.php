<?php
namespace local_bitrixsync\idempotency;

defined('MOODLE_INTERNAL') || die();

class engine {

    public function check(array $operation): array {
        return [
            'mode' => 'shadow_only',
            'operation' => $operation,
            'idempotency_key' => $this->make_key($operation),
            'exists' => false,
            'action' => 'safe_to_process',
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }

    protected function make_key(array $operation): string {
        return sha1(json_encode($operation));
    }
}
