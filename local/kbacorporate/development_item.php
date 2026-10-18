<?php
require_once(__DIR__ . '/../../config.php');

require_login();

/** Convert one optional HTML date to a stable midday Moodle timestamp. */
function local_kbacorporate_ipr_date(string $name, bool $required = true): int {
    $value = $required ? required_param($name, PARAM_RAW_TRIMMED) : optional_param($name, '', PARAM_RAW_TRIMMED);
    if ($value === '' && !$required) {
        return 0;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) ||
            !checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1])) {
        throw new invalid_parameter_exception('Invalid individual development-plan date.');
    }
    return make_timestamp((int)$matches[1], (int)$matches[2], (int)$matches[3], 12, 0, 0);
}

$context = context_system::instance();
$itemid = required_param('id', PARAM_INT);
$item = \local_kbacorporate\local\development_plans::get_item($itemid);
$plan = \local_kbacorporate\local\development_plans::get_plan((int)$item->planid);

$isself = (int)$plan->userid === (int)$USER->id;
$canmanage = has_capability('local/kbacorporate:managedevelopmentplans', $context) &&
    \local_kbacorporate\local\access::can_view_employee((int)$plan->companyid, (int)$USER->id, (int)$plan->userid);
$isitemmentor = (int)($item->mentorid ?? 0) === (int)$USER->id;
$isplanmentor = (int)($plan->mentorid ?? 0) === (int)$USER->id;
$ismentor = ($isitemmentor || $isplanmentor) &&
    \local_kbacorporate\local\development_plans::is_valid_mentor_assignment(
        (int)$plan->companyid, (int)$USER->id);
if (!$isself && !$canmanage && !$ismentor) {
    throw new required_capability_exception($context,
        'local/kbacorporate:managedevelopmentplans', 'nopermissions', '');
}

$caneditdraft = $canmanage && (string)$plan->status ===
    \local_kbacorporate\local\development_plans::STATUS_DRAFT;
$canparticipate = $isself || $canmanage || $ismentor;
$cancheckpoint = $canmanage || $ismentor;
$canreview = ($canmanage || $ismentor) && (int)($item->evidencesubmittedby ?? 0) !== (int)$USER->id;

if (data_submitted() && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action === 'updateitem') {
        if (!$caneditdraft) {
            throw new required_capability_exception($context,
                'local/kbacorporate:managedevelopmentplans', 'nopermissions', '');
        }
        \local_kbacorporate\local\development_plans::update_ipr_item(
            $itemid,
            optional_param('courseid', 0, PARAM_INT),
            local_kbacorporate_ipr_date('duedate'),
            required_param('itemstatus', PARAM_ALPHANUMEXT),
            required_param('goal', PARAM_TEXT),
            optional_param('practicaltask', '', PARAM_TEXT),
            optional_param('evidencecriteria', '', PARAM_TEXT),
            optional_param('mentorid', 0, PARAM_INT),
            optional_param('required', 0, PARAM_BOOL) === 1,
            local_kbacorporate_ipr_date('checkpointdate', false),
            (int)$USER->id
        );
    } else if ($action === 'startitem') {
        if (!$canparticipate) {
            throw new moodle_exception('nopermissions', 'error');
        }
        \local_kbacorporate\local\development_plans::mark_item_in_progress($itemid, (int)$USER->id);
    } else if ($action === 'submitevidence') {
        if (!$canparticipate) {
            throw new moodle_exception('nopermissions', 'error');
        }
        \local_kbacorporate\local\development_plans::submit_item_evidence(
            $itemid,
            (int)$USER->id,
            optional_param('evidenceurl', '', PARAM_URL),
            optional_param('evidencecomment', '', PARAM_TEXT)
        );
    } else if ($action === 'approveevidence' || $action === 'rejectevidence') {
        if (!$canreview) {
            throw new required_capability_exception($context,
                'local/kbacorporate:managedevelopmentplans', 'nopermissions', '');
        }
        \local_kbacorporate\local\development_plans::review_item_evidence(
            $itemid,
            (int)$USER->id,
            $action === 'approveevidence',
            optional_param('reviewcomment', '', PARAM_TEXT)
        );
    } else if ($action === 'checkpoint') {
        if (!$cancheckpoint) {
            throw new moodle_exception('nopermissions', 'error');
        }
        \local_kbacorporate\local\development_plans::record_checkpoint(
            $itemid,
            (int)$USER->id,
            local_kbacorporate_ipr_date('checkpointdate'),
            required_param('checkpointcomment', PARAM_TEXT)
        );
    } else if ($action === 'deleteitem') {
        if (!$caneditdraft) {
            throw new required_capability_exception($context,
                'local/kbacorporate:managedevelopmentplans', 'nopermissions', '');
        }
        \local_kbacorporate\local\development_plans::delete_item($itemid, (int)$USER->id);
        redirect(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => (int)$plan->id]),
            get_string('changessaved'));
    } else {
        throw new moodle_exception('invalidaction');
    }
    redirect(new moodle_url('/local/kbacorporate/development_item.php', ['id' => $itemid]),
        get_string('changessaved'));
}

// Refresh after any possible action.
$item = \local_kbacorporate\local\development_plans::get_item($itemid);
$plan = \local_kbacorporate\local\development_plans::get_plan((int)$item->planid);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/development_item.php', ['id' => $itemid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('ipritemtitle', 'local_kbacorporate', format_string($item->competencyname)));
$PAGE->set_heading(get_string('ipritemtitle', 'local_kbacorporate', format_string($item->competencyname)));

echo $OUTPUT->header();
$navlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => (int)$plan->id]),
        '← ' . get_string('developmentplan', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/study_plan_progress.php', ['id' => (int)$plan->id]),
        get_string('studyplanprogresstitle', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/development_plan_history.php', ['id' => (int)$plan->id]),
        get_string('iprhistorytitle', 'local_kbacorporate')),
];
echo html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);

echo html_writer::start_div('kbacorp-ipr-item-hero');
echo html_writer::div(get_string('individualdevelopmentplan', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(format_string($item->competencyname), 2);
echo html_writer::div(s((string)$item->idnumber) . ' · ' . fullname($plan) . ' · ' .
    format_string($plan->companyname), 'kbacorp-pagelead');
echo html_writer::end_div();

$statuslabel = get_string('developmentitemstatus_' . (string)$item->status, 'local_kbacorporate');
echo html_writer::div(get_string('status') . ': ' . $statuslabel,
    'alert ' . ((string)$item->status === \local_kbacorporate\local\development_plans::ITEM_PENDING_REVIEW ?
        'alert-warning' : ((string)$item->status === \local_kbacorporate\local\development_plans::ITEM_COMPLETED ?
            'alert-success' : 'alert-info')));

$currentlabel = $item->currentlevel === null ? get_string('competencynotassessed', 'local_kbacorporate') :
    \local_kbacorporate\local\competencies::level_label((int)$item->currentlevel);
$targetlabel = \local_kbacorporate\local\competencies::level_label((int)$item->targetlevel);
$gap = $item->currentlevel === null ? null : max((int)$item->targetlevel - (int)$item->currentlevel, 0);
$mentorname = trim((string)($item->mentorname ?? '')) !== '' ? (string)$item->mentorname :
    (trim((string)($plan->mentorname ?? '')) !== '' ? (string)$plan->mentorname :
        get_string('iprmentornotassigned', 'local_kbacorporate'));

echo html_writer::start_div('kbacorp-ipr-item-metrics');
foreach ([
    [$currentlabel, get_string('currentlevel', 'local_kbacorporate')],
    [$targetlabel, get_string('targetlevel', 'local_kbacorporate')],
    [$gap === null ? '—' : (string)$gap, get_string('competencygap', 'local_kbacorporate')],
    [$mentorname, get_string('iprmentor', 'local_kbacorporate')],
] as $metric) {
    echo html_writer::div(html_writer::tag('strong', s($metric[0])) . html_writer::span($metric[1]),
        'kbacorp-ipr-item-metric');
}
echo html_writer::end_div();

$courseoptions = [0 => get_string('nocourseassigned', 'local_kbacorporate')];
foreach (\local_kbacorporate\local\repository::get_company_courses((int)$plan->companyid) as $course) {
    if (!empty($course->active) && !empty($course->visible)) {
        $courseoptions[(int)$course->courseid] = format_string($course->fullname);
    }
}
$mentoroptions = [0 => get_string('iprmentornotassigned', 'local_kbacorporate')] +
    \local_kbacorporate\local\development_plans::mentor_options((int)$plan->companyid);

if ($caneditdraft) {
    echo $OUTPUT->heading(get_string('ipredititem', 'local_kbacorporate'), 3);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-ipr-item-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $itemid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'updateitem']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('kbacorp-ipr-field kbacorp-ipr-field-wide');
    echo html_writer::label(get_string('iprdevelopmentgoal', 'local_kbacorporate'), 'ipr-goal');
    echo html_writer::tag('textarea', s((string)$item->goal), [
        'id' => 'ipr-goal', 'name' => 'goal', 'rows' => 3, 'maxlength' => 5000, 'required' => 'required',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field kbacorp-ipr-field-wide');
    echo html_writer::label(get_string('iprpracticaltask', 'local_kbacorporate'), 'ipr-practicaltask');
    echo html_writer::tag('textarea', s((string)($item->practicaltask ?? '')), [
        'id' => 'ipr-practicaltask', 'name' => 'practicaltask', 'rows' => 4, 'maxlength' => 20000,
        'placeholder' => get_string('iprpracticaltaskplaceholder', 'local_kbacorporate'),
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field kbacorp-ipr-field-wide');
    echo html_writer::label(get_string('iprevidencecriteria', 'local_kbacorporate'), 'ipr-evidencecriteria');
    echo html_writer::tag('textarea', s((string)($item->evidencecriteria ?? '')), [
        'id' => 'ipr-evidencecriteria', 'name' => 'evidencecriteria', 'rows' => 4, 'maxlength' => 10000,
        'placeholder' => get_string('iprevidencecriteriaplaceholder', 'local_kbacorporate'),
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field');
    echo html_writer::label(get_string('recommendedcourse', 'local_kbacorporate'), 'ipr-course');
    echo html_writer::select($courseoptions, 'courseid', (int)$item->courseid, false, ['id' => 'ipr-course']);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field');
    echo html_writer::label(get_string('iprmentor', 'local_kbacorporate'), 'ipr-mentor');
    echo html_writer::select($mentoroptions, 'mentorid', (int)($item->mentorid ?? 0), false, ['id' => 'ipr-mentor']);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field');
    echo html_writer::label(get_string('targetdate', 'local_kbacorporate'), 'ipr-duedate');
    echo html_writer::empty_tag('input', [
        'type' => 'date', 'id' => 'ipr-duedate', 'name' => 'duedate',
        'value' => userdate((int)$item->duedate, '%Y-%m-%d'), 'required' => 'required',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field');
    echo html_writer::label(get_string('iprcheckpointdate', 'local_kbacorporate'), 'ipr-checkpointdate');
    echo html_writer::empty_tag('input', [
        'type' => 'date', 'id' => 'ipr-checkpointdate', 'name' => 'checkpointdate',
        'value' => !empty($item->checkpointdate) ? userdate((int)$item->checkpointdate, '%Y-%m-%d') : '',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field');
    echo html_writer::label(get_string('status'), 'ipr-status');
    echo html_writer::select([
        \local_kbacorporate\local\development_plans::ITEM_PLANNED =>
            get_string('developmentitemstatus_planned', 'local_kbacorporate'),
        \local_kbacorporate\local\development_plans::ITEM_INPROGRESS =>
            get_string('developmentitemstatus_inprogress', 'local_kbacorporate'),
    ], 'itemstatus', (string)$item->status, false, ['id' => 'ipr-status']);
    echo html_writer::end_div();

    echo html_writer::start_div('kbacorp-ipr-field kbacorp-ipr-checkbox');
    echo html_writer::checkbox('required', 1, !empty($item->required),
        get_string('iprobjectiveismandatory', 'local_kbacorporate'));
    echo html_writer::end_div();

    echo html_writer::div(html_writer::tag('button', get_string('savechanges'), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]), 'kbacorp-ipr-field kbacorp-ipr-actions');
    echo html_writer::end_tag('form');
} else {
    echo $OUTPUT->heading(get_string('iprdevelopmentcontent', 'local_kbacorporate'), 3);
    echo html_writer::start_div('kbacorp-ipr-content-grid');
    foreach ([
        [get_string('iprdevelopmentgoal', 'local_kbacorporate'), (string)$item->goal],
        [get_string('iprpracticaltask', 'local_kbacorporate'), (string)($item->practicaltask ?? '')],
        [get_string('iprevidencecriteria', 'local_kbacorporate'), (string)($item->evidencecriteria ?? '')],
    ] as $content) {
        echo html_writer::div(
            html_writer::tag('strong', $content[0]) .
            html_writer::div(trim($content[1]) !== '' ? nl2br(s($content[1])) :
                get_string('iprnotset', 'local_kbacorporate'), 'mt-2'),
            'kbacorp-ipr-content-card'
        );
    }
    echo html_writer::end_div();
}

if ($item->courseid) {
    echo html_writer::div(
        html_writer::tag('strong', get_string('recommendedcourse', 'local_kbacorporate') . ': ') .
        html_writer::link(new moodle_url('/course/view.php', ['id' => (int)$item->courseid]),
            format_string($item->coursename), ['target' => '_blank', 'rel' => 'noopener']),
        'alert alert-info'
    );
}

if ((string)$plan->status === \local_kbacorporate\local\development_plans::STATUS_ACTIVE &&
        $canparticipate && (string)$item->status === \local_kbacorporate\local\development_plans::ITEM_PLANNED) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'my-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $itemid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'startitem']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('button', get_string('iprstartitem', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
}

if ((string)$plan->status === \local_kbacorporate\local\development_plans::STATUS_ACTIVE &&
        $canparticipate && in_array((string)$item->status, [
            \local_kbacorporate\local\development_plans::ITEM_PLANNED,
            \local_kbacorporate\local\development_plans::ITEM_INPROGRESS,
        ], true)) {
    echo $OUTPUT->heading(get_string('iprsubmitevidence', 'local_kbacorporate'), 3);
    echo html_writer::div(get_string('iprevidencenote', 'local_kbacorporate'), 'alert alert-info');
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-ipr-evidence-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $itemid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'submitevidence']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::label(get_string('iprevidenceurl', 'local_kbacorporate'), 'ipr-evidenceurl');
    echo html_writer::empty_tag('input', [
        'type' => 'url', 'id' => 'ipr-evidenceurl', 'name' => 'evidenceurl',
        'value' => (string)($item->evidenceurl ?? ''), 'maxlength' => 1333,
        'placeholder' => 'https://',
    ]);
    echo html_writer::label(get_string('iprevidencecomment', 'local_kbacorporate'), 'ipr-evidencecomment');
    echo html_writer::tag('textarea', s((string)($item->evidencecomment ?? '')), [
        'id' => 'ipr-evidencecomment', 'name' => 'evidencecomment', 'rows' => 5, 'maxlength' => 20000,
    ]);
    echo html_writer::tag('button', get_string('iprsubmitevidencebutton', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'btn btn-primary mt-2',
    ]);
    echo html_writer::end_tag('form');
}

if (!empty($item->timeevidencesubmitted)) {
    echo $OUTPUT->heading(get_string('iprevidencesubmitted', 'local_kbacorporate'), 3);
    echo html_writer::start_div('kbacorp-ipr-evidence-summary');
    echo html_writer::div(
        html_writer::tag('strong', get_string('iprevidencesubmittedby', 'local_kbacorporate') . ': ') .
        s((string)($item->evidencesubmittername ?: get_string('unknownuser'))) . ' · ' .
        userdate((int)$item->timeevidencesubmitted, get_string('strftimedatetimeshort', 'langconfig'))
    );
    if (!empty($item->evidenceurl)) {
        echo html_writer::div(html_writer::link((string)$item->evidenceurl,
            get_string('ipropenevidence', 'local_kbacorporate'), ['target' => '_blank', 'rel' => 'noopener']));
    }
    if (trim((string)($item->evidencecomment ?? '')) !== '') {
        echo html_writer::div(nl2br(s((string)$item->evidencecomment)), 'mt-2');
    }
    if (!empty($item->timereviewed)) {
        echo html_writer::div(
            html_writer::tag('strong', get_string('iprreviewedby', 'local_kbacorporate') . ': ') .
            s((string)($item->reviewername ?: get_string('unknownuser'))) . ' · ' .
            userdate((int)$item->timereviewed, get_string('strftimedatetimeshort', 'langconfig')),
            'mt-2'
        );
        if (trim((string)($item->reviewcomment ?? '')) !== '') {
            echo html_writer::div(nl2br(s((string)$item->reviewcomment)), 'mt-1');
        }
    }
    echo html_writer::end_div();
}

if ($canreview && (string)$plan->status === \local_kbacorporate\local\development_plans::STATUS_ACTIVE &&
        (string)$item->status === \local_kbacorporate\local\development_plans::ITEM_PENDING_REVIEW) {
    echo $OUTPUT->heading(get_string('iprreviewevidence', 'local_kbacorporate'), 3);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-ipr-review-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $itemid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::label(get_string('iprreviewcomment', 'local_kbacorporate'), 'ipr-reviewcomment');
    echo html_writer::tag('textarea', '', [
        'id' => 'ipr-reviewcomment', 'name' => 'reviewcomment', 'rows' => 4, 'maxlength' => 20000,
    ]);
    echo html_writer::start_div('kbacorp-development-workflow mt-2');
    echo html_writer::tag('button', get_string('iprapproveevidence', 'local_kbacorporate'), [
        'type' => 'submit', 'name' => 'action', 'value' => 'approveevidence', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::tag('button', get_string('iprrejectevidence', 'local_kbacorporate'), [
        'type' => 'submit', 'name' => 'action', 'value' => 'rejectevidence', 'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
}

if ($cancheckpoint && in_array((string)$plan->status, [
        \local_kbacorporate\local\development_plans::STATUS_DRAFT,
        \local_kbacorporate\local\development_plans::STATUS_ACTIVE,
    ], true)) {
    echo $OUTPUT->heading(get_string('iprcheckpoint', 'local_kbacorporate'), 3);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-ipr-checkpoint-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $itemid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'checkpoint']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::label(get_string('iprcheckpointdate', 'local_kbacorporate'), 'ipr-checkpoint-date');
    echo html_writer::empty_tag('input', [
        'type' => 'date', 'id' => 'ipr-checkpoint-date', 'name' => 'checkpointdate',
        'value' => !empty($item->checkpointdate) ? userdate((int)$item->checkpointdate, '%Y-%m-%d') :
            userdate(min((int)$item->duedate, time() + (14 * DAYSECS)), '%Y-%m-%d'),
        'required' => 'required',
    ]);
    echo html_writer::label(get_string('iprcheckpointcomment', 'local_kbacorporate'), 'ipr-checkpoint-comment');
    echo html_writer::tag('textarea', s((string)($item->checkpointcomment ?? '')), [
        'id' => 'ipr-checkpoint-comment', 'name' => 'checkpointcomment', 'rows' => 4,
        'maxlength' => 20000, 'required' => 'required',
    ]);
    echo html_writer::tag('button', get_string('iprsavecheckpoint', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'btn btn-secondary mt-2',
    ]);
    echo html_writer::end_tag('form');
}

$history = array_values(array_filter(
    \local_kbacorporate\local\development_plans::get_history((int)$plan->id),
    static function($event) use ($itemid) { return (int)$event->itemid === $itemid; }
));
if ($history) {
    echo $OUTPUT->heading(get_string('iprrecenthistory', 'local_kbacorporate'), 3);
    echo html_writer::start_div('kbacorp-ipr-history-list');
    foreach (array_slice($history, 0, 8) as $event) {
        $actor = (int)$event->actorid > 0 ? fullname($event) : get_string('iprsystemactor', 'local_kbacorporate');
        $eventkey = 'iprhistoryevent_' . (string)$event->eventtype;
        $label = get_string_manager()->string_exists($eventkey, 'local_kbacorporate') ?
            get_string($eventkey, 'local_kbacorporate') : (string)$event->eventtype;
        echo html_writer::div(
            html_writer::tag('strong', s($label)) .
            html_writer::span(userdate((int)$event->timecreated,
                get_string('strftimedatetimeshort', 'langconfig')) . ' · ' . s($actor)) .
            (trim((string)($event->comment ?? '')) !== '' ?
                html_writer::div(s(shorten_text((string)$event->comment, 240)), 'mt-1') : ''),
            'kbacorp-ipr-history-event'
        );
    }
    echo html_writer::end_div();
}

if ($caneditdraft) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-4']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $itemid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'deleteitem']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('button', get_string('delete'), [
        'type' => 'submit', 'class' => 'btn btn-outline-danger',
        'onclick' => "return confirm('" . addslashes_js(get_string('confirmdeletedevelopmentitem', 'local_kbacorporate')) . "');",
    ]);
    echo html_writer::end_tag('form');
}

echo html_writer::div(get_string('developmentplanevidencenote', 'local_kbacorporate'), 'alert alert-info mt-4');
echo html_writer::div(implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']);
echo $OUTPUT->footer();
