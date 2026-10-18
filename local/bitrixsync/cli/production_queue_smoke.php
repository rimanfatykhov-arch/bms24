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

$dealid = (int)$options['dealid'];
$expected = 'QUEUE_' . $dealid;

if (!hash_equals($expected, (string)$options['confirm'])) {
    cli_error('Use --confirm=' . $expected);
}

$result = (
    new \local_bitrixsync\services\production_activation_service()
)->queue_smoke($dealid);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

exit(!empty($result['stats']['failed']) ? 3 : 0);
