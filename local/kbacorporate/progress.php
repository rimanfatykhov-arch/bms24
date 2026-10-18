<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewprogress', $context);
$companyid = required_param('companyid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);

$validstatuses = ['', 'notenrolled', 'notstarted', 'inprogress', 'completed'];
if (!in_array($status, $validstatuses, true)) {
    $status = '';
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/progress.php', ['companyid' => $companyid, 'courseid' => $courseid, 'status' => $status, 'departmentid' => $departmentid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('progressnav', 'local_kbacorporate'));
$PAGE->set_heading(get_string('progressnav', 'local_kbacorporate'));

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$allrows = \local_kbacorporate\local\report::get_rows($companyid, $visibleids);
$rows = [];
foreach ($allrows as $row) {
    if ($courseid && (int)$row->courseid !== $courseid) {
        continue;
    }
    if ($status !== '' && $row->status !== $status) {
        continue;
    }
    $rows[] = $row;
}

$courses = $DB->get_records_sql(
    "SELECT c.id, c.fullname
       FROM {local_kbacorp_course} cc
       JOIN {course} c ON c.id = cc.courseid
      WHERE cc.companyid = :companyid AND cc.active = 1
   ORDER BY c.fullname",
    ['companyid' => $companyid]
);

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]), '← ' . get_string('backtodashboard', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('progresspagedesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid, '/local/kbacorporate/progress.php', ['courseid' => $courseid, 'status' => $status]);

// Lightweight GET filters, intentionally independent from administrator forms.
echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/kbacorporate/progress.php'))->out(false), 'class' => 'kbacorp-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]);
$courseoptions = [0 => get_string('allcourses', 'local_kbacorporate')];
foreach ($courses as $course) {
    $courseoptions[$course->id] = format_string($course->fullname);
}
echo html_writer::label(get_string('course', 'local_kbacorporate'), 'kbacorp-course-filter', false, ['class' => 'kbacorp-filter-label']);
echo html_writer::select($courseoptions, 'courseid', $courseid, false, ['id' => 'kbacorp-course-filter', 'class' => 'custom-select']);
$statusoptions = [
    '' => get_string('allstatuses', 'local_kbacorporate'),
    'notenrolled' => get_string('notenrolled', 'local_kbacorporate'),
    'notstarted' => get_string('notstarted', 'local_kbacorporate'),
    'inprogress' => get_string('inprogress', 'local_kbacorporate'),
    'completed' => get_string('completed', 'local_kbacorporate'),
];
echo html_writer::label(get_string('status', 'local_kbacorporate'), 'kbacorp-status-filter', false, ['class' => 'kbacorp-filter-label']);
echo html_writer::select($statusoptions, 'status', $status, false, ['id' => 'kbacorp-status-filter', 'class' => 'custom-select']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

$summary = \local_kbacorporate\local\report::summary($rows);
$filteredcards = [
    [get_string('records', 'local_kbacorporate'), count($rows)],
    [get_string('training', 'local_kbacorporate'), $summary->training],
    [get_string('completed', 'local_kbacorporate'), $summary->completed],
    [get_string('avgprogress', 'local_kbacorporate'), $summary->avgprogress === null ? '—' : $summary->avgprogress . '%'],
];
echo html_writer::start_div('kbacorp-summary kbacorp-summary-compact');
foreach ($filteredcards as $card) {
    echo html_writer::div(html_writer::div(s((string)$card[1]), 'value') . html_writer::div(s($card[0]), 'label'), 'kbacorp-card');
}
echo html_writer::end_div();

if (!$rows) {
    echo $OUTPUT->notification(get_string('nofilterresults', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$showgrades = has_capability('local/kbacorporate:viewgrades', $context);
$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('fullname', 'local_kbacorporate'),
    get_string('position', 'local_kbacorporate'),
    get_string('course', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('progress', 'local_kbacorporate'),
];
if ($showgrades) {
    $table->head[] = get_string('grade', 'local_kbacorporate');
}
$table->head[] = get_string('lastaccess', 'local_kbacorporate');

foreach ($rows as $row) {
    $progress = '—';
    if ($row->progress !== null) {
        $width = max(0, min(100, (float)$row->progress));
        $progress = html_writer::div(
            s($row->progress . '%') . html_writer::div(html_writer::span('', '', ['style' => 'width:' . $width . '%']), 'kbacorp-progressbar'),
            'kbacorp-progress'
        );
    }
    $userurl = new moodle_url('/local/kbacorporate/employee.php', ['companyid' => $companyid, 'userid' => $row->userid]);
    $datarow = [
        html_writer::link($userurl, s($row->fullname)),
        s($row->position ?: '—'),
        html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]), format_string($row->coursename), ['class' => 'kbacorp-course-link']),
        html_writer::span(get_string($row->status, 'local_kbacorporate'), 'kbacorp-status kbacorp-status-' . $row->status),
        $progress,
    ];
    if ($showgrades) {
        $datarow[] = $row->grade === null ? '—' : s($row->grade . '%');
    }
    $datarow[] = $row->lastaccess ? userdate($row->lastaccess) : '—';
    $table->data[] = $datarow;
}
echo html_writer::table($table);

echo $OUTPUT->footer();
