<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect First Controlled Executor\n";
echo "====================================\n";

$result = (new \local_bitrixsync\executor\controlled_executor())
    ->execute([
        'deal_id' => 3761,
        'confirmed' => true,
        'actions' => [
            'courses' => [8,2,13],
            'license' => true,
        ],
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
