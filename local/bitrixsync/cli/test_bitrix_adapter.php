<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$method = 'crm.deal.get';
$id = 0;

foreach ($argv as $arg) {
    if (strpos($arg, '--method=') === 0) {
        $method = substr($arg, 9);
    }
    if (strpos($arg, '--id=') === 0) {
        $id = (int)substr($arg, 5);
    }
}

echo "KBA Connect Adapter Execution Debug\n";
echo "=================================\n";

$result = (new \local_bitrixsync\debug\adapter_probe())
    ->inspect($method, ['ID' => $id]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
