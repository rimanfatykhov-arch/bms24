<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect Real Execution Adapter Layer\n";
echo "========================================\n";

$result = [
    'moodle' => (new \local_bitrixsync\adapter\moodle_real_adapter())->prepare([
        'user_id' => 40,
        'courses' => [8,2,13],
    ]),
    'corporate' => (new \local_bitrixsync\adapter\corporate_real_adapter())->prepare([
        'corporate_id' => 1,
    ]),
    'crm' => (new \local_bitrixsync\adapter\crm_feedback_adapter())->prepare([
        'deal_id' => 3761,
    ]),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
