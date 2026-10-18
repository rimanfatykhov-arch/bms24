<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/_bootstrap.php');

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
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
KBA Connect status (cache-first)

Usage:
  php local/bitrixsync/cli/status.php
  php local/bitrixsync/cli/status.php --json

This command performs NO external Bitrix24 REST requests.

HELP;
    exit(0);
}

$dbman = $DB->get_manager();

$engine = (string)get_config(
    'local_bitrixsync',
    'engine_mode'
) ?: 'legacy';

$corporate = new \local_bitrixsync\services\corporate_bridge();
$cdiag = $corporate->diagnostic();

$fieldmanager = new \local_bitrixsync\services\crm_field_manager();
$crmready = $fieldmanager->is_ready(false);
$crmcachetime = $fieldmanager->get_cached_resolution_time();

$readiness = (
    new \local_bitrixsync\services\production_readiness_service()
)->get_cached();

$totalcourses = (int)$DB->count_records_select(
    'course',
    'id <> ?',
    [SITEID]
);

$mappedcourses = (int)$DB->count_records(
    'local_bitrixsync_productmap',
    ['active' => 1]
);

$pendingqueue = (int)$DB->count_records(
    'local_bitrixsync_syncqueue',
    ['status' => 'pending']
);

$failedqueue = (int)$DB->count_records(
    'local_bitrixsync_syncqueue',
    ['status' => 'failed']
);

$lastreal = $DB->get_record_sql(
    "SELECT *
       FROM {local_bitrixsync_dealrun}
      WHERE dryrun = 0
   ORDER BY id DESC",
    [],
    IGNORE_MULTIPLE
);

$data = [
    'release' => 'KBA Connect v5.0.13-alpha',
    'engine' => $engine,
    'v5_inbound_enabled' => (bool)get_config(
        'local_bitrixsync',
        'v5_inbound_enabled'
    ),
    'queue_enabled' => (bool)get_config(
        'local_bitrixsync',
        'queue_enabled'
    ),
    'crm_writeback_enabled' => (bool)get_config(
        'local_bitrixsync',
        'crm_writeback_enable'
    ),
    'crm_timeline_enabled' => (bool)get_config(
        'local_bitrixsync',
        'crm_timeline_enable'
    ),
    'bitrix_configured' =>
        trim((string)get_config(
            'local_bitrixsync',
            'bitrix_webhook_url'
        )) !== '',
    'catalog' => [
        'courses' => $totalcourses,
        'mapped' => $mappedcourses,
        'complete' => $totalcourses > 0 &&
            $mappedcourses >= $totalcourses,
    ],
    'corporate' => [
        'available' => !empty($cdiag['available']),
        'compatible' => !empty($cdiag['compatible']),
        'api_release' => (string)(
            $cdiag['contract']['api_release'] ?? '0'
        ),
        'writesafe' => !empty(
            $cdiag['status']['writesafe']
        ),
        'licenseavailable' => !empty(
            $cdiag['status']['licenseavailable']
        ),
    ],
    'crm_fields' => [
        'ready' => $crmready,
        'cache_time' => $crmcachetime,
    ],
    'queue' => [
        'pending' => $pendingqueue,
        'failed' => $failedqueue,
    ],
    'readiness_cache' => [
        'exists' => !empty($readiness),
        'ready' => !empty($readiness['ready']),
        'time' => (int)($readiness['time'] ?? 0),
    ],
    'last_real_run' => $lastreal
        ? [
            'id' => (int)$lastreal->id,
            'dealid' => (int)$lastreal->dealid,
            'status' => (string)$lastreal->status,
            'failed' => (int)$lastreal->failed,
            'timecreated' => (int)$lastreal->timecreated,
        ]
        : null,
];

if (!empty($options['json'])) {
    kba_cli_json($data);
    exit(0);
}

echo "KBA Connect v5 status\n";
echo "=====================\n";

kba_cli_status_line(
    'Engine',
    $engine === 'v5'
        ? 'KBA Connect v5'
        : 'Legacy v0.4.10'
);

kba_cli_status_line(
    'Bitrix REST configured',
    kba_cli_bool($data['bitrix_configured'])
);

kba_cli_status_line(
    'Moodle Master Catalog',
    $mappedcourses . ' / ' . $totalcourses
);

kba_cli_status_line(
    'Corporate API',
    !empty($data['corporate']['compatible'])
        ? 'v1 / ' . $data['corporate']['api_release']
        : 'NOT READY'
);

kba_cli_status_line(
    'Corporate writesafe',
    kba_cli_bool($data['corporate']['writesafe'])
);

kba_cli_status_line(
    'CRM fields cache',
    $crmready ? 'READY' : 'NOT READY'
);

kba_cli_status_line(
    'Queue',
    'pending=' . $pendingqueue .
    ', failed=' . $failedqueue
);

kba_cli_status_line(
    'CRM writeback',
    kba_cli_bool($data['crm_writeback_enabled'])
);

kba_cli_status_line(
    'CRM timeline',
    kba_cli_bool($data['crm_timeline_enabled'])
);

kba_cli_status_line(
    'v5 inbound',
    kba_cli_bool($data['v5_inbound_enabled'])
);

kba_cli_status_line(
    'Readiness cache',
    !empty($readiness)
        ? (!empty($readiness['ready'])
            ? 'READY'
            : 'NOT READY')
        : 'EMPTY'
);

if ($lastreal) {
    kba_cli_status_line(
        'Last real v5 Deal',
        '#' . (int)$lastreal->dealid .
        ' / ' . (string)$lastreal->status
    );
}

kba_cli_separator();

echo "This command did not call Bitrix24.\n";
