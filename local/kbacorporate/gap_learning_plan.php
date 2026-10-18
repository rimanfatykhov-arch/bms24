<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managedevelopmentplans', $context);

$companies = \local_kbacorporate\local\access::get_allowed_companies($USER->id);
$companyid = optional_param('companyid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
if (!$companyid && $companies) {
    $first = reset($companies);
    $companyid = (int)$first->id;
}
if ($companyid <= 0) {
    throw new invalid_parameter_exception('Company is required.');
}
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id);

$employees = [];
if ($visibleids) {
    list($insql, $params) = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'glpu');
    $employees = array_values($DB->get_records_select('user', "id {$insql} AND deleted = 0", $params,
        'lastname, firstname', 'id,firstname,lastname,email'));
}
$employeeoptions = [0 => get_string('choose')];
foreach ($employees as $employee) {
    $employeeoptions[(int)$employee->id] = fullname($employee);
}
if ($userid > 0 && !in_array($userid, array_map('intval', $visibleids), true)) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}

$url = new moodle_url('/local/kbacorporate/gap_learning_plan.php', ['companyid' => $companyid, 'userid' => $userid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('gaplearningplanbuilder', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));
$moodleavailable = \local_kbacorporate\local\moodle_learning_plans::available();

if (data_submitted() && confirm_sesskey()) {
    if ($userid <= 0) {
        throw new invalid_parameter_exception('Employee is required.');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if (!$moodleavailable) {
        throw new moodle_exception('moodlelearningplansdisabled', 'local_kbacorporate');
    }
    if ($action === 'build') {
        $selectedids = optional_param_array('selectedcompetencyids', [], PARAM_INT);
        $result = \local_kbacorporate\local\development_plans::build_gap_learning_plan(
            $companyid,
            $userid,
            (int)$USER->id,
            $selectedids
        );
        $message = get_string('gaplearningplanbuildresult', 'local_kbacorporate', (object)[
            'added' => $result->added,
            'updated' => $result->updated,
            'unchanged' => $result->unchanged,
            'retained' => $result->retained,
        ]);
        $message .= ' ' . get_string('gaplearningplanselectionresult', 'local_kbacorporate', (object)[
            'selected' => $result->selected,
            'skipped' => $result->skipped,
        ]);
        $notify = \core\output\notification::NOTIFY_SUCCESS;
        if ($result->moodle) {
            $message .= ' ' . get_string('gaplearningplanmoodlesyncresult', 'local_kbacorporate', (object)[
                'planid' => $result->moodle->moodleplanid,
                'competencies' => $result->moodle->competencies,
                'added' => $result->moodle->added,
                'removed' => $result->moodle->removed,
            ]);
        } else if (!empty($result->moodleerror)) {
            $message .= ' ' . get_string('gaplearningplanmoodlesyncdeferred', 'local_kbacorporate', $result->moodleerror);
            $notify = \core\output\notification::NOTIFY_WARNING;
        }
        redirect(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
            'companyid' => $companyid, 'userid' => $userid, 'built' => 1,
        ]), $message, null, $notify);
    }
    if ($action === 'sync') {
        $preview = \local_kbacorporate\local\development_plans::preview_gap_learning_plan($companyid, $userid);
        if (!$preview->plan) {
            throw new moodle_exception('gaplearningplannoplan', 'local_kbacorporate');
        }
        $sync = \local_kbacorporate\local\moodle_learning_plans::ensure_plan((int)$preview->plan->id);
        redirect($url, get_string('gaplearningplanmoodlesyncresult', 'local_kbacorporate', (object)[
            'planid' => $sync->moodleplanid,
            'competencies' => $sync->competencies,
            'added' => $sync->added,
            'removed' => $sync->removed,
        ]), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    throw new moodle_exception('invalidaction');
}

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/role_learning_matrix.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('rolelearningmatrix', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), get_string('employeegap', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/moodle_learning_plans.php', ['companyid' => $companyid]),
        get_string('moodlelearningplansintegration', 'local_kbacorporate')),
];
$navigation = html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('gaplearningplanbuilder', 'local_kbacorporate'));
echo html_writer::div(get_string('gaplearningplanbuilderdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
if (!$moodleavailable) {
    echo $OUTPUT->notification(get_string('moodlelearningplansdisabled', 'local_kbacorporate'), 'warning');
}

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-toolbar']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::label(get_string('employee', 'local_kbacorporate'), 'gap-learning-userid');
echo html_writer::select($employeeoptions, 'userid', $userid, false, ['id' => 'gap-learning-userid']);
echo html_writer::tag('button', get_string('preview', 'local_kbacorporate'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if ($userid <= 0) {
    echo $OUTPUT->notification(get_string('gaplearningplanchooseemployee', 'local_kbacorporate'), 'info');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$preview = \local_kbacorporate\local\development_plans::preview_gap_learning_plan($companyid, $userid);
$selectedemployee = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,email', MUST_EXIST);

echo $OUTPUT->heading(fullname($selectedemployee), 3);
$cards = [
    [$preview->counts->eligible, get_string('gaplearningplaneligible', 'local_kbacorporate')],
    [$preview->counts->blocked, get_string('gaplearningplanblocked', 'local_kbacorporate')],
    [$preview->counts->withcourse . '/' . $preview->counts->eligible,
        get_string('gaplearningplancoursecoverage', 'local_kbacorporate')],
    [$preview->counts->mandatorywithoutcourse, get_string('gaplearningplanmandatorywithoutcourse', 'local_kbacorporate')],
];
echo html_writer::start_div('kbacorp-metric-grid kbacorp-metric-grid-4');
foreach ($cards as [$value, $label]) {
    echo html_writer::div(html_writer::div(s((string)$value), 'kbacorp-metric-value') .
        html_writer::div(s($label), 'kbacorp-metric-label'), 'kbacorp-metric-card');
}
echo html_writer::end_div();

$readinessclass = in_array($preview->readiness, ['ready', 'nogaps'], true) ? 'success' :
    ($preview->readiness === 'locked' ? 'warning' : 'info');
echo $OUTPUT->notification(get_string('gaplearningplanreadiness_' . $preview->readiness,
    'local_kbacorporate', $preview->counts), $readinessclass);

echo html_writer::div(get_string('gaplearningplanschedulelegend', 'local_kbacorporate'),
    'alert alert-light border');

if (!$preview->rows) {
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

if ($preview->counts->retained > 0) {
    echo $OUTPUT->notification(get_string('gaplearningplanretainednotice', 'local_kbacorporate', $preview->counts->retained), 'info');
}
if ($preview->locked) {
    echo $OUTPUT->notification(get_string('gaplearningplanlockednotice', 'local_kbacorporate'), 'warning');
}
if ($preview->counts->blocked > 0) {
    echo $OUTPUT->notification(get_string('gaplearningplanblockednotice', 'local_kbacorporate', $preview->counts->blocked), 'warning');
}
if ($preview->counts->mandatorywithoutcourse > 0) {
    echo $OUTPUT->notification(get_string('gaplearningplanmandatorycoursewarning', 'local_kbacorporate',
        $preview->counts->mandatorywithoutcourse), 'warning');
}

$canbuild = !$preview->locked && $moodleavailable && $preview->counts->eligible > 0;
if ($canbuild) {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/kbacorporate/study_plan_provision.php'))->out(false),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'review']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('gaplearningplaninclude', 'local_kbacorporate'),
    get_string('gaplearningplansequence', 'local_kbacorporate'),
    get_string('competencylabel', 'local_kbacorporate'),
    get_string('gaplearningplanmandatory', 'local_kbacorporate'),
    get_string('currentlevel', 'local_kbacorporate'),
    get_string('targetlevel', 'local_kbacorporate'),
    get_string('competencygap', 'local_kbacorporate'),
    get_string('priority', 'local_kbacorporate'),
    get_string('recommendedcourse', 'local_kbacorporate'),
    get_string('gaplearningplanduedate', 'local_kbacorporate'),
    get_string('gaplearningplanaction', 'local_kbacorporate'),
];
foreach ($preview->rows as $row) {
    if ($canbuild && $row->eligible) {
        $include = html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'selectedcompetencyids[]',
            'value' => (int)$row->competencyid,
            'checked' => 'checked',
            'aria-label' => get_string('gaplearningplanincludecompetency', 'local_kbacorporate', $row->shortname),
        ]);
    } else {
        $include = html_writer::span('—', 'text-muted');
    }
    $sequence = $row->eligible
        ? get_string('gaplearningplanphasevalue', 'local_kbacorporate', (object)[
            'phase' => $row->phase,
            'sequence' => $row->sequence,
        ])
        : '—';
    $competency = format_string($row->shortname) . ($row->idnumber !== '' ?
        html_writer::div(s($row->idnumber), 'text-muted small') : '');
    $mandatory = $row->required ? get_string('yes') : get_string('no');
    if ($row->currentlevel === null) {
        if ($row->provisionallevel !== null) {
            $current = get_string('gaplearningplanpendinglevel', 'local_kbacorporate', $row->provisionallevel);
        } else {
            $current = get_string('competencynotassessed', 'local_kbacorporate');
        }
    } else {
        $current = (string)$row->currentlevel;
    }
    $gap = $row->gap === null ? '—' : (string)$row->gap;
    $priority = get_string('rolepriority_' . $row->priority, 'local_kbacorporate');
    if ($row->courseid > 0) {
        $course = html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]),
            format_string($row->coursename));
        if ($row->courseprogress !== null) {
            $course .= html_writer::div(get_string('gaplearningplancourseprogress', 'local_kbacorporate',
                (int)$row->courseprogress), 'text-muted small');
        }
    } else {
        $course = html_writer::span(get_string('matrixnocourse', 'local_kbacorporate'), 'text-muted');
    }
    $duedate = $row->eligible ? userdate((int)$row->duedate, get_string('strftimedate', 'langconfig')) : '—';
    $action = get_string('gaplearningplanaction_' . $row->action, 'local_kbacorporate');
    if ($row->warning !== '') {
        $action .= html_writer::div(get_string('gaplearningplanwarning_' . $row->warning,
            'local_kbacorporate'), 'text-warning small');
    }
    $table->data[] = [
        $include,
        s($sequence),
        $competency,
        s($mandatory),
        s($current),
        (int)$row->targetlevel,
        s($gap),
        s($priority),
        $course,
        s($duedate),
        $action,
    ];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');

if ($canbuild) {
    echo html_writer::div(
        html_writer::tag('button', get_string('studyplanprovisionreview', 'local_kbacorporate'), [
            'type' => 'submit', 'class' => 'btn btn-primary',
        ]),
        'kbacorp-toolbar'
    );
    echo html_writer::end_tag('form');
}

echo html_writer::start_div('kbacorp-toolbar');
if ($preview->locked && $preview->plan && $moodleavailable) {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'sync']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('button', get_string('syncmoodlelearningplan', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_tag('form');
}
if ($preview->plan) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => (int)$preview->plan->id]),
        get_string('opendevelopmentplan', 'local_kbacorporate'), 'get');
    $moodleplan = \local_kbacorporate\local\moodle_learning_plans::linked_plan((int)$preview->plan->id);
    if ($moodleplan) {
        echo $OUTPUT->single_button(\local_kbacorporate\local\moodle_learning_plans::plan_url((int)$moodleplan->get('id')),
            get_string('openmoodlelearningplan', 'local_kbacorporate'), 'get');
    }
}
echo html_writer::end_div();

echo $navigation;
echo $OUTPUT->footer();
