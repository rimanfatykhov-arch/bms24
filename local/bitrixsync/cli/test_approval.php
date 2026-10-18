<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Controlled Execution Approval\n";
echo "========================================\n";

$result = (new \local_bitrixsync\execution\approval())
    ->approve([
        'deal_id' => 3761,
        'approved' => true,
        'approved_by' => 'admin_test',
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
