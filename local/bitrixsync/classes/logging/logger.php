<?php
namespace local_bitrixsync\logging;

defined('MOODLE_INTERNAL') || die();

/** Lightweight diagnostic logger. Secrets are never persisted. */
class logger {
    public static function debug(int $dealid, string $event, array $payload = []): void {
        $level = (string)(get_config('local_bitrixsync', 'logging_level') ?: 'normal');
        if ($level !== 'debug') {
            return;
        }
        $scope = (int)get_config('local_bitrixsync', 'debug_deal_id');
        if ($scope > 0 && $scope !== $dealid) {
            return;
        }
        self::write('[KBA Connect DEBUG] Deal ' . $dealid . ' ' . $event, $payload);
    }

    public static function corporate_schema(string $event, array $payload = []): void {
        self::write('[CORPORATE_SCHEMA] ' . $event, $payload);
    }

    public static function corporate_pairing(string $event, array $payload = []): void {
        self::write('[CORPORATE_PAIRING] ' . $event, $payload);
    }

    public static function corporate_role_sync(string $event, array $payload = []): void {
        self::write('[CORPORATE_ROLE_SYNC] ' . $event, $payload);
    }

    private static function write(string $prefix, array $payload = []): void {
        $line = $prefix;
        if ($payload) {
            $safe = self::sanitize($payload);
            $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false) {
                $line .= ' ' . $json;
            }
        }
        error_log($line);
    }

    private static function sanitize(array $payload): array {
        $safe = [];
        foreach ($payload as $key => $value) {
            $lower = strtolower((string)$key);
            if (strpos($lower, 'token') !== false || strpos($lower, 'secret') !== false ||
                    strpos($lower, 'authorization') !== false || strpos($lower, 'webhook') !== false ||
                    strpos($lower, 'pairing_code') !== false || strpos($lower, 'password') !== false) {
                continue;
            }
            $safe[$key] = is_array($value) ? self::sanitize($value) : $value;
        }
        return $safe;
    }
}
