<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewalerts', $context);

$companyid = required_param('companyid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$preset = optional_param('preset', 'month', PARAM_ALPHA);
$from = optional_param('from', '', PARAM_RAW_TRIMMED);
$to = optional_param('to', '', PARAM_RAW_TRIMMED);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$severity = optional_param('severity', '', PARAM_ALPHA);
$type = optional_param('type', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$sendnow = optional_param('sendnow', 0, PARAM_BOOL);

if ($sendnow) {
    require_capability('local/kbacorporate:managealerts', $context);
    require_sesskey();
    $sendresult = \local_kbacorporate\local\attention_notifier::send_company($companyid, true, true);
    $message = get_string('attentionmanualsendresult', 'local_kbacorporate', (object)[
        'recipients' => $sendresult->recipients,
        'sent' => $sendresult->sent,
        'empty' => $sendresult->empty,
        'errors' => $sendresult->errors,
    ]);
    redirect(new moodle_url('/local/kbacorporate/alerts.php', [
        'companyid' => $companyid,
        'preset' => $preset,
        'from' => $from,
        'to' => $to,
        'departmentid' => $departmentid,
        'severity' => $severity,
        'type' => $type,
        'userid' => $userid,
    ]), $message);
}

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
if ($userid && !in_array($userid, $visibleids, true)) {
    $userid = 0;
}
$management = \local_kbacorporate\local\management_report::build($companyid, $preset, $from, $to, $visibleids);
$period = $management->period;
$alerts = \local_kbacorporate\local\attention::filter($management->alerts, $severity, $type, $userid);
$summary = \local_kbacorporate\local\attention::summary($alerts);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/alerts.php', [
    'companyid' => $companyid,
    'preset' => $period->preset,
    'from' => $period->from,
    'to' => $period->to,
    'departmentid' => $departmentid,
    'severity' => $severity,
    'type' => $type,
    'userid' => $userid,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('attentioncenter', 'local_kbacorporate'));
$PAGE->set_heading(get_string('attentioncenter', 'local_kbacorporate'));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$presetoptions = [
    'week' => get_string('periodweek', 'local_kbacorporate'),
    'month' => get_string('periodmonth', 'local_kbacorporate'),
    'quarter' => get_string('periodquarter', 'local_kbacorporate'),
    'custom' => get_string('periodcustom', 'local_kbacorporate'),
];
$severityoptions = [
    '' => get_string('allriskseverities', 'local_kbacorporate'),
    \local_kbacorporate\local\attention::SEVERITY_HIGH => get_string('riskseverity_high', 'local_kbacorporate'),
    \local_kbacorporate\local\attention::SEVERITY_MEDIUM => get_string('riskseverity_medium', 'local_kbacorporate'),
    \local_kbacorporate\local\attention::SEVERITY_LOW => get_string('riskseverity_low', 'local_kbacorporate'),
];
$typeoptions = ['' => get_string('allrisktypes', 'local_kbacorporate')];
foreach (\local_kbacorporate\local\attention::types() as $risktype) {
    $typeoptions[$risktype] = \local_kbacorporate\local\attention::type_label($risktype);
}
$useroptions = [0 => get_string('allemployees', 'local_kbacorporate')];
foreach ($management->employees as $employee) {
    $useroptions[(int)$employee->userid] = $employee->fullname;
}

$baseparams = [
    'companyid' => $companyid,
    'preset' => $period->preset,
    'from' => $period->from,
    'to' => $period->to,
    'departmentid' => $departmentid,
    'severity' => $severity,
    'type' => $type,
    'userid' => $userid,
];

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
    '← ' . get_string('backtodashboard', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('attentioncenterdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter(
    $companyid,
    $USER->id,
    $departmentid,
    '/local/kbacorporate/alerts.php',
    ['preset' => $period->preset, 'from' => $period->from, 'to' => $period->to, 'severity' => $severity,
        'type' => $type, 'userid' => $userid]
);

// Period selection.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/alerts.php'))->out(false),
    'class' => 'kbacorp-filters kbacorp-time-filters',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'severity', 'value' => $severity]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'type', 'value' => $type]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
echo html_writer::label(get_string('periodtype', 'local_kbacorporate'), 'kbacorp-alert-preset', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($presetoptions, 'preset', $period->preset, false,
    ['id' => 'kbacorp-alert-preset', 'class' => 'custom-select']);
echo html_writer::label(get_string('periodfrom', 'local_kbacorporate'), 'kbacorp-alert-from', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'kbacorp-alert-from', 'name' => 'from',
    'value' => $period->from, 'class' => 'form-control']);
echo html_writer::label(get_string('periodto', 'local_kbacorporate'), 'kbacorp-alert-to', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'kbacorp-alert-to', 'name' => 'to',
    'value' => $period->to, 'class' => 'form-control']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::div(get_string('periodresolved', 'local_kbacorporate', (object)[
    'from' => userdate($period->start, get_string('strftimedateshort', 'langconfig')),
    'to' => userdate($period->endexclusive - DAYSECS, get_string('strftimedateshort', 'langconfig')),
    'days' => $period->days,
]), 'kbacorp-period-resolved');

// Signal filters.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/alerts.php'))->out(false),
    'class' => 'kbacorp-filters',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'preset', 'value' => $period->preset]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'from', 'value' => $period->from]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'to', 'value' => $period->to]);
echo html_writer::label(get_string('riskseverity', 'local_kbacorporate'), 'kbacorp-alert-severity', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($severityoptions, 'severity', $severity, false,
    ['id' => 'kbacorp-alert-severity', 'class' => 'custom-select']);
echo html_writer::label(get_string('risktype', 'local_kbacorporate'), 'kbacorp-alert-type', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($typeoptions, 'type', $type, false,
    ['id' => 'kbacorp-alert-type', 'class' => 'custom-select']);
echo html_writer::label(get_string('employee', 'local_kbacorporate'), 'kbacorp-alert-user', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select($useroptions, 'userid', $userid, false,
    ['id' => 'kbacorp-alert-user', 'class' => 'custom-select']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

$cards = [
    [get_string('employeesrequiringattention', 'local_kbacorporate'), $summary->employees, ''],
    [get_string('riskseverity_high', 'local_kbacorporate'), $summary->high, 'kbacorp-card-risk-high'],
    [get_string('riskseverity_medium', 'local_kbacorporate'), $summary->medium, 'kbacorp-card-risk-medium'],
    [get_string('riskseverity_low', 'local_kbacorporate'), $summary->low, 'kbacorp-card-risk-low'],
    [get_string('alertsfound', 'local_kbacorporate'), $summary->alerts, ''],
];
echo html_writer::start_div('kbacorp-summary kbacorp-alert-summary');
foreach ($cards as [$label, $value, $class]) {
    echo html_writer::div(
        html_writer::div(s((string)$value), 'value') . html_writer::div(s($label), 'label'),
        trim('kbacorp-card ' . $class)
    );
}
echo html_writer::end_div();

if (has_capability('local/kbacorporate:viewlicenses', $context) &&
        \local_kbacorporate\local\licenses::is_available()) {
    echo $OUTPUT->heading(get_string('licensewarnings', 'local_kbacorporate'), 3);
    if (!$management->licensealerts) {
        echo $OUTPUT->notification(get_string('nolicensenotifications', 'local_kbacorporate'), 'notifysuccess');
    } else {
        $licensetable = new html_table();
        $licensetable->attributes['class'] = 'generaltable kbacorp-report';
        $licensetable->head = [
            get_string('riskseverity', 'local_kbacorporate'),
            get_string('course', 'local_kbacorporate'),
            get_string('attentionreason', 'local_kbacorporate'),
            get_string('usedseats', 'local_kbacorporate'),
            get_string('purchasedseats', 'local_kbacorporate'),
        ];
        foreach ($management->licensealerts as $licensealert) {
            $licensetable->data[] = [
                html_writer::span(
                    s(\local_kbacorporate\local\attention::severity_label($licensealert->severity)),
                    'kbacorp-risk-badge kbacorp-risk-' . $licensealert->severity
                ),
                html_writer::link(new moodle_url('/local/kbacorporate/license_view.php', [
                    'companyid' => $companyid,
                    'licenseid' => $licensealert->licenseid,
                    'departmentid' => $departmentid,
                ]), format_string($licensealert->coursename)),
                s($licensealert->reason),
                (int)$licensealert->used,
                (int)$licensealert->seats === 0 ? get_string('licenseunlimited', 'local_kbacorporate') : (int)$licensealert->seats,
            ];
        }
        echo html_writer::table($licensetable);
    }
}

$thresholds = $management->attentionthresholds;
echo html_writer::div(get_string('attentionthresholdsnote', 'local_kbacorporate', (object)[
    'notstarted' => $thresholds['notstarteddays'],
    'inactive' => $thresholds['inactivitydays'],
    'progress' => $thresholds['progresspercent'],
    'grade' => $thresholds['gradepercent'],
    'training' => $thresholds['trainingpercent'],
    'webinar' => $thresholds['webinarpercent'],
]), 'kbacorp-info-note');

$notificationstatus = \local_kbacorporate\local\attention_notifier::status($company);
$statusmessage = $notificationstatus['enabled']
    ? get_string('attentionnotificationsstatuson', 'local_kbacorporate', (object)[
        'frequency' => get_string('alertfrequency_' . $notificationstatus['frequency'], 'local_kbacorporate'),
        'severity' => get_string('riskseverity_' . $notificationstatus['minimumseverity'], 'local_kbacorporate'),
        'recipients' => $notificationstatus['recipients'],
    ])
    : get_string('attentionnotificationsstatusoff', 'local_kbacorporate');
$statusclass = $notificationstatus['enabled'] ? 'kbacorp-notification-status-on' : 'kbacorp-notification-status-off';
$statushtml = html_writer::span(s($statusmessage), $statusclass);
if ($notificationstatus['lastsent'] > 0) {
    $statushtml .= ' · ' . s(get_string('attentionlastsent', 'local_kbacorporate',
        userdate($notificationstatus['lastsent'])));
}
if (has_capability('local/kbacorporate:managecompanies', $context)) {
    $statushtml .= ' · ' . html_writer::link(
        new moodle_url('/local/kbacorporate/company_edit.php', ['id' => $companyid]),
        get_string('configurealerts', 'local_kbacorporate')
    );
    $statushtml .= ' · ' . html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'local_kbacorporate_settings']),
        get_string('configurealertthresholds', 'local_kbacorporate')
    );
}
echo html_writer::div($statushtml, 'kbacorp-attention-notification-status');

$actions = html_writer::link(
    new moodle_url('/local/kbacorporate/export_alerts.php', $baseparams),
    get_string('exportalertscsv', 'local_kbacorporate'),
    ['class' => 'btn btn-secondary']
);
if (has_capability('local/kbacorporate:managealerts', $context)) {
    $sendurl = new moodle_url('/local/kbacorporate/alerts.php', $baseparams + [
        'sendnow' => 1,
        'sesskey' => sesskey(),
    ]);
    $actions .= ' ' . $OUTPUT->single_button($sendurl,
        get_string('sendattentionnotificationsnow', 'local_kbacorporate'), 'post');
}
echo html_writer::div($actions, 'kbacorp-toolbar');

echo $OUTPUT->heading(get_string('attentionitems', 'local_kbacorporate') . ' (' . count($alerts) . ')', 3);
if (!$alerts) {
    echo $OUTPUT->notification(get_string('noattentionitemsforfilter', 'local_kbacorporate'), 'notifysuccess');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report kbacorp-alert-table';
    $table->head = [
        get_string('riskseverity', 'local_kbacorporate'),
        get_string('fullname', 'local_kbacorporate'),
        get_string('department', 'local_kbacorporate'),
        get_string('risktype', 'local_kbacorporate'),
        get_string('course', 'local_kbacorporate'),
        get_string('attentionreason', 'local_kbacorporate'),
        get_string('lastaccess', 'local_kbacorporate'),
    ];
    foreach ($alerts as $alert) {
        $severityhtml = html_writer::span(
            s(\local_kbacorporate\local\attention::severity_label($alert->severity)),
            'kbacorp-risk-badge kbacorp-risk-' . $alert->severity
        );
        $employeehtml = html_writer::link(
            new moodle_url('/local/kbacorporate/employee.php', [
                'companyid' => $companyid,
                'userid' => $alert->userid,
            ]),
            s($alert->fullname)
        );
        if ($alert->position !== '') {
            $employeehtml .= html_writer::div(s($alert->position), 'kbacorp-cell-muted');
        }
        $coursehtml = '—';
        if ($alert->courseid > 0) {
            $coursehtml = html_writer::link(
                new moodle_url('/course/view.php', ['id' => $alert->courseid]),
                format_string($alert->coursename),
                ['class' => 'kbacorp-course-link']
            );
        }
        $table->data[] = [
            $severityhtml,
            $employeehtml,
            $alert->departments !== '' ? s($alert->departments) : '—',
            s(\local_kbacorporate\local\attention::type_label($alert->type)),
            $coursehtml,
            s($alert->reason),
            $alert->lastaccess ? userdate($alert->lastaccess) : '—',
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
