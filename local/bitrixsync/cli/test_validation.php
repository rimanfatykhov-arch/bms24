<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Validation & Rollback Layer\n";
echo "======================================\n";

$result = [
    'validation' => (new \local_bitrixsync\validation\validator())
        ->validate([
            'courses' => [8,2,13],
            'corporate_id' => 1,
        ]),
    'rollback' => (new \local_bitrixsync\rollback\plan())
        ->build([
            'enrol_courses',
            'assign_license',
        ]),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
