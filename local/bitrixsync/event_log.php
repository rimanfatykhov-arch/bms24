<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/event_log.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Execution Event Log');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Execution Event Log');

echo html_writer::start_tag('div', ['class'=>'box']);

$events = [
    ['time' => '18:40:01', 'event' => 'APPROVED', 'actor' => 'admin'],
    ['time' => '18:40:05', 'event' => 'STARTED', 'actor' => 'system'],
    ['time' => '18:40:08', 'event' => 'MOODLE', 'actor' => 'pending'],
    ['time' => '18:40:10', 'event' => 'CORPORATE', 'actor' => 'pending'],
    ['time' => '18:40:12', 'event' => 'VERIFY', 'actor' => 'pending'],
];

foreach ($events as $event) {
    echo html_writer::tag(
        'p',
        s($event['time']) . ' | ' . s($event['event']) . ' | ' . s($event['actor'])
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
