<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managerolecompetencies', $context);
$canmanageplans = has_capability('local/kbacorporate:managedevelopmentplans', $context);

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
if ($companyid <= 0) {
    throw new invalid_parameter_exception('Company is required.');
}
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$roleid = optional_param('roleid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
    'companyid' => $companyid,
    'roleid' => $roleid,
    'userid' => $userid,
]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('rolelearningmatrix', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

$roles = \local_kbacorporate\local\business_roles::get_catalog($companyid, false);
if ($roleid > 0) {
    $roles = array_values(array_filter($roles, static function($role) use ($roleid) {
        return (int)$role->id === $roleid;
    }));
}

$sql = "SELECT u.id, u.firstname, u.lastname, u.email
          FROM {local_kbacorp_member} m
          JOIN {user} u ON u.id = m.userid
         WHERE m.companyid = :companyid
           AND m.isemployee = 1
           AND u.deleted = 0
      ORDER BY u.lastname, u.firstname";
$employees = array_values($DB->get_records_sql($sql, ['companyid' => $companyid]));
$selectedemployee = null;
foreach ($employees as $employee) {
    if ((int)$employee->id === $userid) {
        $selectedemployee = $employee;
        break;
    }
}
if ($userid > 0 && !$selectedemployee) {
    throw new invalid_parameter_exception('Selected user is not an employee of this company.');
}

$snapshotmap = [];
if ($selectedemployee) {
    $snapshot = \local_kbacorporate\local\competencies::get_user_snapshot($userid, $companyid, false);
    foreach ($snapshot['competencies'] as $row) {
        $snapshotmap[(int)$row->competencyid] = $row;
    }
}

$coursemap = [];
if ($DB->get_manager()->table_exists(new xmldb_table('local_kbacorp_course')) &&
        $DB->get_manager()->table_exists(new xmldb_table('competency_coursecomp'))) {
    $coursesql = "SELECT cc.id AS mapid, cc.competencyid, c.id AS courseid, c.fullname
                    FROM {local_kbacorp_course} kc
                    JOIN {course} c ON c.id = kc.courseid
                    JOIN {competency_coursecomp} cc ON cc.courseid = c.id
                   WHERE kc.companyid = :companyid
                     AND kc.active = 1
                     AND c.visible = 1
                ORDER BY cc.competencyid, c.fullname";
    foreach ($DB->get_records_sql($coursesql, ['companyid' => $companyid]) as $course) {
        $coursemap[(int)$course->competencyid][] = $course;
    }
}

$planitems = [];
$plan = null;
$moodleplan = null;
$moodleplancompetencies = [];
$moodleplansavailable = \local_kbacorporate\local\moodle_learning_plans::available();
if ($selectedemployee && \local_kbacorporate\local\development_plans::available()) {
    $plan = $DB->get_record_select('local_kbacorp_devplan',
        'companyid = :companyid AND userid = :userid AND status IN (:draft, :pending, :active)', [
            'companyid' => $companyid,
            'userid' => $userid,
            'draft' => \local_kbacorporate\local\development_plans::STATUS_DRAFT,
            'pending' => \local_kbacorporate\local\development_plans::STATUS_PENDING,
            'active' => \local_kbacorporate\local\development_plans::STATUS_ACTIVE,
        ], '*', IGNORE_MULTIPLE);
    if ($plan) {
        foreach (\local_kbacorporate\local\development_plans::get_items((int)$plan->id) as $item) {
            $planitems[(int)$item->competencyid] = $item;
        }
        if ($moodleplansavailable) {
            $moodleplan = \local_kbacorporate\local\moodle_learning_plans::linked_plan((int)$plan->id);
            if ($moodleplan) {
                $moodleplancompetencies = array_fill_keys(
                    \local_kbacorporate\local\moodle_learning_plans::plan_competency_ids((int)$moodleplan->get('id')), true);
            }
        }
    }
}

$roleoptions = [0 => get_string('allbusinessroles', 'local_kbacorporate')];
foreach (\local_kbacorporate\local\business_roles::get_catalog($companyid, false) as $role) {
    $roleoptions[(int)$role->id] = format_string($role->name);
}
$employeeoptions = [0 => get_string('matrixnoselectedemployee', 'local_kbacorporate')];
foreach ($employees as $employee) {
    $employeeoptions[(int)$employee->id] = fullname($employee);
}

$rows = [];
$requirementcount = 0;
$domainrequirementcount = 0;
$coursecovered = 0;
$gapcount = 0;
foreach ($roles as $role) {
    foreach (\local_kbacorporate\local\business_roles::get_role_competencies((int)$role->id) as $req) {
        if (\local_kbacorporate\local\business_roles::is_domain_competency_code((string)$req->idnumber)) {
            $domainrequirementcount++;
            continue;
        }
        $requirementcount++;
        $courses = $coursemap[(int)$req->competencyid] ?? [];
        if ($courses) {
            $coursecovered++;
        }
        $current = null;
        $gap = null;
        if (isset($snapshotmap[(int)$req->competencyid])) {
            $snap = $snapshotmap[(int)$req->competencyid];
            $current = $snap->currentlevel;
            if ($current !== null) {
                $gap = max(0, (int)$req->targetlevel - (int)$current);
                if ($gap > 0) {
                    $gapcount++;
                }
            }
        }
        $rows[] = (object)[
            'role' => $role,
            'requirement' => $req,
            'courses' => $courses,
            'current' => $current,
            'gap' => $gap,
            'planitem' => $planitems[(int)$req->competencyid] ?? null,
        ];
    }
}

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]),
        get_string('businessroles', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/development_plans.php', ['companyid' => $companyid]),
        get_string('developmentplans', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeegap', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('gaplearningplanbuilder', 'local_kbacorporate')),
];
if ($roleid > 0) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/role_course_coverage.php', [
        'companyid' => $companyid, 'roleid' => $roleid,
    ]), get_string('rolecoursecoverage', 'local_kbacorporate'));
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/role_course_coverage_integrity.php', [
        'companyid' => $companyid, 'roleid' => $roleid,
    ]), get_string('coursecoverageintegrity', 'local_kbacorporate'));
}
$navigationlinks[] = html_writer::link(
    new moodle_url('/local/kbacorporate/moodle_learning_plans.php', ['companyid' => $companyid]),
    get_string('moodlelearningplansintegration', 'local_kbacorporate')
);
$navigation = html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('rolelearningmatrix', 'local_kbacorporate'));
echo html_writer::div(get_string('rolelearningmatrixdesc', 'local_kbacorporate'), 'kbacorp-pagelead');

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-toolbar']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::label(get_string('businessrole', 'local_kbacorporate'), 'matrix-roleid');
echo html_writer::select($roleoptions, 'roleid', $roleid, false, ['id' => 'matrix-roleid']);
echo html_writer::label(get_string('matrixemployee', 'local_kbacorporate'), 'matrix-userid');
echo html_writer::select($employeeoptions, 'userid', $userid, false, ['id' => 'matrix-userid']);
echo html_writer::tag('button', get_string('apply', 'local_kbacorporate'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

$cards = [
    [count($roles), get_string('matrixroles', 'local_kbacorporate')],
    [$requirementcount, get_string('matrixrequirements', 'local_kbacorporate')],
    [$coursecovered . '/' . $requirementcount, get_string('matrixcoursecoverage', 'local_kbacorporate')],
    [$selectedemployee ? $gapcount : '—', get_string('matrixgaps', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4');
foreach ($cards as [$value, $label]) {
    echo html_writer::div(html_writer::div(s((string)$value), 'kbacorp-metric-value') .
        html_writer::div(s($label), 'kbacorp-metric-label'), 'kbacorp-metric-card');
}
echo html_writer::end_div();

if ($domainrequirementcount > 0) {
    $integrityurl = new moodle_url('/local/kbacorporate/business_roles.php', ['companyid' => $companyid]);
    if ($roleid > 0) {
        $integrityurl = new moodle_url('/local/kbacorporate/role_profile_integrity.php', [
            'companyid' => $companyid, 'roleid' => $roleid,
        ]);
    }
    echo $OUTPUT->notification(
        get_string('matrixdomainnodesignored', 'local_kbacorporate', $domainrequirementcount) . ' ' .
        html_writer::link($integrityurl, get_string('roleprofileopenintegrity', 'local_kbacorporate')),
        'warning'
    );
}

if (!$rows) {
    echo $OUTPUT->notification(get_string('matrixnorows', 'local_kbacorporate'), 'info');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-role-learning-matrix';
$table->head = [
    get_string('businessrole', 'local_kbacorporate'),
    get_string('competencylabel', 'local_kbacorporate'),
    get_string('targetlevel', 'local_kbacorporate'),
    get_string('currentlevel', 'local_kbacorporate'),
    get_string('competencygap', 'local_kbacorporate'),
    get_string('priority', 'local_kbacorporate'),
    get_string('matrixlearningplan', 'local_kbacorporate'),
    get_string('recommendedcourses', 'local_kbacorporate'),
];
foreach ($rows as $row) {
    $req = $row->requirement;
    $role = $row->role;
    $rolelink = html_writer::link(new moodle_url('/local/kbacorporate/business_role_profile.php', [
        'roleid' => (int)$role->id, 'companyid' => $companyid,
    ]), format_string($role->name));
    $competency = format_string($req->shortname) . ($req->idnumber ? html_writer::div(s($req->idnumber), 'text-muted small') : '');
    $currenttext = $selectedemployee ? ($row->current === null ? '—' : (string)$row->current) : '—';
    $gaptext = $selectedemployee ? ($row->current === null ? '?' : (string)$row->gap) : '—';
    $plantext = '—';
    if ($selectedemployee) {
        $competencyid = (int)$req->competencyid;
        if ($moodleplan && isset($moodleplancompetencies[$competencyid])) {
            $plantext = html_writer::link(
                \local_kbacorporate\local\moodle_learning_plans::plan_url((int)$moodleplan->get('id')),
                get_string('matrixinplan', 'local_kbacorporate'),
                ['target' => '_blank', 'rel' => 'noopener']
            );
            if ($row->planitem) {
                $plantext .= html_writer::div(get_string('kbaextensionmetadata', 'local_kbacorporate') .
                    ' · ' . s((string)$row->planitem->status), 'text-muted small');
            }
        } else if ($row->planitem && $plan && $moodleplansavailable) {
            $plantext = html_writer::span(get_string('moodlelearningplansyncneeded', 'local_kbacorporate'), 'badge badge-warning');
        } else if ($row->current === null || ($row->gap !== null && $row->gap > 0)) {
            $plantext = get_string('matrixnotinplan', 'local_kbacorporate');
        }
    }
    $courselinks = [];
    foreach ($row->courses as $course) {
        $courselinks[] = html_writer::link(new moodle_url('/course/view.php', ['id' => (int)$course->courseid]),
            format_string($course->fullname));
    }
    $table->data[] = [
        $rolelink,
        $competency,
        (int)$req->targetlevel,
        $currenttext,
        $gaptext,
        s((string)$req->priority) . (!empty($req->required) ? ' · ' . get_string('bmsmandatorycriterion', 'local_kbacorporate') : ''),
        $plantext,
        $courselinks ? implode(html_writer::empty_tag('br'), $courselinks) : get_string('matrixnocourse', 'local_kbacorporate'),
    ];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');

if ($selectedemployee) {
    echo html_writer::start_div('kbacorp-toolbar');
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeegapopen', 'local_kbacorporate'), 'get');
    if ($moodleplan) {
        echo $OUTPUT->single_button(
            \local_kbacorporate\local\moodle_learning_plans::plan_url((int)$moodleplan->get('id')),
            get_string('openmoodlelearningplan', 'local_kbacorporate'), 'get');
        if ($plan) {
            echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => (int)$plan->id]),
                get_string('kbaextensionmetadata', 'local_kbacorporate'), 'get');
        }
    } else if ($plan && $moodleplansavailable) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/moodle_learning_plans.php', [
            'companyid' => $companyid,
        ]), get_string('syncmoodlelearningplan', 'local_kbacorporate'), 'get');
    } else if (!$plan && $canmanageplans) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('gaplearningplanpreviewbutton', 'local_kbacorporate'), 'get');
    }
    if ($canmanageplans && $plan) {
        echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
            'companyid' => $companyid, 'userid' => $userid,
        ]), get_string('gaplearningplanrefreshbutton', 'local_kbacorporate'), 'get');
    }
    echo html_writer::end_div();
}

echo $navigation;
echo $OUTPUT->footer();
