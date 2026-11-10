<?php
/** Exact fractional storage after repair, scoped to the already verified disposable fixture. */
if (!defined('CLI_SCRIPT') || !isset($marker, $root, $decimals)) { throw new RuntimeException('Fixture context required'); }
require_once($core . '/local/kbacompany/db/upgrade_schema_2026111001.php');
$before = schema_snapshot();
$results = [];
foreach ($decimals as $item) {
    $table = $item['table']; $field = $item['column'];
    $precision = (int)$item['expected']['max_length']; $scale = (int)$item['expected']['scale'];
    $max = str_repeat('9', $precision - $scale) . '.' . str_repeat('9', $scale);
    $unit = '0.' . str_repeat('0', $scale - 1) . '1';
    foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'] as $tenant) {
        $records = $DB->get_records($table, ['companyuuid' => $tenant], 'id', '*', 0, 1);
        $row = reset($records);
        foreach ([$max, '-' . $max, $unit, '-' . $unit] as $value) {
            $DB->set_field($table, $field, $value, ['id' => $row->id, 'companyuuid' => $tenant]);
            expect($DB->get_field($table, $field, ['id' => $row->id, 'companyuuid' => $tenant]) === $value,
                'fraction.exact.' . $table . '.' . $field);
        }
        if (!$item['expected']['not_null']) {
            $DB->set_field($table, $field, null, ['id' => $row->id, 'companyuuid' => $tenant]);
            expect($DB->get_field($table, $field, ['id' => $row->id, 'companyuuid' => $tenant]) === null, 'fraction.null-preserved');
        }
    }
    $results[$table . '.' . $field] = 'PASS: signed extrema and smallest fractions in both companies';
}
$data = [];
foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'] as $tenant) {
    $row = new stdClass();
    foreach ($DB->get_columns($admin, false) as $field => $column) {
        if (!$column->auto_increment && $column->not_null && $column->default_value === null) {
            $row->$field = in_array($column->meta_type, ['I', 'N', 'F', 'R', 'L'], true) ? 0 : '';
        }
    }
    $row->companyuuid = $tenant;
    $row->uuid = $tenant;
    $row->starttime = '09:15'; $row->duetime = '17:20';
    $id = $DB->insert_record($admin, $row);
    expect($DB->get_field($admin, 'starttime', ['id' => $id]) === '09:15' &&
        $DB->get_field($admin, 'duetime', ['id' => $id]) === '17:20', 'admin.nonempty-times-seeded');
}
foreach (array_unique(array_merge([$admin], array_column($decimals, 'table'))) as $table) {
    $data[$table] = $DB->get_records($table, null, 'id');
}
local_kbacompany_upgrade_schema_2026111001();
expect(schema_snapshot() === $before, 'fraction.repeat-helper-no-ddl');
foreach ($data as $table => $records) {
    expect(serialize($DB->get_records($table, null, 'id')) === serialize($records), 'fraction.repeat-helper-data-preserved');
}
json_write($evidence . '/fractions.json', $results);
