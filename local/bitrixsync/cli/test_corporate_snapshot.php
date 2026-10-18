<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Corporate Real Snapshot\n";
echo "==================================\n";

$result = (new \local_bitrixsync\resolver\corporate_snapshot_resolver())
    ->resolve(1);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
