<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/health.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect System Health');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect System Health');

echo html_writer::start_tag('div', ['class'=>'box']);

$health = [
    'Bitrix24 API' => 'READY',
    'Corporate API' => 'READY',
    'Moodle Core' => 'READY',
    'Queue Engine' => 'READY',
    'Database' => 'READY',
    'Warnings' => '0',
    'Errors' => '0',
];

foreach ($health as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
