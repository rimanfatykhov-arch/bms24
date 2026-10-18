<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewlicenses', $context);
$companyid = required_param('companyid', PARAM_INT);
$licenseid = required_param('licenseid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$license = \local_kbacorporate\local\licenses::get_license($licenseid, $companyid);
if (!$license) {
    throw new moodle_exception('licensenotfound', 'local_kbacorporate');
}
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$allocations = \local_kbacorporate\local\licenses::get_license_allocations($licenseid, $visibleids);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/license_view.php', [
    'companyid' => $companyid,
    'licenseid' => $licenseid,
    'departmentid' => $departmentid,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('licensedetails', 'local_kbacorporate'));
$PAGE->set_heading(get_string('licensedetails', 'local_kbacorporate'));

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/licenses.php', ['companyid' => $companyid, 'departmentid' => $departmentid]),
    '← ' . get_string('licenses', 'local_kbacorporate')
), 'kbacorp-backlink');
echo $OUTPUT->heading(format_string($license->fullname), 2);
echo html_writer::div(format_string($company->name), 'kbacorp-pagelead');
echo \local_kbacorporate\local\departments::render_filter(
    $companyid,
    $USER->id,
    $departmentid,
    '/local/kbacorporate/license_view.php',
    ['licenseid' => $licenseid]
);

$period = (object)[
    'from' => $license->datefrom ? userdate($license->datefrom, get_string('strftimedateshort', 'langconfig')) : '—',
    'to' => $license->dateto ? userdate($license->dateto, get_string('strftimedateshort', 'langconfig')) : '—',
];
$cards = [
    [get_string('licenseperiod', 'local_kbacorporate'), $period->from . ' — ' . $period->to],
    [get_string('purchasedseats', 'local_kbacorporate'), $license->unlimited ? get_string('licenseunlimited', 'local_kbacorporate') : $license->seats],
    [get_string('usedseats', 'local_kbacorporate'), $license->used],
    [get_string('freeseats', 'local_kbacorporate'), $license->unlimited ? '—' : $license->available],
    [get_string('licenseutilization', 'local_kbacorporate'), $license->utilizationpercent === null ? '—' : format_float($license->utilizationpercent, 1) . '%'],
    [get_string('status', 'local_kbacorporate'), \local_kbacorporate\local\licenses::status_label((string)$license->effectivestatus)],
];
echo html_writer::start_div('kbacorp-summary');
foreach ($cards as [$label, $value]) {
    echo html_writer::div(html_writer::div(s((string)$value), 'value') . html_writer::div(s($label), 'label'), 'kbacorp-card');
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('licenseallocationsvisible', 'local_kbacorporate'), 3);
if (!$allocations) {
    echo $OUTPUT->notification(get_string('licensenoallocationsvisible', 'local_kbacorporate'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('employee', 'local_kbacorporate'),
        get_string('departments', 'local_kbacorporate'),
        get_string('licenseallocationstatus', 'local_kbacorporate'),
        get_string('licenseassignedat', 'local_kbacorporate'),
    ];
    foreach ($allocations as $allocation) {
        $table->data[] = [
            html_writer::link(new moodle_url('/local/kbacorporate/employee.php', [
                'companyid' => $companyid,
                'userid' => $allocation->userid,
            ]), s($allocation->fullname)),
            s($allocation->departments ?: '—'),
            s(\local_kbacorporate\local\licenses::allocation_status_label((string)$allocation->effectivestatus)),
            !empty($allocation->timeassigned) ? userdate($allocation->timeassigned) : '—',
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
