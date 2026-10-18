<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managedevelopmentplans', $context);
require_capability('local/kbacorporate:exportreport', $context);
$companyid = required_param('companyid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$statusfilter = optional_param('status', 'all', PARAM_ALPHANUMEXT);
$query = trim((string)optional_param('q', '', PARAM_TEXT));
$period = optional_param('period', '12', PARAM_ALPHANUMEXT);
if (!in_array($period, ['3', '6', '12', 'all'], true)) {
    $period = '12';
}
$allowedstatuses = ['all', 'draft', 'pending', 'active', 'completed', 'cancelled'];
if (!in_array($statusfilter, $allowedstatuses, true)) {
    $statusfilter = 'all';
}
\local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$report = \local_kbacorporate\local\development_plans::get_company_effectiveness(
    $companyid, $visibleids, $statusfilter, $query);
$breakdowns = \local_kbacorporate\local\development_plans::get_effectiveness_breakdowns($companyid, $report->rows);
$periodfrom = $period === 'all' ? 0 : strtotime('-' . (int)$period . ' months', usergetmidnight(time()));
$timeline = \local_kbacorporate\local\development_plans::get_effectiveness_timeline($report->rows, $periodfrom, time());
$safe = ['\local_kbacorporate\local\competency_map', 'csv_value'];
$csv = new csv_export_writer('semicolon');
$csv->set_filename('development-effectiveness-company-' . $companyid . '-' . userdate(time(), '%Y%m%d'));
$csv->add_data([
    get_string('employee', 'local_kbacorporate'), get_string('email'),
    get_string('developmentplan', 'local_kbacorporate'), get_string('status'),
    get_string('progress'), get_string('developmentactionsoverdue', 'local_kbacorporate'),
    get_string('developmentoutcomeassessed', 'local_kbacorporate'),
    get_string('developmentoutcomeimproved', 'local_kbacorporate'),
    get_string('developmentoutcomereached', 'local_kbacorporate'),
    get_string('developmentoutcomegaps', 'local_kbacorporate'),
]);
foreach ($report->rows as $row) {
    $plan = $row->plan;
    $outcome = $row->outcome;
    $csv->add_data(array_map($safe, [
        fullname($plan), (string)$plan->email, (string)$plan->title,
        get_string('developmentplanstatus_' . $plan->status, 'local_kbacorporate'),
        $row->progress->percent . '%', (string)$row->progress->overdue,
        $outcome ? $outcome->assessed . '/' . $outcome->total : '',
        $outcome ? (string)$outcome->improved : '', $outcome ? (string)$outcome->reached : '',
        $outcome ? (string)$outcome->remaininggaps : '',
    ]));
}
$csv->add_data([]);
$csv->add_data([get_string('developmenteffectivenessbyrole', 'local_kbacorporate')]);
$csv->add_data([get_string('name'), get_string('developmentmetricplans', 'local_kbacorporate'),
    get_string('developmentmetricactive', 'local_kbacorporate'),
    get_string('developmentmetriccompleted', 'local_kbacorporate'), get_string('developmentmetricprogress', 'local_kbacorporate'),
    get_string('developmentmetricoverdue', 'local_kbacorporate'), get_string('developmentmetricimproved', 'local_kbacorporate'),
    get_string('developmentmetrictargets', 'local_kbacorporate')]);
foreach ($breakdowns->roles as $item) {
    $csv->add_data(array_map($safe, [$item->name, (string)$item->plans, (string)$item->active,
        (string)$item->completed, $item->progress . '%', (string)$item->overdue,
        (string)$item->improved, (string)$item->targetsreached]));
}
$csv->add_data([]);
$csv->add_data([get_string('developmenteffectivenessbydepartment', 'local_kbacorporate')]);
$csv->add_data([get_string('name'), get_string('developmentmetricplans', 'local_kbacorporate'),
    get_string('developmentmetricactive', 'local_kbacorporate'),
    get_string('developmentmetriccompleted', 'local_kbacorporate'), get_string('developmentmetricprogress', 'local_kbacorporate'),
    get_string('developmentmetricoverdue', 'local_kbacorporate'), get_string('developmentmetricimproved', 'local_kbacorporate'),
    get_string('developmentmetrictargets', 'local_kbacorporate')]);
foreach ($breakdowns->departments as $item) {
    $csv->add_data(array_map($safe, [$item->name, (string)$item->plans, (string)$item->active,
        (string)$item->completed, $item->progress . '%', (string)$item->overdue,
        (string)$item->improved, (string)$item->targetsreached]));
}
$csv->add_data([]);
$csv->add_data([get_string('developmenteffectivenesstimeline', 'local_kbacorporate')]);
$csv->add_data([get_string('effectivenessperiod', 'local_kbacorporate'),
    get_string('timelineassessed', 'local_kbacorporate'), get_string('timelinecomparable', 'local_kbacorporate'),
    get_string('timelineaveragebefore', 'local_kbacorporate'), get_string('timelineaverageafter', 'local_kbacorporate'),
    get_string('timelineaveragegrowth', 'local_kbacorporate'),
    get_string('developmentmetricimproved', 'local_kbacorporate'),
    get_string('developmentmetrictargets', 'local_kbacorporate')]);
foreach ($timeline->periods as $item) {
    $csv->add_data(array_map($safe, [userdate($item->periodtime, '%B %Y'), (string)$item->assessed,
        (string)$item->comparable, $item->averagebefore === null ? '' : (string)$item->averagebefore,
        $item->averageafter === null ? '' : (string)$item->averageafter,
        $item->averagegrowth === null ? '' : (string)$item->averagegrowth,
        (string)$item->improved, (string)$item->reached]));
}
$csv->download_file();
exit;
