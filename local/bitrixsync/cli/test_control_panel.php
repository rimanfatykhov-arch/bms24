<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Execution Control Panel UI\n";
echo "=====================================\n";

echo json_encode([
    'deal_id' => 3761,
    'mode' => 'controlled_test',
    'confirmation' => 'approved',
    'token' => 'ready',
    'actions' => [
        'start' => true,
        'rollback' => true,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
