<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect First Test Execution Harness\n";
echo "=======================================\n";

$result = (new \local_bitrixsync\execution\test_harness())
    ->prepare([
        'deal_id' => 3761,
        'approved' => true,
        'guard_ok' => true,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
