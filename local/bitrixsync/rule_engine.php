<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/rule_engine.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Rule Engine');

echo $OUTPUT->header();

echo html_writer::tag('h2','Automation Rule Engine');

echo html_writer::tag(
    'p',
    'Правило: Bitrix24 Stage → KBA Connect actions'
);

echo html_writer::tag(
    'pre',
    s(json_encode(
        (new \local_bitrixsync\rules\rule_test_service())->test(3761),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    ))
);

echo $OUTPUT->footer();
