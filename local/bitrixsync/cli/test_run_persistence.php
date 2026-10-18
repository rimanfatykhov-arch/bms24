<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Run Persistence Engine\n";
echo "=================================\n";

$service = new \local_bitrixsync\execution\persistence_run();

$result = [
    $service->create(3761, 'RUN-001'),
    $service->event('RUN-001', 'EXECUTION_STARTED'),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
