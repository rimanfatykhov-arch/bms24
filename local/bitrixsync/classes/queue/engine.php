<?php
namespace local_bitrixsync\queue;

defined('MOODLE_INTERNAL') || die();

class engine {

    public function push(array $payload): array {
        return [
            'mode' => 'shadow_only',
            'status' => 'queued',
            'payload' => $payload,
            'executed' => false,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
