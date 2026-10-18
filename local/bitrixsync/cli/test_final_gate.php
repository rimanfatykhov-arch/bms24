<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Controlled Execution Final Gate\n";
echo "==========================================\n";

$result = (new \local_bitrixsync\execution\final_gate())
    ->check([
        'deal_id' => 3761,
        'simulation_ok' => true,
        'approved' => true,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
