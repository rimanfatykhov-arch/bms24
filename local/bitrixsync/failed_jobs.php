<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

echo $OUTPUT->header();

echo html_writer::tag('h2','Failed Jobs Center');

echo html_writer::tag(
    'p',
    'Управление ошибками очереди, повторными попытками и закрытием задач.'
);

echo $OUTPUT->footer();
