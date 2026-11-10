<?php
// Corporate Kanban read-only benchmark. Run from a staging Moodle CLI, never from a web URL.
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_kbacorporate\local\implementation_board_query;

[$options, $unrecognized] = cli_get_params([
    'help' => false, 'userid' => 0, 'companyid' => 0, 'runs' => 5,
], ['h' => 'help']);
if ($options['help']) {
    echo "Usage: php local/kbacorporate/cli/benchmark_kanban.php --userid=ID --companyid=ID [--runs=5]\n";
    exit(0);
}
$userid = (int)$options['userid'];
$companyid = (int)$options['companyid'];
$runs = max(1, min(20, (int)$options['runs']));
if ($userid <= 0 || $companyid <= 0 || !$DB->record_exists('user', ['id' => $userid]) ||
        !$DB->record_exists('local_kbacorp_company', ['id' => $companyid])) {
    cli_error('Specify existing --userid and --companyid (positive integer IDs).');
}
$allcurators = has_capability('local/kbacorporate:manageimplementationaudits',
    context_system::instance(), $userid);
$assigned = $DB->record_exists('local_kbacorp_impl_program', [
    'companyid' => $companyid, 'curatorid' => $userid,
]);
if (!$assigned && !$allcurators) {
    cli_error('User cannot access the requested implementation company.');
}
$measurements = [];
for ($iteration = 0; $iteration < $runs; $iteration++) {
    $beforequeries = $DB->perf_get_queries();
    $before = microtime(true);
    $summary = implementation_board_query::summary($userid, $allcurators,
        ['companyid' => $companyid]);
    $visible = 0;
    foreach (implementation_board_query::STATUSES as $status) {
        $rows = implementation_board_query::column($userid, $allcurators,
            ['companyid' => $companyid], $status, 0);
        $visible += count($rows);
    }
    $measurements[] = [
        'ms' => round((microtime(true) - $before) * 1000, 2),
        'queries' => $DB->perf_get_queries() - $beforequeries,
        'total' => $summary['total'],
        'visible' => $visible,
    ];
}
// Outputs aggregates only, no task names, contacts or company names.
echo json_encode(['runs' => $runs, 'companyid' => $companyid,
    'measurements' => $measurements, 'peakmemorybytes' => memory_get_peak_usage(true)],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
