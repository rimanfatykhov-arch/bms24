<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
admin_externalpage_setup('local_kbagamification_rules');
require_capability('local/kbagamification:manage', context_system::instance());

$delete = optional_param('delete', 0, PARAM_INT);
$toggle = optional_param('toggle', 0, PARAM_INT);

if ($delete && confirm_sesskey()) {
    $DB->delete_records('local_kbagam_rules', ['id' => $delete]);
    redirect(new moodle_url('/local/kbagamification/rules.php'), get_string('ruledeleted', 'local_kbagamification'));
}

if ($toggle && confirm_sesskey()) {
    $rule = $DB->get_record('local_kbagam_rules', ['id' => $toggle], '*', MUST_EXIST);
    $rule->enabled = $rule->enabled ? 0 : 1;
    $rule->timemodified = time();
    $DB->update_record('local_kbagam_rules', $rule);
    redirect(new moodle_url('/local/kbagamification/rules.php'));
}

$rules = $DB->get_records('local_kbagam_rules', null, 'sortorder ASC, id ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rules', 'local_kbagamification'));
echo $OUTPUT->notification(get_string('rulescompletionnote', 'local_kbagamification'), 'info');
echo $OUTPUT->single_button(new moodle_url('/local/kbagamification/rule_edit.php'), get_string('addrule', 'local_kbagamification'), 'get');

$table = new html_table();
$table->head = [
    get_string('rulename', 'local_kbagamification'),
    get_string('eventtype', 'local_kbagamification'),
    get_string('xp', 'local_kbagamification'),
    get_string('coins', 'local_kbagamification'),
    get_string('status'),
    get_string('actions'),
];

foreach ($rules as $rule) {
    $eventlabel = get_string('eventcode_' . $rule->eventcode, 'local_kbagamification');
    $toggleurl = new moodle_url('/local/kbagamification/rules.php', ['toggle' => $rule->id, 'sesskey' => sesskey()]);
    $editurl = new moodle_url('/local/kbagamification/rule_edit.php', ['id' => $rule->id]);
    $deleteurl = new moodle_url('/local/kbagamification/rules.php', ['delete' => $rule->id, 'sesskey' => sesskey()]);
    $actions = html_writer::link($editurl, get_string('edit')) . ' · ' .
        html_writer::link($toggleurl, $rule->enabled ? get_string('disable') : get_string('enable')) . ' · ' .
        html_writer::link($deleteurl, get_string('delete'));

    $table->data[] = [
        format_string($rule->name),
        $eventlabel,
        (int) $rule->xp,
        (int) $rule->coins,
        $rule->enabled ? get_string('enabled', 'local_kbagamification') : get_string('disabled', 'local_kbagamification'),
        $actions,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
