<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:exportreport', $context);
$companyid = required_param('companyid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
$rows = \local_kbacorporate\local\report::get_rows($companyid, $visibleids);
$bbbrows = \local_kbacorporate\local\bigbluebutton::is_available()
    ? \local_kbacorporate\local\bigbluebutton::get_company_rows($companyid, $visibleids) : [];
$bbbbycourse = \local_kbacorporate\local\bigbluebutton::aggregate_by_user_course($bbbrows);
$departmentnames = \local_kbacorporate\local\departments::get_department_names_for_users($companyid, $visibleids);

$filename = clean_filename('KBA_' . $company->name . '_training_' . date('Y-m-d'));
$csv = new csv_export_writer();
$csv->set_filename($filename);
$csv->add_data([
    get_string('company', 'local_kbacorporate'),
    get_string('fullname', 'local_kbacorporate'),
    get_string('email', 'local_kbacorporate'),
    get_string('phone', 'local_kbacorporate'),
    get_string('position', 'local_kbacorporate'),
    get_string('department', 'local_kbacorporate'),
    get_string('course', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('progress', 'local_kbacorporate'),
    get_string('grade', 'local_kbacorporate'),
    get_string('enrolledat', 'local_kbacorporate'),
    get_string('lastaccess', 'local_kbacorporate'),
    get_string('bbbwebinarsassigned', 'local_kbacorporate'),
    get_string('bbbwebinarsattended', 'local_kbacorporate'),
    get_string('bbbtotalminutes', 'local_kbacorporate'),
    get_string('bbbattendance', 'local_kbacorporate'),
    get_string('bbbavgcoverage', 'local_kbacorporate'),
    get_string('bbblateassignments', 'local_kbacorporate'),
    get_string('bbbengagementevents', 'local_kbacorporate'),
]);
foreach ($rows as $row) {
    $bbbkey = $row->userid . ':' . $row->courseid;
    $bbb = $bbbbycourse[$bbbkey] ?? null;
    $csv->add_data([
        $company->name,
        $row->fullname,
        $row->email,
        $row->phone,
        $row->position,
        !empty($departmentnames[$row->userid]) ? implode(', ', $departmentnames[$row->userid]) : '',
        $row->coursename,
        get_string($row->status, 'local_kbacorporate'),
        $row->progress === null ? '' : $row->progress,
        $row->grade === null ? '' : $row->grade,
        $row->enrolledat ? userdate($row->enrolledat) : '',
        $row->lastaccess ? userdate($row->lastaccess) : '',
        $bbb ? $bbb->assigned : 0,
        $bbb ? $bbb->attended : 0,
        $bbb ? $bbb->minutes : '',
        ($bbb && $bbb->attendancepercent !== null) ? $bbb->attendancepercent : '',
        ($bbb && $bbb->avgcoveragepercent !== null) ? $bbb->avgcoveragepercent : '',
        $bbb ? $bbb->late : 0,
        $bbb ? $bbb->engagementtotal : 0,
    ]);
}
$csv->download_file();
exit;
