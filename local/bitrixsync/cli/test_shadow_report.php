<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Shadow Report Engine\n";
echo "===============================\n";

$report = [
    'deal_id' => 3761,
    'company' => 'ООО АЛТРЕЙД',
    'courses' => [8,2,13],
    'corporate_id' => 1,
    'mode' => 'shadow_only',
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
