<?php
namespace local_bitrixsync\debug;

defined('MOODLE_INTERNAL') || die();

class deal_adapter_runner {

    public function run(int $dealid): array {
        $result = [
            'dealid' => $dealid,
            'executed' => false,
            'adapter' => '\\local_bitrixsync\\adapters\\bitrix24\\deal_client',
            'deal' => null,
            'product_rows' => null,
            'company' => null,
            'contact' => null,
            'errors' => [],
        ];

        $class = '\\local_bitrixsync\\adapters\\bitrix24\\deal_client';

        if (!class_exists($class)) {
            $result['errors'][] = 'deal_client_not_found';
            return $result;
        }

        try {
            $client = new $class();

            $result['deal'] = method_exists($client, 'get_deal')
                ? $client->get_deal($dealid)
                : null;

            $result['product_rows'] = method_exists($client, 'get_product_rows')
                ? $client->get_product_rows($dealid)
                : null;

            $result['executed'] = true;

        } catch (\Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }
}
