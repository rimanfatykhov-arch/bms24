<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_kbagamification\form\reward_form;

$id = optional_param('id', 0, PARAM_INT);
require_login();
admin_externalpage_setup('local_kbagamification_rewards');
require_capability('local/kbagamification:managecatalog', context_system::instance());

$record = null;
if ($id) {
    $record = $DB->get_record('local_kbagam_rewards', ['id' => $id], '*', MUST_EXIST);
}
$form = new reward_form();
if ($record) {
    $form->set_data($record);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbagamification/rewards.php'));
} else if ($data = $form->get_data()) {
    $now = time();
    $data->timemodified = $now;
    if (empty($data->id)) {
        $data->timecreated = $now;
        $DB->insert_record('local_kbagam_rewards', $data);
    } else {
        $DB->update_record('local_kbagam_rewards', $data);
    }
    redirect(new moodle_url('/local/kbagamification/rewards.php'), get_string('rewardsaved', 'local_kbagamification'));
}

$PAGE->set_title($id ? get_string('editreward', 'local_kbagamification') : get_string('addreward', 'local_kbagamification'));
$PAGE->set_heading(get_string('rewardcatalog', 'local_kbagamification'));
echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
