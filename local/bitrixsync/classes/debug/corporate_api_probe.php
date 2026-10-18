<?php
namespace local_bitrixsync\debug;

defined('MOODLE_INTERNAL') || die();

class corporate_api_probe {

    public function inspect(): array {
        $class = '\\local_bitrixsync\\adapters\\corporate\\api_client';

        $result = [
            'adapter' => $class,
            'exists' => class_exists($class),
            'constructor' => null,
            'methods' => [],
        ];

        if (!class_exists($class)) {
            return $result;
        }

        $reflection = new \ReflectionClass($class);

        $result['constructor'] = $reflection->getConstructor()
            ? 'exists'
            : 'none';

        foreach ($reflection->getMethods() as $method) {
            $result['methods'][] = $method->name;
        }

        return $result;
    }
}
