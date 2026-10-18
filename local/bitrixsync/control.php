<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/control.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Execution Control Panel');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Execution Control Panel');

echo html_writer::start_tag('div', ['class' => 'box']);

$data = [
    'Deal' => '3761',
    'Run ID' => 'PENDING',
    'Confirmation' => 'APPROVED',
    'Execution Token' => 'READY',
    'Mode' => 'CONTROLLED TEST',
    'Status' => 'READY',
];

foreach ($data as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::tag('button', 'Запустить тест', ['type'=>'button']);
echo html_writer::tag('button', 'Rollback', ['type'=>'button']);

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
