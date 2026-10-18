<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class approval_handler {

    public function approve(int $dealid): array {
        return [
            'event' => 'APPROVED',
            'deal_id' => $dealid,
            'run_status' => 'READY_TO_START',
            'next' => 'runner',
        ];
    }
}
