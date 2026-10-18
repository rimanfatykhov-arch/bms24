<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url(
    '/local/bitrixsync/bitrix_rest_diagnostics.php'
));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string(
    'bitrix_rest_diagnostics',
    'local_bitrixsync'
));
$PAGE->set_heading(get_string(
    'bitrix_rest_diagnostics',
    'local_bitrixsync'
));

$result = (
    new \local_bitrixsync\services\crm_rest_probe_service()
)->run();

echo $OUTPUT->header();
echo html_writer::tag(
    'h2',
    get_string('bitrix_rest_diagnostics', 'local_bitrixsync')
);

echo html_writer::tag(
    'p',
    get_string('bitrix_rest_diagnostics_desc', 'local_bitrixsync')
);

echo html_writer::tag(
    'pre',
    s(json_encode(
        $result,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    ))
);

if (!empty($result['ready'])) {
    echo $OUTPUT->notification(
        get_string(
            'bitrix_rest_diagnostics_ok',
            'local_bitrixsync'
        ),
        'success'
    );
} else {
    echo $OUTPUT->notification(
        get_string(
            'bitrix_rest_diagnostics_failed',
            'local_bitrixsync'
        ),
        'error'
    );
}

echo $OUTPUT->footer();
