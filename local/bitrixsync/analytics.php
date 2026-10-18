<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/analytics.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Execution Analytics');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Execution Analytics');

echo html_writer::start_tag('div', ['class' => 'box']);

$metrics = [
    'Total Runs' => '2',
    'SUCCESS' => '1',
    'FAILED' => '0',
    'ROLLBACK' => '0',
    'Average Execution Time' => '0 sec',
    'Moodle Actions' => '1',
    'Corporate Actions' => '1',
    'CRM Feedback' => '1',
];

foreach ($metrics as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
