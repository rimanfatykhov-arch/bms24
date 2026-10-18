<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/notifications.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Notifications');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Notifications');

echo html_writer::start_tag('div', ['class'=>'box']);

$notifications = [
    ['type' => 'INFO', 'message' => 'Execution review required'],
    ['type' => 'SUCCESS', 'message' => 'Last controlled check completed'],
    ['type' => 'WARNING', 'message' => 'No active execution runs'],
];

foreach ($notifications as $notification) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $notification['type'] . ': ') .
        s($notification['message'])
    );
}

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
