<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability(
    'local/bitrixsync:manage',
    context_system::instance()
);

$PAGE->set_url(
    new moodle_url('/local/bitrixsync/production.php')
);
$PAGE->set_context(context_system::instance());
$PAGE->set_title(
    get_string('production_readiness', 'local_bitrixsync')
);
$PAGE->set_heading(
    get_string('pluginname', 'local_bitrixsync')
);

$action = optional_param(
    'action',
    '',
    PARAM_ALPHA
);

$service =
    new \local_bitrixsync\services\production_readiness_service();

$error = null;
$message = null;

if ($action === 'refresh') {
    require_sesskey();

    try {
        $service->refresh();

        redirect(
            new moodle_url('/local/bitrixsync/production.php'),
            get_string(
                'production_refresh_complete',
                'local_bitrixsync'
            ),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$result = $service->get_cached();

echo $OUTPUT->header();
echo $OUTPUT->heading(
    get_string('production_readiness', 'local_bitrixsync')
);

echo $OUTPUT->notification(
    get_string('cli_tools_note', 'local_bitrixsync'),
    'info'
);

echo $OUTPUT->notification(
    get_string(
        'production_cached_notice',
        'local_bitrixsync'
    ),
    'info'
);

if ($error) {
    echo $OUTPUT->notification(
        s($error),
        'error'
    );
}

if (!$result) {
    echo $OUTPUT->notification(
        get_string(
            'production_cache_empty',
            'local_bitrixsync'
        ),
        'warning'
    );
} else {
    $age = max(
        0,
        time() - (int)($result['time'] ?? 0)
    );

    echo html_writer::div(
        get_string(
            'production_cache_age',
            'local_bitrixsync',
            (object)[
                'date' => userdate(
                    (int)($result['time'] ?? 0)
                ),
                'seconds' => $age,
            ]
        ),
        'mb-3'
    );

    if (!empty($result['ready'])) {
        echo $OUTPUT->notification(
            get_string(
                'production_ready',
                'local_bitrixsync'
            ),
            'success'
        );
    } else {
        echo $OUTPUT->notification(
            get_string(
                'production_not_ready',
                'local_bitrixsync'
            ),
            'warning'
        );
    }

    $table = new html_table();
    $table->head = [
        get_string(
            'diagnostic_item',
            'local_bitrixsync'
        ),
        get_string(
            'diagnostic_status',
            'local_bitrixsync'
        ),
        get_string(
            'details',
            'local_bitrixsync'
        ),
        get_string(
            'required',
            'local_bitrixsync'
        ),
    ];

    foreach (($result['checks'] ?? []) as $check) {
        $table->data[] = [
            s($check['name']),
            !empty($check['ok'])
                ? get_string(
                    'ok',
                    'local_bitrixsync'
                )
                : get_string(
                    'not_ready',
                    'local_bitrixsync'
                ),
            s((string)$check['detail']),
            !empty($check['required'])
                ? get_string('yes')
                : get_string('no'),
        ];
    }

    echo html_writer::table($table);
}

echo html_writer::link(
    new moodle_url(
        '/local/bitrixsync/production.php',
        [
            'action' => 'refresh',
            'sesskey' => sesskey(),
        ]
    ),
    get_string(
        'production_refresh',
        'local_bitrixsync'
    ),
    ['class' => 'btn btn-primary mr-2']
);

echo ' ';

echo html_writer::link(
    new moodle_url(
        '/local/bitrixsync/crm_fields.php'
    ),
    get_string(
        'crm_fields',
        'local_bitrixsync'
    ),
    ['class' => 'btn btn-secondary mr-2']
);

echo ' ';

echo html_writer::link(
    new moodle_url(
        '/admin/settings.php',
        ['section' => 'local_bitrixsync']
    ),
    get_string('settings'),
    ['class' => 'btn btn-secondary']
);

$inboundtoken = (string)get_config(
    'local_bitrixsync',
    'v5_inbound_token'
);

if ($inboundtoken !== '') {
    $endpoint = new moodle_url(
        '/local/bitrixsync/webhook_v5.php',
        ['key' => $inboundtoken]
    );

    echo $OUTPUT->heading(
        get_string(
            'v5_webhook_endpoint',
            'local_bitrixsync'
        ),
        3
    );

    echo html_writer::tag(
        'code',
        s($endpoint->out(false))
    );

    echo html_writer::div(
        get_string(
            'v5_webhook_endpoint_help',
            'local_bitrixsync'
        ),
        'mt-2 mb-3'
    );
}

echo $OUTPUT->footer();
