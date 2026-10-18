<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/rule_engine_manager.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Production Rules');

echo $OUTPUT->header();

echo html_writer::tag('h2','Production Rule Engine');

global $DB;

$rules = $DB->get_records('local_bitrixsync_rules');

echo html_writer::start_tag('table',['class'=>'generaltable']);
echo html_writer::tag('tr',
    html_writer::tag('th','Rule') .
    html_writer::tag('th','Active')
);

foreach ($rules as $rule) {
    echo html_writer::tag('tr',
        html_writer::tag('td',s($rule->name)) .
        html_writer::tag('td',$rule->active ? 'YES':'NO')
    );
}

echo html_writer::end_tag('table');

echo $OUTPUT->footer();
