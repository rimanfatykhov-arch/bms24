<?php
/** Standalone PHP smoke contract; no Moodle database or vendor packages needed. */
define('MOODLE_INTERNAL', true);
require __DIR__ . '/../classes/local/implementation_board_query.php';
require __DIR__ . '/../classes/local/implementation_kanban.php';
require __DIR__ . '/../classes/local/implementation_analytics.php';
require __DIR__ . '/../classes/local/implementation_task_discussion.php';
if (!defined('DAYSECS')) { define('DAYSECS', 86400); }
function usergetmidnight($t) { return $t - ($t % DAYSECS); }
function has_capability($cap, $ctx, $userid) { return false; }
class context_system { public static function instance() { return new self(); } }
class fake_db {
    public array $queries = [];
    public function sql_concat(...$parts): string { return 'CONCAT(' . implode(',', $parts) . ')'; }
    public function sql_like(string $field, string $param, bool $casesensitive): string {
        return "$field LIKE $param";
    }
    public function sql_like_escape(string $q): string { return addcslashes($q, '%_'); }
    public function get_records_sql(string $sql, array $params, int $from = 0, int $count = 0): array {
        $this->queries[] = [$sql, $params, $from, $count];
        return [];
    }
}
$DB = new fake_db();
use local_kbacorporate\local\implementation_board_query as query;
function verify(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, $message . "\n"); exit(1); }
}
$filters = ['companyid' => 25, 'curatorid' => 999, 'ownerid' => -1,
    'priority' => 'critical', 'scope' => 'overdue', 'status' => 'inprogress',
    'q' => "test%' OR 1=1", 'evidence' => 'submitted'];
$summary = query::summary(70, false, $filters);
verify($summary['total'] === 0, 'Expected empty count');
query::column(70, false, $filters, 'inprogress', 2);
verify(count($DB->queries) === 2, 'Expected summary + bounded SQL page');
foreach ($DB->queries as [$sql, $params]) {
    verify(strpos($sql, 't.deleted = 0') !== false, 'Missing soft-delete boundary');
    verify(strpos($sql, 'p.curatorid = :actorcurator') !== false,
        'Missing explicit assigned-curator scope');
    verify(strpos($sql, 't.companyid = :companyid') !== false,
        'Missing company scope');
    verify(strpos($sql, 't.ownerid = 0') !== false, 'Missing unassigned filter');
    verify(strpos($sql, 'newer.versionno') !== false, 'Missing latest evidence guard');
    verify(strpos($sql, 'OR 1=1') === false, 'Unsafe interpolated user search');
    verify(isset($params['search0']), 'Search should be bound');
}
verify($DB->queries[1][2] === 30 && $DB->queries[1][3] === 15,
    'Column did not use server-side LIMIT/OFFSET');
verify(query::page(999, 32) === 2 && query::page(-1, 32) === 0,
    'Out of range column page handling failed');
query::facets(70, false);
verify(count($DB->queries) === 6, 'Four bounded facet queries expected');
echo "Implementation board 1824 SQL contract: PASS (6 checks / queries reviewed)\n";
