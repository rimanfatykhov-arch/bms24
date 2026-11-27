<?php
/**
 * Execute the real upgrade entrypoint with metadata/DDL doubles (no Moodle boot).
 * Non-target historical tables/fields/indexes are modelled as already present.
 * This tests repair reachability, ordering and retry safety, not SQL compatibility
 * or the correctness of every historical migration between 1645 and 1879.
 */
define('MOODLE_INTERNAL', true);
define('XMLDB_TYPE_INTEGER', 'int');
define('XMLDB_SEQUENCE', true);
define('XMLDB_KEY_PRIMARY', 'primary');
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
    // Non-target table definitions are constructed even when already installed.
    public function add_field(...$args) {}
    public function add_key(...$args) {}
    public function add_index(...$args) {}
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
    public $installs = [];
    public $failon = null;
    public $silenton = null;
    public $failinstall = null;
    public $incompleteinstall = null;
    public $xmloverride = null;
    public function __construct($db) { $this->db = $db; }
    // Moodle's database_manager::table_exists() accepts an xmldb_table or a table name string.
    public function table_exists($table) {
        if (!is_string($table) && !($table instanceof xmldb_table)) {
            throw new InvalidArgumentException('table_exists() needs xmldb_table or string');
        }
        $name = is_string($table) ? $table : $table->name;
        return $name !== $this->db->missingtable && !isset($this->db->absenttables[$name]);
    }
    // Models database_manager::install_one_table_from_xmldb_file(): the table must be defined in the
    // XMLDB file; its fields, keys and indexes are recorded so the test can compare them independently.
    public function install_one_table_from_xmldb_file($file, $tablename) {
        if ($this->table_exists($tablename)) { throw new RuntimeException('table already exists: ' . $tablename); }
        if ($tablename === $this->failinstall) { throw new RuntimeException('simulated create table failure'); }
        $xml = simplexml_load_file($this->xmloverride ?? $file);
        $found = null;
        foreach ($xml->TABLES->TABLE as $definition) {
            if ((string)$definition['NAME'] === $tablename) { $found = $definition; }
        }
        if (!$found) { throw new RuntimeException('table not defined in XMLDB file: ' . $tablename); }
        $structure = ['fields' => [], 'keys' => [], 'indexes' => []];
        foreach ($found->FIELDS->FIELD as $field) {
            $structure['fields'][(string)$field['NAME']] = [(string)$field['TYPE'], (string)$field['LENGTH'],
                (string)$field['NOTNULL'], (string)$field['SEQUENCE'], isset($field['DEFAULT']) ? (string)$field['DEFAULT'] : null];
        }
        foreach ($found->KEYS->KEY as $key) {
            $structure['keys'][(string)$key['NAME']] = [(string)$key['TYPE'], (string)$key['FIELDS']];
        }
        if (isset($found->INDEXES)) {
            foreach ($found->INDEXES->INDEX as $index) {
                $structure['indexes'][(string)$index['NAME']] = [(string)$index['UNIQUE'], (string)$index['FIELDS']];
            }
        }
        if ($tablename === $this->incompleteinstall) { array_pop($structure['indexes']); }
        $this->installs[] = $tablename;
        unset($this->db->absenttables[$tablename]);
        $this->db->tables[$tablename] = $structure;
    }
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
    // Tables introduced after the published #114 level (2026111100) are absent before their upgrade step.
    public $absenttables = [];
    public $tables = [];
    // Sentinel existing values in two companies; any repair DML is unsupported.
    public $rows = ['A' => ['starttime' => '09:15', 'duetime' => '17:20', 'executionstatus' => 'done'],
        'B' => ['starttime' => '10:00', 'duetime' => '18:00', 'executionstatus' => 'inprogress']];
    public function __construct($targets, $presentmask) {
        $this->targets = $targets;
        $this->manager = new SchemaParityManager($this);
        global $newtables;
        $this->absenttables = array_fill_keys(array_keys($newtables), true);
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
        if ($table === 'local_kbacompany_adminitem' || preg_match('/_(finbudfact|finbudline|finline|statbind|statnorm|statpolicy)$/', $table)) {
            $xml = simplexml_load_file(__DIR__ . '/../db/install.xml');
            foreach ($xml->TABLES->TABLE as $definition) {
                if ((string)$definition['NAME'] !== $table) { continue; }
                foreach ($definition->FIELDS->FIELD as $field) {
                    $numeric = (string)$field['TYPE'] === 'number';
                    $default = isset($field['DEFAULT']) ? (string)$field['DEFAULT'] : null;
                    $columns[(string)$field['NAME']] = (object)['meta_type' => $numeric ? 'N' : 'C',
                        'max_length' => (int)$field['LENGTH'], 'scale' => (int)$field['DECIMALS'],
                        'not_null' => (string)$field['NOTNULL'] === 'true', 'auto_increment' => false,
                        'default_value' => $default];
                }
            }
        }
        if ($table === 'local_kbacompany_task') {
            foreach (['companyuuid', 'status', 'duedate'] as $name) {
                $columns[$name] = (object)['meta_type' => 'C'];
            }
        }
        return $columns;
    }
    public function get_prefix() { return 'qa_'; }
    // Later ADR-0004 fields are modelled as already matching install.xml. The real
    // two-engine repair suite covers their DDL/ranges; retain every original0890 case.
    public function get_dbfamily() { return 'mysql'; }
    public function get_field_sql($sql, $params = []) {
        return strpos($sql, 'sql_mode') !== false ? 'STRICT_TRANS_TABLES' : 'InnoDB';
    }
    public function record_exists_sql($sql, $params = []) { return false; }
    public function get_record_sql($sql, $params = []) {
        $table = substr($params['tablename'], 3);
        $column = $this->get_columns($table)[$params['fieldname']];
        return (object)['coltype' => $column->meta_type === 'N' ?
            'decimal(' . $column->max_length . ',' . $column->scale . ')' : 'varchar(5)', 'extra' => ''];
    }
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
// Tables added after 2026111100 and their XMLDB structure, parsed independently (DOM) of the manager double (SimpleXML).
$dom = new DOMDocument();
$dom->load(__DIR__ . '/../db/install.xml');
$newtables = [];
foreach ($dom->getElementsByTagName('TABLE') as $tablenode) {
    $name = $tablenode->getAttribute('NAME');
    if (!preg_match('/^local_kbacompany_(dreport|wprincipal$|wpaudit$|wpgrant$|wpdeleg$|posoccup$|posoccuplog$)/', $name)) { continue; }
    $structure = ['fields' => [], 'keys' => [], 'indexes' => []];
    foreach ($tablenode->getElementsByTagName('FIELD') as $node) {
        $structure['fields'][$node->getAttribute('NAME')] = [$node->getAttribute('TYPE'), $node->getAttribute('LENGTH'),
            $node->getAttribute('NOTNULL'), $node->getAttribute('SEQUENCE'), $node->hasAttribute('DEFAULT') ? $node->getAttribute('DEFAULT') : null];
    }
    foreach ($tablenode->getElementsByTagName('KEY') as $node) {
        $structure['keys'][$node->getAttribute('NAME')] = [$node->getAttribute('TYPE'), $node->getAttribute('FIELDS')];
    }
    foreach ($tablenode->getElementsByTagName('INDEX') as $node) {
        $structure['indexes'][$node->getAttribute('NAME')] = [$node->getAttribute('UNIQUE'), $node->getAttribute('FIELDS')];
    }
    $newtables[$name] = $structure;
}
// ADR-0009 R1 adds five tables, ADR-0010 two more (dreportcc, dreportset), BMOS-A1.1 six registries.
if (count($newtables) !== 13) { throw new RuntimeException('expected thirteen ADR-0009/ADR-0010/BMOS-A1.1 additive tables in install.xml'); }
foreach ($newtables as $name => $structure) {
    if (!$structure['fields'] || !isset($structure['keys']['primary'])) { throw new RuntimeException('incomplete XMLDB fixture: ' . $name); }
}
if (!preg_match('/\$plugin->version\s*=\s*(\d+);/', file_get_contents(__DIR__ . '/../version.php'), $versionmatch)) {
    throw new RuntimeException('plugin version not found');
}
$targetversion = (int)$versionmatch[1];
function new_tables_installed($DB) {
    global $newtables;
    return !$DB->absenttables && $DB->tables == $newtables;
}
require_once(__DIR__ . '/../db/upgrade.php');

foreach ([2026101645, 2026101879, 2026110802, 2026110804, 2026110890, 2026111100] as $oldversion) {
    foreach (range(0, 7) as $presentmask) {
        // The published #114 level (2026111100) already contains every repaired field.
        if ($oldversion >= 2026111100 && $presentmask !== 7) { continue; }
        $DB = new SchemaParityDatabase($targets, $presentmask);
        $before = $DB->rows;
        $savepoints = [];
        parity_check(xmldb_local_kbacompany_upgrade($oldversion), 'actual upgrade returns success');
        parity_check(end($savepoints) === $targetversion, 'final version.php checkpoint reached from ' . $oldversion);
        parity_check($oldversion >= 2026111100 || in_array(2026111100, $savepoints, true), 'repair checkpoint passed from ' . $oldversion);
        parity_check(in_array(2026111600, $savepoints, true), 'daily report table step passed from ' . $oldversion);
        parity_check(in_array(2026112301, $savepoints, true), 'ADR-0010 table step passed from ' . $oldversion);
        parity_check(in_array(2026112800, $savepoints, true), 'BMOS-A1.1 registry table step passed from ' . $oldversion);
        parity_check(new_tables_installed($DB), 'five R1 tables installed exactly as install.xml from ' . $oldversion);
        parity_check($DB->manager->installs === array_keys($newtables), 'each new table installed once, in order');
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
        parity_check($DB->manager->installs === array_keys($newtables) && new_tables_installed($DB), 'retry installs no table twice');
        $savepoints = [];
        xmldb_local_kbacompany_upgrade($targetversion);
        parity_check(!$savepoints && $DB->manager->adds === $adds && count($DB->manager->installs) === 13, 'installed target is no-op');
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
        parity_check(!$DB->manager->adds && !array_filter($savepoints, static function($v) { return $v >= 2026110890; }), 'incompatibility stopped before DDL/savepoint');
    }
}

foreach (['failon', 'silenton'] as $failuremode) {
    $DB = new SchemaParityDatabase($targets, 0);
    $DB->manager->$failuremode = 'local_kbacompany_task.duetime';
    $savepoints = [];
    $failed = false;
    try { xmldb_local_kbacompany_upgrade(2026101879); } catch (RuntimeException $e) { $failed = true; }
    parity_check($failed && !array_filter($savepoints, static function($v) { return $v >= 2026110890; }), 'failed/incomplete DDL cannot advance savepoint');
    parity_check(isset($DB->columns['local_kbacompany_task']['starttime']), 'partial DDL model exercised');
    $DB->manager->$failuremode = null;
    xmldb_local_kbacompany_upgrade(2026101879);
    parity_check(end($savepoints) === $targetversion && new_tables_installed($DB), 'partial DDL retry reaches final checkpoint');
}
$DB = new SchemaParityDatabase($targets, 0);
$DB->missingtable = 'local_kbacompany_deviation';
$savepoints = [];
try {
    xmldb_local_kbacompany_upgrade(2026101879);
    throw new RuntimeException('missing required table accepted');
} catch (ddl_table_missing_exception $e) {
    parity_check(!array_filter($savepoints, static function($v) { return $v >= 2026110890; }) && !$DB->manager->adds, 'missing table stops before DDL/savepoint');
}
// ADR-0009 R1 tables from the published 2026111100 level: partial state, failed create, incomplete table, missing definition.
$names = array_keys($newtables);
$DB = new SchemaParityDatabase($targets, 7);
unset($DB->absenttables[$names[1]]);
$DB->tables[$names[1]] = $newtables[$names[1]];
$savepoints = [];
xmldb_local_kbacompany_upgrade(2026111100);
parity_check($DB->manager->installs === array_values(array_diff($names, [$names[1]])) && new_tables_installed($DB),
    'only absent daily report tables are installed');

$DB = new SchemaParityDatabase($targets, 7);
$DB->manager->failinstall = $names[2];
$savepoints = [];
$failed = false;
try { xmldb_local_kbacompany_upgrade(2026111100); } catch (RuntimeException $e) { $failed = true; }
parity_check($failed && !array_filter($savepoints, static function($v) { return $v >= 2026111600; }),
    'failed table creation cannot advance the 2026111600 savepoint');
parity_check($DB->manager->installs === array_slice($names, 0, 2) && isset($DB->absenttables[$names[2]]), 'partial create state modelled');
$DB->manager->failinstall = null;
$savepoints = [];
xmldb_local_kbacompany_upgrade(2026111100);
parity_check(end($savepoints) === $targetversion && new_tables_installed($DB) && count($DB->manager->installs) === 13,
    'retry after failed create installs the remaining tables once');

$DB = new SchemaParityDatabase($targets, 7);
$DB->manager->incompleteinstall = $names[0];
$savepoints = [];
xmldb_local_kbacompany_upgrade(2026111100);
parity_check(!new_tables_installed($DB), 'an incomplete (damaged) daily report table is detected by the structure comparison');

$broken = tempnam(sys_get_temp_dir(), 'xmldb');
file_put_contents($broken, preg_replace('/<TABLE NAME="' . $names[3] . '".*?<\/TABLE>/s', '', file_get_contents(__DIR__ . '/../db/install.xml')));
$DB = new SchemaParityDatabase($targets, 7);
$DB->manager->xmloverride = $broken;
$savepoints = [];
$failed = false;
try { xmldb_local_kbacompany_upgrade(2026111100); } catch (RuntimeException $e) { $failed = strpos($e->getMessage(), 'not defined') !== false; }
unlink($broken);
parity_check($failed && !array_filter($savepoints, static function($v) { return $v >= 2026111600; }),
    'missing table definition stops before the 2026111600 savepoint');

$DB = new SchemaParityDatabase($targets, 7);
$failed = false;
try { $DB->manager->table_exists(42); } catch (InvalidArgumentException $e) { $failed = true; }
parity_check($failed, 'table_exists() rejects unsupported argument types');

echo "PASS: schema parity upgrade entrypoint, 41 version/partial-state cases, thirteen additive tables (R1 + ADR-0010 + BMOS-A1.1) and failures; target $targetversion; $assertions assertions\n";
echo "NOT RUN: actual Moodle SQL drivers, fresh install and complete historical migration semantics\n";
