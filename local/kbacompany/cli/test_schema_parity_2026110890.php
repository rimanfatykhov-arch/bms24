<?php
/**
 * Execute the real upgrade entrypoint with metadata/DDL doubles (no Moodle boot).
 * Non-target historical tables/fields/indexes are modelled as already present.
 * This tests repair reachability, ordering and retry safety, not SQL compatibility
 * or the correctness of every historical migration between 1645 and 1879.
 */
define('MOODLE_INTERNAL', true);
define('XMLDB_TYPE_CHAR', 'char');
define('XMLDB_TYPE_TEXT', 'text');
define('XMLDB_NOTNULL', true);
define('XMLDB_INDEX_NOTUNIQUE', false);

class upgrade_exception extends RuntimeException {
    public function __construct($plugin, $version, $message) { parent::__construct($message); }
}
class ddl_table_missing_exception extends RuntimeException {}
class ddl_field_missing_exception extends RuntimeException {
    public function __construct($field, $table) { parent::__construct($table . '.' . $field); }
}
class xmldb_table {
    public $name;
    public function __construct($name) { $this->name = $name; }
}
class xmldb_field {
    public $name;
    public $metadata;
    public function __construct($name, $type, $length, $unsigned, $notnull, $sequence, $default, $previous = null) {
        $this->name = $name;
        $this->metadata = (object)['meta_type' => $type === 'char' ? 'C' : $type,
            'max_length' => (int)$length, 'not_null' => (bool)$notnull,
            // MySQL/MariaDB XMLDB generator supplies '' for NOT NULL CHAR with no explicit default.
            'default_value' => $type === 'char' && $notnull && $default === null ? '' : $default];
    }
}
class xmldb_index {
    public function __construct($name, $unique, $fields) {}
}

class SchemaParityManager {
    public $db;
    public $adds = [];
    public $failon = null;
    public $silenton = null;
    public function __construct($db) { $this->db = $db; }
    public function table_exists($table) { return $table->name !== $this->db->missingtable; }
    public function field_exists($table, $field) {
        $key = $table->name . '.' . $field->name;
        return !isset($this->db->targets[$key]) || isset($this->db->columns[$table->name][$field->name]);
    }
    public function index_exists($table, $index) { return true; }
    public function add_field($table, $field) {
        $key = $table->name . '.' . $field->name;
        if ($key === $this->failon) { throw new RuntimeException('simulated DDL failure'); }
        $this->adds[] = $key;
        if ($key !== $this->silenton) {
            $this->db->columns[$table->name][$field->name] = clone $field->metadata;
        }
    }
}
class SchemaParityDatabase {
    public $columns = [];
    public $targets;
    public $manager;
    public $missingtable = null;
    // Sentinel existing values in two companies; any repair DML is unsupported.
    public $rows = ['A' => ['starttime' => '09:15', 'duetime' => '17:20', 'executionstatus' => 'done'],
        'B' => ['starttime' => '10:00', 'duetime' => '18:00', 'executionstatus' => 'inprogress']];
    public function __construct($targets, $presentmask) {
        $this->targets = $targets;
        $this->manager = new SchemaParityManager($this);
        $bit = 1;
        foreach ($targets as $key => $metadata) {
            [$table, $field] = explode('.', $key);
            $this->columns[$table] = $this->columns[$table] ?? [];
            if ($presentmask & $bit) { $this->columns[$table][$field] = clone $metadata; }
            $bit *= 2;
        }
    }
    public function get_manager() { return $this->manager; }
    public function get_columns($table, $cache = true) {
        $columns = $this->columns[$table] ?? [];
        if ($table === 'local_kbacompany_task') {
            foreach (['companyuuid', 'status', 'duedate'] as $name) {
                $columns[$name] = (object)['meta_type' => 'C'];
            }
        }
        return $columns;
    }
    public function get_prefix() { return 'qa_'; }
    // Ordered-index semantics are covered by the real SQL test. This field-focused
    // double models the unrelated ordered index as present, like historical tables.
    public function get_indexes($table, $cache = true) {
        return ['existing_exact' => ['unique' => false, 'columns' => ['companyuuid', 'status', 'duedate']]];
    }
}
$savepoints = [];
function purge_all_caches() {}
function set_config($name, $value, $plugin = null) { return true; }
function upgrade_plugin_savepoint($success, $version, $type, $plugin) {
    global $savepoints;
    if (!$success || $type !== 'local' || $plugin !== 'kbacompany') {
        throw new RuntimeException('unexpected savepoint');
    }
    $savepoints[] = $version;
}
$assertions = 0;
function parity_check($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
}

// Read expected definitions from fresh-install XML, independently of the helper.
$xml = file_get_contents(__DIR__ . '/../db/install.xml');
$targets = [];
foreach (['local_kbacompany_task' => ['starttime', 'duetime'],
        'local_kbacompany_deviation' => ['executionstatus']] as $table => $fields) {
    if (!preg_match('/<TABLE NAME="' . preg_quote($table, '/') . '"[^>]*>(.*?)<\/TABLE>/s', $xml, $match)) {
        throw new RuntimeException('missing fixture table');
    }
    foreach ($fields as $name) {
        if (!preg_match('/<FIELD NAME="' . $name . '"([^>]*)\/>/', $match[1], $fieldmatch)) {
            throw new RuntimeException('missing fixture field');
        }
        preg_match_all('/([A-Z]+)="([^"]*)"/', $fieldmatch[1], $attributes, PREG_SET_ORDER);
        $attrs = [];
        foreach ($attributes as $attr) { $attrs[$attr[1]] = $attr[2]; }
        $targets[$table . '.' . $name] = (object)['meta_type' => $attrs['TYPE'] === 'char' ? 'C' : $attrs['TYPE'],
            'max_length' => (int)$attrs['LENGTH'], 'not_null' => $attrs['NOTNULL'] === 'true',
            'default_value' => $attrs['DEFAULT']];
    }
}
require_once(__DIR__ . '/../db/upgrade.php');

foreach ([2026101645, 2026101879, 2026110802, 2026110890] as $oldversion) {
    foreach (range(0, 7) as $presentmask) {
        $DB = new SchemaParityDatabase($targets, $presentmask);
        $before = $DB->rows;
        $savepoints = [];
        parity_check(xmldb_local_kbacompany_upgrade($oldversion), 'actual upgrade returns success');
        parity_check(end($savepoints) === 2026110901, 'repair checkpoint reached from ' . $oldversion);
        foreach ($targets as $key => $metadata) {
            [$table, $field] = explode('.', $key);
            parity_check($DB->columns[$table][$field] == $metadata, 'matches fresh XMLDB: ' . $key);
        }
        parity_check($DB->rows === $before, 'existing two-company values unchanged');
        $adds = $DB->manager->adds;
        $expectedadds = [];
        $bit = 1;
        foreach (array_keys($targets) as $key) {
            if (!($presentmask & $bit)) { $expectedadds[] = $key; }
            $bit *= 2;
        }
        parity_check($adds === $expectedadds, 'only missing target fields are added');
        xmldb_local_kbacompany_upgrade($oldversion);
        parity_check($DB->manager->adds === $adds, 'retry adds no duplicate fields');
        $savepoints = [];
        xmldb_local_kbacompany_upgrade(2026110901);
        parity_check(!$savepoints && $DB->manager->adds === $adds, 'installed target is no-op');
    }
}

foreach (['meta_type' => 'I', 'max_length' => 40, 'not_null' => false,
        'default_value' => 'unexpected'] as $property => $wrongvalue) {
    // A bad later field must stop the repair before it adds either missing task field.
    $DB = new SchemaParityDatabase($targets, 4);
    $DB->columns['local_kbacompany_deviation']['executionstatus']->$property = $wrongvalue;
    $savepoints = [];
    try {
        xmldb_local_kbacompany_upgrade(2026101879);
        throw new RuntimeException('incompatible existing column accepted');
    } catch (upgrade_exception $e) {
        parity_check(!$DB->manager->adds && !$savepoints, 'incompatibility stopped before DDL/savepoint');
    }
}

foreach (['failon', 'silenton'] as $failuremode) {
    $DB = new SchemaParityDatabase($targets, 0);
    $DB->manager->$failuremode = 'local_kbacompany_task.duetime';
    $savepoints = [];
    $failed = false;
    try { xmldb_local_kbacompany_upgrade(2026101879); } catch (RuntimeException $e) { $failed = true; }
    parity_check($failed && !$savepoints, 'failed/incomplete DDL cannot advance savepoint');
    parity_check(isset($DB->columns['local_kbacompany_task']['starttime']), 'partial DDL model exercised');
    $DB->manager->$failuremode = null;
    xmldb_local_kbacompany_upgrade(2026101879);
    parity_check(end($savepoints) === 2026110901, 'partial DDL retry reaches checkpoint');
}
$DB = new SchemaParityDatabase($targets, 0);
$DB->missingtable = 'local_kbacompany_deviation';
$savepoints = [];
try {
    xmldb_local_kbacompany_upgrade(2026101879);
    throw new RuntimeException('missing required table accepted');
} catch (ddl_table_missing_exception $e) {
    parity_check(!$savepoints && !$DB->manager->adds, 'missing table stops before DDL/savepoint');
}
echo "PASS: schema parity upgrade entrypoint, 32 version/partial-state cases and failures; $assertions assertions\n";
echo "NOT RUN: actual Moodle SQL drivers, fresh install and complete historical migration semantics\n";
