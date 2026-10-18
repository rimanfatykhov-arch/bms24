<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/result.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Execution Result');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Execution Result');

echo html_writer::start_tag('div', ['class' => 'box']);

$data = [
    'Deal' => '3761',
    'Run ID' => 'RUN-001',
    'Result' => 'WAITING',
    'Moodle Users' => '0',
    'Courses Assigned' => '0',
    'Corporate Licenses' => '0',
    'CRM Feedback' => 'PENDING',
    'Errors' => '0',
    'Event Log' => 'AVAILABLE',
];

foreach ($data as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
