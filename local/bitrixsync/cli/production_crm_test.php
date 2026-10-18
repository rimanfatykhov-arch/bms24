<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'dealid' => 3761,
        'confirm' => '',
        'help' => false,
    ],
    ['h' => 'help']
);

if (!empty($options['help'])) {
    echo "Idempotent REAL controlled run with CRM writeback/timeline enabled.\n\n";
    echo "php local/bitrixsync/cli/production_crm_test.php \\\n";
    echo "  --dealid=3761 --confirm=CRM_TEST_3761\n";
    exit(0);
}

$dealid = (int)$options['dealid'];
$expected = 'CRM_TEST_' . $dealid;

if (!hash_equals($expected, (string)$options['confirm'])) {
    cli_error('Use --confirm=' . $expected);
}

$result = (
    new \local_bitrixsync\services\production_activation_service()
)->crm_controlled_test($dealid);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

if (!empty($result['failed'])) {
    exit(3);
}
