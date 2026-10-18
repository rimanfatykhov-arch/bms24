<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Controlled Execute Engine\n";
echo "====================================\n";

$result = (new \local_bitrixsync\execution\controlled_execute_engine())
    ->execute([
        'deal_id' => 3761,
        'execution_token' => 'test-token',
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
