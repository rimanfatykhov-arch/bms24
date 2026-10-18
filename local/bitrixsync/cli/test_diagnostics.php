<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Diagnostics Center\n";
echo "=============================\n";

$result = (new \local_bitrixsync\health\diagnostics())->run();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
