<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'dealid' => 3761,
        'stage' => 'C3:EXECUTING',
        'confirm' => '',
        'skip-crm-provision' => false,
        'help' => false,
    ],
    ['h' => 'help']
);

if (!empty($options['help'])) {
    echo "Finalize production settings after a proven idempotent REAL controlled run.\n\n";
    echo "php local/bitrixsync/cli/production_finalize.php \\\n";
    echo "  --dealid=3761 --stage='C3:EXECUTING' --confirm=FINALIZE_3761\n";
    exit(0);
}

$dealid = (int)$options['dealid'];
$expected = 'FINALIZE_' . $dealid;

if (!hash_equals($expected, (string)$options['confirm'])) {
    cli_error('Use --confirm=' . $expected);
}

try {
    $service = new \local_bitrixsync\services\production_activation_service();

    $result = $service->finalize(
        $dealid,
        (string)$options['stage'],
        empty($options['skip-crm-provision'])
    );

    echo json_encode(
        $result,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;

    if (empty($result['crm']['ready'])) {
        exit(2);
    }
} catch (\Throwable $e) {
    echo json_encode([
        'ready' => false,
        'step' => 'production_finalize',
        'error' => $e->getMessage(),
        'next_command' =>
            'php local/bitrixsync/cli/bitrix_crm_rest_probe.php',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(2);
}
