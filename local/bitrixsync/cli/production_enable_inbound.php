<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'dealid' => 3761,
        'stage' => 'C3:EXECUTING',
        'confirm' => '',
        'help' => false,
    ],
    ['h' => 'help']
);

if (!empty($options['help'])) {
    echo "Enable automatic v5 inbound only after the Bitrix event URL is configured.\n\n";
    echo "php local/bitrixsync/cli/production_enable_inbound.php \\\n";
    echo "  --dealid=3761 --stage='C3:EXECUTING' --confirm=ENABLE_INBOUND_3761\n";
    exit(0);
}

$dealid = (int)$options['dealid'];
$expected = 'ENABLE_INBOUND_' . $dealid;

if (!hash_equals($expected, (string)$options['confirm'])) {
    cli_error('Use --confirm=' . $expected);
}

$result = (
    new \local_bitrixsync\services\production_activation_service()
)->enable_inbound($dealid, (string)$options['stage']);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
