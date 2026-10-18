<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$result = (new \local_bitrixsync\rules\database_rule_engine())
    ->match([
        'ID' => 3761,
        'STAGE_ID' => 'C3:EXECUTING'
    ]);

echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
