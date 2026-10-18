<?php
namespace local_bitrixsync\resolver;

defined('MOODLE_INTERNAL') || die();

class corporate_snapshot_resolver {

    public function resolve(int $corporateid): array {
        $result = [
            'mode' => 'shadow_only',
            'corporate_id' => $corporateid,
            'adapter' => '\\local_bitrixsync\\adapters\\corporate\\api_client',
            'company' => null,
            'license_snapshot' => null,
            'employees' => null,
            'executed' => false,
            'changes' => [
                'database' => 0,
                'corporate' => 0,
                'moodle' => 0,
                'crm' => 0,
            ],
        ];

        $class = '\\local_bitrixsync\\adapters\\corporate\\api_client';

        if (!class_exists($class)) {
            $result['error'] = 'corporate_api_client_missing';
            return $result;
        }

        try {
            $client = new $class();

            $result['available_methods'] = [
                'get_company_snapshot' => method_exists($client, 'get_company_snapshot'),
                'get_license_snapshot' => method_exists($client, 'get_license_snapshot'),
            ];

            $result['execution_plan'] = [
                'company' => 'READ snapshot prepared',
                'licenses' => 'READ snapshot prepared',
                'employees' => 'next resolver stage',
            ];

            $result['executed'] = true;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
