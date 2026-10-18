<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_kbagamification\local\achievement_manager;

require_login();
admin_externalpage_setup('local_kbagamification_achievements');
require_capability('local/kbagamification:manage', context_system::instance());

$delete = optional_param('delete', 0, PARAM_INT);
$toggle = optional_param('toggle', 0, PARAM_INT);

if ($delete && confirm_sesskey()) {
    if ($DB->record_exists('local_kbagam_userach', ['achievementid' => $delete])) {
        redirect(new moodle_url('/local/kbagamification/achievements.php'),
            get_string('achievementcannotdelete', 'local_kbagamification'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $DB->delete_records('local_kbagam_achievements', ['id' => $delete]);
    redirect(new moodle_url('/local/kbagamification/achievements.php'), get_string('achievementdeleted', 'local_kbagamification'));
}

if ($toggle && confirm_sesskey()) {
    $achievement = $DB->get_record('local_kbagam_achievements', ['id' => $toggle], '*', MUST_EXIST);
    $achievement->enabled = $achievement->enabled ? 0 : 1;
    $achievement->timemodified = time();
    $DB->update_record('local_kbagam_achievements', $achievement);
    redirect(new moodle_url('/local/kbagamification/achievements.php'));
}

$achievements = $DB->get_records('local_kbagam_achievements', null, 'sortorder ASC, id ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('achievements', 'local_kbagamification'));
echo $OUTPUT->notification(get_string('achievementsnote', 'local_kbagamification'), 'info');
echo $OUTPUT->single_button(new moodle_url('/local/kbagamification/achievement_edit.php'),
    get_string('addachievement', 'local_kbagamification'), 'get');

$table = new html_table();
$table->head = [
    get_string('achievement', 'local_kbagamification'),
    get_string('rarity', 'local_kbagamification'),
    get_string('achievementcondition', 'local_kbagamification'),
    get_string('achievementreward', 'local_kbagamification'),
    get_string('status'),
    get_string('actions'),
];
foreach ($achievements as $achievement) {
    $condition = get_string('condition_' . $achievement->conditiontype, 'local_kbagamification');
    $condition .= ': ' . (int) $achievement->targetvalue;
    if ($achievement->conditiontype === 'specific_course_completed') {
        $course = $DB->get_record('course', ['id' => $achievement->courseid], 'id,fullname');
        $condition = $course ? format_string($course->fullname) : get_string('missingcourse', 'local_kbagamification');
    }

    $editurl = new moodle_url('/local/kbagamification/achievement_edit.php', ['id' => $achievement->id]);
    $toggleurl = new moodle_url('/local/kbagamification/achievements.php', ['toggle' => $achievement->id, 'sesskey' => sesskey()]);
    $deleteurl = new moodle_url('/local/kbagamification/achievements.php', ['delete' => $achievement->id, 'sesskey' => sesskey()]);
    $actions = html_writer::link($editurl, get_string('edit')) . ' · ' .
        html_writer::link($toggleurl, $achievement->enabled ? get_string('disable') : get_string('enable')) . ' · ' .
        html_writer::link($deleteurl, get_string('delete'));

    $title = achievement_manager::icon_glyph($achievement->icon) . ' ' . format_string($achievement->name);
    $table->data[] = [
        html_writer::tag('strong', $title) . html_writer::empty_tag('br') .
            html_writer::tag('span', format_text($achievement->description, FORMAT_PLAIN), ['class' => 'text-muted small']),
        get_string('rarity_' . $achievement->rarity, 'local_kbagamification'),
        $condition,
        '+' . (int) $achievement->rewardxp . ' XP / +' . (int) $achievement->rewardcoins . ' ' . get_string('coins', 'local_kbagamification'),
        $achievement->enabled ? get_string('enabled', 'local_kbagamification') : get_string('disabled', 'local_kbagamification'),
        $actions,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
