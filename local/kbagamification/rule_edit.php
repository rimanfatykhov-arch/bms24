<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\form\rule_form;

$id = optional_param('id', 0, PARAM_INT);
require_login();
require_capability('local/kbagamification:manage', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbagamification/rule_edit.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('editrule', 'local_kbagamification'));
$PAGE->set_heading(get_string('editrule', 'local_kbagamification'));

$rule = null;
if ($id) {
    $rule = $DB->get_record('local_kbagam_rules', ['id' => $id], '*', MUST_EXIST);
}

$form = new rule_form();
if ($rule) {
    $form->set_data($rule);
}

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbagamification/rules.php'));
}

if ($data = $form->get_data()) {
    $now = time();
    $record = (object) [
        'name' => $data->name,
        'eventcode' => $data->eventcode,
        'enabled' => empty($data->enabled) ? 0 : 1,
        'xp' => (int) $data->xp,
        'coins' => (int) $data->coins,
        'conditionsjson' => null,
        'sortorder' => (int) $data->sortorder,
        'timemodified' => $now,
    ];
    if (!empty($data->id)) {
        $record->id = (int) $data->id;
        $DB->update_record('local_kbagam_rules', $record);
    } else {
        $record->timecreated = $now;
        $DB->insert_record('local_kbagam_rules', $record);
    }
    redirect(new moodle_url('/local/kbagamification/rules.php'), get_string('changessaved'));
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
