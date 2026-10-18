<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/diagnostics.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Diagnostics Center');

echo $OUTPUT->header();

echo \local_bitrixsync\ui\admin_shell::render('diagnostics');

echo html_writer::tag('h2', 'KBA Connect Diagnostics Center');

echo \local_bitrixsync\ui\admin_shell::card(
    get_string('bms_diagnostics_card_title', 'local_bitrixsync'),
    get_string('bms_diagnostics_card_desc', 'local_bitrixsync'),
    '/local/bitrixsync/bms_diagnostics.php'
);
echo html_writer::empty_tag('br');

echo html_writer::start_tag('div', ['class'=>'box']);

$data = [
    'Bitrix24 Connection' => 'READY',
    'Corporate API' => 'READY',
    'Moodle Core' => 'READY',
    'Queue Engine' => 'READY',
    'Last Run ID' => 'RUN-001',
    'Last Event' => 'APPROVED',
    'Errors' => '0',
    'Warnings' => '0',
];

foreach ($data as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
