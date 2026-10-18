<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Execution Report UI\n";
echo "==============================\n";

echo json_encode([
    'deal_id' => 3761,
    'company' => 'ООО АЛТРЕЙД',
    'courses' => [8,2,13],
    'corporate_id' => 1,
    'status' => 'PENDING',
    'verification' => 'READY',
    'rollback' => 'AVAILABLE',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
