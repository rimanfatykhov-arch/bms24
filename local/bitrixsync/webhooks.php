<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/webhooks.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect — Webhooks');

echo $OUTPUT->header();

echo html_writer::tag('h2','Webhooks');

echo html_writer::tag('p',
    'Inbound webhook включается только после Production Wizard проверки.'
);

echo html_writer::link(
    new moodle_url('/local/bitrixsync/setup_wizard.php'),
    'Открыть Production Wizard',
    ['class'=>'btn btn-danger']
);

echo $OUTPUT->footer();
