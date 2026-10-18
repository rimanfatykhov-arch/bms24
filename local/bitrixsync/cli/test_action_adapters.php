<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Action Adapter Layer\n";
echo "===============================\n";

$result = [
    'moodle' => (new \local_bitrixsync\actions\moodle_action_adapter())
        ->plan([
            'user_id' => 40,
            'courses' => [8,2,13],
        ]),
    'corporate' => (new \local_bitrixsync\actions\corporate_action_adapter())
        ->plan([
            'corporate_id' => 1,
            'license' => true,
        ]),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
