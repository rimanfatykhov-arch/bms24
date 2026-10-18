<?php
namespace local_bitrixsync\resolver;

defined('MOODLE_INTERNAL') || die();

class corporate_shadow_resolver {

    public function resolve(array $deal): array {
        return [
            'mode' => 'shadow_only',
            'company' => [
                'source' => 'bitrix_company_field',
                'corporate_id' => $deal['corporate_id'] ?? null,
                'status' => 'planned',
            ],
            'licenses' => [],
            'seats' => [],
            'employees' => [],
            'changes' => [
                'database' => 0,
                'corporate' => 0,
                'moodle' => 0,
                'crm' => 0,
            ],
        ];
    }
}
