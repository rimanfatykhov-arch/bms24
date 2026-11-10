<?php
/** Pure tests: no Moodle config or data. */
define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/implementation_acceptance_metrics.php');
use local_kbacorporate\local\implementation_acceptance_metrics as M;
$tests = 0;
$check = static function(bool $pass, string $label) use (&$tests): void {
    if (!$pass) { throw new RuntimeException('FAIL: ' . $label); }
    $tests++;
};
$check(M::median([]) === 0.0, 'empty median');
$check(M::median([5]) === 5.0, 'singleton median');
$check(M::median([5, 1, 4]) === 4.0, 'odd median');
$check(M::median([4, 1, 5, 2]) === 3.0, 'even median');
$check(M::percentile([5, 1, 4, 2, 3], 95) === 5.0, 'nearest rank p95');
$check(M::percentile([5, 1, 4, 2, 3], 50) === 3.0, 'p50');
$check(M::percentile([], 95) === 0.0, 'empty p95');
$agg = M::aggregate([['ms' => 1.23, 'queries' => 10], ['ms' => 3.25, 'queries' => 12]]);
$check($agg['median_ms'] === 2.24 && $agg['p95_ms'] === 3.25, 'summary time');
$check($agg['median_queries'] === 11.0 && $agg['p95_queries'] === 12.0, 'summary queries');
try { M::percentile([1], 0); $check(false, 'invalid percentile'); } catch (InvalidArgumentException $e) { $check(true, 'invalid percentile'); }
echo "Corporate 1825 performance metrics: {$tests}/{$tests} PASS\n";
