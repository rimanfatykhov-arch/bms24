<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo json_encode([
    'ui' => 'approval',
    'deal_id' => 3761,
    'status' => 'READY FOR APPROVAL',
    'execution' => 'blocked_until_confirm'
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
