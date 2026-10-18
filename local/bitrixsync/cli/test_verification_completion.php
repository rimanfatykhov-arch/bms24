<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Verification Completion\n";
echo "==================================\n";

$result = [
    'verification' => (new \local_bitrixsync\verification\corporate_crm_verifier())
        ->check(['deal_id' => 3761]),
    'report' => (new \local_bitrixsync\verification\final_verification_report())
        ->build(['run_id' => 'RUN-001', 'deal_id' => 3761]),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
