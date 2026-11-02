<?php
/**
 * BMS24 Communication Center read-only poll benchmark for a TEST Moodle instance.
 *
 * Usage (from Moodle root):
 * php local/kbacompany/cli/benchmark_communication_poll_1825.php \
 *   --uuid=COMPANY_UUID --actorid=TEST_USER_ID --runs=10
 *
 * No mutations, message text or identifying attributes are written to output.
 * This checks a SINGLE actor and is not a concurrent-load benchmark.
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_kbacompany\local\communication_live_service;

list($options, $unknown) = cli_get_params([
    'help' => false, 'uuid' => '', 'actorid' => 0, 'runs' => 10,
], ['h' => 'help']);
if (!empty($options['help'])) {
    echo "Use --uuid=UUID --actorid=TEST_USER_ID --runs=10 (1..30). Read-only benchmark.\n";
    exit(0);
}
if ($unknown || empty($options['uuid']) || (int)$options['actorid'] <= 0 ||
        strlen((string)$options['uuid']) > 128 ||
        (int)$options['runs'] < 1 || (int)$options['runs'] > 30) {
    cli_error('Supply --uuid, --actorid and --runs between 1 and 30.');
}
$actor = (int)$options['actorid'];
$runs = (int)$options['runs'];
$times = [];
$reads = [];
try {
    // One non-measured warm-up. All ACL checks are still executed on measured runs.
    communication_live_service::snapshot((string)$options['uuid'], $actor);
    for ($i = 0; $i < $runs; $i++) {
        $before = method_exists($DB, 'perf_get_reads') ? (int)$DB->perf_get_reads() : null;
        $start = microtime(true);
        $state = communication_live_service::snapshot((string)$options['uuid'], $actor);
        $times[] = (microtime(true) - $start) * 1000;
        if ($before !== null) { $reads[] = (int)$DB->perf_get_reads() - $before; }
        if (empty($state['revision']) || strlen((string)$state['revision']) !== 64) {
            cli_error('Invalid poll revision.');
        }
    }
} catch (Throwable $e) {
    // Do not print internal SQL and user/company information to terminal or ticket logs.
    cli_error('Benchmark unavailable: verify test actor membership and Moodle setup.');
}
sort($times, SORT_NUMERIC);
$count = count($times);
$median = $count % 2 ? $times[(int)floor($count / 2)] :
    ($times[$count / 2 - 1] + $times[$count / 2]) / 2;
$p95 = $times[max(0, (int)ceil(0.95 * $count) - 1)];
echo 'runs=' . $runs . ' median_ms=' . round($median, 2) .
    ' p95_ms=' . round($p95, 2) .
    ' max_ms=' . round($times[$count - 1], 2) .
    ' peak_memory_mb=' . round(memory_get_peak_usage(true) / 1048576, 2) . "\n";
if ($reads) {
    sort($reads, SORT_NUMERIC);
    echo 'sql_reads_median=' . $reads[(int)floor(count($reads) / 2)] .
        ' sql_reads_max=' . max($reads) . "\n";
}
echo "NOTE: single-actor diagnostic, not a concurrent load test; no live traffic data collected.\n";
