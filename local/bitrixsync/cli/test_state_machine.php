<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Run State Machine\n";
echo "============================\n";

$engine = new \local_bitrixsync\execution\run_state_machine();

$result = [
    $engine->transition('READY', 'APPROVED'),
    $engine->transition('APPROVED', 'RUNNING'),
    $engine->transition('VERIFYING', 'COMPLETED'),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
