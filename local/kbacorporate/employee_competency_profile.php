<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcompetencies', $context);

$companyid = optional_param('companyid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$scope = optional_param('scope', \local_kbacorporate\local\employee_competency_profile::SCOPE_PROFILE,
    PARAM_ALPHANUMEXT);
$status = optional_param('status', 'all', PARAM_ALPHANUMEXT);
$source = optional_param('source', 'all', PARAM_ALPHANUMEXT);
$kind = optional_param('kind', 'all', PARAM_ALPHANUMEXT);
$query = trim((string)optional_param('q', '', PARAM_TEXT));

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
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
    list($insql, $params) = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'ecpuser');
    $employees = array_values($DB->get_records_select('user',
        "id {$insql} AND deleted = 0", $params, 'lastname, firstname',
        'id,firstname,lastname,email'));
}
$employeeoptions = [0 => get_string('employeeprofilechooseemployee', 'local_kbacorporate')];
foreach ($employees as $employeeoption) {
    $employeeoptions[(int)$employeeoption->id] = fullname($employeeoption) .
        ($employeeoption->email ? ' — ' . $employeeoption->email : '');
}
if ($userid > 0 && !isset($employeeoptions[$userid])) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}

$urlparams = ['companyid' => $companyid, 'userid' => $userid];
foreach (['scope' => $scope, 'status' => $status, 'source' => $source, 'kind' => $kind] as $name => $value) {
    if ($value !== 'all' && !($name === 'scope' && $value === \local_kbacorporate\local\employee_competency_profile::SCOPE_PROFILE)) {
        $urlparams[$name] = $value;
    }
}
if ($query !== '') {
    $urlparams['q'] = $query;
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/employee_competency_profile.php', $urlparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('employeecompetencyprofile', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
];
if ($userid > 0) {
    if (has_capability('local/kbacorporate:viewemployees', $context)) {
        $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('employeecard', 'local_kbacorporate'));
    }
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_assessment_history.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeeassessmenthistory', 'local_kbacorporate'));
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/mycompetencies.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeeprofiledetailedcards', 'local_kbacorporate'));
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeegap', 'local_kbacorporate'));
    if ($fullaccess && has_capability('local/kbacorporate:managedevelopmentplans', $context)) {
        $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/development_plans.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('developmentplans', 'local_kbacorporate'));
    }
    if ($fullaccess && has_capability('local/kbacorporate:managecompetencyaudits', $context)) {
        $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/competency_audits.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('competencyaudits', 'local_kbacorporate'));
    }
}
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']
);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('employeecompetencyprofile', 'local_kbacorporate'));
echo html_writer::div(get_string('employeecompetencyprofiledesc', 'local_kbacorporate'), 'kbacorp-pagelead');

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-toolbar kbacorp-employee-profile-selector']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::label(get_string('employee', 'local_kbacorporate'), 'employee-profile-userid');
echo html_writer::select($employeeoptions, 'userid', $userid, false, ['id' => 'employee-profile-userid']);
echo html_writer::tag('button', get_string('employeeprofileopen', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

if ($userid <= 0) {
    echo $OUTPUT->notification(get_string('employeeprofilechooseemployeeinfo', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$employee = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
    'id,firstname,lastname,email,department,phone1,phone2', MUST_EXIST);
$profile = \local_kbacorporate\local\employee_competency_profile::build($companyid, $userid);
$filteredrows = \local_kbacorporate\local\employee_competency_profile::filter(
    $profile->rows, $scope, $status, $source, $kind, $query
);
$summary = $profile->summary;

echo html_writer::start_div('kbacorp-employee-profile-hero');
echo html_writer::start_div('kbacorp-employee-profile-identity');
echo html_writer::tag('h3', s(fullname($employee)), ['class' => 'kbacorp-employee-profile-name']);
$metaparts = [];
if ($employee->email !== '') {
    $metaparts[] = s($employee->email);
}
if ($employee->department !== '') {
    $metaparts[] = s($employee->department);
}
if ($metaparts) {
    echo html_writer::div(implode(' · ', $metaparts), 'kbacorp-muted');
}
if ($profile->roles) {
    $rolelinks = [];
    foreach ($profile->roles as $role) {
        $rolelinks[] = html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
            'companyid' => $companyid, 'roleid' => (int)$role->id,
        ]), format_string($role->name));
    }
    echo html_writer::div(
        html_writer::tag('strong', get_string('employeegapassignedroles', 'local_kbacorporate') . ': ') .
        implode(', ', $rolelinks), 'kbacorp-employee-profile-roles'
    );
} else {
    echo html_writer::div(get_string('employeeprofilenoroles', 'local_kbacorporate'), 'kbacorp-muted');
}
echo html_writer::end_div();

$stateclass = 'is-ready';
$statelabel = get_string('employeeprofilestate_ready', 'local_kbacorporate');
if ($summary->profile === 0) {
    $stateclass = 'is-empty';
    $statelabel = get_string('employeeprofilestate_empty', 'local_kbacorporate');
} else if ($summary->unassessed > 0) {
    $stateclass = 'is-attention';
    $statelabel = get_string('employeeprofilestate_unassessed', 'local_kbacorporate', $summary->unassessed);
} else if ($summary->gaps > 0) {
    $stateclass = 'is-warning';
    $statelabel = get_string('employeeprofilestate_gaps', 'local_kbacorporate', $summary->gaps);
}
echo html_writer::div(s($statelabel), 'kbacorp-employee-profile-state ' . $stateclass);
echo html_writer::end_div();

$cards = [
    [$summary->profile, get_string('employeeprofileprofilecompetencies', 'local_kbacorporate')],
    [$summary->assessmentcoverage . '%', get_string('assessmentcoverage', 'local_kbacorporate')],
    [$summary->targetreadiness . '%', get_string('targetreadiness', 'local_kbacorporate')],
    [$summary->gaps, get_string('competencygaps', 'local_kbacorporate')],
    [$summary->unassessed, get_string('employeegaprow_unassessed', 'local_kbacorporate')],
    [$summary->pending, get_string('employeegaprow_pending', 'local_kbacorporate')],
    [$summary->withcourses . '/' . $summary->profile, get_string('employeeprofilecoursecoverage', 'local_kbacorporate')],
    [$summary->historyevents, get_string('employeeprofilehistoryevents', 'local_kbacorporate')],
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

if (!$profile->available) {
    echo $OUTPUT->notification(get_string('competencynotavailable', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$scopeoptions = [
    \local_kbacorporate\local\employee_competency_profile::SCOPE_PROFILE =>
        get_string('employeeprofilescope_profile', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SCOPE_ALL =>
        get_string('employeeprofilescope_all', 'local_kbacorporate'),
];
$statusoptions = [
    'all' => get_string('allstatuses', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::STATUS_GAP =>
        get_string('employeegaprow_gap', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::STATUS_UNASSESSED =>
        get_string('employeegaprow_unassessed', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::STATUS_PENDING =>
        get_string('employeegaprow_pending', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::STATUS_MET =>
        get_string('employeegaprow_met', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::STATUS_OUTSIDE =>
        get_string('competencystatus_outsideprofile', 'local_kbacorporate'),
];
$sourceoptions = [
    'all' => get_string('employeeprofileallassessmentsources', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_AUDIT =>
        get_string('employeeprofilesource_audit', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_AI =>
        get_string('employeeprofilesource_ai', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_MOODLE =>
        get_string('employeeprofilesource_moodle', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_COURSE =>
        get_string('employeeprofilesource_course', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_PLAN =>
        get_string('employeeprofilesource_plan', 'local_kbacorporate'),
    \local_kbacorporate\local\employee_competency_profile::SOURCE_NONE =>
        get_string('employeeprofilesource_none', 'local_kbacorporate'),
];
$kindoptions = [
    'all' => get_string('employeegapalltypes', 'local_kbacorporate'),
    'mandatory' => get_string('employeegapmandatory', 'local_kbacorporate'),
    'supporting' => get_string('employeegapsupporting', 'local_kbacorporate'),
    'outsideprofile' => get_string('competencystatus_outsideprofile', 'local_kbacorporate'),
];

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
echo html_writer::select($scopeoptions, 'scope', $scope, false, [
    'aria-label' => get_string('employeeprofilescope', 'local_kbacorporate')]);
echo html_writer::select($statusoptions, 'status', $status, false, ['aria-label' => get_string('status')]);
echo html_writer::select($sourceoptions, 'source', $source, false, [
    'aria-label' => get_string('employeeprofileassessmentsource', 'local_kbacorporate')]);
echo html_writer::select($kindoptions, 'kind', $kind, false, [
    'aria-label' => get_string('employeegaprequirementtype', 'local_kbacorporate')]);
echo html_writer::empty_tag('input', [
    'type' => 'search', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('employeegapsearch', 'local_kbacorporate'),
]);
echo html_writer::tag('button', get_string('apply', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_profile.php', [
    'companyid' => $companyid, 'userid' => $userid,
]), get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::div(get_string('employeeprofilefiltered', 'local_kbacorporate', (object)[
    'shown' => count($filteredrows), 'total' => count($profile->rows),
]), 'kbacorp-muted mb-3');

if (!$filteredrows) {
    echo $OUTPUT->notification(get_string('employeeprofilenofilterrows', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report kbacorp-employee-profile-table';
    $table->head = [
        get_string('competencylabel', 'local_kbacorporate'),
        get_string('businessrole', 'local_kbacorporate'),
        get_string('employeegaprequirementtype', 'local_kbacorporate'),
        get_string('currentlevel', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'),
        get_string('competencygap', 'local_kbacorporate'),
        get_string('employeeprofilelatestassessment', 'local_kbacorporate'),
        get_string('recommendedcourses', 'local_kbacorporate'),
        get_string('employeeprofilehistory', 'local_kbacorporate'),
        get_string('status'),
    ];

    foreach ($filteredrows as $row) {
        $competency = format_string($row->shortname);
        if ($row->idnumber !== '') {
            $competency .= html_writer::div(s($row->idnumber), 'text-muted small');
        }
        if ($row->frameworkname !== '') {
            $competency .= html_writer::div(s($row->frameworkname), 'text-muted small');
        }
        $roles = [];
        foreach ($row->roles as $role) {
            $roles[] = format_string((string)$role->name);
        }
        $rolehtml = $roles ? implode(', ', $roles) : '—';

        if (!$row->targeted) {
            $kindhtml = html_writer::span(get_string('competencystatus_outsideprofile', 'local_kbacorporate'),
                'badge badge-light');
        } else {
            $kindlabel = $row->required ? get_string('employeegapmandatory', 'local_kbacorporate')
                : get_string('employeegapsupporting', 'local_kbacorporate');
            $prioritylabel = get_string('rolepriority_' . $row->priority, 'local_kbacorporate');
            $kindhtml = html_writer::span($kindlabel, $row->required ? 'badge badge-info' : 'badge badge-light') .
                html_writer::div(s($prioritylabel), 'text-muted small');
        }

        $scaleid = (int)($row->competencyscaleid ?: $row->frameworkscaleid);
        $current = $row->currentlevel === null
            ? get_string('competencynotassessed', 'local_kbacorporate')
            : \local_kbacorporate\local\competencies::level_label($row->currentlevel, $scaleid);
        if ($row->currentlevel === null && $row->provisionallevel !== null) {
            $current .= html_writer::div(get_string('employeegapprovisional', 'local_kbacorporate',
                \local_kbacorporate\local\competencies::level_label($row->provisionallevel, $scaleid)),
                'text-muted small');
        }
        $target = $row->targetlevel === null ? '—'
            : \local_kbacorporate\local\competencies::level_label($row->targetlevel, $scaleid);
        $gap = $row->gap === null ? '—' : (string)$row->gap;

        if ($row->latestassessment) {
            $sourcekey = 'employeeprofilesource_' . $row->assessment_source;
            $sourcelabel = get_string_manager()->string_exists($sourcekey, 'local_kbacorporate')
                ? get_string($sourcekey, 'local_kbacorporate') : $row->assessment_source;
            $assessmentparts = [$sourcelabel];
            if ($row->latestassessment->actorname !== '') {
                $assessmentparts[] = $row->latestassessment->actorname;
            }
            if ((int)$row->latestassessment->timecreated > 0) {
                $assessmentparts[] = userdate((int)$row->latestassessment->timecreated,
                    get_string('strftimedateshort', 'langconfig'));
            }
            $assessmenthtml = s(implode(' · ', $assessmentparts));
        } else {
            $assessmenthtml = get_string('employeeprofilesource_none', 'local_kbacorporate');
        }

        $courselinks = [];
        foreach ($row->courses as $course) {
            $coursename = format_string((string)($course->fullname ?? $course->coursename ?? ''));
            if ($coursename === '') {
                continue;
            }
            if (!empty($course->url)) {
                $courselinks[] = html_writer::link($course->url, $coursename);
            } else if (!empty($course->courseid)) {
                $courselinks[] = html_writer::link(new moodle_url('/course/view.php', [
                    'id' => (int)$course->courseid,
                ]), $coursename);
            } else {
                $courselinks[] = $coursename;
            }
        }
        $coursehtml = $courselinks ? implode(html_writer::empty_tag('br'), $courselinks)
            : get_string('matrixnocourse', 'local_kbacorporate');
        if ($row->plan) {
            $coursehtml .= html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', [
                'id' => (int)$row->plan->planid,
            ]), get_string('employeeprofileinplan', 'local_kbacorporate')), 'text-muted small');
        }

        $historyurl = new moodle_url('/local/kbacorporate/employee_competency_history.php', [
            'companyid' => $companyid,
            'userid' => $userid,
            'competencyid' => $row->competencyid,
        ]);
        $historyhtml = html_writer::link($historyurl,
            get_string('employeeprofilehistorycount', 'local_kbacorporate', $row->historycount));

        $statuskey = $row->status === \local_kbacorporate\local\employee_competency_profile::STATUS_OUTSIDE
            ? 'competencystatus_outsideprofile' : 'employeegaprow_' . $row->status;
        $statuslabel = get_string($statuskey, 'local_kbacorporate');
        $statusclass = 'badge badge-secondary';
        if ($row->status === \local_kbacorporate\local\employee_competency_profile::STATUS_MET) {
            $statusclass = 'badge badge-success';
        } else if ($row->status === \local_kbacorporate\local\employee_competency_profile::STATUS_GAP) {
            $statusclass = 'badge badge-warning';
        }
        $statushtml = html_writer::span($statuslabel, $statusclass);

        $table->data[] = [
            $competency,
            $rolehtml,
            $kindhtml,
            $current,
            $target,
            $gap,
            $assessmenthtml,
            $coursehtml,
            $historyhtml,
            $statushtml,
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

if ($fullaccess) {
    echo html_writer::start_div('kbacorp-toolbar');
    if (has_capability('local/kbacorporate:managecompetencyaudits', $context) && $summary->unassessed > 0) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/competency_audits.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('employeegapcreateaudit', 'local_kbacorporate'), 'get');
    }
    if (has_capability('local/kbacorporate:managedevelopmentplans', $context) && $summary->gaps > 0) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('gaplearningplanpreviewbutton', 'local_kbacorporate'), 'get');
    }
    echo html_writer::end_div();
}

echo $navigation;
echo $OUTPUT->footer();
