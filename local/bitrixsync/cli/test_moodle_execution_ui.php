<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Moodle Execution UI Layer\n";
echo "====================================\n";

$result = [
    'dashboard' => 'available',
    'deal_id' => 3761,
    'mode' => 'controlled_action',
    'writes' => false
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
