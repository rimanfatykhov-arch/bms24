<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcompetencies', $context);
require_capability('local/kbacorporate:exportreport', $context);

$companyid = required_param('companyid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$scope = optional_param('scope', \local_kbacorporate\local\employee_assessment_history::SCOPE_PROFILE,
    PARAM_ALPHANUMEXT);
$source = optional_param('source', \local_kbacorporate\local\employee_assessment_history::SOURCE_ALL,
    PARAM_ALPHANUMEXT);
$status = optional_param('status', \local_kbacorporate\local\employee_assessment_history::STATUS_ALL,
    PARAM_ALPHANUMEXT);
$trend = optional_param('trend', \local_kbacorporate\local\employee_assessment_history::TREND_ALL,
    PARAM_ALPHANUMEXT);
$datefrom = trim((string)optional_param('datefrom', '', PARAM_ALPHANUMEXT));
$dateuntil = trim((string)optional_param('dateuntil', '', PARAM_ALPHANUMEXT));
$query = trim((string)optional_param('q', '', PARAM_TEXT));

$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
if (!\local_kbacorporate\local\access::can_view_employee($companyid, $USER->id, $userid)) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}
$employee = $DB->get_record('user', ['id' => $userid, 'deleted' => 0],
    'id,firstname,lastname,email', MUST_EXIST);

$projection = \local_kbacorporate\local\employee_assessment_history::build($companyid, $userid);
$fromtime = \local_kbacorporate\local\employee_assessment_history::parse_date($datefrom, false);
$totime = \local_kbacorporate\local\employee_assessment_history::parse_date($dateuntil, true);
$events = \local_kbacorporate\local\employee_assessment_history::filter(
    $projection->events, $scope, $source, $status,
    \local_kbacorporate\local\employee_assessment_history::TREND_ALL,
    $fromtime, $totime, $query
);
$trends = \local_kbacorporate\local\employee_assessment_history::build_trends($events);
$trendmap = [];
foreach ($trends as $trendrow) {
    $trendmap[(int)$trendrow->competencyid] = (string)$trendrow->trend;
}
foreach ($events as $event) {
    $event->trend = $trendmap[(int)$event->competencyid] ??
        \local_kbacorporate\local\employee_assessment_history::TREND_NO_COMPARISON;
}
$events = \local_kbacorporate\local\employee_assessment_history::filter(
    $events, \local_kbacorporate\local\employee_assessment_history::SCOPE_ALL,
    \local_kbacorporate\local\employee_assessment_history::SOURCE_ALL,
    \local_kbacorporate\local\employee_assessment_history::STATUS_ALL,
    $trend, 0, 0, ''
);
$trends = \local_kbacorporate\local\employee_assessment_history::filter_trends($trends, $trend);
$summary = \local_kbacorporate\local\employee_assessment_history::summarise($events, $trends);

$safe = ['\\local_kbacorporate\\local\\employee_assessment_history', 'csv_value'];
$source_label = static function(string $value): string {
    $key = 'employeeprofilesource_' . $value;
    return get_string_manager()->string_exists($key, 'local_kbacorporate')
        ? get_string($key, 'local_kbacorporate') : $value;
};
$status_label = static function(string $value): string {
    $key = 'employeeprofilehistorystatus_' . $value;
    return get_string_manager()->string_exists($key, 'local_kbacorporate')
        ? get_string($key, 'local_kbacorporate') : $value;
};
$trend_label = static function(string $value, ?int $delta = null): string {
    $key = 'assessmenttrend_' . $value;
    if ($value === 'improved' && $delta !== null) {
        return get_string($key, 'local_kbacorporate', '+' . $delta);
    }
    if ($value === 'declined' && $delta !== null) {
        return get_string($key, 'local_kbacorporate', (string)$delta);
    }
    return get_string($key, 'local_kbacorporate');
};

$csv = new csv_export_writer('semicolon');
$filename = 'employee-assessment-history-' . $companyid . '-' . $userid . '-' . userdate(time(), '%Y%m%d');
$csv->set_filename($filename);

$csv->add_data([
    get_string('company', 'local_kbacorporate'),
    get_string('employee', 'local_kbacorporate'),
    get_string('email'),
]);
$csv->add_data(array_map($safe, [format_string($company->name), fullname($employee), $employee->email]));
$csv->add_data([]);
$csv->add_data([get_string('assessmenthistorysummary', 'local_kbacorporate')]);
$csv->add_data([
    get_string('assessmenthistoryevents', 'local_kbacorporate'),
    get_string('assessmenthistorycompetencies', 'local_kbacorporate'),
    get_string('assessmenthistoryaccepted', 'local_kbacorporate'),
    get_string('assessmenthistorypending', 'local_kbacorporate'),
    get_string('assessmenthistorynotaccepted', 'local_kbacorporate'),
    get_string('assessmenthistoryassessors', 'local_kbacorporate'),
]);
$csv->add_data([
    (string)$summary->events,
    (string)$summary->competencies,
    (string)($summary->confirmed + $summary->recorded),
    (string)$summary->pending,
    (string)($summary->rejected + $summary->missing),
    (string)$summary->assessors,
]);

$csv->add_data([]);
$csv->add_data([get_string('assessmenthistorydynamics', 'local_kbacorporate')]);
$csv->add_data([
    get_string('competencycode', 'local_kbacorporate'),
    get_string('competencylabel', 'local_kbacorporate'),
    get_string('assessmenthistoryfirstassessment', 'local_kbacorporate'),
    get_string('assessmenthistorylatestassessment', 'local_kbacorporate'),
    get_string('assessmenthistorychange', 'local_kbacorporate'),
    get_string('assessmenthistoryassessmentcount', 'local_kbacorporate'),
    get_string('targetlevel', 'local_kbacorporate'),
    get_string('competencygap', 'local_kbacorporate'),
    get_string('employeeprofileassessmentsource', 'local_kbacorporate'),
    get_string('employeeprofilehistoryactor', 'local_kbacorporate'),
]);
foreach ($trends as $row) {
    $first = $row->firstlevel === null ? '' :
        \local_kbacorporate\local\competencies::level_label((int)$row->firstlevel, (int)$row->scaleid);
    $latest = $row->latestlevel === null ? '' :
        \local_kbacorporate\local\competencies::level_label((int)$row->latestlevel, (int)$row->scaleid);
    $target = $row->targetlevel === null ? '' :
        \local_kbacorporate\local\competencies::level_label((int)$row->targetlevel, (int)$row->scaleid);
    $csv->add_data(array_map($safe, [
        $row->competencyidnumber,
        $row->competencyshortname,
        $first . ($row->firsttime ? ' · ' . userdate((int)$row->firsttime) : ''),
        $latest . ($row->latesttime ? ' · ' . userdate((int)$row->latesttime) : ''),
        $trend_label((string)$row->trend, $row->delta),
        (string)$row->comparableevents,
        $target,
        $row->gap === null ? '' : (string)$row->gap,
        $source_label((string)$row->latestsource),
        $row->latestactorname,
    ]));
}

$csv->add_data([]);
$csv->add_data([get_string('assessmenthistoryjournal', 'local_kbacorporate')]);
$csv->add_data([
    get_string('date'),
    get_string('competencycode', 'local_kbacorporate'),
    get_string('competencylabel', 'local_kbacorporate'),
    get_string('employeeprofileassessmentsource', 'local_kbacorporate'),
    get_string('competencylevel', 'local_kbacorporate'),
    get_string('status'),
    get_string('employeeprofilehistoryactor', 'local_kbacorporate'),
    get_string('assessmenthistoryevent', 'local_kbacorporate'),
    get_string('description'),
]);
foreach ($events as $event) {
    $level = $event->level === null ? '' :
        \local_kbacorporate\local\competencies::level_label((int)$event->level, (int)$event->scaleid);
    $csv->add_data(array_map($safe, [
        $event->timecreated ? userdate((int)$event->timecreated) : '',
        $event->competencyidnumber,
        $event->competencyshortname,
        $source_label((string)$event->source),
        $level,
        $status_label((string)$event->status),
        $event->actorname,
        $event->title,
        $event->details,
    ]));
}
$csv->download_file();
exit;
