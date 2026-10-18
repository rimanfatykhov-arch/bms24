<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewemployees', $context);
$companyid = required_param('companyid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/employees.php', ['companyid' => $companyid, 'departmentid' => $departmentid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('employeesnav', 'local_kbacorporate'));
$PAGE->set_heading(get_string('employeesnav', 'local_kbacorporate'));

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$employees = \local_kbacorporate\local\report::get_employees($companyid, $visibleids);
$rows = \local_kbacorporate\local\report::get_rows($companyid, $visibleids);
$departmentnames = \local_kbacorporate\local\departments::get_department_names_for_users($companyid, $visibleids);
$gamificationrows = [];
if (has_capability('local/kbacorporate:viewgamification', $context) &&
        \local_kbacorporate\local\gamification::is_enabled() &&
        \local_kbacorporate\local\gamification::is_available()) {
    $snapshot = \local_kbacorporate\local\gamification::get_company_snapshot($visibleids, 0, 'xp');
    if ($snapshot) {
        foreach ($snapshot['leaderboard'] as $gamificationrow) {
            $gamificationrows[(int)$gamificationrow['userid']] = $gamificationrow;
        }
    }
}
$byuser = [];
foreach ($rows as $row) {
    if (!isset($byuser[$row->userid])) {
        $byuser[$row->userid] = [];
    }
    $byuser[$row->userid][] = $row;
}

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]), '← ' . get_string('backtodashboard', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('employeespagedesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid, '/local/kbacorporate/employees.php');

if (!$employees) {
    echo $OUTPUT->notification(get_string('noemployees', 'local_kbacorporate'), 'info');
    if (has_capability('local/kbacorporate:managemembers', $context)) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/members.php', ['companyid' => $companyid]), get_string('addemployees', 'local_kbacorporate'), 'get');
    }
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('fullname', 'local_kbacorporate'),
    get_string('position', 'local_kbacorporate'),
    get_string('department', 'local_kbacorporate'),
    get_string('email', 'local_kbacorporate'),
    get_string('coursescount', 'local_kbacorporate'),
    get_string('training', 'local_kbacorporate'),
    get_string('completed', 'local_kbacorporate'),
    get_string('avgprogress', 'local_kbacorporate'),
];
if ($gamificationrows) {
    $table->head[] = get_string('gamificationlevel', 'local_kbacorporate');
    $table->head[] = get_string('gamificationxp', 'local_kbacorporate');
    $table->head[] = get_string('gamificationachievements', 'local_kbacorporate');
}
$table->head[] = get_string('lastaccess', 'local_kbacorporate');

foreach ($employees as $employee) {
    $userrows = isset($byuser[$employee->id]) ? $byuser[$employee->id] : [];
    $coursecount = count($userrows);
    $training = 0;
    $completed = 0;
    $progressvalues = [];
    $lastaccess = 0;
    foreach ($userrows as $row) {
        if ($row->status === 'inprogress' || $row->status === 'completed') {
            $training++;
        }
        if ($row->status === 'completed') {
            $completed++;
        }
        if ($row->progress !== null) {
            $progressvalues[] = $row->progress;
        }
        if ($row->lastaccess > $lastaccess) {
            $lastaccess = $row->lastaccess;
        }
    }
    $avg = $progressvalues ? round(array_sum($progressvalues) / count($progressvalues), 1) . '%' : '—';
    $url = new moodle_url('/local/kbacorporate/employee.php', ['companyid' => $companyid, 'userid' => $employee->id]);
    $datarow = [
        html_writer::link($url, s(fullname($employee))),
        s($employee->position ?: '—'),
        s(!empty($departmentnames[$employee->id]) ? implode(', ', $departmentnames[$employee->id]) : '—'),
        s($employee->email),
        $coursecount,
        $training,
        $completed,
        $avg,
    ];
    if ($gamificationrows) {
        $gamificationrow = $gamificationrows[(int)$employee->id] ?? null;
        $datarow[] = $gamificationrow ? s($gamificationrow['levelname']) : '—';
        $datarow[] = $gamificationrow ? (int)$gamificationrow['xp'] : 0;
        $datarow[] = $gamificationrow ? (int)$gamificationrow['achievementcount'] : 0;
    }
    $datarow[] = $lastaccess ? userdate($lastaccess) : '—';
    $table->data[] = $datarow;
}

echo html_writer::table($table);
echo $OUTPUT->footer();
