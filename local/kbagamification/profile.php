<?php
require_once(__DIR__ . '/../../config.php');

use local_kbagamification\local\achievement_manager;
use local_kbagamification\local\profile_manager;
use local_kbagamification\local\navigation_manager;

$userid = optional_param('userid', $USER->id, PARAM_INT);
$section = optional_param('section', 'profile', PARAM_ALPHA);
$currentnav = $section === 'achievements' ? 'achievements' : 'profile';
require_login();

$systemcontext = context_system::instance();
if ($userid === (int) $USER->id) {
    require_capability('local/kbagamification:viewown', $systemcontext);
} else {
    require_capability('local/kbagamification:viewany', $systemcontext);
}

$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/kbagamification/profile.php', ['userid' => $userid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('learningprofile', 'local_kbagamification'));
$PAGE->set_heading(fullname($user));

// Backfill eligible achievements for learners with historical Moodle completion data.
achievement_manager::evaluate_user($userid, 'profile_view', 0);

$viewdata = profile_manager::get_profile_data($userid);
$viewdata['navigation'] = navigation_manager::get_user_nav_data($userid, $currentnav);

// Theme-friendly profile picture rendered by Moodle, without direct file URL assumptions.
$userpicture = new user_picture($user);
$userpicture->size = 100;
$viewdata['userpicture'] = $userpicture->get_url($PAGE)->out(false);

// Safe fallback URL for legacy links and external integrations.
$viewdata['profileurl'] = (new moodle_url('/local/kbagamification/profile.php', ['userid' => $userid]))->out(false);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_kbagamification/profile', $viewdata);
echo $OUTPUT->footer();
