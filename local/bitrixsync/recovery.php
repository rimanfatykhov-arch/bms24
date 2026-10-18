<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/recovery.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Recovery Center');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Recovery Center');

echo html_writer::start_tag('div', ['class' => 'box']);

$data = [
    'Failed Runs' => '0',
    'Rollback Available' => 'YES',
    'Last Recovery' => 'NONE',
    'Recovery Status' => 'READY',
];

foreach ($data as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::tag('button', 'Rollback', ['type'=>'button']);
echo html_writer::tag('button', 'Retry Run', ['type'=>'button']);
echo html_writer::tag('button', 'View Error', ['type'=>'button']);

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
