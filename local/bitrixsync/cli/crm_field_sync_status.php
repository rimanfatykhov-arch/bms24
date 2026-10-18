<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$service = new \local_bitrixsync\services\crm_field_sync_service();

echo json_encode([
    'active' => $service->has_active_jobs(),
    'queue' => $service->queue_status(),
    'cache_time' => (
        new \local_bitrixsync\services\crm_field_manager()
    )->get_cached_resolution_time(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
