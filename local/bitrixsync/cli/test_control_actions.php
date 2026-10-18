<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Execution Control Actions\n";
echo "====================================\n";

$service = new \local_bitrixsync\execution\control_actions();

echo json_encode([
    'start' => $service->start_test([
        'deal_id' => 3761,
        'approved' => true,
    ]),
    'rollback' => $service->rollback([
        'deal_id' => 3761,
    ]),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
