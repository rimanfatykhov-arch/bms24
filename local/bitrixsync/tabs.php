<?php
require(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/bitrixsync/tabs.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Center');

echo $OUTPUT->header();

echo html_writer::tag('h2', 'KBA Connect Center');

$tabs = [
    'Dashboard' => 'index.php',
    'Planner' => 'planner.php',
    'Approval' => 'approval.php',
    'Execution' => 'command_center.php',
    'Timeline' => 'timeline.php',
    'Metrics' => 'metrics.php',
    'SLA' => 'sla.php',
    'History' => 'history.php',
    'Diagnostics' => 'diagnostics.php',
    'Recovery' => 'recovery.php',
];

echo html_writer::start_tag('div', ['class'=>'tabs']);

foreach ($tabs as $name => $url) {
    echo html_writer::link(
        new moodle_url('/local/bitrixsync/'.$url),
        $name,
        ['class'=>'btn btn-secondary m-1']
    );
}

echo html_writer::end_tag('div');

echo html_writer::tag('hr', '');

echo html_writer::tag('h3', 'System Overview');

echo html_writer::tag('p', 'Deal: 3761');
echo html_writer::tag('p', 'Company: ООО АЛТРЕЙД');
echo html_writer::tag('p', 'Status: READY');

echo $OUTPUT->footer();
