<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Portal Layout Framework\n";
echo "==================================\n";

$result = (new \local_bitrixsync\ui\layout_framework())->get();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
