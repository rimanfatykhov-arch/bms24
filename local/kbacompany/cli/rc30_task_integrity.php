<?php
/**
 * build2026101860 — read-only Task Center integrity preflight for Moodle CLI.
 * Run: php local/kbacompany/cli/rc30_task_integrity.php --company=COMPANY_UUID
 *      php local/kbacompany/cli/rc30_task_integrity.php --all
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_kbacompany\local\task_rc30_integrity_service as integrity;
use local_kbacompany\local\task_rc30_cli_response_service as response;

list($options, $unknown) = cli_get_params([
    'help'=>false, 'company'=>'', 'all'=>false, 'format'=>'text', 'strict'=>false,
], [
    'h'=>'help', 'c'=>'company',
]);
if ($options['help']) {
    echo "BMS24 RC30 task integrity (read-only)\n\n"
       . "  --company=UUID    Check one company (recommended)\n"
       . "  --all             Check all companies (explicit opt-in)\n"
       . "  --format=text|json  Output format (default text)\n"
       . "  --strict          Treat warning categories as a failed gate\n"
       . "  --help            Show usage\n";
    exit(0);
}
$format = (string)$options['format'];
$strict = !empty($options['strict']);
$scope = !empty($options['all']) ? 'all' :
    ((string)$options['company'] !== '' ? 'company' : 'unspecified');
if (!in_array($format, ['text', 'json'], true)) {
    response::emit_failure('integrity', 'text', 'unsupported_format', $scope, $strict);
    exit(2);
}
if ($unknown || (!empty($options['all']) === ((string)$options['company'] !== ''))) {
    response::emit_failure('integrity', $format, 'invalid_arguments', $scope, $strict);
    exit(2);
}
$companyuuid = !empty($options['all']) ? '' : (string)$options['company'];
try {
    $results = integrity::scan($DB, $companyuuid);
    $summary = integrity::summary($results);
} catch (\Throwable $e) {
    response::emit_failure('integrity', $format, response::error_code($e), $scope, $strict);
    exit(2);
}
$failed = $summary['errors'] > 0 || ($strict && $summary['warnings'] > 0);
if ($format === 'json') {
    $payload = [
        'schema_version'=>1,
        'gate'=>$failed ? 'failed' : ($summary['warnings'] > 0 ? 'warnings' : 'passed'),
        'scope'=>$companyuuid === '' ? 'all' : 'company',
        'strict'=>$strict,
        'summary'=>$summary,
        'checks'=>$results,
        'read_only'=>true,
    ];
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($failed ? 1 : 0);
}
echo "BMS24 RC30 Task Center integrity | " . ($companyuuid === '' ? 'all companies' : 'one company') . "\n";
foreach ($results as $name => $item) {
    echo sprintf("%-30s %-7s %d\n", $name, strtoupper($item['severity']), $item['count']);
}
echo "Checks: {$summary['checks']} | violations: {$summary['errors']} error categories, "
   . "{$summary['warnings']} warning categories\n";
if ($failed) {
    fwrite(STDERR, "RC30 DATA GATE: FAILED (errors or strict warnings). Investigate before release promotion; no data was changed.\n");
    exit(1);
}
if ($summary['warnings'] > 0) {
    echo "RC30 DATA GATE: WARNINGS. Review historical/manual records. No data was changed.\n";
} else {
    echo "RC30 DATA GATE: PASSED for these checks. No data was changed.\n";
}
exit(0);
