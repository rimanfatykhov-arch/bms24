<?php
/** Reconstruct observed metadata ONLY in a disposable CI database; not a backup restore. */
declare(strict_types=1);
$core = realpath($argv[1] ?? ''); $candidate = realpath($argv[2] ?? ''); $out = $argv[3] ?? '';
$root = realpath(getenv('TEST_ROOT') ?: '');
if (!$root || !$core || strpos($core, $root . '/') !== 0 || strpos($out, $root . '/') !== 0 ||
        getenv('DBHOST') !== '127.0.0.1' || !getenv('GITHUB_ACTIONS')) { throw new RuntimeException('Disposable CI only'); }
define('CLI_SCRIPT', true);
require $core . '/config.php';
require_once($core . '/lib/ddllib.php');
if ($CFG->dbhost !== '127.0.0.1' || !preg_match('/^bmsci_[a-f0-9]{10}_fromprod1878$/D', $CFG->dbname) ||
        (string)get_config('local_kbacompany', 'version') !== '2026101878' ||
        (string)get_config('local_kbacorporate', 'version') !== '2026102613') { throw new RuntimeException('Wrong baseline'); }
$fixture = json_decode(file_get_contents($candidate . '/docs/deploy/RC30_PRODUCTION_SCHEMA_DIFF.json'), true, 512, JSON_THROW_ON_ERROR);
$dbman = $DB->get_manager();
foreach ($fixture['decimal_type_differences'] as $spec) {
    if (!preg_match('/^local_kbacompany_[a-z]+$/D', $spec['table']) ||
            !preg_match('/^[a-z]+$/D', $spec['column']) ||
            !preg_match('/^decimal\((18|8),0\)$/D', $spec['actual_type'], $m)) { throw new RuntimeException('Unsafe fixture'); }
    $column = $DB->get_columns($spec['table'], false)[$spec['column']];
    $dbman->change_field_type(new xmldb_table($spec['table']), new xmldb_field($spec['column'],
        XMLDB_TYPE_NUMBER, $m[1] . ',0', null, $column->not_null, null, $column->default_value));
}
foreach (['starttime','duetime'] as $name) {
    $table = new xmldb_table('local_kbacompany_adminitem'); $field = new xmldb_field($name);
    if ($dbman->field_exists($table, $field)) { $dbman->drop_field($table, $field); }
}
// Preserve known historical Corporate defaults; never silently migrate them to0901 defaults.
$dbman->change_field_default(new xmldb_table('local_kbacorp_devplan'),
    new xmldb_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active'));
$dbman->change_field_default(new xmldb_table('local_kbacorp_member'),
    new xmldb_field('isemployee', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, 0));
$expected = json_decode(file_get_contents($candidate . '/docs/release/production1878-schema.json'), true, 512, JSON_THROW_ON_ERROR);
$differences = []; $checked = 0;
$normaltype = static function(string $type): string { return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', strtolower($type)); };
foreach ($expected['columns'] as $spec) {
    $name = substr($spec['TABLE_NAME'], 4);
    $rows = $DB->get_records_sql('SELECT COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type,
        IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra,
        CHARACTER_SET_NAME AS charset_name, COLLATION_NAME AS collation_name
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tablename AND COLUMN_NAME = :columnname',
        ['tablename'=>$CFG->prefix . $name, 'columnname'=>$spec['COLUMN_NAME']]);
    $actual = reset($rows);
    if (!$actual) { $differences[] = $name . '.' . $spec['COLUMN_NAME'] . ':missing'; continue; }
    // MariaDB reports quoted character defaults; normalize the SQL literal only.
    $default = $actual->column_default;
    $quoted = $default !== null && strlen($default) >= 2 && $default[0] === "'" && substr($default, -1) === "'";
    if ($quoted) {
        $default = str_replace("''", "'", substr($default, 1, -1));
    }
    if (!$quoted && $default === 'NULL' && $actual->is_nullable === 'YES') { $default = null; }
    if ($normaltype($actual->column_type) !== $normaltype($spec['COLUMN_TYPE']) ||
            $actual->is_nullable !== $spec['IS_NULLABLE'] ||
            ($default === null ? null : hash('sha256', (string)$default)) !== $spec['default_sha256'] ||
            $actual->extra !== $spec['EXTRA'] || $actual->charset_name !== $spec['CHARACTER_SET_NAME'] ||
            $actual->collation_name !== $spec['COLLATION_NAME']) { $differences[] = $name . '.' . $spec['COLUMN_NAME']; }
    $checked++;
}
$report = ['scope'=>'Observed production metadata reconstruction with synthetic data; NOT backup restore',
    'bms'=>2026101878,'corporate'=>2026102613,'checked_columns'=>$checked,'differences'=>$differences,
    'status'=>$differences ? 'FAIL' : 'PASS'];
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report) . "\n";
exit($differences ? 1 : 0);
