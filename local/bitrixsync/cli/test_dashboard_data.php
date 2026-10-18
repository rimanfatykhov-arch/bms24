<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo json_encode([
    'deal' => 3761,
    'company' => 'ООО АЛТРЕЙД',
    'courses' => [8,2,13],
    'corporate_id' => 1,
    'status' => 'READY FOR REVIEW'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
