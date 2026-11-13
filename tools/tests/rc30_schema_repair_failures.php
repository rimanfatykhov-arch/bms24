<?php
/** Included only after disposable DB provenance checks in rc30_schema_repair.php. */
if (!defined('CLI_SCRIPT') || !isset($marker, $root, $decimals, $rows)) { throw new RuntimeException('Fixture context required'); }
require_once($core . '/lib/upgradelib.php');
require_once($core . '/local/kbacompany/db/upgrade.php');
set_config('version', '2026110906', 'local_kbacompany');
$negative = [];
// Load the scratch copy once, as Moodle upgrade.php will do, before the reader
// reuses the same frozen definitions. Candidate/core are separate filesystem paths.
require_once $core . '/local/kbacompany/db/upgrade_schema_2026111001.php';
require_once $candidate . '/tools/rc30_preupgrade_preflight.php';
$readonlypreflight = static function() use ($DB, $CFG): array {
    return rc30_preupgrade_preflight(static function(string $sql, array $params) use ($DB): array {
        return array_map(static function($row): array { return (array)$row; }, array_values($DB->get_records_sql($sql, $params)));
    }, $CFG->prefix);
};
expect($readonlypreflight()['status'] === 'PASS', 'readonly-preflight.safe-baseline');
$allrows = static function() use ($DB, $decimals): array {
    $result = [];
    foreach (array_unique(array_column($decimals, 'table')) as $table) {
        $result[$table] = $DB->get_records($table, null, 'id');
    }
    return $result;
};
$reject = static function(string $id, string $reason) use (&$negative, $evidence, $allrows, $readonlypreflight): void {
    $before = schema_snapshot();
    $data = $allrows();
    $readcaught = false;
    try { $readonlypreflight(); }
    catch (RuntimeException $error) {
        expect(strpos($error->getMessage(), $reason) !== false, $id . '.readonly-specific-rejection');
        $readcaught = true;
    }
    expect($readcaught, $id . '.readonly-rejected');
    expect(schema_snapshot() === $before, $id . '.readonly-no-ddl');
    expect(serialize($allrows()) === serialize($data), $id . '.readonly-data-unchanged');
    $caught = false;
    try { xmldb_local_kbacompany_upgrade(2026110906); }
    catch (upgrade_exception $error) {
        expect(strpos($error->debuginfo, $reason) !== false, $id . '.specific-rejection');
        $caught = true;
    }
    expect($caught, $id . '.rejected');
    expect(schema_snapshot() === $before, $id . '.no-ddl');
    expect(serialize($allrows()) === serialize($data), $id . '.all-data-unchanged');
    expect((string)get_config('local_kbacompany', 'version') === '2026110906', $id . '.no-savepoint');
    $negative[$id] = 'PASS';
    json_write($evidence . '/rejections.json', $negative);
};
foreach ($decimals as $item) {
    $table = $item['table']; $field = $item['column'];
    $row = current(array_filter($rows, static function($r) use ($table) { return $r['table'] === $table; }));
    $original = $DB->get_field($table, $field, ['id' => $row['id']], MUST_EXIST);
    $outside = '1' . str_repeat('0', (int)$item['expected']['max_length'] - (int)$item['expected']['scale']);
    foreach ([$outside, '-' . $outside] as $index => $value) {
        $DB->set_field($table, $field, $value, ['id' => $row['id']]);
        $reject('range.' . $table . '.' . $field . '.' . ($index ? 'negative' : 'positive'), 'out-of-range values in ' . $table . '.' . $field);
    }
    $DB->set_field($table, $field, $original, ['id' => $row['id']]);
}
// A defect in the last target must stop additions/conversions in every earlier target.
$table = 'local_kbacompany_statpolicy'; $field = 'powerlevel';
$ddl = static function(string $definition) use ($DB, $CFG, $table, $field): void {
    $DB->change_database_structure(['ALTER TABLE ' . identifier($CFG->prefix . $table) .
        ' MODIFY COLUMN ' . identifier($field) . ' ' . $definition], [$table]);
};
foreach (['wrong-precision' => 'DECIMAL(9,0) NULL DEFAULT NULL',
        'unexpected-scale' => 'DECIMAL(8,1) NULL DEFAULT NULL',
        'wrong-default' => 'DECIMAL(8,0) NULL DEFAULT 0'] as $case => $definition) {
    $ddl($definition);
    $reject('definition.' . $case, 'incompatible definition ' . $table . '.' . $field);
    $ddl('DECIMAL(8,0) NULL DEFAULT NULL');
}
// Test nullability/signedness on an empty target to avoid altering test values or NULLs.
$saved = $DB->get_records($table, null, 'id');
$DB->delete_records($table);
foreach (['wrong-nullability' => 'DECIMAL(8,0) NOT NULL DEFAULT 0',
        'unsigned' => 'DECIMAL(8,0) UNSIGNED NULL DEFAULT NULL',
        'wrong-type' => 'VARCHAR(20) NULL DEFAULT NULL'] as $case => $definition) {
    $ddl($definition);
    $reject('definition.' . $case, 'incompatible definition ' . $table . '.' . $field);
    $ddl('DECIMAL(8,0) NULL DEFAULT NULL');
}
foreach ($saved as $record) { $DB->import_record($table, $record); }
// Existing time values must not be silently coerced into the new CHAR contract.
$DB->change_database_structure(['ALTER TABLE ' . identifier($CFG->prefix . $admin) .
    " ADD COLUMN `duetime` VARCHAR(6) NOT NULL DEFAULT ''"], [$admin]);
$reject('definition.adminitem-duetime', 'incompatible definition ' . $admin . '.duetime');
$dbman->drop_field(new xmldb_table($admin), new xmldb_field('duetime'));
$oldmode = $DB->get_field_sql('SELECT @@SESSION.sql_mode');
$DB->execute("SET SESSION sql_mode = ''");
try { $reject('connection.non-strict', 'strict SQL mode required'); }
finally { $DB->execute('SET SESSION sql_mode = ?', [$oldmode]); }
// Fixture mutations above were reversible; identities/values must match the original safe seed.
foreach ($rows as $row) {
    expect((array)$DB->get_record($row['table'], ['id' => $row['id']], '*', MUST_EXIST) === $row['row'], 'negative-fixture.restored');
}
expect(count($negative) === 30, 'negative.thirty-independent-cases');
