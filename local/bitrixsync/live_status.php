<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/live_status.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Live Execution Status');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Live Execution Status');

echo html_writer::start_tag('div', ['class'=>'box']);

$status = [
    'Run ID' => 'PENDING',
    'Deal' => '3761',
    'Status' => 'READY',
    'Started' => 'NO',
    'Moodle Actions' => 'PENDING',
    'Corporate Actions' => 'PENDING',
    'CRM Feedback' => 'PENDING',
    'Verification' => 'PENDING',
];

foreach ($status as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
