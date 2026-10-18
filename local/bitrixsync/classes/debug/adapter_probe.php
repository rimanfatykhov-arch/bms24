<?php
namespace local_bitrixsync\debug;

defined('MOODLE_INTERNAL') || die();

class adapter_probe {

    public function inspect(string $method, array $params = []): array {
        $result = [
            'method' => $method,
            'params' => $params,
            'mode' => 'debug_only',
            'calls_executed' => false,
            'candidates' => [],
        ];

        $classes = [
            '\\local_bitrixsync\\adapters\\bitrix24\\deal_client',
            '\\local_bitrixsync\\adapters\\bitrix24\\client',
            '\\local_bitrixsync\\adapters\\bitrix24\\catalog_client',
            '\\local_bitrixsync\\adapters\\corporate\\api_client',
        ];

        foreach ($classes as $class) {
            $result['candidates'][] = [
                'class' => $class,
                'exists' => class_exists($class),
            ];
        }

        return $result;
    }
}
