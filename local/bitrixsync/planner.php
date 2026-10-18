<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/planner.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Execution Planner');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Execution Planner');

echo html_writer::start_tag('div', ['class' => 'box']);

$data = [
    'Deal' => '3761',
    'Company' => 'ООО АЛТРЕЙД',
    'Courses' => '8, 2, 13',
    'Corporate ID' => '1',
    'Actions' => 'Moodle Enrollment, License Allocation, CRM Feedback',
    'Status' => 'READY FOR APPROVAL',
];

foreach ($data as $key => $value) {
    echo html_writer::tag(
        'p',
        html_writer::tag('strong', $key . ': ') . s($value)
    );
}

echo html_writer::link(
    new moodle_url('/local/bitrixsync/approval.php'),
    'Перейти к Approval'
);

echo html_writer::end_tag('div');

echo $OUTPUT->footer();
