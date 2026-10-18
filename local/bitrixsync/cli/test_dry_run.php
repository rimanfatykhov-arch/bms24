<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Controlled Execution Dry Run\n";
echo "=======================================\n";

$result = (new \local_bitrixsync\execution\journal())
    ->dry_run([
        'deal_id' => 3761,
        'courses' => [8,2,13],
        'corporate_id' => 1,
    ]);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
