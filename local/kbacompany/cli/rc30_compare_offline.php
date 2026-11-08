<?php
/**
 * BMS24 build2026101871: offline, read-only comparison of RC30 evidence files.
 * No Moodle config.php, MySQL, network, or writable application state required.
 *
 * php local/kbacompany/cli/rc30_compare_offline.php \
 *   --baseline=before.json --current=after.json --format=json --strict
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(2);
}
// These three services contain only pure report validation and SQL definitions.
// MOODLE_INTERNAL satisfies their standard direct-file-access guard, but no
// Moodle bootstrap, connection, globals or core functions are invoked here.
defined('MOODLE_INTERNAL') || define('MOODLE_INTERNAL', true);
require_once(__DIR__ . '/../classes/local/task_rc30_integrity_service.php');
require_once(__DIR__ . '/../classes/local/task_rc30_acceptance_service.php');
require_once(__DIR__ . '/../classes/local/task_rc30_report_comparison_service.php');

use local_kbacompany\local\task_rc30_report_comparison_service as comparison;

$options = [
    'baseline'=>'', 'current'=>'', 'format'=>'text',
    'strict'=>false, 'confirm-same-company'=>false, 'help'=>false,
];
$invalid = false;
$seen = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--strict' || $arg === '--confirm-same-company' ||
            $arg === '--help' || $arg === '-h') {
        $name = $arg === '-h' ? 'help' : substr($arg, 2);
        if (isset($seen[$name])) { $invalid = true; }
        $seen[$name] = true;
        $options[$name] = true;
        continue;
    }
    if (preg_match('/^--(baseline|current|format)=(.*)$/Ds', $arg, $match)) {
        // No duplicated options: ambiguity can hide an operator typo.
        $name = $match[1];
        if (isset($seen[$name])) { $invalid = true; }
        $seen[$name] = true;
        $options[$name] = $match[2];
        continue;
    }
    $invalid = true;
}
if ($options['help'] && !$invalid) {
    echo "BMS24 RC30 offline evidence comparison (read-only, no Moodle or database)\n\n"
       . "  --baseline=PATH          Baseline acceptance JSON\n"
       . "  --current=PATH           Current acceptance JSON\n"
       . "  --format=text|json       Output format (default text)\n"
       . "  --strict                 Treat warnings as blocking\n"
       . "  --confirm-same-company   Confirm company for pre-1870 company reports\n"
       . "  --help                   Show usage\n\n"
       . "Only local evidence files are read. No application data are changed.\n";
    exit(0);
}
$format = (string)$options['format'];
$strict = $options['strict'];
$knownerrors = [
    'invalid_arguments', 'unsupported_format', 'report_file_unavailable',
    'report_invalid_json', 'report_invalid_schema', 'report_scope_mismatch',
    'report_company_unverified', 'report_checks_missing', 'report_checks_changed',
    'report_summary_mismatch', 'report_version_rollback',
    'report_installation_mismatch', 'report_time_rollback', 'report_same_file',
    'report_duplicate_snapshot', 'comparison_execution_error',
];
$emitfailure = static function(string $code) use ($format, $strict, $knownerrors): void {
    if (!in_array($code, $knownerrors, true)) {
        $code = 'comparison_execution_error';
    }
    if ($format === 'json') {
        echo json_encode([
            'schema_version'=>1,
            'comparison_complete'=>false,
            'read_only'=>true,
            'rc30_promotion_approved'=>false,
            'strict'=>$strict,
            'comparison_gate'=>'failed',
            'errors'=>[$code],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    } else {
        fwrite(STDERR, 'RC30 offline comparison: ' . $code . "\n");
    }
};
if (!in_array($format, ['text', 'json'], true)) {
    $emitfailure('unsupported_format');
    exit(2);
}
if ($invalid || $options['baseline'] === '' || $options['current'] === '') {
    $emitfailure('invalid_arguments');
    exit(2);
}
try {
    $baselinepath = realpath($options['baseline']);
    $currentpath = realpath($options['current']);
    if ($baselinepath !== false && $currentpath !== false &&
            $baselinepath === $currentpath) {
        throw new \InvalidArgumentException('report_same_file');
    }
    $baseline = comparison::read_report($options['baseline']);
    $current = comparison::read_report($options['current']);
    // A copied snapshot isn't evidence of two separate validation runs.
    // Reject equivalent JSON even when paths or whitespace differ.
    if ($baseline === $current) {
        throw new \InvalidArgumentException('report_duplicate_snapshot');
    }
    $result = comparison::compare($baseline, $current, $strict,
        $options['confirm-same-company']);
} catch (\InvalidArgumentException $e) {
    $emitfailure($e->getMessage());
    exit(2);
} catch (\Throwable $e) {
    $emitfailure('comparison_execution_error');
    exit(2);
}
if ($format === 'json') {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} else {
    echo 'BMS24 RC30 offline comparison: ' . strtoupper($result['comparison_gate']) . "\n";
    echo 'Versions: ' . $result['baseline_plugin_version'] . ' -> ' .
        $result['current_plugin_version'] . "\n";
    echo 'Installation: ' . ($result['installation_verified'] ? 'VERIFIED' : 'UNVERIFIED') . "\n";
    echo 'Chronology: ' . ($result['chronology_verified'] ? 'VERIFIED' : 'UNVERIFIED') . "\n";
    $population = $result['task_population_change'];
    echo 'Tasks: ' . $population['before'] . ' -> ' . $population['after'] .
        ' (delta ' . $population['delta'] . ")\n";
    if ($population['review_required']) {
        echo "WARNING: task count decreased; check the reason.\n";
    }
    foreach (['index_regressions', 'integrity_regressions',
              'new_error_codes', 'new_warning_codes'] as $key) {
        echo $key . ': ' . count($result[$key]) . "\n";
    }
    foreach ($result['provenance_warnings'] as $warning) {
        echo 'PROVENANCE WARNING: ' . $warning . "\n";
    }
    echo "RC30 promotion: NOT AUTOMATIC. Manual acceptance required. No data changed.\n";
}
exit($result['comparison_gate'] === 'failed' ? 1 : 0);
