<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'dealid' => 3761,
        'userid' => 0,
        'confirm' => '',
        'help' => false,
    ],
    ['h' => 'help']
);

if (!empty($options['help'])) {
    echo "REAL controlled execution.\n\n";
    echo "php local/bitrixsync/cli/production_execute.php \\\n";
    echo "  --dealid=3761 --confirm=EXECUTE_3761 [--userid=40]\n";
    exit(0);
}

$dealid = (int)$options['dealid'];

$result = (new \local_bitrixsync\services\production_launch_service())->execute(
    $dealid,
    (string)$options['confirm'],
    !empty($options['userid']) ? (int)$options['userid'] : null
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (!empty($result['failed'])) {
    exit(3);
}
