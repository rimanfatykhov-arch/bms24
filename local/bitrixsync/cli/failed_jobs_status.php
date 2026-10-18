<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$result = (new \local_bitrixsync\monitoring\failed_jobs_manager())
    ->summary();

echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
