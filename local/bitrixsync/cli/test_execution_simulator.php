<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Controlled Execution Simulator\n";
echo "=========================================\n";

$result = (new \local_bitrixsync\execution\simulator())
    ->simulate([
        'deal_id' => 3761,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
