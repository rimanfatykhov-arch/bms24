<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcompetencies', $context);

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$status = optional_param('status', 'all', PARAM_ALPHANUMEXT);
$priority = optional_param('priority', 'all', PARAM_ALPHANUMEXT);
$kind = optional_param('kind', 'all', PARAM_ALPHANUMEXT);
$query = trim((string)optional_param('q', '', PARAM_TEXT));

if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
if ($companyid <= 0) {
    throw new invalid_parameter_exception('Company is required.');
}
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$fullaccess = \local_kbacorporate\local\access::has_full_company_access($companyid, $USER->id);

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id);
$employees = [];
if ($visibleids) {
    list($insql, $params) = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'egapuser');
    $employees = array_values($DB->get_records_select('user', "id {$insql} AND deleted = 0", $params,
        'lastname, firstname', 'id,firstname,lastname,email'));
}
$employeeoptions = [0 => get_string('employeegapchooseemployee', 'local_kbacorporate')];
foreach ($employees as $employee) {
    $employeeoptions[(int)$employee->id] = fullname($employee) . ($employee->email ? ' — ' . s($employee->email) : '');
}
if ($userid > 0 && !isset($employeeoptions[$userid])) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}

$urlparams = ['companyid' => $companyid, 'userid' => $userid];
if ($status !== 'all') {
    $urlparams['status'] = $status;
}
if ($priority !== 'all') {
    $urlparams['priority'] = $priority;
}
if ($kind !== 'all') {
    $urlparams['kind'] = $kind;
}
if ($query !== '') {
    $urlparams['q'] = $query;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/employee_gap.php', $urlparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('employeegap', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
];
if ($userid > 0) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_profile.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeecompetencyprofile', 'local_kbacorporate'));
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_assessment_history.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeeassessmenthistory', 'local_kbacorporate'));
}
if ($fullaccess && has_capability('local/kbacorporate:managerolecompetencies', $context)) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_assignments.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('businessroleassignments', 'local_kbacorporate'));
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('rolelearningmatrix', 'local_kbacorporate'));
}
if ($fullaccess && has_capability('local/kbacorporate:managedevelopmentplans', $context)) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('gaplearningplanbuilder', 'local_kbacorporate'));
}
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']
);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('employeegap', 'local_kbacorporate'));
echo html_writer::div(get_string('employeegapdesc', 'local_kbacorporate'), 'kbacorp-pagelead');

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-toolbar']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::label(get_string('employee', 'local_kbacorporate'), 'employee-gap-userid');
echo html_writer::select($employeeoptions, 'userid', $userid, false, ['id' => 'employee-gap-userid']);
echo html_writer::tag('button', get_string('employeegapcalculate', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

if ($userid <= 0) {
    echo $OUTPUT->notification(get_string('employeegapchooseemployeeinfo', 'local_kbacorporate'), 'info');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$employee = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,email', MUST_EXIST);
$result = \local_kbacorporate\local\employee_gap::calculate($companyid, $userid);
$filteredrows = \local_kbacorporate\local\employee_gap::filter($result->rows, $status, $priority, $kind, $query);

echo $OUTPUT->heading(fullname($employee), 3);
if ($result->roles) {
    $rolelinks = [];
    foreach ($result->roles as $role) {
        $rolelinks[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
            'companyid' => $companyid,
            'roleid' => (int)$role->id,
        ]), format_string($role->name));
    }
    echo html_writer::div(
        html_writer::tag('strong', get_string('employeegapassignedroles', 'local_kbacorporate') . ': ') .
        implode(', ', $rolelinks),
        'kbacorp-pagelead'
    );
}

$counts = $result->counts;
$cards = [
    [$counts->roles, get_string('matrixroles', 'local_kbacorporate')],
    [$counts->competencies, get_string('employeegaprequirements', 'local_kbacorporate')],
    [$counts->assessmentcoverage . '%', get_string('assessmentcoverage', 'local_kbacorporate')],
    [$counts->targetreadiness . '%', get_string('targetreadiness', 'local_kbacorporate')],
    [$counts->unassessed, get_string('employeegaprow_unassessed', 'local_kbacorporate')],
    [$counts->gaps, get_string('competencygaps', 'local_kbacorporate')],
    [$counts->mandatorygaps, get_string('employeegapmandatorygaps', 'local_kbacorporate')],
    [$counts->gapswithcourse . '/' . $counts->gaps, get_string('employeegapgapswithcourses', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4');
foreach ($cards as [$value, $label]) {
    echo html_writer::div(
        html_writer::div(s((string)$value), 'kbacorp-metric-value') .
        html_writer::div(s($label), 'kbacorp-metric-label'),
        'kbacorp-metric-card'
    );
}
echo html_writer::end_div();

$statekey = 'employeegapstate_' . $result->state;
$notifytype = 'info';
if ($result->state === \local_kbacorporate\local\employee_gap::STATE_READY) {
    $notifytype = 'success';
} else if (in_array($result->state, [
        \local_kbacorporate\local\employee_gap::STATE_CRITICAL_GAPS,
        \local_kbacorporate\local\employee_gap::STATE_REQUIRED_GAPS,
    ], true)) {
    $notifytype = 'warning';
}
echo $OUTPUT->notification(get_string($statekey, 'local_kbacorporate', $counts), $notifytype);

if ($result->state === \local_kbacorporate\local\employee_gap::STATE_NO_ROLES && $fullaccess) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/business_role_assignments.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('assignbusinessroles', 'local_kbacorporate'), 'get');
}
if ($result->state === \local_kbacorporate\local\employee_gap::STATE_EMPTY_PROFILE && $fullaccess && $result->roles) {
    $firstrole = reset($result->roles);
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'companyid' => $companyid, 'roleid' => (int)$firstrole->id,
    ]), get_string('roleprofileconfigure', 'local_kbacorporate'), 'get');
}

if (!$result->rows) {
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$statusoptions = [
    'all' => get_string('allstatuses', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_gap::ROW_GAP => get_string('employeegaprow_gap', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_gap::ROW_UNASSESSED => get_string('employeegaprow_unassessed', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_gap::ROW_PENDING => get_string('employeegaprow_pending', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_gap::ROW_MET => get_string('employeegaprow_met', 'local_kbacorporate'),
];
$priorityoptions = [
    'all' => get_string('employeegapallpriorities', 'local_kbacorporate'),
    \local_kbacorporate\local\business_roles::PRIORITY_CRITICAL => get_string('rolepriority_critical', 'local_kbacorporate'),
    \local_kbacorporate\local\business_roles::PRIORITY_CORE => get_string('rolepriority_core', 'local_kbacorporate'),
    \local_kbacorporate\local\business_roles::PRIORITY_ADDITIONAL => get_string('rolepriority_additional', 'local_kbacorporate'),
];
$kindoptions = [
    'all' => get_string('employeegapalltypes', 'local_kbacorporate'),
    'mandatory' => get_string('employeegapmandatory', 'local_kbacorporate'),
    'supporting' => get_string('employeegapsupporting', 'local_kbacorporate'),
];

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
echo html_writer::select($statusoptions, 'status', $status, false, ['aria-label' => get_string('status')]);
echo html_writer::select($priorityoptions, 'priority', $priority, false, ['aria-label' => get_string('priority', 'local_kbacorporate')]);
echo html_writer::select($kindoptions, 'kind', $kind, false, ['aria-label' => get_string('employeegaprequirementtype', 'local_kbacorporate')]);
echo html_writer::empty_tag('input', [
    'type' => 'search', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('employeegapsearch', 'local_kbacorporate'),
]);
echo html_writer::tag('button', get_string('apply', 'local_kbacorporate'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
    'companyid' => $companyid, 'userid' => $userid,
]), get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::div(get_string('employeegapfiltered', 'local_kbacorporate', (object)[
    'shown' => count($filteredrows),
    'total' => count($result->rows),
]), 'kbacorp-muted mb-3');

if (!$filteredrows) {
    echo $OUTPUT->notification(get_string('employeegapnofilterrows', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('competencylabel', 'local_kbacorporate'),
        get_string('businessrole', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'),
        get_string('currentlevel', 'local_kbacorporate'),
        get_string('competencygap', 'local_kbacorporate'),
        get_string('rolepriority', 'local_kbacorporate'),
        get_string('employeegaprequirementtype', 'local_kbacorporate'),
        get_string('recommendedcourses', 'local_kbacorporate'),
        get_string('status'),
        get_string('actions', 'local_kbacorporate'),
    ];

    foreach ($filteredrows as $row) {
        $competency = format_string($row->shortname) . ($row->idnumber !== ''
            ? html_writer::div(s($row->idnumber), 'text-muted small') : '');
        $rolelabels = [];
        foreach ($row->roles as $role) {
            $rolelabels[] = format_string((string)$role->name);
        }
        $scaleid = (int)($row->competencyscaleid ?: $row->frameworkscaleid);
        $current = $row->currentlevel === null
            ? get_string('competencynotassessed', 'local_kbacorporate')
            : \local_kbacorporate\local\competencies::level_label((int)$row->currentlevel, $scaleid);
        if ($row->currentlevel === null && $row->provisionallevel !== null) {
            $current .= html_writer::div(get_string('employeegapprovisional', 'local_kbacorporate',
                \local_kbacorporate\local\competencies::level_label((int)$row->provisionallevel, $scaleid)), 'text-muted small');
        }
        $gap = $row->gap === null ? '—' : (string)$row->gap;
        $prioritylabel = get_string('rolepriority_' . $row->priority, 'local_kbacorporate');
        $kindlabel = $row->required
            ? get_string('employeegapmandatory', 'local_kbacorporate')
            : get_string('employeegapsupporting', 'local_kbacorporate');

        $courselinks = [];
        foreach ($row->courses as $course) {
            if (!empty($course->url)) {
                $courselinks[] = html_writer::link($course->url, format_string($course->fullname));
            } else {
                $courselinks[] = format_string($course->fullname);
            }
        }
        $coursehtml = $courselinks ? implode(html_writer::empty_tag('br'), $courselinks)
            : get_string('matrixnocourse', 'local_kbacorporate');

        $statuslabel = get_string('employeegaprow_' . $row->status, 'local_kbacorporate');
        $statusclass = $row->status === \local_kbacorporate\local\employee_gap::ROW_MET
            ? 'badge badge-success' : ($row->status === \local_kbacorporate\local\employee_gap::ROW_GAP
                ? 'badge badge-warning' : 'badge badge-secondary');
        $statushtml = html_writer::span($statuslabel, $statusclass);

        $actions = [];
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_history.php', [
            'companyid' => $companyid, 'userid' => $userid, 'competencyid' => $row->competencyid,
        ]), get_string('employeeprofilehistory', 'local_kbacorporate'));
        if (in_array($row->status, [
                \local_kbacorporate\local\employee_gap::ROW_UNASSESSED,
                \local_kbacorporate\local\employee_gap::ROW_PENDING,
            ], true) && has_capability('local/kbacorporate:managecompetencyaudits', $context) && $fullaccess) {
            $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/competency_audits.php', [
                'companyid' => $companyid, 'userid' => $userid,
            ]), get_string('employeegapassess', 'local_kbacorporate'));
        }
        if ($row->status === \local_kbacorporate\local\employee_gap::ROW_GAP && !$row->coursecovered &&
                has_capability('local/kbacorporate:managerolecompetencies', $context) && $fullaccess && $row->roles) {
            $firstrole = reset($row->roles);
            $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/role_course_coverage.php', [
                'companyid' => $companyid, 'roleid' => (int)$firstrole->id,
            ]), get_string('employeegapconfigurecourse', 'local_kbacorporate'));
        }

        $table->data[] = [
            $competency,
            $rolelabels ? implode(', ', $rolelabels) : '—',
            \local_kbacorporate\local\competencies::level_label((int)$row->targetlevel, $scaleid),
            $current,
            $gap,
            $prioritylabel,
            $kindlabel,
            $coursehtml,
            $statushtml,
            implode(' · ', $actions),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo html_writer::start_div('kbacorp-toolbar');
if ($fullaccess && has_capability('local/kbacorporate:managecompetencyaudits', $context) && $counts->unassessed > 0) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/competency_audits.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeegapcreateaudit', 'local_kbacorporate'), 'get');
}
if ($fullaccess && has_capability('local/kbacorporate:managedevelopmentplans', $context) &&
        ($counts->gaps > 0 || $counts->unassessed > 0)) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('gaplearningplanpreviewbutton', 'local_kbacorporate'), 'get');
}
echo html_writer::end_div();

echo $navigation;
echo $OUTPUT->footer();
