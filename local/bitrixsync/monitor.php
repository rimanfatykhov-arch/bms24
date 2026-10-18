<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/monitor.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Execution Monitor');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Execution Monitor');

echo html_writer::start_tag('div', ['class'=>'box']);

$steps = [
    'Audit' => 'READY',
    'Validation' => 'READY',
    'Approval' => 'READY',
    'Moodle Actions' => 'PENDING',
    'Corporate License' => 'PENDING',
    'CRM Feedback' => 'PENDING',
];

echo html_writer::tag('p', '<strong>Deal:</strong> 3761');

foreach ($steps as $name => $status) {
    echo html_writer::tag('p',
        html_writer::tag('strong', $name . ': ') . s($status)
    );
}

echo html_writer::tag('p',
    html_writer::tag('strong', 'Overall Status: ') . 'READY'
);

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
