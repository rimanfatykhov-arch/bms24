<?php
// Read-only schema diagnostic. Run as the Moodle web-server OS user from the Moodle root:
// php local/kbacompany/cli/check_deviation_schema.php --json
// No DDL, repair, user impersonation or business-data queries are performed here.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognised) = cli_get_params(['help' => false, 'json' => false], ['h' => 'help']);
if ($unrecognised) {
    cli_error('Unknown option(s): ' . implode(', ', $unrecognised));
}
if ($options['help']) {
    echo "Read-only BMS24 deviation schema check.\n";
    echo "Usage: php local/kbacompany/cli/check_deviation_schema.php [--json]\n";
    echo "Exit 0 = required schema present; exit 2 = upgrade/repair required.\n";
    exit(0);
}
$issues = \local_kbacompany\local\deviation_schema::issues(true);
$plugin = new stdClass();
require(__DIR__ . '/../version.php');
$result = [
    'component' => 'local_kbacompany',
    'code_version' => $plugin->version,
    'installed_version' => (int)get_config('local_kbacompany', 'version'),
    'status' => $issues ? 'UPGRADE_REQUIRED' : 'SCHEMA_PRESENT',
    'issues' => $issues,
    'scope' => 'Read-only deviation schema metadata; not functional or production acceptance.',
];
if ($options['json']) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    echo 'BMS24: ' . $result['status'] . PHP_EOL;
    echo 'Code: ' . $result['code_version'] . '; installed: ' . $result['installed_version'] . PHP_EOL;
    foreach ($issues as $issue) {
        echo '- ' . $issue . PHP_EOL;
    }
    echo $result['scope'] . PHP_EOL;
}
exit($issues ? 2 : 0);
