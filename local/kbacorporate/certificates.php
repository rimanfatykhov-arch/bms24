<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcertificates', $context);

$companyid = required_param('companyid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
if (!in_array($status, ['', 'issued', 'notissued'], true)) {
    $status = '';
}
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/certificates.php', [
    'companyid' => $companyid,
    'courseid' => $courseid,
    'userid' => $userid,
    'status' => $status,
    'departmentid' => $departmentid,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('certificatesnav', 'local_kbacorporate'));
$PAGE->set_heading(get_string('certificatesnav', 'local_kbacorporate'));

echo $OUTPUT->header();
echo html_writer::div(
    html_writer::link(new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
        '← ' . get_string('backtodashboard', 'local_kbacorporate')),
    'kbacorp-backlink'
);
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('certificatespagedesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid, '/local/kbacorporate/certificates.php', ['courseid' => $courseid, 'userid' => $userid, 'status' => $status]);

if (!\local_kbacorporate\local\customcert::is_available()) {
    echo $OUTPUT->notification(get_string('customcertnotavailabledesc', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$allrows = \local_kbacorporate\local\customcert::get_company_rows($companyid, $visibleids);
$courses = [];
$employees = [];
foreach ($allrows as $row) {
    $courses[$row->courseid] = format_string($row->coursename);
    $employees[$row->userid] = $row->fullname;
}
asort($courses);
asort($employees);

$rows = [];
foreach ($allrows as $row) {
    if ($courseid && (int)$row->courseid !== $courseid) {
        continue;
    }
    if ($userid && (int)$row->userid !== $userid) {
        continue;
    }
    if ($status === 'issued' && empty($row->issued)) {
        continue;
    }
    if ($status === 'notissued' && !empty($row->issued)) {
        continue;
    }
    $rows[] = $row;
}

// Read-only filters.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/local/kbacorporate/certificates.php'))->out(false),
    'class' => 'kbacorp-filters',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]);
echo html_writer::label(get_string('course', 'local_kbacorporate'), 'kbacorp-cert-course-filter', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select([0 => get_string('allcourses', 'local_kbacorporate')] + $courses,
    'courseid', $courseid, false, ['id' => 'kbacorp-cert-course-filter', 'class' => 'custom-select']);
echo html_writer::label(get_string('fullname', 'local_kbacorporate'), 'kbacorp-cert-user-filter', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select([0 => get_string('allemployees', 'local_kbacorporate')] + $employees,
    'userid', $userid, false, ['id' => 'kbacorp-cert-user-filter', 'class' => 'custom-select']);
echo html_writer::label(get_string('status', 'local_kbacorporate'), 'kbacorp-cert-status-filter', false,
    ['class' => 'kbacorp-filter-label']);
echo html_writer::select([
    '' => get_string('allstatuses', 'local_kbacorporate'),
    'issued' => get_string('certificateissued', 'local_kbacorporate'),
    'notissued' => get_string('certificatenotissued', 'local_kbacorporate'),
], 'status', $status, false, ['id' => 'kbacorp-cert-status-filter', 'class' => 'custom-select']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), [
    'type' => 'submit', 'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

$summary = \local_kbacorporate\local\customcert::summary($rows);
$cards = [
    [get_string('certificateactivities', 'local_kbacorporate'), $summary->certificateactivities],
    [get_string('certificatesissued', 'local_kbacorporate'), $summary->issued],
    [get_string('certificatesnotissued', 'local_kbacorporate'), $summary->notissued],
    [get_string('certificatecoverage', 'local_kbacorporate'),
        $summary->issuedpercent === null ? '—' : format_float($summary->issuedpercent, 1) . '%'],
    [get_string('employeeswithcertificates', 'local_kbacorporate'), $summary->employeeswithissued],
];
echo html_writer::start_div('kbacorp-summary kbacorp-summary-compact');
foreach ($cards as $card) {
    echo html_writer::div(
        html_writer::div(s((string)$card[1]), 'value') . html_writer::div(s($card[0]), 'label'),
        'kbacorp-card'
    );
}
echo html_writer::end_div();

echo html_writer::div(get_string('certificateregistrynote', 'local_kbacorporate'), 'kbacorp-info-note');

if (has_capability('local/kbacorporate:exportreport', $context)) {
    echo html_writer::div(
        $OUTPUT->single_button(new moodle_url('/local/kbacorporate/export_certificates.php', [
            'companyid' => $companyid,
            'courseid' => $courseid,
            'userid' => $userid,
            'status' => $status,
            'departmentid' => $departmentid,
        ]), get_string('certificateexportcsv', 'local_kbacorporate'), 'get'),
        'kbacorp-toolbar'
    );
}

if (!$rows) {
    echo $OUTPUT->notification(get_string('nocertificaterows', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report kbacorp-cert-registry';
$table->head = [
    get_string('fullname', 'local_kbacorporate'),
    get_string('course', 'local_kbacorporate'),
    get_string('certificate', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('certificateissuedat', 'local_kbacorporate'),
    get_string('certificatecode', 'local_kbacorporate'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($rows as $row) {
    $userurl = new moodle_url('/local/kbacorporate/employee.php', [
        'companyid' => $companyid,
        'userid' => $row->userid,
    ]);
    $statushtml = !empty($row->issued)
        ? html_writer::span(get_string('certificateissued', 'local_kbacorporate'),
            'kbacorp-status kbacorp-cert-status-issued')
        : html_writer::span(get_string('certificatenotissued', 'local_kbacorporate'),
            'kbacorp-status kbacorp-cert-status-notissued');

    $actions = '—';
    if (!empty($row->issued)) {
        $actions = html_writer::link(new moodle_url('/local/kbacorporate/certificate_download.php', [
            'companyid' => $companyid,
            'issueid' => $row->issueid,
        ]), get_string('downloadcertificate', 'local_kbacorporate'), ['class' => 'btn btn-sm btn-outline-primary']);
        if ($row->code !== '' && ($row->verifyany || has_capability(
                'mod/customcert:verifycertificate', context_module::instance((int)$row->cmid)))) {
            $verifyurl = new moodle_url('/mod/customcert/verify_certificate.php', [
                'contextid' => $row->contextid,
                'code' => $row->code,
            ]);
            $actions .= ' ' . html_writer::link($verifyurl, get_string('verifycertificate', 'local_kbacorporate'), [
                'class' => 'btn btn-sm btn-outline-secondary',
            ]);
        }
    }

    $certcell = format_string($row->certificatename);
    if ($row->issuecount > 1) {
        $certcell .= html_writer::div(
            get_string('certificateissuecount', 'local_kbacorporate', $row->issuecount),
            'kbacorp-cell-muted'
        );
    }
    $table->data[] = [
        html_writer::link($userurl, s($row->fullname)),
        html_writer::link(new moodle_url('/course/view.php', ['id' => $row->courseid]), format_string($row->coursename), ['class' => 'kbacorp-course-link']),
        $certcell,
        $statushtml,
        $row->issuedat ? userdate($row->issuedat) : '—',
        $row->code !== '' ? html_writer::tag('code', s($row->code)) : '—',
        $actions,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
