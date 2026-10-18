<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Bitrix24 CRM REST Probe\n";
echo "===================================\n";

$result = (
    new \local_bitrixsync\services\crm_rest_probe_service()
)->run();

echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) . PHP_EOL;

exit(!empty($result['ready']) ? 0 : 2);
