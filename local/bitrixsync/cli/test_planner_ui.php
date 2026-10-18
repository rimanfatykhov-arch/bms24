<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Execution Planner UI\n";
echo "===============================\n";

$result = (new \local_bitrixsync\ui\execution_planner())->get();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
