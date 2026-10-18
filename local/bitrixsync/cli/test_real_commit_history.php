<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Real Database Commit History\n";
echo "=======================================\n";

$repo = new \local_bitrixsync\data\real_commit_repository();

$result = [
    $repo->save_run([
        'run_id' => 'RUN-001',
        'deal_id' => 3761,
    ]),
    $repo->save_event('RUN-001', 'EXECUTION_COMPLETED'),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
