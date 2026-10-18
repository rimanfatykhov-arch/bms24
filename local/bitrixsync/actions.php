<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/actions.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Portal Actions');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Portal Actions');

echo html_writer::start_tag('div', ['class' => 'box']);

$actions = [
    ['label' => 'Открыть план', 'url' => 'planner.php'],
    ['label' => 'Approval', 'url' => 'approval.php'],
    ['label' => 'Monitor', 'url' => 'live_status.php'],
    ['label' => 'Rollback', 'url' => 'recovery.php'],
];

foreach ($actions as $action) {
    echo html_writer::link(
        new moodle_url('/local/bitrixsync/' . $action['url']),
        $action['label']
    );
    echo html_writer::empty_tag('br');
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
