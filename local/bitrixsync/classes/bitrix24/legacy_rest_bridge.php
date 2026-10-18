<?php
namespace local_bitrixsync\bitrix24;

defined('MOODLE_INTERNAL') || die();

/**
 * Compatibility connector for the legacy REST layer.
 *
 * Alpha.5.1 keeps the v5 side read-only.
 * The adapter tries to discover an existing legacy service and otherwise
 * returns a diagnostic response instead of writing anything.
 */
class legacy_rest_bridge {
    public function call(string $method, array $params = []): array {
        $result = [
            'method' => $method,
            'params' => $params,
            'mode' => 'legacy_adapter_shadow',
            'read_only' => true,
            'connected' => false,
            'source' => 'none',
        ];

        // Existing implementations can be connected here without changing
        // v5 workflow classes.
        if (class_exists('\\local_bitrixsync\\bitrix\\rest_client')) {
            $result['source'] = 'legacy_rest_client_detected';
        }

        return $result;
    }
}
