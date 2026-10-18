<?php
require_once(__DIR__ . '/../../config.php');

require_login();

/** Convert one HTML date field to a stable midday Moodle timestamp. */
function local_kbacorporate_development_date(string $name): int {
    $value = required_param($name, PARAM_RAW_TRIMMED);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) ||
            !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
        throw new invalid_parameter_exception('Invalid development-plan date.');
    }
    return make_timestamp((int)$matches[1], (int)$matches[2], (int)$matches[3], 12, 0, 0);
}

$context = context_system::instance();
$planid = required_param('id', PARAM_INT);
$plan = \local_kbacorporate\local\development_plans::get_plan($planid);
$isself = (int)$plan->userid === (int)$USER->id;
$canmanage = has_capability('local/kbacorporate:managedevelopmentplans', $context) &&
    \local_kbacorporate\local\access::can_view_employee((int)$plan->companyid, $USER->id, (int)$plan->userid);
$hasmentorassignment = (int)($plan->mentorid ?? 0) === (int)$USER->id ||
    $DB->record_exists('local_kbacorp_devitem', [
        'planid' => $planid,
        'mentorid' => (int)$USER->id,
    ]);
$ismentor = $hasmentorassignment &&
    \local_kbacorporate\local\development_plans::is_valid_mentor_assignment(
        (int)$plan->companyid, (int)$USER->id);
$cancreatefollowup = has_capability('local/kbacorporate:managecompetencyaudits', $context) &&
    \local_kbacorporate\local\access::has_full_company_access((int)$plan->companyid, $USER->id);
if (!$isself && !$canmanage && !$ismentor) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}
if (data_submitted() && confirm_sesskey()) {
    $redirectmessage = get_string('changessaved');
    $action = optional_param('action', 'itemstatus', PARAM_ALPHANUMEXT);
    $items = \local_kbacorporate\local\development_plans::get_items($planid);
    $allowedids = array_map(static function($item) { return (int)$item->id; }, $items);
    if (in_array($action, ['acceptplan', 'requestchanges'], true)) {
        if (!$isself) {
            throw new moodle_exception('developmentplandecisionnotallowed', 'local_kbacorporate');
        }
        \local_kbacorporate\local\development_plans::employee_decision($planid, (int)$USER->id,
            $action === 'acceptplan', optional_param('employeecomment', '', PARAM_TEXT));
    } else if ($action === 'itemstatus') {
        if (!$canmanage) {
            throw new required_capability_exception($context,
                'local/kbacorporate:managedevelopmentplans', 'nopermissions', '');
        }
        $itemid = required_param('itemid', PARAM_INT);
        if (!in_array($itemid, $allowedids, true)) { throw new moodle_exception('invalidrecord'); }
        \local_kbacorporate\local\development_plans::set_item_status($itemid,
            required_param('itemstatus', PARAM_ALPHANUMEXT), (int)$USER->id);
    } else {
        if (!$canmanage) {
            throw new required_capability_exception($context,
                'local/kbacorporate:managedevelopmentplans', 'nopermissions', '');
        }
        if ($action === 'submitplan') {
            \local_kbacorporate\local\development_plans::submit_for_agreement($planid, (int)$USER->id);
        } else if (in_array($action, ['cancel', 'reopen'], true)) {
            \local_kbacorporate\local\development_plans::manager_transition(
                $planid, $action, (int)$USER->id);
        } else if ($action === 'synccourses') {
            $result = \local_kbacorporate\local\development_plans::sync_course_completions($planid);
            $redirectmessage = get_string('developmentcoursesyncresult', 'local_kbacorporate', $result);
        } else if ($action === 'syncmoodleplan') {
            $result = \local_kbacorporate\local\moodle_learning_plans::ensure_plan($planid);
            $redirectmessage = get_string('moodlelearningplanssyncresultone', 'local_kbacorporate', (object)[
                'planid' => $result->moodleplanid,
                'competencies' => $result->competencies,
                'added' => $result->added,
                'removed' => $result->removed,
            ]);
        } else if ($action === 'createfollowupaudit') {
            if (!$cancreatefollowup) {
                throw new required_capability_exception($context,
                    'local/kbacorporate:managecompetencyaudits', 'nopermissions', '');
            }
            $auditid = \local_kbacorporate\local\competency_audits::create_development_audit($planid, (int)$USER->id);
            redirect(new moodle_url('/local/kbacorporate/competency_reassessment.php', ['id' => $auditid]),
                get_string('reassessmentcreated', 'local_kbacorporate'));
        } else if ($action === 'updateplan') {
            \local_kbacorporate\local\development_plans::update_ipr_plan(
                $planid,
                required_param('title', PARAM_TEXT),
                optional_param('objective', '', PARAM_TEXT),
                optional_param('mentorid', 0, PARAM_INT),
                local_kbacorporate_development_date('targetdate'),
                local_kbacorporate_development_date('reviewdate'),
                (int)$USER->id
            );
        } else if ($action === 'updateitem') {
            $itemid = required_param('itemid', PARAM_INT);
            if (!in_array($itemid, $allowedids, true)) { throw new moodle_exception('invalidrecord'); }
            \local_kbacorporate\local\development_plans::update_item($itemid,
                optional_param('courseid', 0, PARAM_INT), local_kbacorporate_development_date('duedate'),
                required_param('itemstatus', PARAM_ALPHANUMEXT), (int)$USER->id);
        } else if ($action === 'deleteitem') {
            $itemid = required_param('itemid', PARAM_INT);
            if (!in_array($itemid, $allowedids, true)) { throw new moodle_exception('invalidrecord'); }
            \local_kbacorporate\local\development_plans::delete_item($itemid, (int)$USER->id);
        } else if ($action === 'additem') {
            \local_kbacorporate\local\development_plans::add_profile_item($planid,
                required_param('competencyid', PARAM_INT), optional_param('courseid', 0, PARAM_INT),
                local_kbacorporate_development_date('duedate'), (int)$USER->id);
        } else {
            throw new moodle_exception('invalidaction');
        }
    }
    redirect(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $planid]),
        $redirectmessage);
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $planid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(format_string($plan->title));
$PAGE->set_heading(format_string($plan->title));
echo $OUTPUT->header();
$navigationlinks = [];
if ($canmanage) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/development_plans.php', [
        'companyid' => (int)$plan->companyid,
    ]), '← ' . get_string('developmentplans', 'local_kbacorporate'));
} else {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/mycompetencies.php', [
        'companyid' => (int)$plan->companyid,
    ]), '← ' . get_string('mycompetencies', 'local_kbacorporate'));
}
if (has_capability('local/kbacorporate:viewdashboard', $context)) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/index.php', [
        'companyid' => (int)$plan->companyid,
    ]), get_string('backtodashboard', 'local_kbacorporate'));
}
$navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/study_plan_progress.php', [
    'id' => $planid,
]), get_string('studyplanprogresstitle', 'local_kbacorporate'));
$navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/development_plan_history.php', [
    'id' => $planid,
]), get_string('iprhistorytitle', 'local_kbacorporate'));
if ($canmanage) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/moodle_learning_plans.php', [
        'companyid' => (int)$plan->companyid,
    ]), get_string('moodlelearningplansintegration', 'local_kbacorporate'));
}
$navigation = html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'),
    $navigationlinks), 'kbacorp-page-navigation', ['role' => 'navigation']);
echo $navigation;
echo $OUTPUT->heading(format_string($plan->title), 2);
echo html_writer::div(fullname($plan) . ' · ' . format_string($plan->companyname), 'kbacorp-pagelead');
echo html_writer::div(get_string('status') . ': ' .
    get_string('developmentplanstatus_' . $plan->status, 'local_kbacorporate'),
    'alert alert-' . ($plan->status === \local_kbacorporate\local\development_plans::STATUS_ACTIVE ? 'success' : 'info'));
$moodleplan = \local_kbacorporate\local\moodle_learning_plans::available() ?
    \local_kbacorporate\local\moodle_learning_plans::linked_plan($planid) : null;
if ($moodleplan) {
    echo html_writer::div(
        html_writer::tag('strong', get_string('moodlelearningplansource', 'local_kbacorporate') . ': ') .
        html_writer::link(
            \local_kbacorporate\local\moodle_learning_plans::plan_url((int)$moodleplan->get('id')),
            get_string('moodlelearningplansourcestandard', 'local_kbacorporate') . ' #' . (int)$moodleplan->get('id'),
            ['target' => '_blank', 'rel' => 'noopener']
        ) . html_writer::div(get_string('kbaextensionmetadata', 'local_kbacorporate'), 'text-muted small'),
        'alert alert-success'
    );
} else if ($canmanage && \local_kbacorporate\local\moodle_learning_plans::available()) {
    $syncform = html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block ml-2']) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'syncmoodleplan']) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::tag('button', get_string('syncmoodlelearningplan', 'local_kbacorporate'), [
            'type' => 'submit', 'class' => 'btn btn-sm btn-primary'
        ]) . html_writer::end_tag('form');
    echo html_writer::div(get_string('moodlelearningplansyncneeded', 'local_kbacorporate') . $syncform,
        'alert alert-warning');
}
if (!empty($plan->employeecomment)) {
    echo html_writer::div(html_writer::tag('strong', get_string('employeecomment', 'local_kbacorporate') . ': ') .
        s($plan->employeecomment), 'alert alert-warning');
}
$items = \local_kbacorporate\local\development_plans::get_items($planid);
$progress = \local_kbacorporate\local\development_plans::get_progress_summary($items);
echo html_writer::start_div('kbacorp-development-summary');
foreach ([
    [$progress->percent . '%', get_string('developmentprogress', 'local_kbacorporate'), false],
    [$progress->completed . '/' . $progress->total, get_string('developmentactionscompleted', 'local_kbacorporate'), false],
    [$progress->inprogress, get_string('developmentactionsinprogress', 'local_kbacorporate'), false],
    [$progress->pendingreview, get_string('ipractionspendingreview', 'local_kbacorporate'), $progress->pendingreview > 0],
    [$progress->overdue, get_string('developmentactionsoverdue', 'local_kbacorporate'), $progress->overdue > 0],
] as $metric) {
    echo html_writer::div(html_writer::tag('strong', $metric[0]) . html_writer::span($metric[1]),
        'kbacorp-development-metric' . ($metric[2] ? ' is-overdue' : ''));
}
echo html_writer::div(html_writer::div(html_writer::span('', '', [
    'style' => 'width:' . $progress->percent . '%',
]), 'kbacorp-progressbar') . html_writer::span(get_string('developmentprogressdetail', 'local_kbacorporate', $progress)),
    'kbacorp-development-progress');
echo html_writer::end_div();
$courseoptions = [0 => get_string('nocourseassigned', 'local_kbacorporate')];
foreach (\local_kbacorporate\local\repository::get_company_courses((int)$plan->companyid) as $course) {
    if (!empty($course->active) && !empty($course->visible)) {
        $courseoptions[(int)$course->courseid] = format_string($course->fullname);
    }
}
$statuses = [
    \local_kbacorporate\local\development_plans::ITEM_PLANNED =>
        get_string('developmentitemstatus_planned', 'local_kbacorporate'),
    \local_kbacorporate\local\development_plans::ITEM_INPROGRESS =>
        get_string('developmentitemstatus_inprogress', 'local_kbacorporate'),
    \local_kbacorporate\local\development_plans::ITEM_PENDING_REVIEW =>
        get_string('developmentitemstatus_pendingreview', 'local_kbacorporate'),
    \local_kbacorporate\local\development_plans::ITEM_COMPLETED =>
        get_string('developmentitemstatus_completed', 'local_kbacorporate'),
];
$mentoroptions = [0 => get_string('iprmentornotassigned', 'local_kbacorporate')] +
    \local_kbacorporate\local\development_plans::mentor_options((int)$plan->companyid);

$iseditable = $canmanage && $plan->status === \local_kbacorporate\local\development_plans::STATUS_DRAFT;
if ($iseditable) {
    echo $OUTPUT->heading(get_string('iprplancontext', 'local_kbacorporate'), 3);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'class' => 'kbacorp-development-plan-settings kbacorp-ipr-plan-settings',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'updateplan']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('kbacorp-ipr-field kbacorp-ipr-field-wide');
    echo html_writer::label(get_string('developmentplantitle', 'local_kbacorporate'), 'development-plan-title');
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'id' => 'development-plan-title',
        'name' => 'title',
        'value' => $plan->title,
        'maxlength' => 255,
        'required' => 'required',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field kbacorp-ipr-field-wide');
    echo html_writer::label(get_string('iprobjective', 'local_kbacorporate'), 'development-plan-objective');
    echo html_writer::tag('textarea', s((string)($plan->objective ?? '')), [
        'id' => 'development-plan-objective',
        'name' => 'objective',
        'rows' => 4,
        'maxlength' => 20000,
        'placeholder' => get_string('iprobjectiveplaceholder', 'local_kbacorporate'),
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field');
    echo html_writer::label(get_string('iprplanmentor', 'local_kbacorporate'), 'development-plan-mentor');
    echo html_writer::select($mentoroptions, 'mentorid', (int)($plan->mentorid ?? 0), false, [
        'id' => 'development-plan-mentor',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field');
    echo html_writer::label(get_string('targetdate', 'local_kbacorporate'), 'development-plan-targetdate');
    echo html_writer::empty_tag('input', [
        'type' => 'date',
        'id' => 'development-plan-targetdate',
        'name' => 'targetdate',
        'value' => userdate((int)$plan->targetdate, '%Y-%m-%d'),
        'required' => 'required',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field');
    echo html_writer::label(get_string('iprreviewdate', 'local_kbacorporate'), 'development-plan-reviewdate');
    echo html_writer::empty_tag('input', [
        'type' => 'date',
        'id' => 'development-plan-reviewdate',
        'name' => 'reviewdate',
        'value' => userdate((int)($plan->reviewdate ?: $plan->targetdate), '%Y-%m-%d'),
        'required' => 'required',
    ]);
    echo html_writer::end_div();

    echo html_writer::div(
        html_writer::tag('button', get_string('savechanges'), [
            'type' => 'submit',
            'class' => 'btn btn-primary',
        ]),
        'kbacorp-ipr-field kbacorp-ipr-actions'
    );
    echo html_writer::end_tag('form');
} else {
    echo html_writer::start_div('kbacorp-ipr-plan-context');
    echo html_writer::div(
        html_writer::span(get_string('iprobjective', 'local_kbacorporate')) .
        html_writer::tag('strong', trim((string)($plan->objective ?? '')) !== '' ?
            format_text((string)$plan->objective, FORMAT_PLAIN) :
            get_string('iprnotset', 'local_kbacorporate')),
        'kbacorp-ipr-context-card'
    );
    echo html_writer::div(
        html_writer::span(get_string('iprplanmentor', 'local_kbacorporate')) .
        html_writer::tag('strong', $plan->mentorname !== '' ?
            s($plan->mentorname) : get_string('iprmentornotassigned', 'local_kbacorporate')),
        'kbacorp-ipr-context-card'
    );
    echo html_writer::div(
        html_writer::span(get_string('iprreviewdate', 'local_kbacorporate')) .
        html_writer::tag('strong', userdate(
            (int)($plan->reviewdate ?: $plan->targetdate), get_string('strftimedate', 'langconfig'))),
        'kbacorp-ipr-context-card'
    );
    echo html_writer::end_div();
}
$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-ipr-items-table';
$table->head = [
    get_string('competencylabel', 'local_kbacorporate'),
    get_string('iprdevelopmentgoal', 'local_kbacorporate'),
    get_string('iprlevels', 'local_kbacorporate'),
    get_string('iprlearningandpractice', 'local_kbacorporate'),
    get_string('iprmentorandcheckpoint', 'local_kbacorporate'),
    get_string('iprstatusanddeadline', 'local_kbacorporate'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($items as $item) {
    $current = $item->currentlevel === null ?
        get_string('competencynotassessed', 'local_kbacorporate') :
        \local_kbacorporate\local\competencies::level_label((int)$item->currentlevel);
    $target = \local_kbacorporate\local\competencies::level_label((int)$item->targetlevel);
    $priority = get_string('rolepriority_' . $item->priority, 'local_kbacorporate');
    $requiredlabel = !empty($item->required) ?
        get_string('mandatory', 'local_kbacorporate') :
        get_string('iprsupporting', 'local_kbacorporate');
    $competencyhtml = html_writer::tag('strong', format_string($item->competencyname)) .
        html_writer::div(s((string)$item->idnumber), 'text-muted small') .
        html_writer::div(
            html_writer::span($priority, 'badge badge-light mr-1') .
            html_writer::span($requiredlabel,
                !empty($item->required) ? 'badge badge-primary' : 'badge badge-secondary'),
            'mt-1'
        );

    $goalhtml = html_writer::div(nl2br(s((string)$item->goal)), 'kbacorp-ipr-goal');
    $levelshtml = html_writer::div(
        html_writer::span($current, 'kbacorp-ipr-level-current') .
        html_writer::span('→', 'kbacorp-ipr-level-arrow') .
        html_writer::span($target, 'kbacorp-ipr-level-target'),
        'kbacorp-ipr-levels'
    );

    $coursehtml = $item->courseid ?
        html_writer::link(new moodle_url('/course/view.php', ['id' => $item->courseid]),
            format_string($item->coursename), ['target' => '_blank', 'rel' => 'noopener']) :
        html_writer::span(get_string('nocourseassigned', 'local_kbacorporate'), 'text-muted');
    $practicehtml = $coursehtml;
    if (trim((string)($item->practicaltask ?? '')) !== '') {
        $practicehtml .= html_writer::div(
            html_writer::tag('strong', get_string('iprpracticaltask', 'local_kbacorporate') . ': ') .
            s(shorten_text((string)$item->practicaltask, 160)),
            'small mt-2'
        );
    }

    $mentorname = trim((string)($item->mentorname ?? '')) !== '' ?
        (string)$item->mentorname :
        (trim((string)($plan->mentorname ?? '')) !== '' ? (string)$plan->mentorname :
            get_string('iprmentornotassigned', 'local_kbacorporate'));
    $mentorhtml = html_writer::div(s($mentorname), 'kbacorp-ipr-mentor');
    if (!empty($item->checkpointdate)) {
        $mentorhtml .= html_writer::div(
            get_string('iprcheckpointshort', 'local_kbacorporate') . ': ' .
            userdate((int)$item->checkpointdate, get_string('strftimedate', 'langconfig')),
            'small text-muted mt-1'
        );
    }
    if (trim((string)($item->checkpointcomment ?? '')) !== '') {
        $mentorhtml .= html_writer::div(
            s(shorten_text((string)$item->checkpointcomment, 120)),
            'small mt-1'
        );
    }

    $statuskey = 'developmentitemstatus_' . (string)$item->status;
    $statushtml = html_writer::span(get_string($statuskey, 'local_kbacorporate'),
        'badge kbacorp-ipr-status kbacorp-ipr-status-' . s((string)$item->status));
    $duedatelabel = userdate((int)$item->duedate, get_string('strftimedate', 'langconfig'));
    if ((string)$item->status !== \local_kbacorporate\local\development_plans::ITEM_COMPLETED &&
            (int)$item->duedate > 0 && (int)$item->duedate < usergetmidnight(time())) {
        $duedatelabel .= ' ' . html_writer::span(get_string('overdue', 'local_kbacorporate'), 'badge badge-danger');
    }
    $statushtml .= html_writer::div($duedatelabel, 'small mt-2');
    if (!empty($item->timeevidencesubmitted)) {
        $statushtml .= html_writer::div(
            get_string('iprevidencesubmittedshort', 'local_kbacorporate') . ': ' .
            userdate((int)$item->timeevidencesubmitted, get_string('strftimedatetimeshort', 'langconfig')),
            'small mt-1'
        );
    }

    $actionshtml = html_writer::link(
        new moodle_url('/local/kbacorporate/development_item.php', ['id' => (int)$item->id]),
        get_string('ipropenitem', 'local_kbacorporate'),
        ['class' => 'btn btn-sm btn-outline-primary']
    );
    $table->data[] = [
        $competencyhtml,
        $goalhtml,
        $levelshtml,
        $practicehtml,
        $mentorhtml,
        $statushtml,
        $actionshtml,
    ];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
if ($plan->status === \local_kbacorporate\local\development_plans::STATUS_COMPLETED) {
    $outcomes = \local_kbacorporate\local\development_plans::get_outcome_summary($planid);
    echo $OUTPUT->heading(get_string('developmentoutcome', 'local_kbacorporate'), 3);
    echo html_writer::start_div('kbacorp-development-summary kbacorp-development-outcomes');
    foreach ([
        [$outcomes->assessed . '/' . $outcomes->total, get_string('developmentoutcomeassessed', 'local_kbacorporate')],
        [$outcomes->improved, get_string('developmentoutcomeimproved', 'local_kbacorporate')],
        [$outcomes->reached, get_string('developmentoutcomereached', 'local_kbacorporate')],
        [$outcomes->remaininggaps, get_string('developmentoutcomegaps', 'local_kbacorporate')],
    ] as $metric) {
        echo html_writer::div(html_writer::tag('strong', $metric[0]) . html_writer::span($metric[1]),
            'kbacorp-development-metric');
    }
    echo html_writer::end_div();
    $outcometable = new html_table();
    $outcometable->attributes['class'] = 'generaltable kbacorp-development-outcome-table';
    $outcometable->head = [get_string('competencylabel', 'local_kbacorporate'),
        get_string('developmentbaselinelevel', 'local_kbacorporate'),
        get_string('developmentconfirmedlevel', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'), get_string('developmentoutcomeresult', 'local_kbacorporate')];
    foreach ($items as $item) {
        $outcome = $outcomes->rows[(int)$item->competencyid];
        $levellabel = static function($level) {
            return $level === null ? get_string('competencynotassessed', 'local_kbacorporate') :
                \local_kbacorporate\local\competencies::level_label((int)$level);
        };
        if ($outcome->current === null) {
            $resultlabel = get_string('developmentoutcomeneedsassessment', 'local_kbacorporate');
        } else if ($outcome->reached) {
            $resultlabel = get_string('developmentoutcometargetreached', 'local_kbacorporate');
        } else if ($outcome->delta !== null && $outcome->delta > 0) {
            $resultlabel = get_string('developmentoutcomegain', 'local_kbacorporate', (object)[
                'delta' => $outcome->delta, 'gap' => $outcome->gap,
            ]);
        } else {
            $resultlabel = get_string('developmentoutcomegap', 'local_kbacorporate', $outcome->gap);
        }
        $outcometable->data[] = [format_string($item->competencyname), $levellabel($outcome->baseline),
            $levellabel($outcome->current), $levellabel($outcome->target), $resultlabel];
    }
    echo html_writer::table($outcometable);
    echo html_writer::div(get_string('developmentoutcomenote', 'local_kbacorporate'), 'alert alert-info');
}
if ($iseditable) {
    $existingids = array_flip(array_map(static function($item) { return (int)$item->competencyid; }, $items));
    $competencyoptions = [];
    $snapshot = \local_kbacorporate\local\competencies::get_user_snapshot(
        (int)$plan->userid, (int)$plan->companyid, false);
    foreach ($snapshot['competencies'] as $row) {
        if (($row->targetsource ?? '') === 'businessrole' && !isset($existingids[(int)$row->competencyid])) {
            $competencyoptions[(int)$row->competencyid] = format_string($row->shortname);
        }
    }
    if ($competencyoptions) {
        echo $OUTPUT->heading(get_string('adddevelopmentitem', 'local_kbacorporate'), 3);
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-filters']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'additem']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::select($competencyoptions, 'competencyid', '', ['' => get_string('choose')],
            ['required' => 'required']);
        echo html_writer::select($courseoptions, 'courseid', 0, false);
        echo html_writer::empty_tag('input', ['type' => 'date', 'name' => 'duedate',
            'value' => userdate((int)$plan->targetdate, '%Y-%m-%d'), 'required' => 'required']);
        echo html_writer::tag('button', get_string('add'), ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::div(get_string('developmentplanevidencenote', 'local_kbacorporate'), 'alert alert-info');
}

if ($canmanage) {
    echo html_writer::start_div('kbacorp-development-workflow');
    if ($plan->status === \local_kbacorporate\local\development_plans::STATUS_ACTIVE) {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'synccourses']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag('button', get_string('syncdevelopmentcourses', 'local_kbacorporate'),
            ['type' => 'submit', 'class' => 'btn btn-secondary']);
        echo html_writer::end_tag('form');
    }
    if ($plan->status === \local_kbacorporate\local\development_plans::STATUS_DRAFT) {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'submitplan']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag('button', get_string('submitdevelopmentplan', 'local_kbacorporate'),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
    if (in_array($plan->status, [\local_kbacorporate\local\development_plans::STATUS_PENDING,
            \local_kbacorporate\local\development_plans::STATUS_ACTIVE,
            \local_kbacorporate\local\development_plans::STATUS_COMPLETED,
            \local_kbacorporate\local\development_plans::STATUS_CANCELLED], true)) {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'reopen']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag('button', get_string('returndevelopmentplantodraft', 'local_kbacorporate'),
            ['type' => 'submit', 'class' => 'btn btn-secondary']);
        echo html_writer::end_tag('form');
    }
    if (in_array($plan->status, [\local_kbacorporate\local\development_plans::STATUS_DRAFT,
            \local_kbacorporate\local\development_plans::STATUS_PENDING,
            \local_kbacorporate\local\development_plans::STATUS_ACTIVE], true)) {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'cancel']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::tag('button', get_string('canceldevelopmentplan', 'local_kbacorporate'),
            ['type' => 'submit', 'class' => 'btn btn-outline-danger']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
}
if ($cancreatefollowup && $plan->status === \local_kbacorporate\local\development_plans::STATUS_COMPLETED) {
    if (!empty($plan->followupauditid) && $DB->record_exists('local_kbacorp_audit', ['id' => $plan->followupauditid])) {
        echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/competency_reassessment.php', [
            'id' => (int)$plan->followupauditid,
        ]), get_string('reassessmentopen', 'local_kbacorporate'), ['class' => 'btn btn-primary']), 'my-3');
    } else {
        echo html_writer::div(html_writer::link(new moodle_url(
            '/local/kbacorporate/competency_reassessment_start.php', ['planid' => $planid]
        ), get_string('reassessmentstartbutton', 'local_kbacorporate'), ['class' => 'btn btn-primary']), 'my-3');
    }
}
if ($isself && $plan->status === \local_kbacorporate\local\development_plans::STATUS_PENDING) {
    echo $OUTPUT->heading(get_string('developmentplanagreement', 'local_kbacorporate'), 3);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-development-agreement']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::label(get_string('employeecomment', 'local_kbacorporate'), 'employeecomment');
    echo html_writer::tag('textarea', '', ['id' => 'employeecomment', 'name' => 'employeecomment', 'rows' => 4]);
    echo html_writer::start_div('kbacorp-development-workflow');
    echo html_writer::tag('button', get_string('acceptdevelopmentplan', 'local_kbacorporate'),
        ['type' => 'submit', 'name' => 'action', 'value' => 'acceptplan', 'class' => 'btn btn-primary']);
    echo html_writer::tag('button', get_string('requestdevelopmentplanchanges', 'local_kbacorporate'),
        ['type' => 'submit', 'name' => 'action', 'value' => 'requestchanges', 'class' => 'btn btn-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
}
echo $navigation;
echo $OUTPUT->footer();
