<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/queue_manager.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Queue Manager');

echo $OUTPUT->header();

echo html_writer::tag('h2','Queue Manager');

$manager = new \local_bitrixsync\queue\queue_manager();

foreach (['pending','processing','completed','failed'] as $state) {
    $result = $manager->status($state);
    echo html_writer::tag(
        'p',
        strtoupper($state).': '.$result['count']
    );
}

echo $OUTPUT->footer();
