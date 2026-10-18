<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/excellib.class.php');

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

$filename = clean_filename('KBA_' . $management->company->name . '_' . $management->period->from . '_' . $management->period->to . '.xlsx');
$workbook = new MoodleExcelWorkbook($filename);
$header = $workbook->add_format(['bold' => 1, 'bg_color' => '#E9ECEF', 'border' => 1, 'text_wrap' => 1]);
$subheader = $workbook->add_format(['bold' => 1]);
$percentformat = $workbook->add_format(['num_format' => '0.0']);

$write_table = static function($sheet, array $headers, array $rows) use ($header) {
    foreach ($headers as $col => $value) {
        $sheet->write_string(0, $col, $value, $header);
    }
    $r = 1;
    foreach ($rows as $row) {
        foreach (array_values($row) as $c => $value) {
            if (is_int($value) || is_float($value)) {
                $sheet->write_number($r, $c, $value);
            } else {
                $sheet->write_string($r, $c, (string)$value);
            }
        }
        $r++;
    }
};

// Executive summary sheet.
$sheet = $workbook->add_worksheet(get_string('excel_summary_sheet', 'local_kbacorporate'));
$sheet->set_column(0, 0, 30);
$sheet->set_column(1, 1, 24);
$sheet->write_string(0, 0, get_string('managementreporttitle', 'local_kbacorporate'), $subheader);
$sheet->write_string(1, 0, get_string('company', 'local_kbacorporate'));
$sheet->write_string(1, 1, $management->company->name);
$sheet->write_string(2, 0, get_string('reportperiod', 'local_kbacorporate'));
$sheet->write_string(2, 1, $management->period->from . ' - ' . $management->period->to);
$metrics = [
    [get_string('employees', 'local_kbacorporate'), count($management->employees)],
    [get_string('avgprogress', 'local_kbacorporate'), $management->progresssummary->avgprogress ?? ''],
    [get_string('avggrade', 'local_kbacorporate'), $management->progresssummary->avggrade ?? ''],
    [get_string('trainingplanhours', 'local_kbacorporate'), round($management->timereport->summary->planminutes / 60, 2)],
    [get_string('trainingfacthours', 'local_kbacorporate'), round($management->timereport->summary->totalminutes / 60, 2)],
    [get_string('trainingplanpercent', 'local_kbacorporate'), $management->timereport->summary->percent ?? ''],
    [get_string('bbbattendance', 'local_kbacorporate'), $management->webinarsummary->attendancepercent ?? ''],
    [get_string('certificatesissued', 'local_kbacorporate'), $management->certificatesummary->issued],
    [get_string('certificatesissuedperiod', 'local_kbacorporate'), $management->certificatesissuedinperiod],
];
if ($canviewlicenses) {
    $metrics[] = [get_string('licensepackages', 'local_kbacorporate'), $management->licensesummary->licenses];
    $metrics[] = [get_string('purchasedseats', 'local_kbacorporate'), $management->licensesummary->totalseats];
    $metrics[] = [get_string('usedseats', 'local_kbacorporate'), $management->licensesummary->usedfinite];
    $metrics[] = [get_string('freeseats', 'local_kbacorporate'), $management->licensesummary->free];
    $metrics[] = [get_string('licenseutilization', 'local_kbacorporate'),
        $management->licensesummary->utilizationpercent ?? ''];
}
if ($canviewalerts) {
    $metrics[] = [get_string('requiresattention', 'local_kbacorporate'), count($management->attention)];
}
$row = 4;
foreach ($metrics as $metric) {
    $sheet->write_string($row, 0, $metric[0], $header);
    if (is_numeric($metric[1])) {
        $sheet->write_number($row, 1, (float)$metric[1]);
    } else {
        $sheet->write_string($row, 1, (string)$metric[1]);
    }
    $row++;
}

// Employees sheet.
$rows = [];
foreach ($management->employees as $employee) {
    $rows[] = [
        $employee->fullname,
        $employee->position,
        $employee->departments,
        $employee->email,
        $employee->courses,
        $employee->completedcourses,
        $employee->avgprogress === null ? '' : $employee->avgprogress,
        $employee->avggrade === null ? '' : $employee->avggrade,
        round($employee->planminutes / 60, 2),
        round($employee->trainingminutes / 60, 2),
        $employee->trainingpercent === null ? '' : $employee->trainingpercent,
        $employee->webinarattendancepercent === null ? '' : $employee->webinarattendancepercent,
        $employee->certificatesissued,
        $employee->certificatesnotissued,
    ];
    if ($canviewalerts) {
        $rows[count($rows) - 1][] = implode('; ', $employee->riskreasons);
    }
}
$sheet = $workbook->add_worksheet(get_string('excel_employees_sheet', 'local_kbacorporate'));
$sheet->set_column(0, 0, 28); $sheet->set_column(1, 3, 24); $sheet->set_column(4, 14, 16);
$employeeheaders = [get_string('fullname', 'local_kbacorporate'), get_string('position', 'local_kbacorporate'),
    get_string('department', 'local_kbacorporate'), get_string('email'), get_string('courses', 'local_kbacorporate'),
    get_string('completed', 'local_kbacorporate'), get_string('avgprogress', 'local_kbacorporate'),
    get_string('avggrade', 'local_kbacorporate'), get_string('trainingplanhours', 'local_kbacorporate'),
    get_string('trainingfacthours', 'local_kbacorporate'), get_string('trainingplanpercent', 'local_kbacorporate'),
    get_string('bbbattendance', 'local_kbacorporate'), get_string('certificatesissued', 'local_kbacorporate'),
    get_string('certificatesnotissued', 'local_kbacorporate')];
if ($canviewalerts) {
    $employeeheaders[] = get_string('attentionreason', 'local_kbacorporate');
}
$write_table($sheet, $employeeheaders, $rows);

// Progress sheet.
$rows = [];
foreach ($management->progressrows as $item) {
    $rows[] = [$item->fullname, $item->position, $item->coursename, get_string($item->status, 'local_kbacorporate'), $item->progress === null ? '' : $item->progress, $item->grade === null ? '' : $item->grade, $item->lastaccess ? userdate($item->lastaccess) : ''];
}
$sheet = $workbook->add_worksheet(get_string('excel_progress_sheet', 'local_kbacorporate'));
$sheet->set_column(0, 2, 28); $sheet->set_column(3, 6, 18);
$write_table($sheet, [get_string('fullname', 'local_kbacorporate'), get_string('position', 'local_kbacorporate'), get_string('course', 'local_kbacorporate'), get_string('status', 'local_kbacorporate'), get_string('progress', 'local_kbacorporate'), get_string('grade', 'local_kbacorporate'), get_string('lastaccess', 'local_kbacorporate')], $rows);

// Learning time sheet.
$rows = [];
foreach ($management->timereport->employees as $item) {
    $rows[] = [$item->fullname, $item->position, round($item->planminutes / 60, 2), round($item->asyncminutes / 60, 2), round($item->bbbminutes / 60, 2), round($item->totalminutes / 60, 2), round($item->varianceminutes / 60, 2), $item->percent === null ? '' : $item->percent];
}
$sheet = $workbook->add_worksheet(get_string('excel_time_sheet', 'local_kbacorporate'));
$sheet->set_column(0, 1, 28); $sheet->set_column(2, 7, 18);
$write_table($sheet, [get_string('fullname', 'local_kbacorporate'), get_string('position', 'local_kbacorporate'), get_string('trainingplanhours', 'local_kbacorporate'), get_string('moodleestimatedhours', 'local_kbacorporate'), get_string('webinarhours', 'local_kbacorporate'), get_string('trainingfacthours', 'local_kbacorporate'), get_string('trainingvariance', 'local_kbacorporate'), get_string('trainingplanpercent', 'local_kbacorporate')], $rows);

// Webinars sheet.
$rows = [];
foreach ($management->webinarrows as $item) {
    $rows[] = [$item->fullname, $item->coursename, $item->webinarname, get_string('bbbstate' . $item->state, 'local_kbacorporate'), round($item->minutes, 1), $item->coveragepercent === null ? '' : $item->coveragepercent, $item->firstjoin ? userdate($item->firstjoin) : '', $item->latenessminutes === null ? '' : $item->latenessminutes, $item->engagementtotal];
}
$sheet = $workbook->add_worksheet(get_string('excel_webinars_sheet', 'local_kbacorporate'));
$sheet->set_column(0, 2, 28); $sheet->set_column(3, 8, 18);
$write_table($sheet, [get_string('fullname', 'local_kbacorporate'), get_string('course', 'local_kbacorporate'), get_string('bbbwebinar', 'local_kbacorporate'), get_string('status', 'local_kbacorporate'), get_string('bbbtotalminutes', 'local_kbacorporate'), get_string('bbbcoverage', 'local_kbacorporate'), get_string('bbbfirstjoin', 'local_kbacorporate'), get_string('bbblateness', 'local_kbacorporate'), get_string('bbbengagement', 'local_kbacorporate')], $rows);

// Certificates sheet.
$rows = [];
foreach ($management->certificaterows as $item) {
    $rows[] = [$item->fullname, $item->coursename, $item->certificatename, $item->issued ? get_string('certificateissued', 'local_kbacorporate') : get_string('certificatenotissued', 'local_kbacorporate'), $item->issuedat ? userdate($item->issuedat) : '', $item->code];
}
$sheet = $workbook->add_worksheet(get_string('excel_certificates_sheet', 'local_kbacorporate'));
$sheet->set_column(0, 2, 28); $sheet->set_column(3, 5, 20);
$write_table($sheet, [get_string('fullname', 'local_kbacorporate'), get_string('course', 'local_kbacorporate'), get_string('certificate', 'local_kbacorporate'), get_string('status', 'local_kbacorporate'), get_string('certificateissuedat', 'local_kbacorporate'), get_string('certificatecode', 'local_kbacorporate')], $rows);


// Corporate licences sheet.
if ($canviewlicenses) {
    $rows = [];
    foreach ($management->licenserows as $item) {
        $rows[] = [
            $item->fullname,
            $item->groupid ? $item->groupname : get_string('nogroup', 'local_kbacorporate'),
            $item->datefrom ? userdate($item->datefrom, '%Y-%m-%d') : '',
            $item->dateto ? userdate($item->dateto, '%Y-%m-%d') : '',
            $item->unlimited ? get_string('licenseunlimited', 'local_kbacorporate') : $item->seats,
            $item->used,
            $item->unlimited ? '' : $item->available,
            $item->utilizationpercent === null ? '' : $item->utilizationpercent,
            \local_kbacorporate\local\licenses::source_label((string)$item->source),
            $item->externaldealid,
            $item->externalrowid,
            \local_kbacorporate\local\licenses::status_label((string)$item->effectivestatus),
        ];
    }
    $sheet = $workbook->add_worksheet(get_string('excel_licenses_sheet', 'local_kbacorporate'));
    $sheet->set_column(0, 1, 28); $sheet->set_column(2, 11, 18);
    $write_table($sheet, [
        get_string('course', 'local_kbacorporate'),
        get_string('group', 'local_kbacorporate'),
        get_string('licensestart', 'local_kbacorporate'),
        get_string('licenseend', 'local_kbacorporate'),
        get_string('purchasedseats', 'local_kbacorporate'),
        get_string('usedseats', 'local_kbacorporate'),
        get_string('freeseats', 'local_kbacorporate'),
        get_string('licenseutilization', 'local_kbacorporate'),
        get_string('licensesource', 'local_kbacorporate'),
        get_string('licenseexternaldealid', 'local_kbacorporate'),
        get_string('licenseexternalrowid', 'local_kbacorporate'),
        get_string('status', 'local_kbacorporate'),
    ], $rows);
}

$workbook->close();
exit;
