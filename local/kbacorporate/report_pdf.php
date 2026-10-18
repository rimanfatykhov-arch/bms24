<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/pdflib.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:exportreport', $context);
$canviewalerts = has_capability('local/kbacorporate:viewalerts', $context);
$canviewlicenses = has_capability('local/kbacorporate:viewlicenses', $context);

$companyid = required_param('companyid', PARAM_INT);
\local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$preset = optional_param('preset', 'month', PARAM_ALPHA);
$from = optional_param('from', '', PARAM_RAW_TRIMMED);
$to = optional_param('to', '', PARAM_RAW_TRIMMED);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$management = \local_kbacorporate\local\management_report::build($companyid, $preset, $from, $to, $visibleids);

$h = static function($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$percent = static function($value) { return $value === null ? '-' : format_float((float)$value, 1) . '%'; };
$hours = static function($minutes) { return format_float(((float)$minutes) / 60, 1) . ' ч'; };

$pdf = new pdf('P', 'mm', 'A4', true, 'UTF-8');
$pdf->SetCreator('KBA Corporate Dashboard');
$pdf->SetAuthor('Kazan Business Academy');
$pdf->SetTitle(get_string('managementreporttitle', 'local_kbacorporate') . ' - ' . $management->company->name);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->AddPage();
$pdf->SetFont(PDF_DEFAULT_FONT, '', 10);

$title = '<h1 style="font-size:20px;">' . $h(get_string('managementreporttitle', 'local_kbacorporate')) . '</h1>' .
    '<h2 style="font-size:15px;">' . $h($management->company->name) . '</h2>' .
    '<p><b>' . $h(get_string('reportperiod', 'local_kbacorporate')) . ':</b> ' . $h($management->period->from . ' - ' . $management->period->to) . '</p>' .
    '<p style="color:#666666;">' . $h(get_string('managementreportgenerated', 'local_kbacorporate', userdate(time()))) . '</p>';
$pdf->writeHTML($title, true, false, true, false, '');

$summaryrows = [
    [get_string('employees', 'local_kbacorporate'), count($management->employees)],
    [get_string('avgprogress', 'local_kbacorporate'), $percent($management->progresssummary->avgprogress)],
    [get_string('avggrade', 'local_kbacorporate'), $percent($management->progresssummary->avggrade)],
    [get_string('trainingplanhours', 'local_kbacorporate'), $hours($management->timereport->summary->planminutes)],
    [get_string('trainingfacthours', 'local_kbacorporate'), $hours($management->timereport->summary->totalminutes)],
    [get_string('trainingplanpercent', 'local_kbacorporate'), $percent($management->timereport->summary->percent)],
    [get_string('bbbattendance', 'local_kbacorporate'), $percent($management->webinarsummary->attendancepercent)],
    [get_string('certificatesissuedperiod', 'local_kbacorporate'), $management->certificatesissuedinperiod],
];
if ($canviewlicenses) {
    $summaryrows[] = [get_string('purchasedseats', 'local_kbacorporate'), $management->licensesummary->totalseats];
    $summaryrows[] = [get_string('usedseats', 'local_kbacorporate'), $management->licensesummary->usedfinite];
    $summaryrows[] = [get_string('freeseats', 'local_kbacorporate'), $management->licensesummary->free];
    $summaryrows[] = [get_string('licenseutilization', 'local_kbacorporate'),
        $percent($management->licensesummary->utilizationpercent)];
}
if ($canviewalerts) {
    $summaryrows[] = [get_string('requiresattention', 'local_kbacorporate'), count($management->attention)];
}
$html = '<h3>' . $h(get_string('executivesummary', 'local_kbacorporate')) . '</h3><table border="1" cellpadding="5" cellspacing="0" width="100%">';
foreach ($summaryrows as $row) {
    $html .= '<tr><td width="65%"><b>' . $h($row[0]) . '</b></td><td width="35%">' . $h($row[1]) . '</td></tr>';
}
$html .= '</table>';
$pdf->writeHTML($html, true, false, true, false, '');

if ($canviewlicenses) {
    $pdf->writeHTML('<h3>' . $h(get_string('licensecontractsummary', 'local_kbacorporate')) . '</h3>', true, false, true, false, '');
    if (!$management->licenserows) {
        $pdf->writeHTML('<p>' . $h(get_string('licensenoitems', 'local_kbacorporate')) . '</p>', true, false, true, false, '');
    } else {
        $html = '<table border="1" cellpadding="4" cellspacing="0" width="100%"><tr style="font-weight:bold;background-color:#eeeeee;"><th width="34%">' . $h(get_string('course', 'local_kbacorporate')) . '</th><th width="20%">' . $h(get_string('licenseperiod', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('purchasedseats', 'local_kbacorporate')) . '</th><th width="10%">' . $h(get_string('usedseats', 'local_kbacorporate')) . '</th><th width="10%">' . $h(get_string('freeseats', 'local_kbacorporate')) . '</th><th width="14%">' . $h(get_string('status', 'local_kbacorporate')) . '</th></tr>';
        foreach ($management->licenserows as $license) {
            $fromdate = $license->datefrom ? userdate($license->datefrom, '%d.%m.%Y') : '-';
            $todate = $license->dateto ? userdate($license->dateto, '%d.%m.%Y') : '-';
            $seats = $license->unlimited ? get_string('licenseunlimited', 'local_kbacorporate') : $license->seats;
            $free = $license->unlimited ? '-' : $license->available;
            $html .= '<tr><td>' . $h($license->fullname) . '</td><td>' . $h($fromdate . ' - ' . $todate) . '</td><td>' . $h($seats) . '</td><td>' . $h($license->used) . '</td><td>' . $h($free) . '</td><td>' . $h(\local_kbacorporate\local\licenses::status_label((string)$license->effectivestatus)) . '</td></tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
    }
}

if ($canviewalerts) {
    $pdf->writeHTML('<h3>' . $h(get_string('requiresattention', 'local_kbacorporate')) . '</h3>', true, false, true, false, '');
    if (!$management->attention) {
        $pdf->writeHTML('<p>' . $h(get_string('noattentionitems', 'local_kbacorporate')) . '</p>', true, false, true, false, '');
    } else {
        $html = '<table border="1" cellpadding="4" cellspacing="0" width="100%"><tr style="font-weight:bold;background-color:#eeeeee;"><th width="28%">' . $h(get_string('fullname', 'local_kbacorporate')) . '</th><th width="14%">' . $h(get_string('avgprogress', 'local_kbacorporate')) . '</th><th width="14%">' . $h(get_string('trainingplanpercent', 'local_kbacorporate')) . '</th><th width="44%">' . $h(get_string('attentionreason', 'local_kbacorporate')) . '</th></tr>';
        foreach (array_slice($management->attention, 0, 15) as $employee) {
            $html .= '<tr><td>' . $h($employee->fullname) . '</td><td>' . $h($percent($employee->avgprogress)) . '</td><td>' . $h($percent($employee->trainingpercent)) . '</td><td>' . $h(implode('; ', $employee->riskreasons)) . '</td></tr>';
        }
        $html .= '</table>';
        $pdf->writeHTML($html, true, false, true, false, '');
    }
}

$pdf->AddPage();
$pdf->writeHTML('<h2>' . $h(get_string('courseportfolio', 'local_kbacorporate')) . '</h2>', true, false, true, false, '');
$html = '<table border="1" cellpadding="4" cellspacing="0" width="100%"><tr style="font-weight:bold;background-color:#eeeeee;"><th width="38%">' . $h(get_string('course', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('employees', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('completed', 'local_kbacorporate')) . '</th><th width="14%">' . $h(get_string('avgprogress', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('avggrade', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('trainingfacthours', 'local_kbacorporate')) . '</th></tr>';
foreach ($management->courses as $course) {
    $html .= '<tr><td>' . $h($course->coursename) . '</td><td>' . $h($course->employees) . '</td><td>' . $h($course->completed) . '</td><td>' . $h($percent($course->avgprogress)) . '</td><td>' . $h($percent($course->avggrade)) . '</td><td>' . $h($hours($course->trainingminutes)) . '</td></tr>';
}
$html .= '</table>';
$pdf->writeHTML($html, true, false, true, false, '');

$pdf->writeHTML('<h2>' . $h(get_string('employees', 'local_kbacorporate')) . '</h2>', true, false, true, false, '');
$html = '<table border="1" cellpadding="3" cellspacing="0" width="100%"><tr style="font-weight:bold;background-color:#eeeeee;"><th width="24%">' . $h(get_string('fullname', 'local_kbacorporate')) . '</th><th width="18%">' . $h(get_string('position', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('avgprogress', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('trainingplanpercent', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('bbbattendance', 'local_kbacorporate')) . '</th><th width="10%">' . $h(get_string('certificatesissued', 'local_kbacorporate')) . '</th><th width="12%">' . $h(get_string('completed', 'local_kbacorporate')) . '</th></tr>';
foreach ($management->employees as $employee) {
    $html .= '<tr><td>' . $h($employee->fullname) . '</td><td>' . $h($employee->position) . '</td><td>' . $h($percent($employee->avgprogress)) . '</td><td>' . $h($percent($employee->trainingpercent)) . '</td><td>' . $h($percent($employee->webinarattendancepercent)) . '</td><td>' . $h($employee->certificatesissued) . '</td><td>' . $h($employee->completedcourses . '/' . $employee->courses) . '</td></tr>';
}
$html .= '</table>';
$pdf->writeHTML($html, true, false, true, false, '');

$filename = clean_filename('KBA_' . $management->company->name . '_' . $management->period->from . '_' . $management->period->to . '.pdf');
$pdf->Output($filename, 'D');
exit;
