<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class execution_verification {

    public function get(): array {
        return [
            'deal_id' => 3761,
            'run_id' => 'RUN-001',
            'systems' => [
                'moodle' => 'PENDING',
                'corporate' => 'PENDING',
                'crm' => 'PENDING',
            ],
            'verification' => 'READY',
            'result' => 'WAITING',
            'rollback' => 'AVAILABLE',
        ];
    }
}
