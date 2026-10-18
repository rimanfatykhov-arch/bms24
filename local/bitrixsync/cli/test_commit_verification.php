<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Execution Commit Verification Engine\n";
echo "===============================================\n";

$result = [
    'commit' => (new \local_bitrixsync\execution\commit_engine())->commit([
        'run_id' => 'RUN-TEST',
        'deal_id' => 3761,
    ]),
    'verification' => (new \local_bitrixsync\verification\execution_verifier())->verify([
        'run_id' => 'RUN-TEST',
        'deal_id' => 3761,
    ]),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
