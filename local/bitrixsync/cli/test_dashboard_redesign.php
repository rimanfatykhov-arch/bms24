<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Admin Dashboard Redesign\n";
echo "===================================\n";

$result = (new \local_bitrixsync\ui\dashboard())->get();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
