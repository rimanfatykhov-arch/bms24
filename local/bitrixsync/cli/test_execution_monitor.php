<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Execution Monitor UI\n";
echo "===============================\n";

echo json_encode([
    'deal_id' => 3761,
    'status' => 'READY',
    'steps' => [
        'audit' => 'READY',
        'validation' => 'READY',
        'approval' => 'READY',
        'moodle' => 'PENDING',
        'corporate' => 'PENDING',
        'crm' => 'PENDING',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
