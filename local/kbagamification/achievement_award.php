<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\form\achievement_award_form;
use local_kbagamification\local\achievement_manager;

$userid = optional_param('userid', 0, PARAM_INT);
require_login();
require_capability('local/kbagamification:awardachievement', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/kbagamification/achievement_award.php', $userid ? ['userid' => $userid] : []));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('awardachievement', 'local_kbagamification'));
$PAGE->set_heading(get_string('awardachievement', 'local_kbagamification'));

$form = new achievement_award_form(null, ['userid' => $userid]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbagamification/achievements.php'));
}
if ($data = $form->get_data()) {
    $ok = achievement_manager::award(
        (int) $data->achievementid,
        (int) $data->userid,
        'manual',
        0,
        (int) $USER->id,
        ['reason' => $data->reason]
    );
    redirect(
        new moodle_url('/local/kbagamification/index.php', ['userid' => (int) $data->userid]),
        $ok ? get_string('achievementawarded', 'local_kbagamification') : get_string('achievementalreadyawarded', 'local_kbagamification')
    );
}

echo $OUTPUT->header();
$form->display();
echo $OUTPUT->footer();
