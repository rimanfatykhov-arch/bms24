<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);
require_capability('local/kbacorporate:managecourses', $context);

$companyid = required_param('companyid', PARAM_INT);
$roleid = required_param('roleid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$role = \local_kbacorporate\local\business_roles::get_role($roleid);
if ((int)$role->companyid !== 0 && (int)$role->companyid !== $companyid) {
    throw new moodle_exception('companynotallowed', 'local_kbacorporate');
}

$url = new moodle_url('/local/kbacorporate/role_course_coverage_integrity.php', [
    'companyid' => $companyid,
    'roleid' => $roleid,
]);
$coverageurl = new moodle_url('/local/kbacorporate/role_course_coverage.php', [
    'companyid' => $companyid,
    'roleid' => $roleid,
]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('coursecoverageintegrity', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$report = \local_kbacorporate\local\role_course_coverage::integrity_report($companyid, $roleid);
$summary = $report->summary;
$companycourses = \local_kbacorporate\local\role_course_coverage::company_courses($companyid);

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), format_string($role->name)),
    html_writer::link($coverageurl, get_string('rolecoursecoverage', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), get_string('rolelearningmatrix', 'local_kbacorporate')),
];
$navigation = html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);

$renderrequirements = static function(array $requirements, string $emptystring) use ($companyid, $roleid): string {
    if (!$requirements) {
        return html_writer::div(get_string($emptystring, 'local_kbacorporate'), 'alert alert-success');
    }
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('competencyname', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'),
        get_string('rolepriority', 'local_kbacorporate'),
        get_string('rolerequired', 'local_kbacorporate'),
        get_string('actions', 'local_kbacorporate'),
    ];
    foreach ($requirements as $requirement) {
        $fixurl = new moodle_url('/local/kbacorporate/role_course_coverage.php', [
            'companyid' => $companyid,
            'roleid' => $roleid,
        ]);
        $fixurl->set_anchor('competency-' . (int)$requirement->competencyid);
        $competency = html_writer::tag('strong', format_string($requirement->shortname));
        if (!empty($requirement->idnumber)) {
            $competency .= html_writer::div(s($requirement->idnumber), 'text-muted small');
        }
        $table->data[] = [
            $competency,
            (int)$requirement->targetlevel,
            get_string('rolepriority_' . $requirement->priority, 'local_kbacorporate'),
            !empty($requirement->required) ? get_string('yes') : get_string('no'),
            html_writer::link($fixurl, get_string('coursecoverageintegrityconfigure', 'local_kbacorporate')),
        ];
    }
    return html_writer::table($table);
};

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('coursecoverageintegrity', 'local_kbacorporate'));
echo html_writer::div(get_string('coursecoverageintegritydesc', 'local_kbacorporate', format_string($role->name)),
    'kbacorp-pagelead');

$statuslabel = get_string('coursecoverageintegritystatuslabel_' . $report->status, 'local_kbacorporate');
$cards = [
    [$statuslabel, get_string('coursecoverageintegrityreadiness', 'local_kbacorporate'),
        $report->learningready ? 'kbacorp-card-accent-confirmed' : 'kbacorp-card-accent-developing'],
    [$summary->covered . '/' . $summary->total, get_string('rolecoursecoverageall', 'local_kbacorporate'),
        'kbacorp-card-accent-total'],
    [$summary->mandatorycovered . '/' . $summary->mandatory,
        get_string('rolecoursecoveragemandatory', 'local_kbacorporate'), 'kbacorp-card-accent-confirmed'],
    [$summary->mandatoryuncovered, get_string('coursecoverageintegritymandatorymissing', 'local_kbacorporate'),
        $summary->mandatoryuncovered ? 'kbacorp-card-accent-developing' : 'kbacorp-card-accent-confirmed'],
    [count($report->courseswithoutcompetencies),
        get_string('coursecoverageintegrityemptycourses', 'local_kbacorporate'), 'kbacorp-card-accent-notstarted'],
    [count($report->duplicates), get_string('coursecoverageintegrityduplicates', 'local_kbacorporate'),
        $report->duplicates ? 'kbacorp-card-accent-developing' : 'kbacorp-card-accent-confirmed'],
    [count($report->outofscope), get_string('coursecoverageintegrityoutofscope', 'local_kbacorporate'),
        $report->outofscope ? 'kbacorp-card-accent-developing' : 'kbacorp-card-accent-confirmed'],
];
echo html_writer::start_div('kbacorp-summary');
foreach ($cards as [$value, $label, $class]) {
    echo html_writer::div(
        html_writer::div(s((string)$value), 'value') . html_writer::div(s($label), 'label'),
        'kbacorp-card ' . $class
    );
}
echo html_writer::end_div();

switch ($report->status) {
    case 'empty':
        echo $OUTPUT->notification(get_string('coursecoverageintegritystatusempty', 'local_kbacorporate'), 'warning');
        break;
    case 'integrityerror':
        echo $OUTPUT->notification(get_string('coursecoverageintegritystatusintegrityerror',
            'local_kbacorporate', count($report->duplicates)), 'error');
        break;
    case 'requirescourses':
        echo $OUTPUT->notification(get_string('coursecoverageintegritystatusblocked', 'local_kbacorporate',
            $summary->mandatoryuncovered), 'warning');
        break;
    case 'partial':
        echo $OUTPUT->notification(get_string('coursecoverageintegritystatuspartial', 'local_kbacorporate'), 'info');
        break;
    default:
        echo $OUTPUT->notification(get_string('coursecoverageintegritystatusready', 'local_kbacorporate'), 'success');
        break;
}

if ($report->duplicates) {
    echo $OUTPUT->notification(get_string('coursecoverageintegrityduplicatenote', 'local_kbacorporate'), 'error');
}
if ($report->outofscope) {
    echo $OUTPUT->notification(get_string('coursecoverageintegrityoutofscopenote', 'local_kbacorporate'), 'warning');
}

echo $OUTPUT->heading(get_string('coursecoverageintegritymandatorytitle', 'local_kbacorporate'), 3);
echo html_writer::div($renderrequirements($report->mandatoryuncovered,
    'coursecoverageintegritynomandatorymissing'), 'kbacorp-table-scroll');

echo $OUTPUT->heading(get_string('coursecoverageintegritysupportingtitle', 'local_kbacorporate'), 3);
echo html_writer::div($renderrequirements($report->supportinguncovered,
    'coursecoverageintegritynosupportingmissing'), 'kbacorp-table-scroll');

echo $OUTPUT->heading(get_string('coursecoverageintegrityemptycoursestitle', 'local_kbacorporate'), 3);
if (!$companycourses) {
    echo $OUTPUT->notification(get_string('rolecoursecoveragenocourses', 'local_kbacorporate'), 'warning');
} else if (!$report->courseswithoutcompetencies) {
    echo $OUTPUT->notification(get_string('coursecoverageintegritynoemptycourses', 'local_kbacorporate'), 'success');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('course'),
        get_string('idnumber'),
        get_string('actions', 'local_kbacorporate'),
    ];
    foreach ($report->courseswithoutcompetencies as $course) {
        $actions = [
            html_writer::link(new moodle_url('/course/view.php', ['id' => (int)$course->id]),
                get_string('view')),
        ];
        $coursecontext = context_course::instance((int)$course->id);
        if (has_capability('moodle/competency:coursecompetencymanage', $coursecontext)) {
            $actions[] = html_writer::link(new moodle_url('/admin/tool/lp/coursecompetencies.php', [
                'courseid' => (int)$course->id,
            ]), get_string('coursecoverageintegritymanagecoursecompetencies', 'local_kbacorporate'));
        }
        $table->data[] = [
            format_string($course->fullname),
            s((string)$course->idnumber),
            implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $actions),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo $OUTPUT->heading(get_string('coursecoverageintegrityoutofscopetitle', 'local_kbacorporate'), 3);
if (!$report->outofscope) {
    echo $OUTPUT->notification(get_string('coursecoverageintegritynooutofscope', 'local_kbacorporate'), 'success');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('competencyname', 'local_kbacorporate'),
        get_string('course'),
        get_string('coursecoverageintegrityreason', 'local_kbacorporate'),
        get_string('actions', 'local_kbacorporate'),
    ];
    foreach ($report->outofscope as $mapping) {
        $reasonkey = 'coursecoverageintegrityreason_' . $mapping->scopereason;
        $table->data[] = [
            format_string($mapping->competencyname) .
                (!empty($mapping->competencycode) ? html_writer::div(s($mapping->competencycode), 'text-muted small') : ''),
            format_string($mapping->fullname),
            get_string($reasonkey, 'local_kbacorporate'),
            html_writer::link(new moodle_url('/course/view.php', ['id' => (int)$mapping->courseid]),
                get_string('view')),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo $OUTPUT->heading(get_string('coursecoverageintegrityduplicatestitle', 'local_kbacorporate'), 3);
if (!$report->duplicates) {
    echo $OUTPUT->notification(get_string('coursecoverageintegritynoduplicates', 'local_kbacorporate'), 'success');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('competencyname', 'local_kbacorporate'),
        get_string('course'),
        get_string('coursecoverageintegrityrelationcount', 'local_kbacorporate'),
    ];
    foreach ($report->duplicates as $duplicate) {
        $table->data[] = [
            format_string($duplicate->competencyname) .
                (!empty($duplicate->competencycode) ? html_writer::div(s($duplicate->competencycode), 'text-muted small') : ''),
            format_string($duplicate->fullname),
            (int)$duplicate->relationcount,
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button($coverageurl, get_string('coursecoverageintegrityconfigurecoverage', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
    'companyid' => $companyid, 'roleid' => $roleid,
]), get_string('rolelearningmatrix', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]),
    get_string('rolecoursecoverageopencourses', 'local_kbacorporate'), 'get');
echo html_writer::end_div();

echo $navigation;
echo $OUTPUT->footer();
