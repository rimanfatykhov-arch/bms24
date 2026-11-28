<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Strict JSON object decoding for the RC33 inbound DTO (contract §3, N02/N25).
 *
 * PHP's json_decode silently keeps the last duplicate key and turns large or fractional numbers into floats.
 * Before decoding, this scanner rejects: more than MAX_BYTES, invalid UTF-8, nesting deeper than MAX_DEPTH,
 * duplicate object keys, and any number that is fractional, exponential, negative zero, has a leading zero or
 * more than 15 digits. Identifiers travel as strings; only small integers are numbers.
 */
final class inbound_json {
    public const MAX_BYTES = 262144;
    public const MAX_DEPTH = 16;

    /** @return array the decoded top-level JSON object */
    public static function decode(string $json): array {
        if (strlen($json) > self::MAX_BYTES) {
            throw new inbound_exception('payload_invalid', 'size');
        }
        if (preg_match('//u', $json) !== 1) {
            throw new inbound_exception('payload_invalid', 'utf8');
        }
        $trimmed = ltrim($json, " \t\r\n");
        if ($trimmed === '' || $trimmed[0] !== '{') {
            throw new inbound_exception('payload_invalid', 'object');
        }
        self::scan($json);
        $value = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($value)) {
            throw new inbound_exception('payload_invalid', 'syntax');
        }
        return $value;
    }

    private static function scan(string $json): void {
        $stack = [];
        $length = strlen($json);
        $i = 0;
        while ($i < $length) {
            $char = $json[$i];
            if ($char === '"') {
                $end = self::string_end($json, $i);
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['object'] && $stack[$top]['expectkey']) {
                    $key = json_decode(substr($json, $i, $end - $i + 1));
                    if (!is_string($key)) {
                        throw new inbound_exception('payload_invalid', 'key');
                    }
                    if (isset($stack[$top]['keys'][$key])) {
                        throw new inbound_exception('payload_invalid', 'duplicate key');
                    }
                    $stack[$top]['keys'][$key] = true;
                    $stack[$top]['expectkey'] = false;
                }
                $i = $end + 1;
                continue;
            }
            if ($char === '{' || $char === '[') {
                $stack[] = ['object' => $char === '{', 'expectkey' => $char === '{', 'keys' => []];
                if (count($stack) > self::MAX_DEPTH) {
                    throw new inbound_exception('payload_invalid', 'depth');
                }
            } else if ($char === '}' || $char === ']') {
                array_pop($stack);
            } else if ($char === ',') {
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['object']) {
                    $stack[$top]['expectkey'] = true;
                }
            } else if ($char === '-' || ($char >= '0' && $char <= '9')) {
                if (!preg_match('/\G-?[0-9]+(?:\.[0-9]*)?(?:[eE][+-]?[0-9]*)?/', $json, $match, 0, $i)) {
                    throw new inbound_exception('payload_invalid', 'number');
                }
                $number = $match[0];
                if (!preg_match('/^-?(?:0|[1-9][0-9]{0,14})$/D', $number) || $number === '-0') {
                    throw new inbound_exception('payload_invalid', 'number');
                }
                $i += strlen($number);
                continue;
            }
            $i++;
        }
    }

    private static function string_end(string $json, int $start): int {
        $length = strlen($json);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($json[$i] === '\\') {
                $i++;
                continue;
            }
            if ($json[$i] === '"') {
                return $i;
            }
        }
        throw new inbound_exception('payload_invalid', 'string');
    }
}
