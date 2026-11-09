<?php
/** Standalone runtime + acceptance gate regression; requires PHP CLI only. */
define('MOODLE_INTERNAL', true);
define('XMLDB_INDEX_NOTUNIQUE', 0);
class invalid_parameter_exception extends Exception {}
class coding_exception extends Exception {}
class xmldb_table {
    private string $name;
    public function __construct(string $name) { $this->name = $name; }
}
class xmldb_index {
    private string $name;
    public function __construct(string $name, int $kind, array $fields) { $this->name=$name; }
    public function getName(): string { return $this->name; }
}
require_once(__DIR__.'/../classes/local/task_rc30_integrity_service.php');
require_once(__DIR__.'/../classes/local/task_rc30_acceptance_service.php');
class TestIndexManager1865 {
    public array $missing = [];
    public function table_exists($table): bool { return true; }
    public function index_exists($table, $index): bool {
        return !in_array($index->getName(), $this->missing, true);
    }
}
class TestDB1865 {
    public TestIndexManager1865 $manager;
    public array $queries = [];
    public string $dbversion = '5.7.21';
    public int $tasks = 10;
    public array $indexoverrides = [];
    public function __construct() { $this->manager = new TestIndexManager1865(); }
    public function get_manager() { return $this->manager; }
    public function get_indexes(string $table, bool $usecache = true): array {
        $indexes = [];
        foreach (\local_kbacompany\local\task_rc30_acceptance_service::REQUIRED_INDEXES as [$target, $name, $fields]) {
            if ($target === $table && !in_array($name, $this->manager->missing, true)) {
                $indexes[$name] = $this->indexoverrides[$name] ?? ['unique' => false, 'columns' => $fields];
            }
        }
        return $indexes;
    }
    public function get_dbfamily(): string { return 'mysql'; }
    public function get_server_info(): array { return ['version'=>$this->dbversion, 'description'=>'MySQL']; }
    public function get_field_sql(string $sql, array $params = []): int {
        $this->queries[] = ['sql'=>$sql, 'params'=>$params];
        if (strpos($sql, 'WHERE 1=1') !== false) { return $this->tasks; }
        return 0;
    }
}
function check1865(bool $condition, string $name): void {
    if (!$condition) { throw new RuntimeException('FAILED: '.$name); }
}
use local_kbacompany\local\task_rc30_acceptance_service as Acceptance;
$uuid='22222222-2222-4222-8222-222222222222';
$db=new TestDB1865();
$actualtargetphp = (bool)preg_match('/^8\.1(?:\.|$)/', PHP_VERSION);
$result=Acceptance::assess($db, Acceptance::EXPECTED_PLUGIN_VERSION, $uuid, true);
check1865($result['technical_gate'] === ($actualtargetphp ? 'passed' : 'failed'), 'PHP runtime gate');
check1865($result['runtime']['db_server_version'] === '5.7.21', 'Database version visible');
check1865(count($db->queries) === 18, 'Exactly eighteen read-only checks');
foreach ($db->queries as $query) {
    check1865(stripos(ltrim($query['sql']), 'SELECT') === 0, 'Only SELECT statements');
    check1865(($query['params']['companyuuid'] ?? '') === $uuid, 'Company-scoped queries');
}
check1865($result['rc30_promotion_approved'] === false, 'Never automatic promotion');
$db->dbversion='8.0.41';
$result=Acceptance::assess($db, Acceptance::EXPECTED_PLUGIN_VERSION, $uuid, true);
check1865($result['technical_gate'] === 'failed', 'MySQL8 strict gate failed');
check1865(in_array('mysql57_target_not_verified', $result['warnings'], true), 'DB mismatch explicit');
$db->dbversion='5.7.21';
$db->manager->missing=['lastlog_ix'];
$result=Acceptance::assess($db, Acceptance::EXPECTED_PLUGIN_VERSION, $uuid, false);
check1865($result['technical_gate'] === 'failed', 'Missing index blocks acceptance');
$db->manager->missing=[];
foreach ([['unique' => false, 'columns' => ['companyuuid', 'duedate', 'status']],
        ['unique' => true, 'columns' => ['companyuuid', 'status', 'duedate']]] as $wrongindex) {
    $db->indexoverrides['status_due_ix'] = $wrongindex;
    $result = Acceptance::assess($db, Acceptance::EXPECTED_PLUGIN_VERSION, $uuid, false);
    check1865(in_array('missing_index_status_due_ix', $result['errors'], true),
        'Wrong order or uniqueness cannot satisfy the required index');
}
$db->indexoverrides=[];
try {
    Acceptance::assess($db, Acceptance::EXPECTED_PLUGIN_VERSION, 'invalid-uuid');
    throw new RuntimeException('Invalid UUID unexpectedly accepted');
} catch (invalid_parameter_exception $e) {
    // Correct: no SQL was executed for invalid UUID.
}
echo "PASS: 4 end-to-end technical gate scenarios; only scoped SELECT checks; no Moodle needed\n";
