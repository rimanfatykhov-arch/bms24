<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'confirm' => '',
        'enable-inbound' => false,
        'enable-writeback' => false,
        'enable-timeline' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    cli_error(
        "Unknown options:\n  " .
        implode("\n  ", $unrecognized)
    );
}

if (!empty($options['help'])) {
    echo <<<HELP
Controlled activation of KBA Connect v5.

Requirements:
  - cached Production Readiness must exist
  - cached Production Readiness must be READY
  - --confirm=ENABLE_V5

Example:
  php local/bitrixsync/cli/activate.php \
    --confirm=ENABLE_V5 \
    --enable-writeback \
    --enable-timeline

Add --enable-inbound only after the Bitrix24 event is configured.

HELP;
    exit(0);
}

if ((string)$options['confirm'] !== 'ENABLE_V5') {
    cli_error(
        'Use --confirm=ENABLE_V5'
    );
}

$readiness = (
    new \local_bitrixsync\services\production_readiness_service()
)->get_cached();

if (!$readiness) {
    cli_error(
        'Production Readiness cache is empty. ' .
        'Run cli/readiness.php --refresh first.'
    );
}

if (empty($readiness['ready'])) {
    cli_error(
        'Production Readiness is NOT READY. ' .
        'Activation aborted.'
    );
}

set_config(
    'queue_enabled',
    1,
    'local_bitrixsync'
);

set_config(
    'crm_writeback_enable',
    !empty($options['enable-writeback'])
        ? 1 : 0,
    'local_bitrixsync'
);

set_config(
    'crm_timeline_enable',
    !empty($options['enable-timeline'])
        ? 1 : 0,
    'local_bitrixsync'
);

set_config(
    'v5_inbound_enabled',
    !empty($options['enable-inbound'])
        ? 1 : 0,
    'local_bitrixsync'
);

set_config(
    'engine_mode',
    'v5',
    'local_bitrixsync'
);

echo "KBA Connect v5 activated.\n";
echo "Queue: ON\n";
echo "CRM writeback: " .
    (!empty($options['enable-writeback'])
        ? 'ON'
        : 'OFF') . "\n";
echo "CRM timeline: " .
    (!empty($options['enable-timeline'])
        ? 'ON'
        : 'OFF') . "\n";
echo "v5 inbound: " .
    (!empty($options['enable-inbound'])
        ? 'ON'
        : 'OFF') . "\n";
echo "\nRollback command:\n";
echo "  php local/bitrixsync/cli/rollback.php --confirm=LEGACY\n";
