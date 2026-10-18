<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\form\award_form;
use local_kbagamification\local\engine;

$userid = optional_param('userid', 0, PARAM_INT);
require_login();
require_capability('local/kbagamification:award', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbagamification/award.php', $userid ? ['userid' => $userid] : []));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('manualaward', 'local_kbagamification'));
$PAGE->set_heading(get_string('manualaward', 'local_kbagamification'));

$form = new award_form(null, ['userid' => $userid]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbagamification/report.php'));
}
if ($data = $form->get_data()) {
    engine::adjust(
        (int) $data->userid,
        (int) $data->xp,
        (int) $data->coins,
        $data->reason,
        'manual',
        0,
        (int) $USER->id
    );
    redirect(
        new moodle_url('/local/kbagamification/index.php', ['userid' => (int) $data->userid]),
        get_string('awardapplied', 'local_kbagamification')
    );
}

echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('manualawardnote', 'local_kbagamification'), 'info');
$form->display();
echo $OUTPUT->footer();
