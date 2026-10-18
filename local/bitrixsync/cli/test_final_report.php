<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Final Execution Report\n";
echo "=================================\n";

$result = (new \local_bitrixsync\execution\final_report())
    ->build([
        'run_id' => 'RUN-TEST',
        'deal_id' => 3761,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
