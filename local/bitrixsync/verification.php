<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/verification.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Execution Verification');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Execution Verification');

echo html_writer::start_tag('div', ['class'=>'box']);

$data = [
    'Deal' => '3761',
    'Run ID' => 'RUN-001',
    'Moodle Status' => 'PENDING',
    'Corporate Status' => 'PENDING',
    'CRM Status' => 'PENDING',
    'Verification' => 'READY',
    'Result' => 'WAITING',
    'Rollback' => 'AVAILABLE',
];

foreach ($data as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
