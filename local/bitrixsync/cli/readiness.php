<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'refresh' => false,
        'json' => false,
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
KBA Connect Production Readiness.

Cached only:
  php local/bitrixsync/cli/readiness.php

Refresh cache (performs short Bitrix24 REST checks):
  php local/bitrixsync/cli/readiness.php --refresh

JSON:
  php local/bitrixsync/cli/readiness.php --refresh --json

HELP;
    exit(0);
}

$service =
    new \local_bitrixsync\services\production_readiness_service();

try {
    $result = !empty($options['refresh'])
        ? $service->refresh()
        : $service->get_cached();
} catch (\Throwable $e) {
    cli_error($e->getMessage());
}

if (!$result) {
    echo "Production Readiness cache is empty.\n";
    echo "Run with --refresh.\n";
    exit(2);
}

if (!empty($options['json'])) {
    kba_cli_json($result);
    exit(!empty($result['ready']) ? 0 : 2);
}

echo "KBA Connect Production Readiness\n";
echo "================================\n";
echo "Cached at: " .
    userdate((int)$result['time']) .
    "\n\n";

foreach (($result['checks'] ?? []) as $check) {
    printf(
        "%-38s %-10s %s%s\n",
        (string)$check['name'],
        !empty($check['ok'])
            ? 'OK'
            : 'NOT READY',
        (string)$check['detail'],
        !empty($check['required'])
            ? ''
            : ' [optional]'
    );
}

echo "\n";
echo !empty($result['ready'])
    ? "PRODUCTION READY: YES\n"
    : "PRODUCTION READY: NO\n";

exit(!empty($result['ready']) ? 0 : 2);
