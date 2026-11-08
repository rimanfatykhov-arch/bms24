<?php
/** Isolated real Moodle DML/XMLDB test; invoke only through the companion runner.
 * Fixtures are minimal target tables, not a full Moodle installation. Only MUC
 * and localization are replaced; SQL, metadata, XMLDB and repair are real code.
 */
if (PHP_SAPI !== 'cli' || !in_array('--allow-disposable-issue44', $argv, true)) {
    exit(2);
}
$database = getenv('BMS44_DATABASE');
if (!preg_match('/^issue44_[a-f0-9]{32}$/D', $database ?: '')) {
    throw new RuntimeException('Missing disposable database identity');
}
$port = (int)getenv('BMS44_PORT');
$password = getenv('BMS44_PASSWORD');
$native = new mysqli('127.0.0.1', 'root', $password, '', $port);
$actualdir = $native->query('SELECT @@datadir')->fetch_row()[0];
$normalise = static fn($path) => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
if ($normalise($actualdir) !== $normalise(getenv('BMS44_DATADIR'))) {
    throw new RuntimeException('Refusing database outside owned scratch datadir');
}
$native->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
define('MOODLE_INTERNAL', true);
define('CLI_SCRIPT', true);
define('PHPUNIT_TEST', false);
$CFG = (object)['dirroot' => getenv('BMS44_MOODLE'), 'wwwroot' => 'http://invalid.local',
    'admin' => 'admin', 'debug' => 0, 'debugdeveloper' => false];
$CFG->libdir = $CFG->dirroot . '/lib';
// Ancillary process-local cache: never use the shared Moodle MUC configuration.
class cache {
    private $data = [];
    public static function make(...$args) { return new self(); }
    public function get($key) { return $this->data[$key] ?? false; }
    public function set($key, $value) { $this->data[$key] = $value; return true; }
    public function delete($key) { unset($this->data[$key]); return true; }
    public function purge() { $this->data = []; return true; }
}
function get_string_manager() { return new class { public function string_exists(...$args) { return false; } }; }
function get_string($key, ...$args) { return $key; }
function debugging($message = '', ...$args) { return false; }
// Exact standalone integer predicate from Moodle 4.1 moodlelib.php.
function is_number($value) { return is_int($value) || (is_string($value) && ((string)(int)$value) === $value); }
require_once($CFG->libdir . '/setuplib.php');
require_once($CFG->libdir . '/dmllib.php');
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->libdir . '/dml/mariadb_native_moodle_database.php');
$DB = new mariadb_native_moodle_database(true);
$DB->connect('127.0.0.1', 'root', $password, $database, 'qa44_',
    ['dbport' => $port, 'dbcollation' => 'utf8mb4_unicode_ci', 'dbengine' => 'InnoDB', 'dbpersist' => false]);
$manager = $DB->get_manager();
require_once(__DIR__ . '/../../local/kbacompany/db/upgradelib.php');
$tables = ['local_kbacompany_task', 'local_kbacompany_deviation'];
$specs = [[$tables[0], 'starttime', '5', ''], [$tables[0], 'duetime', '5', ''],
    [$tables[1], 'executionstatus', '16', 'pending']];
$checks = 0;
function verify($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function fixtures($mask, $wrong = null) {
    global $DB, $manager, $tables, $specs;
    foreach ($tables as $name) {
        $table = new xmldb_table($name);
        if ($manager->table_exists($table)) { $manager->drop_table($table); }
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('companyuuid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, '');
        $table->add_field('title', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $table->add_field('startdate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('duedate', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        foreach ($specs as $i => [$target, $field, $length, $default]) {
            if ($target !== $name || !($mask & (1 << $i))) { continue; }
            $type = XMLDB_TYPE_CHAR;
            $notnull = XMLDB_NOTNULL;
            if ($i === 0 && $wrong) {
                if ($wrong === 'length') { $length = '8'; }
                if ($wrong === 'nullable') { $notnull = null; }
                if ($wrong === 'default') { $default = 'oops'; }
                if ($wrong === 'null-default') { $default = null; }
                if ($wrong === 'type') { $type = XMLDB_TYPE_INTEGER; $length = '5'; $default = '0'; }
            }
            $table->add_field($field, $type, $length, null, $notnull, null, $default);
        }
        $manager->create_table($table);
        foreach (['tenant-a', 'tenant-b'] as $tenant) {
            $row = (object)['companyuuid' => $tenant, 'title' => 'Keep ' . $tenant,
                'startdate' => 1234, 'duedate' => 5678];
            foreach ($specs as $i => [$target, $field]) {
                if ($target === $name && ($mask & (1 << $i))) {
                    $row->$field = $i === 2 ? 'done' : ($wrong === 'type' ? 42 : '09:15');
                }
            }
            $DB->insert_record($name, $row);
        }
    }
}
function snapshot() {
    global $DB, $tables;
    $result = [];
    foreach ($tables as $name) {
        $result[$name] = ['columns' => $DB->get_columns($name, false),
            'rows' => $DB->get_records($name, null, 'id')];
    }
    return $result;
}
try {
    echo 'Server: ' . $native->server_info . '; PHP: ' . PHP_VERSION . "\n";
    for ($mask = 0; $mask < 8; $mask++) {
        fixtures($mask);
        $before = snapshot();
        local_kbacompany_upgrade_schema_parity_2026110890();
        $after = snapshot();
        foreach ($specs as $i => [$table, $field, $length, $default]) {
            $column = $after[$table]['columns'][$field];
            verify($column->meta_type === 'C' && (int)$column->max_length === (int)$length &&
                $column->not_null && $column->default_value === $default, "mask $mask metadata $field");
            foreach ($after[$table]['rows'] as $id => $row) {
                verify($row->$field === (($mask & (1 << $i)) ? $before[$table]['rows'][$id]->$field : $default),
                    "mask $mask preserved/default $field");
            }
        }
        foreach ($tables as $table) {
            foreach ($before[$table]['rows'] as $id => $row) {
                foreach ((array)$row as $field => $value) {
                    verify($after[$table]['rows'][$id]->$field === $value, "mask $mask data $field");
                }
            }
            verify(count($after[$table]['rows']) === 2, 'row count');
            $id = $DB->insert_record($table, (object)['companyuuid' => 'tenant-new', 'title' => 'New']);
            $inserted = $DB->get_record($table, ['id' => $id], '*', MUST_EXIST);
            foreach ($specs as [$target, $field, $length, $default]) {
                if ($table === $target) { verify($inserted->$field === $default, 'future insert default'); }
            }
        }
        $once = snapshot();
        local_kbacompany_upgrade_schema_parity_2026110890();
        verify($once == snapshot(), "mask $mask repeat unchanged");
        echo "PASS presence mask $mask\n";
    }
    foreach (['type', 'length', 'nullable', 'default', 'null-default'] as $wrong) {
        fixtures(1, $wrong);
        if ($wrong === 'null-default') {
            // XMLDB supplies an empty default for NOT NULL CHAR; simulate external schema drift.
            $DB->execute('ALTER TABLE {local_kbacompany_task} ALTER COLUMN starttime DROP DEFAULT');
        }
        $before = snapshot();
        $rejected = false;
        try { local_kbacompany_upgrade_schema_parity_2026110890(); } catch (upgrade_exception $e) { $rejected = true; }
        verify($rejected, "wrong $wrong rejected");
        verify($before == snapshot(), "wrong $wrong no partial DDL/data changes");
        echo "PASS incompatible $wrong\n";
    }
    foreach ($tables as $missing) {
        fixtures(0);
        $manager->drop_table(new xmldb_table($missing));
        $peer = $tables[0] === $missing ? $tables[1] : $tables[0];
        $before = $DB->get_columns($peer, false);
        $rowsbefore = $DB->get_records($peer, null, 'id');
        $rejected = false;
        try { local_kbacompany_upgrade_schema_parity_2026110890(); } catch (ddl_table_missing_exception $e) { $rejected = true; }
        verify($rejected, "missing $missing rejected");
        verify($before == $DB->get_columns($peer, false), 'missing table no peer DDL');
        verify($rowsbefore == $DB->get_records($peer, null, 'id'), 'missing table no peer DML');
        echo "PASS missing $missing\n";
    }
    echo "PASS $checks assertions; real XMLDB helper only; full Moodle upgrade NOT RUN\n";
} finally {
    $DB->dispose();
    $native->close();
}
