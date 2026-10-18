<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'run' => false,
        'limit' => 10,
        'json' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    cli_error(
        "Unknown options:\n  " .
        implode("\n  ", $unrecognized)
    );
}

if (!empty($options['help'])) {
    echo <<<HELP
KBA Connect queue.

Inspect:
  php local/bitrixsync/cli/queue.php

Process:
  php local/bitrixsync/cli/queue.php --run --limit=10

HELP;
    exit(0);
}

$limit = max(
    1,
    min(100, (int)$options['limit'])
);

if (!empty($options['run'])) {
    $manager =
        new \local_bitrixsync\services\sync_manager();

    $stats = $manager->run_batch($limit);

    if (!empty($options['json'])) {
        kba_cli_json($stats);
    } else {
        echo "Processed: {$stats['processed']}\n";
        echo "Completed: {$stats['completed']}\n";
        echo "Failed: {$stats['failed']}\n";
    }

    exit($stats['failed'] > 0 ? 2 : 0);
}

$counts = [];
foreach (
    ['pending', 'processing', 'completed', 'failed']
    as $status
) {
    $counts[$status] = (int)$DB->count_records(
        'local_bitrixsync_syncqueue',
        ['status' => $status]
    );
}

if (!empty($options['json'])) {
    kba_cli_json($counts);
    exit(0);
}

echo "KBA Connect queue\n";
echo "=================\n";
foreach ($counts as $status => $count) {
    kba_cli_status_line(
        ucfirst($status),
        (string)$count
    );
}
