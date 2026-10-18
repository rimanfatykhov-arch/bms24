<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'dealid' => 3761,
        'stage' => 'C3:EXECUTING',
        'help' => false,
    ],
    ['h' => 'help']
);

$result = (
    new \local_bitrixsync\services\production_activation_service()
)->wizard_status(
    (int)$options['dealid'],
    (string)$options['stage']
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) . PHP_EOL;
