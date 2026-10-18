<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\form\level_form;

$id = optional_param('id', 0, PARAM_INT);
require_login();
require_capability('local/kbagamification:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbagamification/level_edit.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('editlevel', 'local_kbagamification'));
$PAGE->set_heading(get_string('editlevel', 'local_kbagamification'));

$level = null;
if ($id) {
    $level = $DB->get_record('local_kbagam_levels', ['id' => $id], '*', MUST_EXIST);
}

$form = new level_form();
if ($level) {
    $form->set_data($level);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbagamification/levels.php'));
}
if ($data = $form->get_data()) {
    $now = time();
    $record = (object) [
        'name' => $data->name,
        'minxp' => (int) $data->minxp,
        'enabled' => empty($data->enabled) ? 0 : 1,
        'sortorder' => (int) $data->sortorder,
        'timemodified' => $now,
    ];
    if (!empty($data->id)) {
        $record->id = (int) $data->id;
        $DB->update_record('local_kbagam_levels', $record);
    } else {
        $record->timecreated = $now;
        $DB->insert_record('local_kbagam_levels', $record);
    }
    redirect(new moodle_url('/local/kbagamification/levels.php'), get_string('changessaved'));
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
