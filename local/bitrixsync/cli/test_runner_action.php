<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Runner Execution Action\n";
echo "==================================\n";

$result = (new \local_bitrixsync\execution\run_launcher())
    ->start(3761);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
