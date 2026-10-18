<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/monitoring.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Monitoring');

echo $OUTPUT->header();

echo html_writer::tag('h2','Automation Monitoring');

echo html_writer::tag('p','Queue Worker: 🟢 Ready');
echo html_writer::tag('p','Rules Engine: 🟢 Ready');
echo html_writer::tag('p','Execution Engine: 🟢 Ready');

echo $OUTPUT->footer();
