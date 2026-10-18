<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Idempotency Engine\n";
echo "=============================\n";

$operation = [
    'deal_id' => 3761,
    'course_id' => 8,
    'user_id' => 40,
];

$result = (new \local_bitrixsync\idempotency\engine())
    ->check($operation);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
