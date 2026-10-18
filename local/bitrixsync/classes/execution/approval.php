<?php
namespace local_bitrixsync\execution;

defined('MOODLE_INTERNAL') || die();

class approval {

    public function approve(array $request): array {
        $approved = (
            !empty($request['approved']) &&
            !empty($request['approved_by']) &&
            isset($request['deal_id']) &&
            (int)$request['deal_id'] === 3761
        );

        return [
            'status' => $approved ? 'approved' : 'rejected',
            'deal_id' => $request['deal_id'] ?? null,
            'approved_by' => $request['approved_by'] ?? null,
            'approved_at' => $approved ? time() : null,
            'package_version' => 'beta.12',
            'execution_allowed' => $approved,
            'writes' => [
                'database' => false,
                'moodle' => false,
                'corporate' => false,
                'crm' => false,
            ],
        ];
    }
}
