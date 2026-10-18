<?php
namespace local_bitrixsync\gate;

defined('MOODLE_INTERNAL') || die();

class confirmation_gate {

    public function confirm(array $request): array {
        $allowed = (
            isset($request['deal_id']) &&
            (int)$request['deal_id'] === 3761 &&
            !empty($request['confirmed_by'])
        );

        return [
            'mode' => 'controlled_active',
            'allowed' => $allowed,
            'deal_id' => $request['deal_id'] ?? null,
            'confirmed_by' => $request['confirmed_by'] ?? null,
            'confirmed_at' => $allowed ? time() : null,
            'actions' => $request['actions'] ?? [],
            'execution' => [
                'ready' => $allowed,
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
