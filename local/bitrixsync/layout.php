<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/layout.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Portal Layout');

echo $OUTPUT->header();

echo html_writer::start_tag('div', ['class'=>'row']);

echo html_writer::start_tag('div', ['class'=>'col-md-3']);
echo html_writer::tag('h3', 'KBA Connect');
echo html_writer::tag('p', 'Dashboard');
echo html_writer::tag('p', 'Execution');
echo html_writer::tag('p', 'Operations');
echo html_writer::tag('p', 'System');
echo html_writer::end_tag('div');

echo html_writer::start_tag('div', ['class'=>'col-md-9']);
echo html_writer::tag('h2', 'KBA Connect Portal');
echo html_writer::tag('p', 'System Status: READY');
echo html_writer::tag('p', 'Deal: 3761');
echo html_writer::tag('p', 'Company: ООО АЛТРЕЙД');
echo html_writer::end_tag('div');

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
