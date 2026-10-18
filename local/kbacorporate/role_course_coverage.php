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

$url = new moodle_url('/local/kbacorporate/role_course_coverage.php', [
    'companyid' => $companyid,
    'roleid' => $roleid,
]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('rolecoursecoverage', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

if (data_submitted() && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action !== 'add') {
        throw new moodle_exception('invalidaction');
    }
    $competencyid = required_param('competencyid', PARAM_INT);
    $courseid = required_param('courseid', PARAM_INT);
    $status = \local_kbacorporate\local\role_course_coverage::add_mapping(
        $companyid, $roleid, $competencyid, $courseid
    );
    $message = $status === 'created'
        ? get_string('rolecoursecoveragemappingcreated', 'local_kbacorporate')
        : get_string('rolecoursecoveragemappingexists', 'local_kbacorporate');
    redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

$requirements = \local_kbacorporate\local\role_course_coverage::role_requirements($roleid);
$companycourses = \local_kbacorporate\local\role_course_coverage::company_courses($companyid);
$mappings = \local_kbacorporate\local\role_course_coverage::mappings($companyid, $roleid);
$summary = \local_kbacorporate\local\role_course_coverage::summary($companyid, $roleid);

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), format_string($role->name)),
    html_writer::link(new moodle_url('/local/kbacorporate/role_course_coverage_integrity.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), get_string('coursecoverageintegrity', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
        'roleid' => $roleid, 'companyid' => $companyid,
    ]), get_string('rolelearningmatrix', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]),
        get_string('courses', 'local_kbacorporate')),
];
$navigation = html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('rolecoursecoverage', 'local_kbacorporate'));
echo html_writer::div(get_string('rolecoursecoveragedesc', 'local_kbacorporate', format_string($role->name)),
    'kbacorp-pagelead');

$cards = [
    [$summary->total, get_string('rolecoursecoveragecompetencies', 'local_kbacorporate')],
    [$summary->covered . '/' . $summary->total, get_string('rolecoursecoverageall', 'local_kbacorporate')],
    [$summary->mandatorycovered . '/' . $summary->mandatory, get_string('rolecoursecoveragemandatory', 'local_kbacorporate')],
    [count($companycourses), get_string('rolecoursecoveragecompanycourses', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4');
foreach ($cards as [$value, $label]) {
    echo html_writer::div(html_writer::div(s((string)$value), 'kbacorp-metric-value') .
        html_writer::div(s($label), 'kbacorp-metric-label'), 'kbacorp-metric-card');
}
echo html_writer::end_div();

echo $OUTPUT->notification(get_string('rolecoursecoverageglobalnote', 'local_kbacorporate'), 'info');
if (!$companycourses) {
    echo $OUTPUT->notification(
        get_string('rolecoursecoveragenocourses', 'local_kbacorporate') . ' ' .
        html_writer::link(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]),
            get_string('rolecoursecoverageopencourses', 'local_kbacorporate')),
        'warning'
    );
}
if ($summary->mandatoryuncovered > 0) {
    echo $OUTPUT->notification(get_string('rolecoursecoveragemandatorywarning', 'local_kbacorporate',
        $summary->mandatoryuncovered), 'warning');
} else if ($summary->total > 0 && $summary->uncovered === 0) {
    echo $OUTPUT->notification(get_string('rolecoursecoveragecomplete', 'local_kbacorporate'), 'success');
}

if (!$requirements) {
    echo $OUTPUT->notification(get_string('roleprofilebulknorows', 'local_kbacorporate'), 'info');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$courseoptionsbase = [];
foreach ($companycourses as $course) {
    $courseoptionsbase[(int)$course->id] = format_string($course->fullname);
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-role-course-coverage';
$table->head = [
    get_string('competencyname', 'local_kbacorporate'),
    get_string('targetlevel', 'local_kbacorporate'),
    get_string('rolepriority', 'local_kbacorporate'),
    get_string('rolerequired', 'local_kbacorporate'),
    get_string('recommendedcourses', 'local_kbacorporate'),
    get_string('status'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($requirements as $req) {
    $competencyid = (int)$req->competencyid;
    $currentmappings = $mappings[$competencyid] ?? [];
    $courselinks = [];
    foreach ($currentmappings as $mapped) {
        $courselinks[] = html_writer::link(new moodle_url('/course/view.php', ['id' => (int)$mapped->courseid]),
            format_string($mapped->fullname));
    }
    $coursecell = $courselinks ? implode(html_writer::empty_tag('br'), $courselinks) :
        html_writer::span(get_string('matrixnocourse', 'local_kbacorporate'), 'text-muted');
    $status = $currentmappings
        ? html_writer::span(get_string('rolecoursecoveragecovered', 'local_kbacorporate'), 'badge badge-success')
        : html_writer::span(get_string('rolecoursecoverageuncovered', 'local_kbacorporate'),
            !empty($req->required) ? 'badge badge-warning' : 'badge badge-secondary');

    $actioncell = '—';
    if ($companycourses) {
        $options = $courseoptionsbase;
        foreach (array_keys($currentmappings) as $mappedcourseid) {
            unset($options[(int)$mappedcourseid]);
        }
        if ($options) {
            $form = html_writer::start_tag('form', [
                'method' => 'post', 'action' => $url->out(false), 'class' => 'kbacorp-inline-form',
            ]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'roleid', 'value' => $roleid]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'competencyid', 'value' => $competencyid]);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'add']);
            $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $form .= html_writer::select($options, 'courseid', '', ['' => get_string('choose')], [
                'class' => 'custom-select custom-select-sm', 'required' => 'required',
                'aria-label' => get_string('rolecoursecoveragechoosecoursearia', 'local_kbacorporate',
                    format_string($req->shortname)),
            ]);
            $form .= html_writer::tag('button', get_string('rolecoursecoverageadd', 'local_kbacorporate'), [
                'type' => 'submit', 'class' => 'btn btn-sm btn-primary',
            ]);
            $form .= html_writer::end_tag('form');
            $actioncell = $form;
        } else {
            $actioncell = get_string('rolecoursecoverageallcompanycoursesmapped', 'local_kbacorporate');
        }
    }

    $competency = html_writer::span('', 'kbacorp-anchor-target', [
            'id' => 'competency-' . $competencyid,
        ]) . html_writer::tag('strong', format_string($req->shortname)) .
        ($req->idnumber ? html_writer::div(s($req->idnumber), 'text-muted small') : '');
    $table->data[] = [
        $competency,
        (int)$req->targetlevel,
        get_string('rolepriority_' . $req->priority, 'local_kbacorporate'),
        !empty($req->required) ? get_string('yes') : get_string('no'),
        $coursecell,
        $status,
        $actioncell,
    ];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');

echo html_writer::start_div('kbacorp-toolbar');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_course_coverage_integrity.php', [
    'companyid' => $companyid, 'roleid' => $roleid,
]), get_string('coursecoverageintegrity', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
    'companyid' => $companyid, 'roleid' => $roleid,
]), get_string('rolelearningmatrix', 'local_kbacorporate'), 'get');
echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/courses.php', ['companyid' => $companyid]),
    get_string('rolecoursecoverageopencourses', 'local_kbacorporate'), 'get');
echo html_writer::end_div();

echo $navigation;
echo $OUTPUT->footer();
