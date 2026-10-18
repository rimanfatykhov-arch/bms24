<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewlicenses', $context);
$companyid = required_param('companyid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$licenses = \local_kbacorporate\local\licenses::get_company_licenses($companyid, true);
$summary = \local_kbacorporate\local\licenses::summary($licenses);
$alerts = \local_kbacorporate\local\licenses::alerts($licenses);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/licenses.php', [
    'companyid' => $companyid,
    'departmentid' => $departmentid,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('licenses', 'local_kbacorporate'));
$PAGE->set_heading(get_string('licenses', 'local_kbacorporate'));

$period = static function($license) {
    if (empty($license->datefrom) && empty($license->dateto)) {
        return get_string('licensenotimelimit', 'local_kbacorporate');
    }
    $from = !empty($license->datefrom) ? userdate($license->datefrom, get_string('strftimedateshort', 'langconfig')) : '—';
    $to = !empty($license->dateto) ? userdate($license->dateto, get_string('strftimedateshort', 'langconfig')) : '—';
    return $from . ' — ' . $to;
};
$capacity = static function($license) {
    if (!empty($license->unlimited)) {
        return get_string('licenseunlimited', 'local_kbacorporate');
    }
    return get_string('licensecapacityformat', 'local_kbacorporate', (object)[
        'used' => (int)$license->used,
        'seats' => (int)$license->seats,
        'free' => (int)$license->available,
    ]);
};

echo $OUTPUT->header();
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
    '← ' . get_string('backtodashboard', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($company->name), 2);
echo html_writer::div(get_string('licensespagedesc', 'local_kbacorporate'), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter(
    $companyid,
    $USER->id,
    $departmentid,
    '/local/kbacorporate/licenses.php'
);

$cards = [
    [get_string('licensepackages', 'local_kbacorporate'), $summary->licenses],
    [get_string('purchasedseats', 'local_kbacorporate'), $summary->totalseats],
    [get_string('usedseats', 'local_kbacorporate'), $summary->usedfinite],
    [get_string('freeseats', 'local_kbacorporate'), $summary->free],
    [get_string('licenseutilization', 'local_kbacorporate'), $summary->utilizationpercent === null ? '—' : format_float($summary->utilizationpercent, 1) . '%'],
    [get_string('expiringlicenses', 'local_kbacorporate'), $summary->expiringlicenses],
];
echo html_writer::start_div('kbacorp-summary');
foreach ($cards as [$label, $value]) {
    echo html_writer::div(html_writer::div(s((string)$value), 'value') . html_writer::div(s($label), 'label'), 'kbacorp-card');
}
echo html_writer::end_div();

if ($alerts) {
    echo $OUTPUT->heading(get_string('licensewarnings', 'local_kbacorporate'), 3);
    foreach (array_slice($alerts, 0, 10) as $alert) {
        $type = $alert->severity === 'high' ? 'notifyproblem' : 'warning';
        echo $OUTPUT->notification(s($alert->reason), $type);
    }
}

if (!$licenses) {
    echo $OUTPUT->notification(get_string('licensenoitems', 'local_kbacorporate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [
    get_string('course', 'local_kbacorporate'),
    get_string('group', 'local_kbacorporate'),
    get_string('licenseperiod', 'local_kbacorporate'),
    get_string('licensecapacity', 'local_kbacorporate'),
    get_string('licenseutilization', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('actions', 'local_kbacorporate'),
];
foreach ($licenses as $license) {
    $statusclass = 'kbacorp-status kbacorp-status-' . preg_replace('/[^a-z]/', '', (string)$license->effectivestatus);
    $utilization = $license->utilizationpercent === null
        ? '—' : format_float($license->utilizationpercent, 1) . '%';
    $table->data[] = [
        html_writer::link(new moodle_url('/course/view.php', ['id' => $license->courseid]),
            format_string($license->fullname), ['class' => 'kbacorp-course-link']),
        $license->groupid ? format_string($license->groupname) : get_string('nogroup', 'local_kbacorporate'),
        s($period($license)),
        s($capacity($license)),
        s($utilization),
        html_writer::span(s(\local_kbacorporate\local\licenses::status_label((string)$license->effectivestatus)), $statusclass),
        html_writer::link(new moodle_url('/local/kbacorporate/license_view.php', [
            'companyid' => $companyid,
            'licenseid' => $license->id,
            'departmentid' => $departmentid,
        ]), get_string('viewdetails', 'local_kbacorporate') . ' →'),
    ];
}
echo html_writer::table($table);

if (has_capability('local/kbacorporate:exportreport', $context)) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/export_licenses.php', [
        'companyid' => $companyid,
        'departmentid' => $departmentid,
    ]), get_string('licensescsv', 'local_kbacorporate'), 'get');
}
if (has_capability('local/kbacorporate:managelicenses', $context)) {
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/licenses_manage.php', [
        'companyid' => $companyid,
    ]), get_string('licensesmanage', 'local_kbacorporate'), 'get');
}
echo $OUTPUT->footer();
