<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managedevelopmentplans', $context);

$companyid = required_param('companyid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$completed = optional_param('completed', 0, PARAM_BOOL);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = array_map('intval', \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id));
if ($userid <= 0 || !in_array($userid, $visibleids, true)) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}
$employee = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
    'id,firstname,lastname,email', MUST_EXIST);

$url = new moodle_url('/local/kbacorporate/study_plan_provision.php', [
    'companyid' => $companyid,
    'userid' => $userid,
]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('studyplanprovisiontitle', 'local_kbacorporate'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacorporate'));

$preview = null;
$result = null;
if (data_submitted()) {
    require_sesskey();
    $action = required_param('action', PARAM_ALPHA);
    $selectedids = optional_param_array('selectedcompetencyids', [], PARAM_INT);
    if ($action === 'review') {
        $preview = \local_kbacorporate\local\study_plan_provisioning::preview(
            $companyid,
            $userid,
            $selectedids
        );
    } else if ($action === 'provision') {
        if (!optional_param('confirmprovision', 0, PARAM_BOOL)) {
            throw new moodle_exception('studyplanprovisionconfirmrequired', 'local_kbacorporate');
        }
        $result = \local_kbacorporate\local\study_plan_provisioning::provision(
            $companyid,
            $userid,
            (int)$USER->id,
            $selectedids
        );
        $SESSION->local_kbacorporate_study_plan_provision_result = $result;
        redirect(new moodle_url('/local/kbacorporate/study_plan_provision.php', [
            'companyid' => $companyid,
            'userid' => $userid,
            'completed' => 1,
        ]));
    } else {
        throw new moodle_exception('invalidaction');
    }
} else if ($completed) {
    if (!empty($SESSION->local_kbacorporate_study_plan_provision_result)) {
        $candidate = $SESSION->local_kbacorporate_study_plan_provision_result;
        if ((int)$candidate->companyid === $companyid && (int)$candidate->userid === $userid) {
            $result = $candidate;
        }
    }
}

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => $companyid,
        'userid' => $userid,
    ]), get_string('employeegap', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
        'companyid' => $companyid,
        'userid' => $userid,
    ]), get_string('gaplearningplanbuilder', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/moodle_learning_plans.php', [
        'companyid' => $companyid,
    ]), get_string('moodlelearningplansintegration', 'local_kbacorporate')),
];
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation',
    ['role' => 'navigation']
);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('studyplanprovisiontitle', 'local_kbacorporate'));
echo html_writer::div(get_string('studyplanprovisiondesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo $OUTPUT->heading(fullname($employee), 3);

if ($result) {
    $notificationtype = $result->complete ? 'success' : 'warning';
    echo $OUTPUT->notification(
        get_string($result->complete ? 'studyplanprovisionsuccess' : 'studyplanprovisionpartial',
            'local_kbacorporate', (object)[
                'kbaplanid' => (int)$result->kbaplanid,
                'moodleplanid' => (int)$result->moodleplanid,
            ]),
        $notificationtype
    );

    $cards = [
        [(int)$result->kbaplanid, get_string('studyplanprovisionkbaplan', 'local_kbacorporate')],
        [$result->moodleplanid > 0 ? (int)$result->moodleplanid : '—',
            get_string('studyplanprovisionmoodleplan', 'local_kbacorporate')],
        [(int)$result->moodlecompetencies, get_string('studyplanprovisioncompetencies', 'local_kbacorporate')],
        [count((array)$result->templates), get_string('studyplanprovisiontemplates', 'local_kbacorporate')],
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

    $summary = new html_table();
    $summary->attributes['class'] = 'generaltable kbacorp-report';
    $summary->data = [
        [get_string('studyplanprovisionkbastatus', 'local_kbacorporate'),
            get_string('developmentplanstatus_' . $result->planstatus, 'local_kbacorporate')],
        [get_string('studyplanprovisionkbachanges', 'local_kbacorporate'),
            get_string('studyplanprovisionkbachangesvalue', 'local_kbacorporate', (object)[
                'added' => (int)$result->added,
                'updated' => (int)$result->updated,
                'unchanged' => (int)$result->unchanged,
                'retained' => (int)$result->retained,
            ])],
        [get_string('studyplanprovisionmoodlestatus', 'local_kbacorporate'),
            $result->moodlestatus === null ? '—' :
                \local_kbacorporate\local\moodle_learning_plans::plan_status_name((int)$result->moodlestatus)],
        [get_string('studyplanprovisionverification', 'local_kbacorporate'),
            get_string($result->verification->ok ? 'studyplanprovisionverificationok' :
                'studyplanprovisionverificationfailed', 'local_kbacorporate', (object)[
                    'expected' => (int)$result->verification->expected,
                    'actual' => (int)$result->verification->actual,
                    'missing' => count((array)$result->verification->missing),
                    'extra' => count((array)$result->verification->extra),
                ])],
    ];
    echo html_writer::div(html_writer::table($summary), 'kbacorp-table-scroll');

    foreach ((array)$result->warnings as $warning) {
        echo $OUTPUT->notification(s((string)$warning), 'warning');
    }
    echo $OUTPUT->notification(get_string('studyplanprovisionnoenrolment', 'local_kbacorporate'), 'info');

    echo html_writer::start_div('kbacorp-toolbar');
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/development_plan.php', [
        'id' => (int)$result->kbaplanid,
    ]), get_string('opendevelopmentplan', 'local_kbacorporate'), 'get');
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/study_plan_progress.php', [
        'id' => (int)$result->kbaplanid,
    ]), get_string('studyplanprogresstitle', 'local_kbacorporate'), 'get');
    if ($result->moodleplanid > 0) {
        echo $OUTPUT->single_button(
            \local_kbacorporate\local\moodle_learning_plans::plan_url((int)$result->moodleplanid),
            get_string('openmoodlelearningplan', 'local_kbacorporate'),
            'get'
        );
    }
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
        'companyid' => $companyid,
        'userid' => $userid,
    ]), get_string('studyplanprovisionbackpreview', 'local_kbacorporate'), 'get');
    echo html_writer::end_div();

    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

if (!$preview) {
    echo $OUTPUT->notification(get_string('studyplanprovisionnoselection', 'local_kbacorporate'), 'info');
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
        'companyid' => $companyid,
        'userid' => $userid,
    ]), get_string('studyplanprovisionbackpreview', 'local_kbacorporate'), 'get');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$cards = [
    [$preview->counts->selected, get_string('studyplanprovisionselected', 'local_kbacorporate')],
    [$preview->counts->withcourse . '/' . $preview->counts->selected,
        get_string('studyplanprovisionwithcourse', 'local_kbacorporate')],
    [$preview->counts->mandatorywithoutcourse,
        get_string('studyplanprovisionmandatorywithoutcourse', 'local_kbacorporate')],
    [$preview->counts->roles, get_string('studyplanprovisionroles', 'local_kbacorporate')],
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

if ($preview->counts->ignored > 0) {
    echo $OUTPUT->notification(get_string('studyplanprovisionignored', 'local_kbacorporate',
        $preview->counts->ignored), 'warning');
}
if ($preview->counts->mandatorywithoutcourse > 0) {
    echo $OUTPUT->notification(get_string('studyplanprovisioncoursewarning', 'local_kbacorporate',
        $preview->counts->mandatorywithoutcourse), 'warning');
}
if (!$preview->canprovision) {
    echo $OUTPUT->notification(get_string('studyplanprovisionblocked', 'local_kbacorporate'), 'warning');
}

echo $OUTPUT->heading(get_string('studyplanprovisionactions', 'local_kbacorporate'), 3);
$actions = new html_table();
$actions->attributes['class'] = 'generaltable kbacorp-report';
$actions->head = [get_string('object', 'local_kbacorporate'), get_string('action')];
$actions->data[] = [get_string('developmentplan', 'local_kbacorporate'),
    get_string('studyplanprovisionaction_' . $preview->kbaplanaction, 'local_kbacorporate')];
$actions->data[] = [get_string('moodlelearningplan', 'local_kbacorporate'),
    get_string('studyplanprovisionaction_' . $preview->moodleplanaction, 'local_kbacorporate')];
foreach ($preview->templateactions as $template) {
    $actions->data[] = [get_string('moodlelearningplantemplate', 'local_kbacorporate') . ': ' .
        format_string($template->rolename),
        get_string('studyplanprovisionaction_' . $template->action, 'local_kbacorporate')];
}
echo html_writer::div(html_writer::table($actions), 'kbacorp-table-scroll');

echo $OUTPUT->heading(get_string('studyplanprovisioncomposition', 'local_kbacorporate'), 3);
$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('gaplearningplansequence', 'local_kbacorporate'),
    get_string('competencylabel', 'local_kbacorporate'),
    get_string('gaplearningplanmandatory', 'local_kbacorporate'),
    get_string('currentlevel', 'local_kbacorporate'),
    get_string('targetlevel', 'local_kbacorporate'),
    get_string('competencygap', 'local_kbacorporate'),
    get_string('priority', 'local_kbacorporate'),
    get_string('recommendedcourse', 'local_kbacorporate'),
    get_string('gaplearningplanduedate', 'local_kbacorporate'),
];
foreach ($preview->rows as $row) {
    $sequence = get_string('gaplearningplanphasevalue', 'local_kbacorporate', (object)[
        'phase' => (int)$row->phase,
        'sequence' => (int)$row->sequence,
    ]);
    $competency = format_string($row->shortname) . ($row->idnumber !== '' ?
        html_writer::div(s($row->idnumber), 'text-muted small') : '');
    $course = (int)$row->courseid > 0
        ? html_writer::link(new moodle_url('/course/view.php', ['id' => (int)$row->courseid]),
            format_string($row->coursename))
        : html_writer::span(get_string('matrixnocourse', 'local_kbacorporate'), 'text-muted');
    $table->data[] = [
        s($sequence),
        $competency,
        $row->required ? get_string('yes') : get_string('no'),
        $row->currentlevel === null ? '—' : (int)$row->currentlevel,
        (int)$row->targetlevel,
        $row->gap === null ? '—' : (int)$row->gap,
        get_string('rolepriority_' . $row->priority, 'local_kbacorporate'),
        $course,
        userdate((int)$row->duedate, get_string('strftimedate', 'langconfig')),
    ];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');

echo $OUTPUT->notification(get_string('studyplanprovisionnoenrolment', 'local_kbacorporate'), 'info');

if ($preview->canprovision) {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'provision']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    foreach ($preview->selectedids as $competencyid) {
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'selectedcompetencyids[]',
            'value' => (int)$competencyid,
        ]);
    }
    echo html_writer::start_div('form-check mb-3');
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'confirmprovision',
        'id' => 'study-plan-confirm',
        'value' => 1,
        'required' => 'required',
        'class' => 'form-check-input',
    ]);
    echo html_writer::label(get_string('studyplanprovisionconfirm', 'local_kbacorporate'),
        'study-plan-confirm', false, ['class' => 'form-check-label']);
    echo html_writer::end_div();
    echo html_writer::start_div('kbacorp-toolbar');
    echo html_writer::tag('button', get_string('studyplanprovisionapply', 'local_kbacorporate'), [
        'type' => 'submit',
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::link(new moodle_url('/local/kbacorporate/gap_learning_plan.php', [
        'companyid' => $companyid,
        'userid' => $userid,
    ]), get_string('cancel'), ['class' => 'btn btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
}

echo $navigation;
echo $OUTPUT->footer();
