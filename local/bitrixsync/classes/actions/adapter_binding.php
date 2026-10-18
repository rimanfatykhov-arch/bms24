<?php
namespace local_bitrixsync\actions;

defined('MOODLE_INTERNAL') || die();

class adapter_binding {

    public function inspect(): array {
        return [
            'moodle_adapter' => [
                'status' => 'ready',
                'write_enabled' => false,
            ],
            'corporate_adapter' => [
                'status' => 'ready',
                'write_enabled' => false,
            ],
            'crm_adapter' => [
                'status' => 'ready',
                'write_enabled' => false,
            ],
            'scope' => [
                'deal_id' => 3761,
                'mode' => 'controlled_test',
            ],
        ];
    }
}
