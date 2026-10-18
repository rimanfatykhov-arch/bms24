<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Dashboard Metrics Real Data Binding\n";
echo "==============================================\n";

$result = [
    'dashboard' => (new \local_bitrixsync\dashboard\real_dashboard())->get(),
    'metrics' => (new \local_bitrixsync\metrics\real_metrics())->get(),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
