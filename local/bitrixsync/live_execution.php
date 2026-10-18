<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/live_execution.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Live Execution');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Live Execution');

echo html_writer::start_tag('div', ['class'=>'box']);

$data = [
    'Deal' => '3761',
    'Run ID' => 'RUN-001',
    'Mode' => 'CONTROLLED TEST',
    'Progress' => '40%',
    'Start' => 'COMPLETED',
    'Moodle' => 'READY',
    'Corporate' => 'RUNNING',
    'CRM' => 'PENDING',
    'Verification' => 'WAITING',
];

foreach ($data as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
