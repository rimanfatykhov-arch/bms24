<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Execution Confirmation Gate\n";
echo "======================================\n";

$result = (new \local_bitrixsync\gate\confirmation_gate())
    ->confirm([
        'deal_id' => 3761,
        'confirmed_by' => 'admin_test',
        'actions' => [
            'enrol_courses' => [8,2,13],
        ],
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
