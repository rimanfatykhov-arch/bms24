<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'refresh' => false,
        'help' => false,
    ],
    ['h' => 'help']
);

$service = new \local_bitrixsync\services\bitrix_funnel_service();

if (!empty($options['refresh'])) {
    $snapshot = $service->scan();
} else {
    $snapshot = $service->get_snapshot();
}

echo json_encode([
    'snapshot_time' => $service->snapshot_time(),
    'selected_stage_ids' => $service->selected_stage_ids(),
    'funnels' => $snapshot,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
