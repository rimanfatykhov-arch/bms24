<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

echo "KBA Connect History Timeline Real Data Binding\n";
echo "==============================================\n";

$result = [
    'history' => (new \local_bitrixsync\history\real_history())->get([
        'run_id' => 'RUN-001',
        'deal_id' => 3761,
    ]),
    'timeline' => (new \local_bitrixsync\history\real_timeline())->get('RUN-001'),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
