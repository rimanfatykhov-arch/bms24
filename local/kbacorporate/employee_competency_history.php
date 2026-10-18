<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcompetencies', $context);

$companyid = required_param('companyid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$competencyid = required_param('competencyid', PARAM_INT);

$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
if (!\local_kbacorporate\local\access::can_view_employee($companyid, $USER->id, $userid)) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}
$employee = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
    'id,firstname,lastname,email', MUST_EXIST);
$competency = $DB->get_record('competency', ['id' => $competencyid],
    'id,shortname,idnumber,description,descriptionformat,scaleid,competencyframeworkid', MUST_EXIST);
$frameworkscaleid = (int)$DB->get_field('competency_framework', 'scaleid', [
    'id' => (int)$competency->competencyframeworkid,
]);

$profile = \local_kbacorporate\local\employee_competency_profile::build($companyid, $userid);
$profilerow = null;
foreach ($profile->rows as $row) {
    if ((int)$row->competencyid === $competencyid) {
        $profilerow = $row;
        break;
    }
}
if (!$profilerow) {
    throw new moodle_exception('employeeprofilecompetencynotvisible', 'local_kbacorporate');
}
$history = \local_kbacorporate\local\employee_competency_profile::history(
    $companyid, $userid, $competencyid
);

$urlparams = ['companyid' => $companyid, 'userid' => $userid, 'competencyid' => $competencyid];
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/employee_competency_history.php', $urlparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('employeecompetencyhistory', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$navigationlinks = [
    html_writer::link(new moodle_url('/local/kbacorporate/employee_competency_profile.php', [
        'companyid' => $companyid, 'userid' => $userid,
    ]), '← ' . get_string('employeecompetencyprofile', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/employee_assessment_history.php', [
        'companyid' => $companyid, 'userid' => $userid,
        'q' => $competency->idnumber,
    ]), get_string('employeeassessmenthistory', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/mycompetencies.php', [
        'companyid' => $companyid, 'userid' => $userid, 'q' => $competency->idnumber,
    ]), get_string('employeeprofiledetailedcards', 'local_kbacorporate')),
    html_writer::link(new moodle_url('/local/kbacorporate/employee_gap.php', [
        'companyid' => $companyid, 'userid' => $userid, 'q' => $competency->idnumber,
    ]), get_string('employeegap', 'local_kbacorporate')),
];
$navigation = html_writer::div(
    implode(html_writer::span('·', 'kbacorp-page-navigation-separator'), $navigationlinks),
    'kbacorp-page-navigation', ['role' => 'navigation']
);

echo $OUTPUT->header();
echo $navigation;
echo $OUTPUT->heading(get_string('employeecompetencyhistory', 'local_kbacorporate'));
echo html_writer::div(get_string('employeecompetencyhistorydesc', 'local_kbacorporate'), 'kbacorp-pagelead');

echo html_writer::start_div('kbacorp-employee-profile-hero');
echo html_writer::start_div('kbacorp-employee-profile-identity');
echo html_writer::tag('h3', s(fullname($employee)), ['class' => 'kbacorp-employee-profile-name']);
$title = format_string($competency->shortname);
if ($competency->idnumber !== '') {
    $title = s($competency->idnumber) . ' · ' . $title;
}
echo html_writer::tag('h4', $title, ['class' => 'kbacorp-competency-history-title']);
$description = trim(html_to_text((string)$competency->description, 0, false));
if ($description !== '') {
    echo html_writer::div(s(shorten_text($description, 420)), 'kbacorp-pagelead');
}
echo html_writer::end_div();

$statuskey = $profilerow->status === \local_kbacorporate\local\employee_competency_profile::STATUS_OUTSIDE
    ? 'competencystatus_outsideprofile' : 'employeegaprow_' . $profilerow->status;
echo html_writer::div(s(get_string($statuskey, 'local_kbacorporate')),
    'kbacorp-employee-profile-state ' . ($profilerow->status === 'met' ? 'is-ready' : 'is-warning'));
echo html_writer::end_div();

$scaleid = (int)($competency->scaleid ?: $frameworkscaleid);
$current = $profilerow->currentlevel === null ? get_string('competencynotassessed', 'local_kbacorporate')
    : \local_kbacorporate\local\competencies::level_label((int)$profilerow->currentlevel, $scaleid);
$target = $profilerow->targetlevel === null ? '—'
    : \local_kbacorporate\local\competencies::level_label((int)$profilerow->targetlevel, $scaleid);
$gap = $profilerow->gap === null ? '—' : (string)$profilerow->gap;
$sourcekey = 'employeeprofilesource_' . $profilerow->assessment_source;
$sourcelabel = get_string_manager()->string_exists($sourcekey, 'local_kbacorporate')
    ? get_string($sourcekey, 'local_kbacorporate') : $profilerow->assessment_source;
$cards = [
    [$current, get_string('currentlevel', 'local_kbacorporate')],
    [$target, get_string('targetlevel', 'local_kbacorporate')],
    [$gap, get_string('competencygap', 'local_kbacorporate')],
    [$sourcelabel, get_string('employeeprofileassessmentsource', 'local_kbacorporate')],
    [count($history), get_string('employeeprofilehistoryevents', 'local_kbacorporate')],
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

if (!$history) {
    echo $OUTPUT->notification(get_string('employeecompetencyhistoryempty', 'local_kbacorporate'), 'info');
    echo $navigation;
    echo $OUTPUT->footer();
    exit;
}

$sourcekeys = [
    \local_kbacorporate\local\employee_competency_profile::SOURCE_AUDIT,
    \local_kbacorporate\local\employee_competency_profile::SOURCE_AI,
    \local_kbacorporate\local\employee_competency_profile::SOURCE_MOODLE,
    \local_kbacorporate\local\employee_competency_profile::SOURCE_COURSE,
    \local_kbacorporate\local\employee_competency_profile::SOURCE_PLAN,
    \local_kbacorporate\local\employee_competency_profile::SOURCE_NONE,
];
$statuslabels = [
    'confirmed' => get_string('employeeprofilehistorystatus_confirmed', 'local_kbacorporate'),
    'recorded' => get_string('employeeprofilehistorystatus_recorded', 'local_kbacorporate'),
    'pending' => get_string('employeeprofilehistorystatus_pending', 'local_kbacorporate'),
    'missing' => get_string('employeeprofilehistorystatus_missing', 'local_kbacorporate'),
    'rejected' => get_string('employeeprofilehistorystatus_rejected', 'local_kbacorporate'),
    'provisional' => get_string('employeeprofilehistorystatus_provisional', 'local_kbacorporate'),
    'proficient' => get_string('employeeprofilehistorystatus_proficient', 'local_kbacorporate'),
    'planned' => get_string('developmentitemstatus_planned', 'local_kbacorporate'),
    'inprogress' => get_string('developmentitemstatus_inprogress', 'local_kbacorporate'),
    'completed' => get_string('developmentitemstatus_completed', 'local_kbacorporate'),
    'cancelled' => get_string('developmentitemstatus_cancelled', 'local_kbacorporate'),
];
$kindlabels = [
    'assessment' => get_string('employeeprofilehistorykind_assessment', 'local_kbacorporate'),
    'evidence' => get_string('employeeprofilehistorykind_evidence', 'local_kbacorporate'),
    'course' => get_string('employeeprofilehistorykind_course', 'local_kbacorporate'),
    'plan' => get_string('employeeprofilehistorykind_plan', 'local_kbacorporate'),
];

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-competency-history-table';
$table->head = [
    get_string('date'),
    get_string('employeeprofilehistoryevent', 'local_kbacorporate'),
    get_string('employeeprofileassessmentsource', 'local_kbacorporate'),
    get_string('competencylevel', 'local_kbacorporate'),
    get_string('status'),
    get_string('employeeprofilehistoryactor', 'local_kbacorporate'),
    get_string('description'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($history as $event) {
    $date = (int)$event->timecreated > 0
        ? userdate((int)$event->timecreated, get_string('strftimedatetimeshort', 'langconfig')) : '—';
    $kindlabel = $kindlabels[$event->kind] ?? (string)$event->kind;
    $eventhtml = html_writer::tag('strong', s($kindlabel));
    if ($event->title !== '') {
        $eventhtml .= html_writer::div(s($event->title), 'text-muted small');
    }
    $eventsourcekey = 'employeeprofilesource_' . $event->source;
    $eventsource = in_array($event->source, $sourcekeys, true) &&
            get_string_manager()->string_exists($eventsourcekey, 'local_kbacorporate')
        ? get_string($eventsourcekey, 'local_kbacorporate') : (string)$event->source;
    if ($event->level !== null) {
        $level = \local_kbacorporate\local\competencies::level_label((int)$event->level, $scaleid);
    } else if ($event->targetlevel !== null) {
        $level = get_string('employeeprofilehistorytargetlevel', 'local_kbacorporate',
            \local_kbacorporate\local\competencies::level_label((int)$event->targetlevel, $scaleid));
    } else {
        $level = '—';
    }
    $eventstatus = $statuslabels[$event->status] ?? (string)$event->status;
    $actor = $event->actorname !== '' ? $event->actorname : '—';
    $details = $event->details !== '' ? s($event->details) : '—';
    $actions = [];
    if ($event->url !== '') {
        $actions[] = html_writer::link($event->url, get_string('open', 'local_kbacorporate'));
    }
    if ($event->externalurl !== '') {
        $actions[] = html_writer::link($event->externalurl,
            get_string('employeeprofileopenevidence', 'local_kbacorporate'), ['target' => '_blank', 'rel' => 'noopener']);
    }
    $table->data[] = [
        $date,
        $eventhtml,
        s($eventsource),
        s($level),
        s($eventstatus),
        s($actor),
        $details,
        $actions ? implode(' · ', $actions) : '—',
    ];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');

echo html_writer::div(get_string('employeecompetencyhistorynote', 'local_kbacorporate'),
    'kbacorp-info-note');
echo $navigation;
echo $OUTPUT->footer();
