<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

echo $OUTPUT->header();

echo html_writer::tag('h2','SLA Monitoring');

echo html_writer::tag('p','0-30 sec: Normal');
echo html_writer::tag('p','30-120 sec: Warning');
echo html_writer::tag('p','120+ sec: Critical');

echo $OUTPUT->footer();
