<?php
namespace local_bitrixsync\executor;

defined('MOODLE_INTERNAL') || die();

class controlled_executor {

    public function execute(array $request): array {
        $allowed = (
            !empty($request['confirmed']) &&
            isset($request['deal_id']) &&
            (int)$request['deal_id'] === 3761
        );

        return [
            'mode' => 'controlled_active',
            'allowed' => $allowed,
            'deal_id' => $request['deal_id'] ?? null,
            'actions' => $request['actions'] ?? [],
            'executed' => false,
            'audit' => [
                'status' => $allowed ? 'ready_for_execution' : 'blocked',
            ],
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
            'rollback' => [
                'available' => true,
            ],
        ];
    }
}
