<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

echo $OUTPUT->header();
echo html_writer::tag('h2','Пользователи');
echo html_writer::tag('p','Связи Bitrix24 Contact ↔ Moodle User.');
echo $OUTPUT->footer();
