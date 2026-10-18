<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'confirm' => '',
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
Emergency rollback to Legacy.

Usage:
  php local/bitrixsync/cli/rollback.php --confirm=LEGACY

Actions:
  engine_mode = legacy
  v5_inbound_enabled = 0
  crm_writeback_enable = 0
  crm_timeline_enable = 0
  queue_enabled = 0

HELP;
    exit(0);
}

if ((string)$options['confirm'] !== 'LEGACY') {
    cli_error(
        'Use --confirm=LEGACY'
    );
}

set_config(
    'engine_mode',
    'legacy',
    'local_bitrixsync'
);

set_config(
    'v5_inbound_enabled',
    0,
    'local_bitrixsync'
);

set_config(
    'crm_writeback_enable',
    0,
    'local_bitrixsync'
);

set_config(
    'crm_timeline_enable',
    0,
    'local_bitrixsync'
);

set_config(
    'queue_enabled',
    0,
    'local_bitrixsync'
);

echo "KBA Connect rollback complete.\n";
echo "Engine: Legacy v0.4.10\n";
echo "v5 inbound: OFF\n";
echo "CRM writeback: OFF\n";
echo "CRM timeline: OFF\n";
echo "Queue: OFF\n";
