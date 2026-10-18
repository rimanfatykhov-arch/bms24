<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect CRM Controlled Feedback Action\n";
echo "==========================================\n";

$result = (new \local_bitrixsync\adapter\crm_controlled_feedback())
    ->execute([
        'deal_id' => 3761,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
