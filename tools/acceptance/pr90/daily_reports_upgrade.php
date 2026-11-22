<?php
// Read-only schema/data evidence. Never contains row values, credentials or user content.
define('CLI_SCRIPT', true);
if (getenv('BMS_R1R2_ACCEPTANCE') !== '1') { fwrite(STDERR, "Acceptance flag required\n"); exit(2); }
require($argv[1] . '/config.php');
if (empty($CFG->noemailever) || !in_array(parse_url($CFG->wwwroot, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Isolated loopback Moodle required\n"); exit(2);
}
$new = ['local_kbacompany_dreport', 'local_kbacompany_dreportver', 'local_kbacompany_dreportlink',
    'local_kbacompany_dreportrev', 'local_kbacompany_dreportlog'];
$out = ['version' => (int)get_config('local_kbacompany', 'version'), 'tables' => [], 'schemas' => [], 'rows' => [],
    'new_tables' => $new, 'enabled' => (bool)get_config('local_kbacompany', 'dailyreports_enabled')];
$tables = array_filter($DB->get_tables(), static function($name) {
    return strpos($name, 'local_kbacompany_') === 0 || strpos($name, 'local_kbacorp') === 0 || $name === 'files';
});
sort($tables);
foreach ($tables as $table) {
    $out['tables'][] = $table;
    $columns = [];
    foreach ($DB->get_columns($table, false) as $name => $field) {
        $columns[$name] = [];
        foreach (['type', 'meta_type', 'max_length', 'scale', 'not_null', 'has_default', 'default_value', 'auto_increment'] as $key) {
            $columns[$name][$key] = $field->$key ?? null;
        }
    }
    $indexes = $DB->get_indexes($table); ksort($indexes);
    $out['schemas'][$table] = ['columns' => $columns, 'indexes' => $indexes];
    $hash = hash_init('sha256'); $count = 0;
    $records = $DB->get_recordset($table, [], 'id ASC');
    foreach ($records as $record) { hash_update($hash, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"); $count++; }
    $records->close();
    $out['rows'][$table] = ['count' => $count, 'sha256' => hash_final($hash)];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
