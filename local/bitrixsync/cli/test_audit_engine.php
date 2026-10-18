<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Persistence Audit Engine\n";
echo "===================================\n";

$result = (new \local_bitrixsync\data\audit_repository())->summary();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
