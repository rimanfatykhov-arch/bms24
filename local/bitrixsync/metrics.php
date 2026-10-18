<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/metrics.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Execution Metrics');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Execution Metrics');

echo html_writer::start_tag('div', ['class'=>'box']);

$metrics = [
    'Run ID' => 'RUN-001',
    'Total Time' => '00:02:15',
    'Moodle Time' => '00:00:40',
    'Corporate Time' => '00:00:35',
    'CRM Time' => '00:00:20',
    'Verification Time' => '00:00:15',
    'Result' => 'RUNNING',
];

foreach ($metrics as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
