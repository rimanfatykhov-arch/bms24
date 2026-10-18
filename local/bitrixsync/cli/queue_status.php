<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$result = (new \local_bitrixsync\queue\queue_manager())
    ->status();

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);
