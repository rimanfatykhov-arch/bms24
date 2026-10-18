<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/command_center.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Command Center');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Command Center');

echo html_writer::start_tag('div', ['class'=>'box']);

$data = [
    'Deal' => '3761',
    'Run ID' => 'RUN-001',
    'Mode' => 'CONTROLLED TEST',
    'Status' => 'READY',
    'Execution Token' => 'READY',
    'Guard' => 'PASSED',
];

foreach ($data as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::tag('h3', 'Actions');

foreach ([
    'Start' => 'runner.php',
    'Planner' => 'planner.php',
    'Approval' => 'approval.php',
    'Monitor' => 'live_status.php',
    'Result' => 'result.php',
    'History' => 'history.php',
] as $label => $url) {
    echo html_writer::link(
        new moodle_url('/local/bitrixsync/' . $url),
        $label
    );
    echo html_writer::empty_tag('br');
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
