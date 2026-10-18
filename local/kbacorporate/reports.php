<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:exportreport', $context);
$canviewalerts = has_capability('local/kbacorporate:viewalerts', $context);
$canviewlicenses = has_capability('local/kbacorporate:viewlicenses', $context);

$companyid = required_param('companyid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$preset = optional_param('preset', 'month', PARAM_ALPHA);
$from = optional_param('from', '', PARAM_RAW_TRIMMED);
$to = optional_param('to', '', PARAM_RAW_TRIMMED);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$management = \local_kbacorporate\local\management_report::build($companyid, $preset, $from, $to, $visibleids);
$period = $management->period;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/reports.php', [
    'companyid' => $companyid,
    'preset' => $period->preset,
    'from' => $period->from,
    'to' => $period->to,
    'departmentid' => $departmentid,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('reportscenter', 'local_kbacorporate'));
$PAGE->set_heading(get_string('reportscenter', 'local_kbacorporate'));
$PAGE->requires->css(new moodle_url('/local/kbacorporate/styles.css'));

$presetoptions = [
    'week' => get_string('periodweek', 'local_kbacorporate'),
    'month' => get_string('periodmonth', 'local_kbacorporate'),
    'quarter' => get_string('periodquarter', 'local_kbacorporate'),
    'custom' => get_string('periodcustom', 'local_kbacorporate'),
];
$exportparams = [
    'companyid' => $companyid,
    'preset' => $period->preset,
    'from' => $period->from,
    'to' => $period->to,
    'departmentid' => $departmentid,
];
$hours = static function($minutes) {
    return format_float(((float)$minutes) / 60, 1) . ' ' . get_string('hoursabbr', 'local_kbacorporate');
};

 echo $OUTPUT->header();
 echo html_writer::div(html_writer::link(
     new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]),
     '← ' . get_string('backtodashboard', 'local_kbacorporate')
 ), 'kbacorp-backlink');
 echo $OUTPUT->heading(format_string($company->name), 2);
 echo html_writer::div(get_string('reportscenterdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
 echo \local_kbacorporate\local\departments::render_filter($companyid, $USER->id, $departmentid, '/local/kbacorporate/reports.php', ['preset' => $period->preset, 'from' => $period->from, 'to' => $period->to]);

 echo html_writer::start_tag('form', [
     'method' => 'get',
     'action' => (new moodle_url('/local/kbacorporate/reports.php'))->out(false),
     'class' => 'kbacorp-filters kbacorp-time-filters',
 ]);
 echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
 echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'departmentid', 'value' => $departmentid]);
 echo html_writer::label(get_string('periodtype', 'local_kbacorporate'), 'kbacorp-report-preset', false, ['class' => 'kbacorp-filter-label']);
 echo html_writer::select($presetoptions, 'preset', $period->preset, false, ['id' => 'kbacorp-report-preset', 'class' => 'custom-select']);
 echo html_writer::label(get_string('periodfrom', 'local_kbacorporate'), 'kbacorp-report-from', false, ['class' => 'kbacorp-filter-label']);
 echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'kbacorp-report-from', 'name' => 'from', 'value' => $period->from, 'class' => 'form-control']);
 echo html_writer::label(get_string('periodto', 'local_kbacorporate'), 'kbacorp-report-to', false, ['class' => 'kbacorp-filter-label']);
 echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'kbacorp-report-to', 'name' => 'to', 'value' => $period->to, 'class' => 'form-control']);
 echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), ['type' => 'submit', 'class' => 'btn btn-secondary']);
 echo html_writer::end_tag('form');

 echo html_writer::div(get_string('periodresolved', 'local_kbacorporate', (object)[
     'from' => userdate($period->start, get_string('strftimedateshort', 'langconfig')),
     'to' => userdate($period->endexclusive - DAYSECS, get_string('strftimedateshort', 'langconfig')),
     'days' => $period->days,
 ]), 'kbacorp-period-resolved');

 echo html_writer::start_div('kbacorp-report-actions');
 $actions = [
     [get_string('managementpdf', 'local_kbacorporate'), new moodle_url('/local/kbacorporate/report_pdf.php', $exportparams), 'PDF'],
     [get_string('managementxlsx', 'local_kbacorporate'), new moodle_url('/local/kbacorporate/export_excel.php', $exportparams), 'XLSX'],
     [get_string('generalcsv', 'local_kbacorporate'), new moodle_url('/local/kbacorporate/export.php', ['companyid' => $companyid, 'departmentid' => $departmentid]), 'CSV'],
     [get_string('trainingtimecsv', 'local_kbacorporate'), new moodle_url('/local/kbacorporate/export_time.php', $exportparams + ['scope' => 'employees']), 'CSV'],
     [get_string('webinarscsv', 'local_kbacorporate'), new moodle_url('/local/kbacorporate/export_webinars.php', ['companyid' => $companyid, 'departmentid' => $departmentid]), 'CSV'],
     [get_string('certificatescsv', 'local_kbacorporate'), new moodle_url('/local/kbacorporate/export_certificates.php', ['companyid' => $companyid, 'departmentid' => $departmentid]), 'CSV'],
 ];
 if ($canviewlicenses) {
     $actions[] = [get_string('licensescsv', 'local_kbacorporate'),
         new moodle_url('/local/kbacorporate/export_licenses.php', [
             'companyid' => $companyid,
             'departmentid' => $departmentid,
         ]), 'CSV'];
 }
 if ($canviewalerts) {
     $actions[] = [get_string('exportalertscsv', 'local_kbacorporate'),
         new moodle_url('/local/kbacorporate/export_alerts.php', $exportparams), 'CSV'];
 }
 foreach ($actions as [$label, $url, $format]) {
     $card = html_writer::div(s($format), 'kbacorp-report-action-format') .
         html_writer::div(s($label), 'kbacorp-report-action-title') .
         html_writer::div(get_string('downloadreport', 'local_kbacorporate') . ' →', 'kbacorp-report-action-open');
     echo html_writer::link($url, $card, ['class' => 'kbacorp-report-action']);
 }
 echo html_writer::end_div();

 $summary = $management->progresssummary;
 $time = $management->timereport->summary;
 $web = $management->webinarsummary;
 $cert = $management->certificatesummary;
 $licensesummary = $management->licensesummary;
 $cards = [
     [get_string('employees', 'local_kbacorporate'), count($management->employees)],
     [get_string('avgprogress', 'local_kbacorporate'), $summary->avgprogress === null ? '—' : format_float($summary->avgprogress, 1) . '%'],
     [get_string('trainingfacthours', 'local_kbacorporate'), $hours($time->totalminutes)],
     [get_string('trainingplanpercent', 'local_kbacorporate'), $time->percent === null ? '—' : format_float($time->percent, 1) . '%'],
     [get_string('bbbattendance', 'local_kbacorporate'), $web->attendancepercent === null ? '—' : format_float($web->attendancepercent, 1) . '%'],
     [get_string('certificatesissuedperiod', 'local_kbacorporate'), $management->certificatesissuedinperiod],
 ];
 if ($canviewlicenses) {
     $cards[] = [get_string('purchasedseats', 'local_kbacorporate'), $licensesummary->totalseats];
     $cards[] = [get_string('freeseats', 'local_kbacorporate'), $licensesummary->free];
     $cards[] = [get_string('licenseutilization', 'local_kbacorporate'),
         $licensesummary->utilizationpercent === null ? '—' : format_float($licensesummary->utilizationpercent, 1) . '%'];
 }
 if ($canviewalerts) {
     $cards[] = [get_string('requiresattention', 'local_kbacorporate'), count($management->attention)];
 }
 echo html_writer::start_div('kbacorp-summary kbacorp-report-kpis');
 foreach ($cards as $card) {
     echo html_writer::div(html_writer::div(s((string)$card[1]), 'value') . html_writer::div(s($card[0]), 'label'), 'kbacorp-card');
 }
 echo html_writer::end_div();

 if ($canviewlicenses) {
 echo $OUTPUT->heading(get_string('licensecontractsummary', 'local_kbacorporate'), 3);
 if (!$management->licenserows) {
     echo $OUTPUT->notification(get_string('licensenoitems', 'local_kbacorporate'), 'info');
 } else {
     $table = new html_table();
     $table->attributes['class'] = 'generaltable kbacorp-report';
     $table->head = [
         get_string('course', 'local_kbacorporate'),
         get_string('licenseperiod', 'local_kbacorporate'),
         get_string('purchasedseats', 'local_kbacorporate'),
         get_string('usedseats', 'local_kbacorporate'),
         get_string('freeseats', 'local_kbacorporate'),
         get_string('licenseutilization', 'local_kbacorporate'),
         get_string('status', 'local_kbacorporate'),
     ];
     foreach ($management->licenserows as $license) {
         $fromdate = $license->datefrom ? userdate($license->datefrom, get_string('strftimedateshort', 'langconfig')) : '—';
         $todate = $license->dateto ? userdate($license->dateto, get_string('strftimedateshort', 'langconfig')) : '—';
         $table->data[] = [
             html_writer::link(new moodle_url('/course/view.php', ['id' => $license->courseid]),
                 format_string($license->fullname)),
             s($fromdate . ' — ' . $todate),
             $license->unlimited ? get_string('licenseunlimited', 'local_kbacorporate') : (int)$license->seats,
             (int)$license->used,
             $license->unlimited ? '—' : (int)$license->available,
             $license->utilizationpercent === null ? '—' : format_float($license->utilizationpercent, 1) . '%',
             s(\local_kbacorporate\local\licenses::status_label((string)$license->effectivestatus)),
         ];
     }
     echo html_writer::table($table);
     echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/licenses.php', [
         'companyid' => $companyid,
         'departmentid' => $departmentid,
     ]), get_string('openlicenseregister', 'local_kbacorporate') . ' →'), 'kbacorp-viewall');
 }
 }

 if ($canviewalerts) {
 echo $OUTPUT->heading(get_string('requiresattention', 'local_kbacorporate'), 3);
 if (!$management->attention) {
     echo $OUTPUT->notification(get_string('noattentionitems', 'local_kbacorporate'), 'notifysuccess');
 } else {
     $table = new html_table();
     $table->attributes['class'] = 'generaltable kbacorp-report';
     $table->head = [get_string('riskseverity', 'local_kbacorporate'), get_string('fullname', 'local_kbacorporate'), get_string('avgprogress', 'local_kbacorporate'), get_string('trainingplanpercent', 'local_kbacorporate'), get_string('bbbattendance', 'local_kbacorporate'), get_string('attentionreason', 'local_kbacorporate')];
     foreach (array_slice($management->attention, 0, 20) as $employee) {
         $table->data[] = [
             html_writer::span(
                 s(\local_kbacorporate\local\attention::severity_label($employee->riskseverity)),
                 'kbacorp-risk-badge kbacorp-risk-' . $employee->riskseverity
             ),
             html_writer::link(new moodle_url('/local/kbacorporate/employee.php', ['companyid' => $companyid, 'userid' => $employee->userid]), s($employee->fullname)),
             $employee->avgprogress === null ? '—' : format_float($employee->avgprogress, 1) . '%',
             $employee->trainingpercent === null ? '—' : format_float($employee->trainingpercent, 1) . '%',
             $employee->webinarattendancepercent === null ? '—' : format_float($employee->webinarattendancepercent, 1) . '%',
             s(implode('; ', $employee->riskreasons)),
         ];
     }
     echo html_writer::table($table);
     echo html_writer::div(
         html_writer::link(new moodle_url('/local/kbacorporate/alerts.php', $exportparams),
             get_string('openattentioncenter', 'local_kbacorporate') . ' →'),
         'kbacorp-viewall'
     );
 }
 }

 echo $OUTPUT->heading(get_string('courseportfolio', 'local_kbacorporate'), 3);
 if (!$management->courses) {
     echo $OUTPUT->notification(get_string('nocoursesassigned', 'local_kbacorporate'), 'info');
 } else {
     $table = new html_table();
     $table->attributes['class'] = 'generaltable kbacorp-report';
     $table->head = [get_string('course', 'local_kbacorporate'), get_string('employees', 'local_kbacorporate'), get_string('completed', 'local_kbacorporate'), get_string('avgprogress', 'local_kbacorporate'), get_string('avggrade', 'local_kbacorporate'), get_string('trainingfacthours', 'local_kbacorporate')];
     foreach ($management->courses as $course) {
         $table->data[] = [
             html_writer::link(new moodle_url('/course/view.php', ['id' => $course->courseid]), s($course->coursename)),
             $course->employees,
             $course->completed,
             $course->avgprogress === null ? '—' : format_float($course->avgprogress, 1) . '%',
             $course->avggrade === null ? '—' : format_float($course->avggrade, 1) . '%',
             $hours($course->trainingminutes),
         ];
     }
     echo html_writer::table($table);
 }

 echo $OUTPUT->footer();
