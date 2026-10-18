<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    ['dealid' => 3761, 'userid' => 0, 'help' => false],
    ['h' => 'help']
);

if (!empty($options['help'])) {
    echo "php local/bitrixsync/cli/production_dryrun.php --dealid=3761 [--userid=40]\n";
    exit(0);
}

$result = (new \local_bitrixsync\services\production_launch_service())->dry_run(
    (int)$options['dealid'],
    !empty($options['userid']) ? (int)$options['userid'] : null
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
