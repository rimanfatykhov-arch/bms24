<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

$manager = new \local_bitrixsync\services\crm_field_manager();
$sync = new \local_bitrixsync\services\crm_field_sync_service();

echo json_encode(
    [
        'legacy_fields' =>
            $manager->legacy_field_summary(),
        'current_resolution' =>
            $manager->get_cached_resolution(),
        'queue' =>
            $sync->queue_status(),
        'stale_recovered' =>
            $sync->recover_stale(),
    ],
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) . PHP_EOL;
