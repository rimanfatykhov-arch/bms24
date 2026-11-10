<?php
/** Inject a driver DDL failure after actual committed DDL, then let the parent retry via CLI. */
if (!defined('CLI_SCRIPT') || !isset($marker, $root, $decimals)) { throw new RuntimeException('Fixture context required'); }
require_once($core . '/lib/upgradelib.php');
require_once($core . '/local/kbacompany/db/upgrade.php');
foreach (['starttime', 'duetime'] as $field) { $dbman->drop_field(new xmldb_table($admin), new xmldb_field($field)); }
foreach ($decimals as $item) {
    $default = $item['column'] === 'weight' ? '1' : '0';
    $nullable = !$item['expected']['not_null'];
    $DB->change_database_structure(['ALTER TABLE ' . identifier($CFG->prefix . $item['table']) .
        ' MODIFY COLUMN ' . identifier($item['column']) . ' ' . $item['actual_type'] .
        ($nullable ? ' NULL DEFAULT NULL' : ' NOT NULL DEFAULT ' . $default)], [$item['table']]);
}
set_config('version', '2026110906', 'local_kbacompany');
core_plugin_manager::reset_caches();
unset_config('allversionshash'); unset($CFG->allversionshash);
class Rc30FailingManager {
    private $inner;
    public $calls = 0;
    public function __construct($inner) { $this->inner = $inner; }
    public function __call($name, $args) { return $this->inner->$name(...$args); }
    public function change_field_precision($table, $field) {
        if (++$this->calls === 2) { throw new RuntimeException('injected second DECIMAL DDL failure'); }
        return $this->inner->change_field_precision($table, $field);
    }
}
class Rc30DatabaseProxy {
    private $inner;
    public $manager;
    public function __construct($inner) { $this->inner = $inner; $this->manager = new Rc30FailingManager($inner->get_manager()); }
    public function __call($name, $args) { return $this->inner->$name(...$args); }
    public function get_manager() { return $this->manager; }
}
$realdb = $DB;
$proxy = new Rc30DatabaseProxy($realdb);
$DB = $proxy;
$failed = false;
try { xmldb_local_kbacompany_upgrade(2026110906); }
catch (RuntimeException $error) {
    expect($error->getMessage() === 'injected second DECIMAL DDL failure', 'partial.injected-failure');
    $failed = true;
} finally { $DB = $realdb; }
expect($failed && $proxy->manager->calls === 2, 'partial.second-ddl-interrupted');
expect((string)get_config('local_kbacompany', 'version') === '2026110906', 'partial.no-target-savepoint');
expect(isset($DB->get_columns($admin, false)['starttime'], $DB->get_columns($admin, false)['duetime']), 'partial.admin-fields-committed');
expect((int)$DB->get_columns('local_kbacompany_finbudfact', false)['actualamount']->scale === 2, 'partial.first-decimal-committed');
expect((int)$DB->get_columns('local_kbacompany_finbudline', false)['planamount']->scale === 0, 'partial.next-decimal-unmodified');
json_write($evidence . '/partial-schema.json', schema_snapshot());
json_write($evidence . '/partial-failure.json', ['status' => 'PASS', 'version_after_failure' => '2026110906',
    'ddl_committed' => ['adminitem.starttime', 'adminitem.duetime', 'finbudfact.actualamount'],
    'fault' => 'test-only manager proxy throws before second DECIMAL DDL; no production hook']);
