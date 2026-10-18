<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewgamification', $context);

$companyid = required_param('companyid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/gamification.php', [
    'companyid' => $companyid,
    'departmentid' => $departmentid,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('gamificationnav', 'local_kbacorporate'));
$PAGE->set_heading(get_string('gamificationnav', 'local_kbacorporate'));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/engagement.css', ['v' => 2026081800]));

// v0.14.1: protect the engagement UI from stale Moodle language caches after an upgrade.
// Existing language strings remain the primary source; fallbacks are used only when a newly
// added string is not yet present in the current string cache.
$engagementstring = static function(string $id, string $ru, string $en): string {
    $manager = get_string_manager();
    if ($manager->string_exists($id, 'local_kbacorporate')) {
        return get_string($id, 'local_kbacorporate');
    }
    return current_language() === 'ru' ? $ru : $en;
};

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')
    ),
    'kbacorp-backlink'
);
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('gamificationpagedesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter(
    $companyid,
    $USER->id,
    $departmentid,
    '/local/kbacorporate/gamification.php'
);

if (!\local_kbacorporate\local\gamification::is_enabled()) {
    echo $OUTPUT->notification(get_string('gamificationdisabled', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

if (!\local_kbacorporate\local\gamification::is_available()) {
    echo $OUTPUT->notification(get_string('gamificationunavailable', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$snapshot = \local_kbacorporate\local\gamification::get_company_snapshot(
    $visibleids,
    \local_kbacorporate\local\gamification::leaderboard_limit(),
    'xp'
);
if (!$snapshot) {
    echo $OUTPUT->notification(get_string('gamificationapierror', 'local_kbacorporate'), 'notifyproblem');
    echo $OUTPUT->footer();
    exit;
}

$summary = $snapshot['summary'];

echo html_writer::start_div('kbacorp-summary kbacorp-gamification-kpis');
$cards = [
    [get_string('gamificationparticipants', 'local_kbacorporate'), (int)$summary['participants']],
    [get_string('gamificationtotalxp', 'local_kbacorporate'), (int)$summary['totalxp']],
    [get_string('gamificationaveragexp', 'local_kbacorporate'), (int)$summary['averagexp']],
    [get_string('gamificationachievements', 'local_kbacorporate'), (int)$summary['achievementcount']],
    [get_string('gamificationavgachievements', 'local_kbacorporate'), s((string)$summary['averageachievements'])],
];
if (\local_kbacorporate\local\gamification::show_coins()) {
    $cards[] = [get_string('gamificationtotalcoins', 'local_kbacorporate'), (int)$summary['totalcoins']];
}
foreach ($cards as $card) {
    echo html_writer::start_div('kbacorp-card');
    echo html_writer::div(s((string)$card[1]), 'value');
    echo html_writer::div(s($card[0]), 'label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Latest achievements across the visible team.
$latestachievements = $snapshot['latestachievements'] ?? [];
if ($latestachievements) {
    usort($latestachievements, static function(array $a, array $b): int {
        return ((int)$b['timeawarded']) <=> ((int)$a['timeawarded']);
    });
    $latestachievements = array_slice($latestachievements, 0, 6);
    echo $OUTPUT->heading($engagementstring('gamificationlatestteam', 'Последние достижения команды', 'Latest team achievements'), 3);
    echo html_writer::start_div('kbacorp-gamification-latest-grid');
    foreach ($latestachievements as $achievement) {
        $url = new moodle_url('/local/kbacorporate/employee.php', [
            'companyid' => $companyid,
            'userid' => (int)$achievement['userid'],
        ]);
        echo html_writer::start_tag('a', [
            'href' => $url->out(false),
            'class' => 'kbacorp-gamification-latest-card',
        ]);
        echo html_writer::span(s((string)$achievement['icon']), 'kbacorp-gamification-latest-icon');
        echo html_writer::start_div('kbacorp-gamification-latest-body');
        echo html_writer::span(s((string)$achievement['fullname']), 'kbacorp-gamification-latest-user');
        echo html_writer::tag('strong', s((string)$achievement['name']));
        echo html_writer::span(userdate((int)$achievement['timeawarded'], get_string('strftimedateshort', 'langconfig')),
            'kbacorp-gamification-latest-date');
        echo html_writer::end_div();
        echo html_writer::span('→', 'kbacorp-gamification-latest-arrow');
        echo html_writer::end_tag('a');
    }
    echo html_writer::end_div();
}

// Visual top learners.
if (\local_kbacorporate\local\gamification::show_leaderboard() && !empty($snapshot['leaderboard'])) {
    echo $OUTPUT->heading($engagementstring('gamificationtoplearners', 'Лидеры обучения', 'Learning leaders'), 3);
    echo html_writer::start_div('kbacorp-gamification-top-grid');
    foreach (array_slice($snapshot['leaderboard'], 0, 3) as $row) {
        $url = new moodle_url('/local/kbacorporate/employee.php', [
            'companyid' => $companyid,
            'userid' => (int)$row['userid'],
        ]);
        echo html_writer::start_tag('a', [
            'href' => $url->out(false),
            'class' => 'kbacorp-gamification-top-card',
        ]);
        echo html_writer::div('#' . (int)$row['rank'], 'kbacorp-gamification-rank');
        echo html_writer::tag('strong', s((string)$row['fullname']), ['class' => 'kbacorp-gamification-top-name']);
        echo html_writer::div(s((string)$row['levelname']), 'kbacorp-gamification-top-level');
        echo html_writer::start_div('kbacorp-gamification-top-stats');
        echo html_writer::span((int)$row['xp'] . ' XP');
        echo html_writer::span((int)$row['achievementcount'] . ' ' . $engagementstring('gamificationachievements_short', 'достиж.', 'achiev.'));
        echo html_writer::end_div();
        echo html_writer::end_tag('a');
    }
    echo html_writer::end_div();
}

// Level distribution.
echo $OUTPUT->heading(get_string('gamificationlevelsdistribution', 'local_kbacorporate'), 3);
if (empty($summary['leveldistribution'])) {
    echo html_writer::div(get_string('gamificationnodata', 'local_kbacorporate'), 'kbacorp-muted');
} else {
    $leveltable = new html_table();
    $leveltable->attributes['class'] = 'generaltable kbacorp-report kbacorp-gamification-levels';
    $leveltable->head = [
        get_string('gamificationlevel', 'local_kbacorporate'),
        get_string('employees', 'local_kbacorporate'),
        get_string('gamificationshare', 'local_kbacorporate'),
    ];
    foreach ($summary['leveldistribution'] as $level) {
        $count = (int)$level['count'];
        $share = (int)$summary['participants'] > 0
            ? round(($count / (int)$summary['participants']) * 100, 1)
            : 0;
        $leveltable->data[] = [
            s((string)$level['levelname']),
            $count,
            s((string)$share . '%'),
        ];
    }
    echo html_writer::table($leveltable);
}

// Top leaderboard. The company/department employee scope was already resolved before the API call.
if (\local_kbacorporate\local\gamification::show_leaderboard()) {
    echo $OUTPUT->heading(get_string('gamificationleaderboard', 'local_kbacorporate'), 3);
    if (empty($snapshot['leaderboard'])) {
        echo html_writer::div(get_string('gamificationnodata', 'local_kbacorporate'), 'kbacorp-muted');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'generaltable kbacorp-report kbacorp-gamification-leaderboard';
        $table->head = [
            get_string('gamificationrank', 'local_kbacorporate'),
            get_string('fullname', 'local_kbacorporate'),
            get_string('gamificationlevel', 'local_kbacorporate'),
            get_string('gamificationxp', 'local_kbacorporate'),
            get_string('gamificationachievements', 'local_kbacorporate'),
        ];
        if (\local_kbacorporate\local\gamification::show_coins()) {
            $table->head[] = get_string('gamificationcoins', 'local_kbacorporate');
        }

        foreach ($snapshot['leaderboard'] as $row) {
            $employeelink = html_writer::link(
                new moodle_url('/local/kbacorporate/employee.php', [
                    'companyid' => $companyid,
                    'userid' => (int)$row['userid'],
                ]),
                s((string)$row['fullname'])
            );
            $datarow = [
                (int)$row['rank'],
                $employeelink,
                s((string)$row['levelname']),
                (int)$row['xp'],
                (int)$row['achievementcount'],
            ];
            if (\local_kbacorporate\local\gamification::show_coins()) {
                $datarow[] = (int)$row['coins'];
            }
            $table->data[] = $datarow;
        }
        echo html_writer::table($table);
    }
} else {
    echo $OUTPUT->notification(get_string('gamificationleaderboardhidden', 'local_kbacorporate'), 'info');
}

// Full employee index. Use an unlimited API result only for displaying individual summaries;
// the adapter still receives the already-authorised corporate employee IDs.
$allsnapshot = \local_kbacorporate\local\gamification::get_company_snapshot($visibleids, 0, 'xp');
if ($allsnapshot && !empty($allsnapshot['leaderboard'])) {
    echo $OUTPUT->heading(get_string('gamificationemployees', 'local_kbacorporate'), 3);
    $employeetable = new html_table();
    $employeetable->attributes['class'] = 'generaltable kbacorp-report kbacorp-gamification-employees';
    $employeetable->head = [
        get_string('fullname', 'local_kbacorporate'),
        get_string('gamificationlevel', 'local_kbacorporate'),
        get_string('gamificationxp', 'local_kbacorporate'),
        get_string('gamificationachievements', 'local_kbacorporate'),
        get_string('actions', 'local_kbacorporate'),
    ];
    if (\local_kbacorporate\local\gamification::show_coins()) {
        array_splice($employeetable->head, 4, 0, [get_string('gamificationcoins', 'local_kbacorporate')]);
    }
    foreach ($allsnapshot['leaderboard'] as $row) {
        $url = new moodle_url('/local/kbacorporate/employee.php', [
            'companyid' => $companyid,
            'userid' => (int)$row['userid'],
        ]);
        $datarow = [
            html_writer::link($url, s((string)$row['fullname'])),
            s((string)$row['levelname']),
            (int)$row['xp'],
            (int)$row['achievementcount'],
        ];
        if (\local_kbacorporate\local\gamification::show_coins()) {
            $datarow[] = (int)$row['coins'];
        }
        $datarow[] = html_writer::link($url, get_string('gamificationviewachievements', 'local_kbacorporate'));
        $employeetable->data[] = $datarow;
    }
    echo html_writer::table($employeetable);
}

echo $OUTPUT->footer();
