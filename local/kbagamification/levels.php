<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
admin_externalpage_setup('local_kbagamification_levels');
require_capability('local/kbagamification:manage', context_system::instance());

$delete = optional_param('delete', 0, PARAM_INT);
$toggle = optional_param('toggle', 0, PARAM_INT);

if ($delete && confirm_sesskey()) {
    $DB->delete_records('local_kbagam_levels', ['id' => $delete]);
    redirect(new moodle_url('/local/kbagamification/levels.php'), get_string('leveldeleted', 'local_kbagamification'));
}
if ($toggle && confirm_sesskey()) {
    $level = $DB->get_record('local_kbagam_levels', ['id' => $toggle], '*', MUST_EXIST);
    $level->enabled = $level->enabled ? 0 : 1;
    $level->timemodified = time();
    $DB->update_record('local_kbagam_levels', $level);
    redirect(new moodle_url('/local/kbagamification/levels.php'));
}

$levels = $DB->get_records('local_kbagam_levels', null, 'minxp ASC, sortorder ASC, id ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('levels', 'local_kbagamification'));
echo $OUTPUT->single_button(new moodle_url('/local/kbagamification/level_edit.php'), get_string('addlevel', 'local_kbagamification'), 'get');

$table = new html_table();
$table->head = [get_string('levelname', 'local_kbagamification'), get_string('minxp', 'local_kbagamification'), get_string('status'), get_string('actions')];
foreach ($levels as $level) {
    $editurl = new moodle_url('/local/kbagamification/level_edit.php', ['id' => $level->id]);
    $toggleurl = new moodle_url('/local/kbagamification/levels.php', ['toggle' => $level->id, 'sesskey' => sesskey()]);
    $deleteurl = new moodle_url('/local/kbagamification/levels.php', ['delete' => $level->id, 'sesskey' => sesskey()]);
    $actions = html_writer::link($editurl, get_string('edit')) . ' · ' .
        html_writer::link($toggleurl, $level->enabled ? get_string('disable') : get_string('enable')) . ' · ' .
        html_writer::link($deleteurl, get_string('delete'));
    $table->data[] = [
        format_string($level->name),
        (int) $level->minxp,
        $level->enabled ? get_string('enabled', 'local_kbagamification') : get_string('disabled', 'local_kbagamification'),
        $actions,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
