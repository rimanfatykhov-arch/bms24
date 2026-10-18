<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/grouplib.php');

use local_kbagamification\local\leaderboard_manager;
use local_kbagamification\local\navigation_manager;

require_login();

$systemcontext = context_system::instance();
require_capability('local/kbagamification:viewleaderboards', $systemcontext);

if (!(bool) get_config('local_kbagamification', 'leaderboardsenabled')) {
    throw new moodle_exception('leaderboardsdisabled', 'local_kbagamification');
}

$courseid = optional_param('courseid', 0, PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$metric = leaderboard_manager::normalise_metric(optional_param('metric', 'xp', PARAM_ALPHA));
$canviewall = has_capability('local/kbagamification:viewallleaderboards', $systemcontext);

if ($canviewall) {
    $courses = $DB->get_records_select(
        'course',
        'id <> :siteid',
        ['siteid' => SITEID],
        'sortorder ASC, id ASC',
        'id,fullname,shortname,visible,groupmode'
    );
} else {
    $courses = enrol_get_users_courses($USER->id, true, 'id,fullname,shortname,visible');
}

if (!empty($courses) && (!$courseid || !isset($courses[$courseid]))) {
    $first = reset($courses);
    $courseid = (int) $first->id;
    $groupid = 0;
}

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/kbagamification/leaderboards.php', [
    'courseid' => $courseid,
    'groupid' => $groupid,
    'metric' => $metric,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('leaderboards', 'local_kbagamification'));
$PAGE->set_heading(get_string('leaderboards', 'local_kbagamification'));

if (empty($courses) || !$courseid) {
    echo $OUTPUT->header();
    echo navigation_manager::render_user_nav((int) $USER->id, 'leaderboards');
    echo $OUTPUT->heading(get_string('leaderboards', 'local_kbagamification'));
    echo $OUTPUT->notification(get_string('nocoursesleaderboard', 'local_kbagamification'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$coursecontext = context_course::instance($courseid);
if (!$canviewall) {
    if (!is_enrolled($coursecontext, $USER, '', true)) {
        throw new required_capability_exception($coursecontext, 'moodle/course:view', 'nopermissions', '');
    }
    require_capability('moodle/course:viewparticipants', $coursecontext);
}

// Respect Moodle course group mode. In separate-groups mode learners without
// accessallgroups must not receive a whole-course leaderboard containing other groups.
$canmanagegroups = $canviewall || has_capability('moodle/course:managegroups', $coursecontext);
$accessallgroups = $canmanagegroups || has_capability('moodle/site:accessallgroups', $coursecontext);
$groupmode = (int) $course->groupmode;
$canseeallgroups = $accessallgroups || $groupmode === VISIBLEGROUPS;
$groups = $canseeallgroups
    ? groups_get_all_groups($courseid, 0, 0, 'g.id,g.name')
    : groups_get_all_groups($courseid, $USER->id, 0, 'g.id,g.name');
$allowwholecourse = $accessallgroups || $groupmode !== SEPARATEGROUPS;
$selfonly = false;

if (!$allowwholecourse && $groupid === 0) {
    if (!empty($groups)) {
        $firstgroup = reset($groups);
        $groupid = (int) $firstgroup->id;
    } else {
        // An ungrouped learner in a separate-groups course sees only their own row.
        $selfonly = true;
    }
}
if ($groupid && !isset($groups[$groupid])) {
    if (!$allowwholecourse && !empty($groups)) {
        $firstgroup = reset($groups);
        $groupid = (int) $firstgroup->id;
    } else {
        $groupid = 0;
    }
}

$ranking = $selfonly
    ? leaderboard_manager::get_user_leaderboard([(int) $USER->id], $metric, 0)
    : leaderboard_manager::get_course_leaderboard($courseid, $groupid, $metric, 0);
$limit = (int) get_config('local_kbagamification', 'leaderboardlimit');
$limit = max(5, min(100, $limit ?: 20));
$toprows = array_slice($ranking, 0, $limit);

$currentrow = null;
foreach ($ranking as $row) {
    if ((int) $row['userid'] === (int) $USER->id) {
        $currentrow = $row;
        break;
    }
}

$showcurrentseparately = $currentrow && (int) $currentrow['rank'] > $limit;
$showcoins = (bool) get_config('local_kbagamification', 'leaderboardshowcoins');
$showteams = (bool) get_config('local_kbagamification', 'teamleaderboardsenabled') && $canmanagegroups && $groupid === 0;
$teamrows = $showteams ? leaderboard_manager::get_course_team_leaderboard($courseid, $limit) : [];
$summary = leaderboard_manager::get_summary_for_users(array_column($ranking, 'userid'));

// Course/group/metric selector.
$baseurl = new moodle_url('/local/kbagamification/leaderboards.php');
echo $OUTPUT->header();
echo navigation_manager::render_user_nav((int) $USER->id, 'leaderboards');
echo $OUTPUT->heading(get_string('leaderboards', 'local_kbagamification'));
echo html_writer::tag('p', get_string('leaderboardsintro', 'local_kbagamification'), ['class' => 'text-muted']);

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $baseurl->out(false), 'class' => 'kba-leaderboard-filter mb-4']);
echo html_writer::start_div('row');

$courseoptions = [];
foreach ($courses as $availablecourse) {
    $courseoptions[(int) $availablecourse->id] = format_string($availablecourse->fullname);
}
echo html_writer::start_div('col-md-5 mb-2');
echo html_writer::label(get_string('course'), 'kba-courseid', false, ['class' => 'font-weight-bold']);
echo html_writer::select($courseoptions, 'courseid', $courseid, false, ['id' => 'kba-courseid', 'class' => 'form-control']);
echo html_writer::end_div();

$groupoptions = [];
if ($selfonly) {
    $groupoptions[0] = get_string('onlyyou', 'local_kbagamification');
} else if ($allowwholecourse) {
    $groupoptions[0] = get_string('wholecourse', 'local_kbagamification');
}
foreach ($groups as $group) {
    $groupoptions[(int) $group->id] = format_string($group->name);
}
echo html_writer::start_div('col-md-3 mb-2');
echo html_writer::label(get_string('group'), 'kba-groupid', false, ['class' => 'font-weight-bold']);
echo html_writer::select($groupoptions, 'groupid', $groupid, false, ['id' => 'kba-groupid', 'class' => 'form-control']);
echo html_writer::end_div();

$metricoptions = [
    leaderboard_manager::METRIC_XP => get_string('metric_xp', 'local_kbagamification'),
    leaderboard_manager::METRIC_ACHIEVEMENTS => get_string('metric_achievements', 'local_kbagamification'),
];
echo html_writer::start_div('col-md-2 mb-2');
echo html_writer::label(get_string('rankingmetric', 'local_kbagamification'), 'kba-metric', false, ['class' => 'font-weight-bold']);
echo html_writer::select($metricoptions, 'metric', $metric, false, ['id' => 'kba-metric', 'class' => 'form-control']);
echo html_writer::end_div();

echo html_writer::start_div('col-md-2 mb-2 d-flex align-items-end');
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('showleaderboard', 'local_kbagamification'), 'class' => 'btn btn-primary btn-block']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_tag('form');

// Summary cards.
echo html_writer::start_div('row mb-4 local-kbagamification-leaderboard-summary');
$cards = [
    [get_string('participants', 'local_kbagamification'), $summary['participants']],
    [get_string('totalxp', 'local_kbagamification'), format_float($summary['totalxp'], 0)],
    [get_string('averagexp', 'local_kbagamification'), format_float($summary['averagexp'], 0)],
    [get_string('totalachievements', 'local_kbagamification'), format_float($summary['achievementcount'], 0)],
];
foreach ($cards as $card) {
    echo html_writer::start_div('col-sm-6 col-lg-3 mb-2');
    echo html_writer::start_div('card h-100');
    echo html_writer::start_div('card-body');
    echo html_writer::div(s($card[0]), 'text-muted');
    echo html_writer::div(s((string) $card[1]), 'h3 mb-0 font-weight-bold');
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo html_writer::end_div();

if (empty($toprows)) {
    echo $OUTPUT->notification(get_string('noleaderboardparticipants', 'local_kbagamification'), 'info');
} else {
    echo $OUTPUT->heading(get_string('participantleaderboard', 'local_kbagamification'), 3);
    $table = new html_table();
    $table->attributes['class'] = 'table table-striped kba-leaderboard-table';
    $table->head = [
        get_string('rank', 'local_kbagamification'),
        get_string('user'),
        get_string('xp', 'local_kbagamification'),
        get_string('currentlevel', 'local_kbagamification'),
        get_string('achievements', 'local_kbagamification'),
    ];
    if ($showcoins) {
        $table->head[] = get_string('coins', 'local_kbagamification');
    }

    $renderrow = static function(array $row) use ($showcoins, $USER, $courseid): array {
        $medal = '';
        if ((int) $row['rank'] === 1) {
            $medal = '🥇 ';
        } else if ((int) $row['rank'] === 2) {
            $medal = '🥈 ';
        } else if ((int) $row['rank'] === 3) {
            $medal = '🥉 ';
        }
        $profileurl = new moodle_url('/user/view.php', ['id' => $row['userid'], 'course' => $courseid]);
        $name = html_writer::link($profileurl, s($row['fullname']));
        if ((int) $row['userid'] === (int) $USER->id) {
            $name .= ' ' . html_writer::tag('span', get_string('you', 'local_kbagamification'), ['class' => 'badge badge-primary']);
        }
        $data = [
            $medal . (int) $row['rank'],
            $name,
            format_float((int) $row['xp'], 0),
            s($row['levelname']),
            format_float((int) $row['achievementcount'], 0),
        ];
        if ($showcoins) {
            $data[] = format_float((int) $row['coins'], 0);
        }
        return $data;
    };

    foreach ($toprows as $row) {
        $table->data[] = $renderrow($row);
    }
    echo html_writer::table($table);

    if ($showcurrentseparately) {
        echo html_writer::tag('p', get_string('yourposition', 'local_kbagamification', (int) $currentrow['rank']), ['class' => 'font-weight-bold mt-3']);
        $currenttable = new html_table();
        $currenttable->attributes['class'] = 'table table-bordered kba-leaderboard-current';
        $currenttable->head = $table->head;
        $currenttable->data[] = $renderrow($currentrow);
        echo html_writer::table($currenttable);
    }
}

if ($showteams && !empty($teamrows)) {
    echo $OUTPUT->heading(get_string('teamleaderboard', 'local_kbagamification'), 3);
    $teamtable = new html_table();
    $teamtable->attributes['class'] = 'table table-striped kba-team-leaderboard-table';
    $teamtable->head = [
        get_string('rank', 'local_kbagamification'),
        get_string('group'),
        get_string('participants', 'local_kbagamification'),
        get_string('totalxp', 'local_kbagamification'),
        get_string('averagexp', 'local_kbagamification'),
        get_string('totalachievements', 'local_kbagamification'),
    ];
    foreach ($teamrows as $row) {
        $medal = ((int) $row['rank'] === 1) ? '🥇 ' : (((int) $row['rank'] === 2) ? '🥈 ' : (((int) $row['rank'] === 3) ? '🥉 ' : ''));
        $teamtable->data[] = [
            $medal . (int) $row['rank'],
            s($row['groupname']),
            (int) $row['participants'],
            format_float((int) $row['totalxp'], 0),
            format_float((int) $row['averagexp'], 0),
            format_float((int) $row['achievementcount'], 0),
        ];
    }
    echo html_writer::table($teamtable);
}

echo $OUTPUT->footer();
