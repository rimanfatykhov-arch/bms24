<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompetencyaudits', $context);

$planid = required_param('planid', PARAM_INT);
$plan = \local_kbacorporate\local\development_plans::get_plan($planid);
$company = \local_kbacorporate\local\access::require_full_company_access(
    (int)$plan->companyid, (int)$USER->id
);
if ((string)$plan->status !== \local_kbacorporate\local\development_plans::STATUS_COMPLETED) {
    throw new moodle_exception('developmentauditplanincomplete', 'local_kbacorporate');
}
if (!empty($plan->followupauditid) && $DB->record_exists('local_kbacorp_audit', [
        'id' => (int)$plan->followupauditid,
    ])) {
    redirect(new moodle_url('/local/kbacorporate/competency_reassessment.php', [
        'id' => (int)$plan->followupauditid,
    ]));
}

$url = new moodle_url('/local/kbacorporate/competency_reassessment_start.php', ['planid' => $planid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('reassessmentstarttitle', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

if (data_submitted() && confirm_sesskey()) {
    if (!optional_param('confirmreassessment', 0, PARAM_BOOL)) {
        redirect($url, get_string('reassessmentconfirmationrequired', 'local_kbacorporate'), null,
            \core\output\notification::NOTIFY_WARNING);
    }
    $auditid = \local_kbacorporate\local\competency_audits::create_development_audit(
        $planid, (int)$USER->id
    );
    redirect(new moodle_url('/local/kbacorporate/competency_reassessment.php', ['id' => $auditid]),
        get_string('reassessmentcreated', 'local_kbacorporate'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$projection = \local_kbacorporate\local\study_plan_progress::calculate($planid);
$items = $projection->rows;
$summary = $projection->summary;
$level = static function($value): string {
    return $value === null
        ? get_string('competencynotassessed', 'local_kbacorporate')
        : \local_kbacorporate\local\competencies::level_label((int)$value);
};

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/development_plan.php', ['id' => $planid]),
        '← ' . get_string('developmentplan', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/study_plan_progress.php', ['id' => $planid]),
        get_string('studyplanprogresstitle', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_profile.php', [
        'companyid' => (int)$plan->companyid,
        'userid' => (int)$plan->userid,
    ]), get_string('employeecompetencyprofile', 'local_kbacorporate')),
];
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']
);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('reassessmentstarttitle', 'local_kbacorporate'));
echo html_writer::div(get_string('reassessmentstartdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo html_writer::div(
    html_writer::tag('strong', fullname($plan)) . ' · ' . format_string($plan->title) . ' · ' .
    userdate((int)($plan->timemodified ?: time()), get_string('strftimedatetime', 'langconfig')),
    'alert alert-info'
);

$cards = [
    [$summary->total, get_string('reassessmentcompetencies', 'local_kbacorporate')],
    [$summary->coursescompleted . '/' . $summary->withcourse,
        get_string('reassessmentcoursescompleted', 'local_kbacorporate')],
    [$summary->assessed . '/' . $summary->total,
        get_string('reassessmentcurrentcoverage', 'local_kbacorporate')],
    [$summary->needsreassessment, get_string('reassessmentrequiredcount', 'local_kbacorporate')],
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

echo $OUTPUT->notification(get_string('reassessmentstartnote', 'local_kbacorporate'), 'warning');

if (!$items) {
    echo $OUTPUT->notification(get_string('developmentauditnoitems', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('competencylabel', 'local_kbacorporate'),
        get_string('recommendedcourse', 'local_kbacorporate'),
        get_string('developmentbaselinelevel', 'local_kbacorporate'),
        get_string('developmentconfirmedlevel', 'local_kbacorporate'),
        get_string('targetlevel', 'local_kbacorporate'),
        get_string('studyplanprogressnextstep', 'local_kbacorporate'),
    ];
    foreach ($items as $row) {
        $competency = format_string($row->competencyname);
        if ((string)$row->idnumber !== '') {
            $competency .= html_writer::div(s((string)$row->idnumber), 'text-muted small');
        }
        $course = (int)$row->courseid > 0
            ? html_writer::link(new moodle_url('/course/view.php', ['id' => (int)$row->courseid]),
                format_string($row->coursename ?: get_string('course')),
                ['target' => '_blank', 'rel' => 'noopener'])
            : html_writer::span(get_string('nocourseassigned', 'local_kbacorporate'), 'text-muted');
        if ((string)$row->course->status === 'completed') {
            $course .= html_writer::div(get_string('studyplanprogresscourse_completed', 'local_kbacorporate'),
                'badge badge-success mt-1');
        }
        $next = in_array((string)$row->reassessment, ['required', 'missingassessment'], true)
            ? html_writer::span(get_string('reassessmentwillinclude', 'local_kbacorporate'), 'badge badge-warning')
            : html_writer::span(get_string('reassessmentwillverify', 'local_kbacorporate'), 'badge badge-secondary');
        $table->data[] = [
            $competency,
            $course,
            $level($row->baseline),
            $level($row->current),
            $level($row->target),
            $next,
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');

    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-4']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'planid', 'value' => $planid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    $checkbox = html_writer::checkbox('confirmreassessment', 1, false,
        get_string('reassessmentconfirmstart', 'local_kbacorporate'), [
            'id' => 'confirmreassessment', 'required' => 'required',
        ]);
    echo html_writer::div($checkbox, 'form-check mb-3');
    echo html_writer::tag('button', get_string('reassessmentcreatebutton', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
}

echo html_writer::div(get_string('reassessmentprivacy', 'local_kbacorporate'),
    'alert alert-light border mt-4');
echo $navigation;
echo $OUTPUT->footer();
