<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Controlled Real Execution Engine\n";
echo "===========================================\n";

$result = (new \local_bitrixsync\execution\real_execution_engine())
    ->prepare([
        'deal_id' => 3761,
        'approved' => true,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
