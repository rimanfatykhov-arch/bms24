<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Database Lifecycle Commit Engine\n";
echo "============================================\n";

$result = [
    'running' => (new \local_bitrixsync\execution\lifecycle_commit())
        ->update('RUN-001', 'RUNNING'),
    'completed' => (new \local_bitrixsync\execution\lifecycle_commit())
        ->update('RUN-001', 'COMPLETED'),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
