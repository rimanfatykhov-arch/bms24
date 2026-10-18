<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Moodle Real Enrolment Adapter\n";
echo "=========================================\n";

$result = (new \local_bitrixsync\adapter\moodle_enrolment_adapter())
    ->check([
        'deal_id' => 3761,
        'user_id' => 40,
        'courses' => [8,2,13],
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
