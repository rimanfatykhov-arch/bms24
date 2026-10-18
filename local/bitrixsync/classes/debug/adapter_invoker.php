<?php
namespace local_bitrixsync\debug;

defined('MOODLE_INTERNAL') || die();

class adapter_invoker {

    public function invoke_deal(int $dealid): array {
        $result = [
            'method' => 'deal_client',
            'dealid' => $dealid,
            'executed' => false,
            'adapter' => null,
            'response' => null,
            'error' => null,
        ];

        $class = '\\local_bitrixsync\\adapters\\bitrix24\\deal_client';

        if (!class_exists($class)) {
            $result['error'] = 'deal_client class not found';
            return $result;
        }

        $result['adapter'] = $class;

        try {
            $reflection = new \ReflectionClass($class);
            $result['constructor'] = $reflection->getConstructor()
                ? 'exists'
                : 'none';

            $methods = [];
            foreach ($reflection->getMethods() as $method) {
                $methods[] = $method->name;
            }

            $result['methods'] = $methods;
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }
}
