<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\form\achievement_form;

$id = optional_param('id', 0, PARAM_INT);
require_login();
require_capability('local/kbagamification:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbagamification/achievement_edit.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('editachievement', 'local_kbagamification'));
$PAGE->set_heading(get_string('editachievement', 'local_kbagamification'));

$achievement = null;
if ($id) {
    $achievement = $DB->get_record('local_kbagam_achievements', ['id' => $id], '*', MUST_EXIST);
}

$form = new achievement_form();
if ($achievement) {
    $form->set_data($achievement);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbagamification/achievements.php'));
}
if ($data = $form->get_data()) {
    $now = time();
    $record = (object) [
        'name' => $data->name,
        'description' => $data->description,
        'icon' => $data->icon,
        'rarity' => $data->rarity,
        'conditiontype' => $data->conditiontype,
        'targetvalue' => (int) $data->targetvalue,
        'courseid' => $data->conditiontype === 'specific_course_completed' ? (int) $data->courseid : 0,
        'rewardxp' => (int) $data->rewardxp,
        'rewardcoins' => (int) $data->rewardcoins,
        'enabled' => empty($data->enabled) ? 0 : 1,
        'sortorder' => (int) $data->sortorder,
        'timemodified' => $now,
    ];
    if (!empty($data->id)) {
        $record->id = (int) $data->id;
        $DB->update_record('local_kbagam_achievements', $record);
    } else {
        $record->timecreated = $now;
        $DB->insert_record('local_kbagam_achievements', $record);
    }
    redirect(new moodle_url('/local/kbagamification/achievements.php'), get_string('changessaved'));
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
