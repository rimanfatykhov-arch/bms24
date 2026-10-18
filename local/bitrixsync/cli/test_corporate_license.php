<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Corporate Controlled License Action\n";
echo "==============================================\n";

$result = (new \local_bitrixsync\adapter\corporate_controlled_license())
    ->execute([
        'deal_id' => 3761,
        'corporate_id' => 1,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
