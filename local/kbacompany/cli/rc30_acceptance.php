<?php
/**
 * Build2026101864: read-only RC30 technical preflight. Root of Moodle:
 * php local/kbacompany/cli/rc30_acceptance.php --company=UUID --format=json --strict
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_kbacompany\local\task_rc30_acceptance_service as acceptance;
use local_kbacompany\local\task_rc30_cli_response_service as response;

list($options, $unknown) = cli_get_params([
    'help'=>false, 'company'=>'', 'all'=>false, 'format'=>'text', 'strict'=>false,
], ['h'=>'help', 'c'=>'company']);
if (!empty($options['help'])) {
    echo "BMS24 RC30 technical acceptance (read-only)\n\n"
       . "  --company=UUID       Validate one company's tasks (recommended)\n"
       . "  --all                Validate all companies (may be expensive)\n"
       . "  --format=text|json   Output format (default text)\n"
       . "  --strict             Treat warnings as a failed gate\n"
       . "  --help               Show usage\n\n"
       . "Technical PASS does not replace manual server acceptance.\n";
    exit(0);
}
$format = (string)$options['format'];
$strict = !empty($options['strict']);
$scope = !empty($options['all']) ? 'all' :
    ((string)$options['company'] !== '' ? 'company' : 'unspecified');
if (!in_array($format, ['text', 'json'], true)) {
    response::emit_failure('acceptance', 'text', 'unsupported_format', $scope, $strict);
    exit(2);
}
if ($unknown || (!empty($options['all']) === ((string)$options['company'] !== ''))) {
    response::emit_failure('acceptance', $format, 'invalid_arguments', $scope, $strict);
    exit(2);
}
try {
    $company = !empty($options['all']) ? '' : (string)$options['company'];
    $installed = (int)get_config('local_kbacompany', 'version');
    // Bind evidence to the configured Moodle site and database identity. The
    // raw site URL, database host/name and table prefix never enter the report.
    $siteurl = rtrim((string)($CFG->wwwroot ?? ''), '/');
    $installationkey = $siteurl === '' ? '' : implode("\n", [
        $siteurl, (string)($CFG->dbtype ?? ''), (string)($CFG->dbhost ?? ''),
        (string)($CFG->dbname ?? ''), (string)($CFG->prefix ?? ''),
    ]);
    $result = acceptance::assess($DB, $installed, $company, !empty($options['strict']),
        $installationkey);
} catch (\Throwable $e) {
    // Failed execution must still leave a valid JSON report for CI/RC30 evidence.
    // Never output raw exception text, SQL, identifiers or paths.
    response::emit_failure('acceptance', $format, response::error_code($e), $scope, $strict);
    exit(2);
}
if ($format === 'json') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} else {
    echo "BMS24 RC30 | technical gate: " . strtoupper($result['technical_gate']) . "\n";
    echo "Plugin version: " . $result['installed_plugin_version'] . " / "
       . $result['expected_plugin_version'] . "\n";
    foreach ($result['indexes'] as $name => $item) {
        echo "Index " . $name . ": " . ($item['present'] ? 'OK' : 'MISSING') . "\n";
    }
    echo "Data checks: " . $result['integrity_summary']['checks']
       . "; failing error categories: " . $result['integrity_summary']['errors']
       . "; warning categories: " . $result['integrity_summary']['warnings'] . "\n";
    if ($result['errors']) { echo "Errors: " . implode(', ', $result['errors']) . "\n"; }
    if ($result['warnings']) { echo "Warnings: " . implode(', ', $result['warnings']) . "\n"; }
    echo "RC30 promotion: NOT AUTOMATIC. Manual acceptance is required. No data changed.\n";
}
exit($result['technical_gate'] === 'failed' ? 1 : 0);
