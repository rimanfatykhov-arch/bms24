<?php
namespace local_bitrixsync\ui;

defined('MOODLE_INTERNAL') || die();

class execution_timeline {

    public function get(): array {
        return [
            'run_id' => 'RUN-001',
            'deal_id' => 3761,
            'events' => [
                ['step'=>'APPROVED','status'=>'COMPLETED'],
                ['step'=>'STARTED','status'=>'COMPLETED'],
                ['step'=>'MOODLE','status'=>'READY'],
                ['step'=>'CORPORATE','status'=>'RUNNING'],
                ['step'=>'CRM','status'=>'PENDING'],
                ['step'=>'VERIFICATION','status'=>'WAITING'],
            ],
        ];
    }
}
