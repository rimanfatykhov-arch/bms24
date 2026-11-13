<?php
/** Bootstrap-free ADR-0004 preflight. Only SELECT; never invokes the migration. */
declare(strict_types=1);
if (!defined('MOODLE_INTERNAL')) { define('MOODLE_INTERNAL', true); }
if (!function_exists('local_kbacompany_decimal_definitions_2026111001')) {
    require_once __DIR__ . '/../local/kbacompany/db/upgrade_schema_2026111001.php';
}

/**
 * $select returns a list of associative rows for SQL with named parameters.
 * Use a read-only DB account and transaction in the caller. Quiesce writers
 * before the final run; this result alone neither locks writers nor permits GO.
 * Exceptions disclose schema identifiers only, never business values.
 */
function rc30_preupgrade_preflight(callable $select, string $prefix): array {
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $prefix)) { throw new RuntimeException('Invalid table prefix'); }
    $query = static function(string $sql, array $params = []) use ($select): array {
        if (strpos($sql, 'SELECT ') !== 0 || strpos($sql, ';') !== false) {
            throw new RuntimeException('SELECT only');
        }
        return $select($sql, $params);
    };
    $first = static function(string $sql, array $params = []) use ($query): array {
        $rows = $query($sql, $params);
        return $rows ? (array)reset($rows) : [];
    };
    $modes = explode(',', strtoupper((string)($first('SELECT @@SESSION.sql_mode AS modes')['modes'] ?? '')));
    if (!array_intersect(['STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES'], $modes)) {
        throw new RuntimeException('strict SQL mode required');
    }
    $definitions = local_kbacompany_decimal_definitions_2026111001();
    $admin = 'local_kbacompany_adminitem';
    foreach (array_unique(array_merge([$admin], array_column($definitions, 0))) as $table) {
        $row = $first('SELECT ENGINE AS engine FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table', ['table' => $prefix . $table]);
        if (strcasecmp($row['engine'] ?? '', 'InnoDB') !== 0) {
            throw new RuntimeException('InnoDB required: ' . $table);
        }
    }
    $column = static function(string $table, string $field) use ($first, $prefix): array {
        return $first('SELECT COLUMN_TYPE AS coltype, EXTRA AS extra, IS_NULLABLE AS nullable,
            COLUMN_DEFAULT AS defaultvalue FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :field',
            ['table' => $prefix . $table, 'field' => $field]);
    };
    $normaldefault = static function($value) {
        if ($value === null) { return null; }
        if (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        return $value === 'NULL' ? null : $value;
    };
    $checks = [];
    foreach (['starttime', 'duetime'] as $field) {
        $actual = $column($admin, $field);
        if ($actual && (strtolower($actual['coltype']) !== 'varchar(5)' || $actual['extra'] !== '' ||
                $actual['nullable'] !== 'NO' || $normaldefault($actual['defaultvalue']) !== '')) {
            throw new RuntimeException('incompatible definition ' . $admin . '.' . $field);
        }
        $checks[$admin . '.' . $field] = $actual ? 'PASS' : 'PASS: add permitted';
    }
    foreach ($definitions as [$table, $field, $precision, $scale, $notnull, $default]) {
        $actual = $column($table, $field);
        $actualdefault = $actual ? $normaldefault($actual['defaultvalue']) : null;
        $defaultok = $default === null ? $actualdefault === null :
            preg_match('/\A' . $default . '(?:\.0+)?\z/D', (string)$actualdefault) === 1;
        if (!$actual || !in_array(strtolower($actual['coltype']),
                ['decimal(' . $precision . ',0)', 'decimal(' . $precision . ',' . $scale . ')'], true) ||
                $actual['extra'] !== '' || ($actual['nullable'] === 'NO') !== $notnull || !$defaultok) {
            throw new RuntimeException('incompatible definition ' . $table . '.' . $field);
        }
        $maximum = str_repeat('9', $precision - $scale) . '.' . str_repeat('9', $scale);
        $outside = $first('SELECT 1 AS incompatible FROM `' . $prefix . $table . '` WHERE `' . $field . '` IS NOT NULL AND
            (`' . $field . '` > CAST(:maximum AS DECIMAL(' . $precision . ',' . $scale . ')) OR
             `' . $field . '` < CAST(:minimum AS DECIMAL(' . $precision . ',' . $scale . '))) LIMIT 1',
            ['maximum' => $maximum, 'minimum' => '-' . $maximum]);
        if ($outside) { throw new RuntimeException('out-of-range values in ' . $table . '.' . $field); }
        $checks[$table . '.' . $field] = 'PASS';
    }
    return ['status' => 'PASS', 'scope' => '13 definitions and 11 DECIMAL ranges; not release approval',
        'checks' => $checks, 'writers_quiesced' => 'NOT VERIFIED', 'backup_restore' => 'NOT RUN'];
}
