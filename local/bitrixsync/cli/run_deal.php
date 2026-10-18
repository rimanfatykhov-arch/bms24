<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'dealid' => 0,
        'real' => false,
        'userid' => 0,
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
Run one Bitrix24 deal through KBA Connect v5.

Dry Run (default):
  php local/bitrixsync/cli/run_deal.php --dealid=123

Real controlled run:
  php local/bitrixsync/cli/run_deal.php --dealid=123 --real

Optional Moodle User override:
  --userid=40

The CLI tool can perform a controlled run while Engine Mode remains Legacy.

HELP;
    exit(0);
}

$dealid = (int)$options['dealid'];
if ($dealid <= 0) {
    cli_error('--dealid is required');
}

$real = !empty($options['real']);
$userid = (int)$options['userid'];

$flow =
    new \local_bitrixsync\services\deal_flow_service();

echo "KBA Connect Deal #{$dealid}\n";
echo "Mode: " .
    ($real ? 'REAL CONTROLLED RUN' : 'DRY RUN') .
    "\n\n";

$start = microtime(true);

try {
    $result = $flow->process(
        $dealid,
        !$real,
        true,
        $userid > 0 ? $userid : null
    );
} catch (\Throwable $e) {
    cli_error($e->getMessage());
}

$elapsed = microtime(true) - $start;

if (!empty($options['json'])) {
    kba_cli_json($result);
    exit(!empty($result['failed']) ? 2 : 0);
}

kba_cli_status_line(
    'Company',
    '#' .
    (int)$result['context']['bitrixcompanyid'] .
    ' ' .
    (string)$result['context']['companytitle']
);

kba_cli_status_line(
    'Contact',
    '#' .
    (int)$result['context']['bitrixcontactid']
);

kba_cli_status_line(
    'Moodle User',
    (string)(int)$result['userid']
);

kba_cli_status_line(
    'Product rows',
    (string)(int)$result['productrows']
);

kba_cli_status_line(
    'Resolved courses',
    (string)(int)$result['resolved']
);

kba_cli_status_line(
    'Enrolled',
    (string)(int)$result['enrolled']
);

kba_cli_status_line(
    'Already processed',
    (string)(int)$result['already']
);

kba_cli_status_line(
    'Failed',
    (string)(int)$result['failed']
);

kba_cli_status_line(
    'Unmapped',
    (string)count($result['unmapped'])
);

foreach (($result['items'] ?? []) as $item) {
    echo sprintf(
        "  Product #%d -> Course #%s -> %s%s\n",
        (int)$item['productid'],
        $item['courseid']
            ? (string)(int)$item['courseid']
            : '—',
        (string)$item['status'],
        !empty($item['error'])
            ? ' / ' . $item['error']
            : ''
    );
}

echo "\nElapsed: " .
    number_format($elapsed, 2) .
    " sec\n";

exit(!empty($result['failed']) ? 2 : 0);
