<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Corporate API Discovery\n";
echo "==================================\n";

$result = (new \local_bitrixsync\debug\corporate_api_probe())
    ->inspect();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
