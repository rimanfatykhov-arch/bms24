<?php
namespace local_bitrixsync\executor;

defined('MOODLE_INTERNAL') || die();

class action_executor {

    public function prepare(array $plan): array {
        return [
            'mode' => 'confirm_required',
            'status' => 'prepared',
            'plan' => $plan,
            'execution' => [
                'confirmed' => false,
                'executed' => false,
            ],
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
