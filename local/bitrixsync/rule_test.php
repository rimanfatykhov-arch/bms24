<?php
require(__DIR__ . '/../../config.php');
require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

echo $OUTPUT->header();
echo html_writer::tag('h2','Rule Test Mode');
echo html_writer::tag('p','Проверка правила перед REAL запуском.');
echo $OUTPUT->footer();
