<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/sla.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect SLA Dashboard');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect SLA Dashboard');

foreach ([
    'Run ID' => 'RUN-001',
    'Target Time' => '00:05:00',
    'Current Time' => '00:02:15',
    'SLA Status' => 'OK',
    'Moodle' => 'OK',
    'Corporate' => 'OK',
    'CRM' => 'OK',
] as $k => $v) {
    echo html_writer::tag('p',
        html_writer::tag('strong', $k.': ').s($v)
    );
}

echo $OUTPUT->footer();
