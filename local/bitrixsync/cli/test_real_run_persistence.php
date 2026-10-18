<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Real Run Persistence\n";
echo "===============================\n";

$result = (new \local_bitrixsync\execution\real_run_persistence())
    ->start(3761, 'RUN-001');

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
