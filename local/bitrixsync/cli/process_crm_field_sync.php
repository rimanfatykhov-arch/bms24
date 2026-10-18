<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$result = (
    new \local_bitrixsync\services\crm_field_sync_service()
)->process_one();

echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . PHP_EOL;

exit(!empty($result['failed']) ? 2 : 0);
