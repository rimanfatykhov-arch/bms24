<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$dealid = optional_param(
    'dealid',
    (int)(get_config('local_bitrixsync', 'controlled_test_deal_id') ?: 3761),
    PARAM_INT
);

$PAGE->set_url(new moodle_url('/local/bitrixsync/approval.php', ['dealid' => $dealid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect Approval');

if (optional_param('approve', 0, PARAM_BOOL)) {
    require_sesskey();
    redirect(new moodle_url('/local/bitrixsync/production_launch.php', [
        'dealid' => $dealid,
        'action' => 'preflight',
        'approved' => 1,
    ]));
}

echo $OUTPUT->header();
echo html_writer::tag('h2', 'KBA Connect Approval');
echo html_writer::tag('p', 'Deal: ' . (int)$dealid);
echo html_writer::tag('p', 'Следующий шаг — реальный Preflight перед controlled execution.');

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'dealid', 'value' => $dealid]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'name' => 'approve',
    'value' => 'Подтвердить и перейти к Production Preflight',
    'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
