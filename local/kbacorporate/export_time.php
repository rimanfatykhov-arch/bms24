<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:exportreport', $context);
require_capability('local/kbacorporate:viewtrainingtime', $context);

$companyid = required_param('companyid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$preset = optional_param('preset', 'week', PARAM_ALPHA);
$from = optional_param('from', '', PARAM_RAW_TRIMMED);
$to = optional_param('to', '', PARAM_RAW_TRIMMED);
$userid = optional_param('userid', 0, PARAM_INT);
$scope = optional_param('scope', 'employees', PARAM_ALPHA);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
if ($userid && !in_array($userid, $visibleids, true)) { $userid = 0; }
if (!in_array($scope, ['employees', 'weeks', 'courses', 'days'], true)) {
    $scope = 'employees';
}

$period = \local_kbacorporate\local\trainingtime::period_for_preset($preset, $from, $to);
$report = \local_kbacorporate\local\trainingtime::build_report(
    $companyid, $period->start, $period->endexclusive, $userid, $visibleids
);

$filename = clean_filename(
    'KBA_' . $company->name . '_training_hours_' . $scope . '_' . $period->from . '_' . $period->to
);
$csv = new csv_export_writer();
$csv->set_filename($filename);

$hours = function($minutes) {
    return round(((float)$minutes) / 60, 2);
};

if ($scope === 'employees') {
    $csv->add_data([
        get_string('company', 'local_kbacorporate'),
        get_string('period', 'local_kbacorporate'),
        get_string('fullname', 'local_kbacorporate'),
        get_string('position', 'local_kbacorporate'),
        get_string('trainingplanhours', 'local_kbacorporate'),
        get_string('moodleestimatedhours', 'local_kbacorporate'),
        get_string('webinarhours', 'local_kbacorporate'),
        get_string('trainingfacthours', 'local_kbacorporate'),
        get_string('trainingvariance', 'local_kbacorporate'),
        get_string('trainingplanpercent', 'local_kbacorporate'),
        get_string('planstatus', 'local_kbacorporate'),
    ]);
    foreach ($report->employees as $row) {
        $csv->add_data([
            $company->name,
            $period->from . ' — ' . $period->to,
            $row->fullname,
            $row->position,
            $hours($row->planminutes),
            $hours($row->asyncminutes),
            $hours($row->bbbminutes),
            $hours($row->totalminutes),
            $hours($row->varianceminutes),
            $row->percent === null ? '' : $row->percent,
            get_string($row->planstate, 'local_kbacorporate'),
        ]);
    }
} else if ($scope === 'weeks') {
    $csv->add_data([
        get_string('company', 'local_kbacorporate'),
        get_string('period', 'local_kbacorporate'),
        get_string('weekperiod', 'local_kbacorporate'),
        get_string('trainingplanhours', 'local_kbacorporate'),
        get_string('moodleestimatedhours', 'local_kbacorporate'),
        get_string('webinarhours', 'local_kbacorporate'),
        get_string('trainingfacthours', 'local_kbacorporate'),
        get_string('trainingvariance', 'local_kbacorporate'),
        get_string('trainingplanpercent', 'local_kbacorporate'),
    ]);
    foreach ($report->weeks as $row) {
        $csv->add_data([
            $company->name,
            $period->from . ' — ' . $period->to,
            \local_kbacorporate\local\trainingtime::date_key($row->weekstart) . ' — ' .
                \local_kbacorporate\local\trainingtime::date_key($row->weekend),
            $hours($row->planminutes),
            $hours($row->asyncminutes),
            $hours($row->bbbminutes),
            $hours($row->totalminutes),
            $hours($row->varianceminutes),
            $row->percent === null ? '' : $row->percent,
        ]);
    }
} else if ($scope === 'courses') {
    $csv->add_data([
        get_string('company', 'local_kbacorporate'),
        get_string('period', 'local_kbacorporate'),
        get_string('course', 'local_kbacorporate'),
        get_string('moodleestimatedhours', 'local_kbacorporate'),
        get_string('webinarhours', 'local_kbacorporate'),
        get_string('trainingfacthours', 'local_kbacorporate'),
        get_string('timeshare', 'local_kbacorporate'),
    ]);
    foreach ($report->courses as $row) {
        $csv->add_data([
            $company->name,
            $period->from . ' — ' . $period->to,
            $row->coursename,
            $hours($row->asyncminutes),
            $hours($row->bbbminutes),
            $hours($row->totalminutes),
            $row->sharepercent,
        ]);
    }
} else {
    $csv->add_data([
        get_string('company', 'local_kbacorporate'),
        get_string('period', 'local_kbacorporate'),
        get_string('date'),
        get_string('trainingplanhours', 'local_kbacorporate'),
        get_string('moodleestimatedhours', 'local_kbacorporate'),
        get_string('webinarhours', 'local_kbacorporate'),
        get_string('trainingfacthours', 'local_kbacorporate'),
        get_string('cumulativeplan', 'local_kbacorporate'),
        get_string('cumulativefact', 'local_kbacorporate'),
    ]);
    foreach ($report->days as $row) {
        $csv->add_data([
            $company->name,
            $period->from . ' — ' . $period->to,
            $row->date,
            $hours($row->planminutes),
            $hours($row->asyncminutes),
            $hours($row->bbbminutes),
            $hours($row->totalminutes),
            $hours($row->cumulativeplanminutes),
            $hours($row->cumulativetotalminutes),
        ]);
    }
}

$csv->download_file();
exit;
