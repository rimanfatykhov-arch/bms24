<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'entity' => '',
        'refresh-only' => false,
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
Provision or refresh KBA Connect Bitrix24 CRM fields.

Usage:
  php local/bitrixsync/cli/provision_crm.php --entity=company
  php local/bitrixsync/cli/provision_crm.php --entity=contact
  php local/bitrixsync/cli/provision_crm.php --entity=deal

Read-only refresh:
  php local/bitrixsync/cli/provision_crm.php --entity=company --refresh-only

Allowed entity values:
  company
  contact
  deal

HELP;
    exit(0);
}

$entity = trim((string)$options['entity']);

if (!in_array(
    $entity,
    ['company', 'contact', 'deal'],
    true
)) {
    cli_error(
        '--entity must be one of: company, contact, deal'
    );
}

$manager =
    new \local_bitrixsync\services\crm_field_manager();

$refreshonly = !empty($options['refresh-only']);

echo "KBA Connect CRM fields: {$entity}\n";
echo "Mode: " .
    ($refreshonly ? 'REFRESH ONLY' : 'PROVISION') .
    "\n\n";

$start = microtime(true);

try {
    $fields = $refreshonly
        ? $manager->resolve_entity($entity, false)
        : $manager->provision_entity($entity);

    $elapsed = microtime(true) - $start;

    $ready = true;
    foreach ($fields as $key => $field) {
        $isready = !empty($field['ready']);
        if (!$isready) {
            $ready = false;
        }

        printf(
            "%-28s %-7s %s\n",
            $key,
            $isready ? 'OK' : 'MISSING',
            (string)($field['field_name'] ?? '')
        );
    }

    echo "\nElapsed: " .
        number_format($elapsed, 2) .
        " sec\n";

    if (!empty($options['json'])) {
        kba_cli_json([
            'entity' => $entity,
            'ready' => $ready,
            'elapsed' => $elapsed,
            'fields' => $fields,
        ]);
    }

    exit($ready ? 0 : 2);

} catch (\Throwable $e) {
    cli_error(
        $e->getMessage(),
        1
    );
}
