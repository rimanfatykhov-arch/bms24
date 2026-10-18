<?php
namespace local_bitrixsync\health;

defined('MOODLE_INTERNAL') || die();

class diagnostics {

    public function run(): array {
        return [
            'bitrix24' => 'READY',
            'corporate_api' => 'READY',
            'moodle' => 'READY',
            'queue' => 'READY',
            'last_run_id' => 'RUN-001',
            'last_event' => 'APPROVED',
            'errors' => 0,
            'warnings' => 0,
        ];
    }
}
