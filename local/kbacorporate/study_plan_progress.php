<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
$planid = required_param('id', PARAM_INT);
$filter = optional_param('filter', \local_kbacorporate\local\study_plan_progress::FILTER_ALL,
    PARAM_ALPHANUMEXT);
$query = trim((string)optional_param('q', '', PARAM_TEXT));

$plan = \local_kbacorporate\local\development_plans::get_plan($planid);
$isself = (int)$plan->userid === (int)$USER->id;
$canmanage = has_capability('local/kbacorporate:managedevelopmentplans', $context) &&
    \local_kbacorporate\local\access::can_view_employee((int)$plan->companyid, (int)$USER->id,
        (int)$plan->userid);
$canassess = has_capability('local/kbacorporate:managecompetencyaudits', $context) &&
    \local_kbacorporate\local\access::has_full_company_access((int)$plan->companyid, (int)$USER->id);
$hasmentorassignment = (int)($plan->mentorid ?? 0) === (int)$USER->id ||
    $DB->record_exists('local_kbacorp_devitem', [
        'planid' => $planid,
        'mentorid' => (int)$USER->id,
    ]);
$ismentor = $hasmentorassignment &&
    \local_kbacorporate\local\development_plans::is_valid_mentor_assignment(
        (int)$plan->companyid, (int)$USER->id);
if (!$isself && !$canmanage && !$ismentor) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}

if (data_submitted() && confirm_sesskey()) {
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action === 'sync') {
        if (!$canmanage) {
            throw new required_capability_exception($context,
                'local/kbacorporate:managedevelopmentplans', 'nopermissions', '');
        }
        $sync = \local_kbacorporate\local\development_plans::sync_course_completions($planid);
        redirect(new moodle_url('/local/kbacorporate/study_plan_progress.php', ['id' => $planid]),
            get_string('studyplanprogresssyncresult', 'local_kbacorporate', $sync), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'createassessment') {
        if (!$canassess) {
            throw new required_capability_exception($context,
                'local/kbacorporate:managecompetencyaudits', 'nopermissions', '');
        }
        $freshplan = \local_kbacorporate\local\development_plans::get_plan($planid);
        if ((string)$freshplan->status !== \local_kbacorporate\local\development_plans::STATUS_COMPLETED) {
            throw new moodle_exception('developmentauditplanincomplete', 'local_kbacorporate');
        }
        $auditid = \local_kbacorporate\local\competency_audits::create_development_audit(
            $planid, (int)$USER->id);
        redirect(new moodle_url('/local/kbacorporate/competency_reassessment.php', ['id' => $auditid]),
            get_string('reassessmentcreated', 'local_kbacorporate'));
    }
    throw new moodle_exception('invalidaction');
}

$urlparams = ['id' => $planid];
if ($filter !== \local_kbacorporate\local\study_plan_progress::FILTER_ALL) {
    $urlparams['filter'] = $filter;
}
if ($query !== '') {
    $urlparams['q'] = $query;
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/study_plan_progress.php', $urlparams));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('studyplanprogresstitle', 'local_kbacorporate'));
$PAGE->set_heading(format_string($plan->title));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$projection = \local_kbacorporate\local\study_plan_progress::calculate($planid);
$summary = $projection->summary;
$rows = \local_kbacorporate\local\study_plan_progress::filter($projection->rows, $filter, $query);
$plan = $projection->plan;
$moodleplan = \local_kbacorporate\local\moodle_learning_plans::available()
    ? \local_kbacorporate\local\moodle_learning_plans::linked_plan($planid) : null;

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $planid]),
        '← ' . get_string('developmentplan', 'local_kbacorporate')),
];
if (has_capability('local/kbacorporate:viewcompetencies', $context)) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_profile.php', [
        'companyid' => (int)$plan->companyid,
        'userid' => (int)$plan->userid,
    ]), get_string('employeecompetencyprofile', 'local_kbacorporate'));
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => (int)$plan->companyid,
        'userid' => (int)$plan->userid,
    ]), get_string('employeegap', 'local_kbacorporate'));
}
if ($canmanage) {
    $navigationlinks[] = html_writer::link(new moodle_url('/local/kbacorporate/development_plans.php', [
        'companyid' => (int)$plan->companyid,
    ]), get_string('developmentplans', 'local_kbacorporate'));
}
if ($moodleplan) {
    $navigationlinks[] = html_writer::link(
        \local_kbacorporate\local\moodle_learning_plans::plan_url((int)$moodleplan->get('id')),
        get_string('openmoodlelearningplan', 'local_kbacorporate'),
        ['target' => '_blank', 'rel' => 'noopener']
    );
}
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']
);

$level = static function($value): string {
    if ($value === null) {
        return get_string('competencynotassessed', 'local_kbacorporate');
    }
    return \local_kbacorporate\local\competencies::level_label((int)$value);
};

$courseprogress = static function($row): string {
    $status = get_string('studyplanprogresscourse_' . $row->course->status, 'local_kbacorporate');
    $html = html_writer::span($status,
        'badge badge-' . ($row->course->status === 'completed' ? 'success' :
            ($row->course->status === 'inprogress' ? 'primary' :
                ($row->course->status === 'notenrolled' || $row->course->status === 'error' ? 'warning' : 'secondary'))));
    if ($row->course->progress !== null) {
        $width = max(0, min(100, (int)$row->course->progress));
        $bar = html_writer::div(html_writer::span('', '', ['style' => 'width:' . $width . '%']),
            'kbacorp-progressbar');
        $html .= html_writer::div($bar . html_writer::span($width . '%'), 'kbacorp-progress mt-1');
    }
    if ((int)$row->course->lastaccess > 0) {
        $html .= html_writer::div(get_string('studyplanprogresslastaccess', 'local_kbacorporate',
            userdate((int)$row->course->lastaccess)), 'text-muted small');
    }
    return $html;
};

$deadline = static function($row): string {
    if ((int)$row->duedate <= 0) {
        return '—';
    }
    $date = userdate((int)$row->duedate, get_string('strftimedate', 'langconfig'));
    if ($row->deadline === 'overdue') {
        return $date . ' ' . html_writer::span(get_string('overdue', 'local_kbacorporate'),
            'badge badge-danger');
    }
    if ($row->deadline === 'duesoon') {
        return $date . ' ' . html_writer::span(get_string('studyplanprogressduesoon', 'local_kbacorporate'),
            'badge badge-warning');
    }
    if ($row->deadline === 'completed') {
        return $date . ' ' . html_writer::span(get_string('developmentitemstatus_completed', 'local_kbacorporate'),
            'badge badge-success');
    }
    return $date;
};

$nextstep = static function($row): string {
    $label = get_string('studyplanprogressnext_' . $row->nextaction, 'local_kbacorporate');
    $class = 'badge badge-secondary';
    if ($row->nextaction === 'targetreached') {
        $class = 'badge badge-success';
    } else if (in_array($row->nextaction, ['reassess', 'submitevidence', 'reviewevidence'], true)) {
        $class = 'badge badge-warning';
    } else if (in_array($row->nextaction, ['continuecourse', 'startcourse'], true)) {
        $class = 'badge badge-primary';
    } else if (in_array($row->nextaction, ['continueoverdue', 'startoverdue'], true)) {
        $class = 'badge badge-danger';
    }
    return html_writer::span($label, $class);
};

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('studyplanprogresstitle', 'local_kbacorporate'));
echo html_writer::div(get_string('studyplanprogressdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::div(
    html_writer::tag('strong', fullname($plan)) . ' · ' . format_string($plan->companyname) . ' · ' .
    get_string('status') . ': ' . get_string('developmentplanstatus_' . $plan->status, 'local_kbacorporate'),
    'alert alert-info'
);

$cards = [
    [$summary->actionprogress . '%', get_string('studyplanprogressactions', 'local_kbacorporate')],
    [$summary->averagecourseprogress === null ? '—' : $summary->averagecourseprogress . '%',
        get_string('studyplanprogresscourseaverage', 'local_kbacorporate')],
    [$summary->targetsreached . '/' . $summary->total,
        get_string('studyplanprogresstargets', 'local_kbacorporate')],
    [$summary->assessmentcoverage . '%', get_string('assessmentcoverage', 'local_kbacorporate')],
    [$summary->completed . '/' . $summary->total,
        get_string('developmentactionscompleted', 'local_kbacorporate')],
    [$summary->overdue, get_string('developmentactionsoverdue', 'local_kbacorporate')],
    [$summary->needsreassessment, get_string('studyplanprogressneedsreassessment', 'local_kbacorporate')],
    [$summary->withoutcourse, get_string('studyplanprogresswithoutcourse', 'local_kbacorporate')],
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

if ($summary->attention > 0) {
    echo $OUTPUT->notification(get_string('studyplanprogressattention', 'local_kbacorporate',
        $summary->attention), 'warning');
} else if ($summary->total > 0 && $summary->targetsreached === $summary->total) {
    echo $OUTPUT->notification(get_string('studyplanprogressalltargets', 'local_kbacorporate'), 'success');
} else {
    echo $OUTPUT->notification(get_string('studyplanprogressnormal', 'local_kbacorporate'), 'info');
}

echo html_writer::start_div('kbacorp-toolbar');
if ($canmanage && (string)$plan->status === \local_kbacorporate\local\development_plans::STATUS_ACTIVE) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'd-inline-block']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'sync']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::tag('button', get_string('studyplanprogresssync', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
}
if ((string)$plan->status === \local_kbacorporate\local\development_plans::STATUS_COMPLETED) {
    if (!empty($plan->followupauditid) && $DB->record_exists('local_kbacorp_audit', [
            'id' => (int)$plan->followupauditid])) {
        echo html_writer::link(new moodle_url('/local/kbacorporate/competency_reassessment.php', [
            'id' => (int)$plan->followupauditid,
        ]), get_string('reassessmentopen', 'local_kbacorporate'), ['class' => 'btn btn-success']);
    } else if ($canassess) {
        echo html_writer::link(new moodle_url('/local/kbacorporate/competency_reassessment_start.php', [
            'planid' => $planid,
        ]), get_string('reassessmentstartbutton', 'local_kbacorporate'), ['class' => 'btn btn-success']);
    }
}
echo html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $planid]),
    get_string('opendevelopmentplan', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filters']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $planid]);
$filteroptions = [
    \local_kbacorporate\local\study_plan_progress::FILTER_ALL => get_string('all'),
    \local_kbacorporate\local\study_plan_progress::FILTER_ATTENTION =>
        get_string('studyplanprogressfilter_attention', 'local_kbacorporate'),
    \local_kbacorporate\local\study_plan_progress::FILTER_OVERDUE =>
        get_string('studyplanprogressfilter_overdue', 'local_kbacorporate'),
    \local_kbacorporate\local\study_plan_progress::FILTER_REASSESSMENT =>
        get_string('studyplanprogressfilter_reassessment', 'local_kbacorporate'),
    \local_kbacorporate\local\study_plan_progress::FILTER_INPROGRESS =>
        get_string('studyplanprogressfilter_inprogress', 'local_kbacorporate'),
    \local_kbacorporate\local\study_plan_progress::FILTER_COMPLETED =>
        get_string('studyplanprogressfilter_completed', 'local_kbacorporate'),
    \local_kbacorporate\local\study_plan_progress::FILTER_NOCOURSE =>
        get_string('studyplanprogressfilter_nocourse', 'local_kbacorporate'),
];
echo html_writer::label(get_string('status'), 'study-plan-progress-filter');
echo html_writer::select($filteroptions, 'filter', $filter, false, ['id' => 'study-plan-progress-filter']);
echo html_writer::label(get_string('search'), 'study-plan-progress-query');
echo html_writer::empty_tag('input', [
    'type' => 'search', 'name' => 'q', 'id' => 'study-plan-progress-query', 'value' => $query,
]);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

if (!$rows) {
    echo $OUTPUT->notification(get_string('nofilterresults', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('competencylabel', 'local_kbacorporate'),
        get_string('recommendedcourse', 'local_kbacorporate'),
        get_string('courseprogress', 'local_kbacorporate'),
        get_string('studyplanprogressplanstatus', 'local_kbacorporate'),
        get_string('targetdate', 'local_kbacorporate'),
        get_string('studyplanprogresslevels', 'local_kbacorporate'),
        get_string('competencygap', 'local_kbacorporate'),
        get_string('studyplanprogressnextstep', 'local_kbacorporate'),
    ];

    foreach ($rows as $row) {
        $competency = html_writer::link(
            new moodle_url('/local/kbacorporate/development_item.php', ['id' => (int)$row->itemid]),
            format_string($row->competencyname)
        );
        if ($row->idnumber !== '') {
            $competency .= html_writer::div(s($row->idnumber), 'text-muted small');
        }
        $competency .= html_writer::div(
            get_string('rolepriority_' . $row->priority, 'local_kbacorporate'),
            'text-muted small'
        );

        if ($row->courseid > 0 && $row->course->exists) {
            $course = html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]),
                format_string($row->coursename));
        } else if ($row->courseid > 0) {
            $course = html_writer::span(get_string('studyplanprogresscoursemissing', 'local_kbacorporate'),
                'text-danger');
        } else {
            $course = html_writer::span(get_string('nocourseassigned', 'local_kbacorporate'), 'text-muted');
        }

        $itemstatus = html_writer::span(
            get_string('developmentitemstatus_' . $row->itemstatus, 'local_kbacorporate'),
            'badge badge-' . ($row->itemstatus === 'completed' ? 'success' :
                ($row->itemstatus === 'pendingreview' ? 'warning' :
                    ($row->itemstatus === 'inprogress' ? 'primary' : 'secondary')))
        );
        $levels = get_string('studyplanprogresslevelsvalue', 'local_kbacorporate', (object)[
            'baseline' => $level($row->baseline),
            'current' => $level($row->current),
            'target' => $level($row->target),
        ]);
        if ($row->delta !== null && (int)$row->delta > 0) {
            $levels .= html_writer::div(get_string('studyplanprogressimprovement', 'local_kbacorporate',
                (int)$row->delta), 'text-success small');
        }
        $gap = $row->gap === null
            ? html_writer::span(get_string('competencynotassessed', 'local_kbacorporate'), 'text-muted')
            : (!empty($row->reached)
                ? html_writer::span(get_string('employeegaprow_met', 'local_kbacorporate'), 'badge badge-success')
                : html_writer::span((string)(int)$row->gap, 'badge badge-warning'));

        $table->data[] = [
            $competency,
            $course,
            $courseprogress($row),
            $itemstatus,
            $deadline($row),
            $levels,
            $gap,
            $nextstep($row),
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo html_writer::div(get_string('studyplanprogressnote', 'local_kbacorporate'), 'alert alert-light border');
echo $navigation;
echo $OUTPUT->footer();
