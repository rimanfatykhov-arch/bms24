<?php
require(__DIR__ . '/../../config.php');
require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

echo $OUTPUT->header();
echo html_writer::tag('h2','Automation Rules');
echo html_writer::tag('p','Правила: стадия Bitrix24 → действия KBA Connect.');
echo $OUTPUT->footer();
