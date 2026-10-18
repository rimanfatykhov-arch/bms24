<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect First Controlled Execution Run\n";
echo "=========================================\n";

$result = (new \local_bitrixsync\execution\controlled_run())
    ->start([
        'deal_id' => 3761,
        'approved' => true,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
