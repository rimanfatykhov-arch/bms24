<?php
/**
 * BMS24 build1867: compare two RC30 technical acceptance JSON files.
 * Read-only: no SQL, no changes to Moodle or evidence files.
 * php local/kbacompany/cli/rc30_compare_reports.php \
 *   --baseline=before.json --current=after.json --format=json --strict
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_kbacompany\local\task_rc30_report_comparison_service as comparator;

list($opts, $unknown) = cli_get_params([
    'help'=>false, 'baseline'=>'', 'current'=>'', 'format'=>'text',
    'strict'=>false, 'confirm-same-company'=>false,
], ['h'=>'help']);
if ($opts['help']) {
    echo "BMS24 RC30 evidence comparison (read-only)\n\n"
       . "  --baseline=PATH          Completed RC30 acceptance JSON before update\n"
       . "  --current=PATH           Completed RC30 acceptance JSON after update\n"
       . "  --format=text|json       Output format (default text)\n"
       . "  --strict                 Treat new warnings as blocking\n"
       . "  --confirm-same-company   Confirm company UUID for old reports without scope digest\n"
       . "  --help                   Show usage\n\n"
       . "No database access, no mutation. RC30 promotion still requires manual sign-off.\n";
    exit(0);
}
$format = (string)$opts['format'];
$strict = !empty($opts['strict']);
$codes = [
    'invalid_arguments', 'unsupported_format', 'report_file_unavailable',
    'report_invalid_json', 'report_invalid_schema', 'report_scope_mismatch',
    'report_company_unverified', 'report_checks_missing', 'report_checks_changed',
    'report_summary_mismatch', 'report_version_rollback',
    'report_installation_mismatch', 'report_time_rollback', 'report_same_file',
    'comparison_execution_error',
];
$failure = function(string $code) use ($format, $strict, $codes): void {
    if (!in_array($code, $codes, true)) { $code = 'comparison_execution_error'; }
    if ($format === 'json') {
        echo json_encode([
            'schema_version'=>1, 'comparison_complete'=>false,
            'read_only'=>true, 'rc30_promotion_approved'=>false,
            'strict'=>$strict, 'comparison_gate'=>'failed', 'errors'=>[$code],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        fwrite(STDERR, "RC30 comparison: " . $code . "\n");
    }
};
if (!in_array($format, ['text', 'json'], true)) {
    $failure('unsupported_format');
    exit(2);
}
if ($unknown || !is_string($opts['baseline']) || !is_string($opts['current']) ||
        $opts['baseline'] === '' || $opts['current'] === '') {
    $failure('invalid_arguments');
    exit(2);
}
try {
    // Identical evidence files are not an update. This check precedes JSON
    // parsing and does not put any file path into the error response.
    $baselinepath = realpath($opts['baseline']);
    $currentpath = realpath($opts['current']);
    if ($baselinepath !== false && $currentpath !== false &&
            $baselinepath === $currentpath) {
        throw new \InvalidArgumentException('report_same_file');
    }
    $baseline = comparator::read_report($opts['baseline']);
    $current = comparator::read_report($opts['current']);
    $result = comparator::compare($baseline, $current, $strict,
        !empty($opts['confirm-same-company']));
} catch (\InvalidArgumentException $e) {
    $failure($e->getMessage());
    exit(2);
} catch (\Throwable $e) {
    $failure('comparison_execution_error');
    exit(2);
}
if ($format === 'json') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} else {
    echo "BMS24 RC30 | evidence comparison: " . strtoupper($result['comparison_gate']) . "\n";
    echo "Versions: " . $result['baseline_plugin_version'] . " -> " . $result['current_plugin_version'] . "\n";
    echo 'Installation: ' . ($result['installation_verified'] ? 'VERIFIED' : 'UNVERIFIED (legacy)') . "\n";
    echo 'Snapshot order: ' . ($result['chronology_verified'] ? 'VERIFIED' : 'UNVERIFIED (legacy)') . "\n";
    $population = $result['task_population_change'];
    echo "Tasks in scope: " . $population['before'] . " -> " . $population['after'] .
        " (change " . ($population['delta'] > 0 ? '+' : '') . $population['delta'] . ")\n";
    if ($population['review_required']) {
        echo "REVIEW REQUIRED: task population decreased; verify the same scope and legitimate deletions.\n";
    }
    foreach (['integrity_regressions'=>'New/worse data issues',
              'integrity_improvements'=>'Improved data issues',
              'index_regressions'=>'Missing indexes',
              'new_error_codes'=>'New error codes',
              'new_warning_codes'=>'New warning codes'] as $key => $label) {
        echo $label . ': ' . count($result[$key]) . "\n";
    }
    foreach ($result['integrity_regressions'] as $change) {
        echo 'REGRESSION ' . $change['check'] . ': ' . $change['before'] .
            ' -> ' . $change['after'] . ' (' . $change['severity'] . ")\n";
    }
    foreach ($result['integrity_improvements'] as $change) {
        echo 'IMPROVED ' . $change['check'] . ': ' . $change['before'] .
            ' -> ' . $change['after'] . "\n";
    }
    if ($result['index_regressions']) {
        echo 'MISSING INDEXES: ' . implode(', ', $result['index_regressions']) . "\n";
    }
    if ($result['new_error_codes']) {
        echo 'NEW ERRORS: ' . implode(', ', $result['new_error_codes']) . "\n";
    }
    if ($result['new_warning_codes']) {
        echo 'NEW WARNINGS: ' . implode(', ', $result['new_warning_codes']) . "\n";
    }
    foreach ($result['runtime_changes'] as $field => $change) {
        echo 'RUNTIME ' . $field . ': ' . ($change['before'] ?? 'unknown') .
            ' -> ' . ($change['after'] ?? 'unknown') . "\n";
    }
    echo "RC30 promotion: NOT AUTOMATIC. Manual acceptance required. No data changed.\n";
}
exit($result['comparison_gate'] === 'failed' ? 1 : 0);
