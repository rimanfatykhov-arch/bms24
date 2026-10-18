<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:exportreport', $context);
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
$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
if ($userid && !in_array($userid, $visibleids, true)) { $userid = 0; }
$rows = \local_kbacorporate\local\customcert::is_available()
    ? \local_kbacorporate\local\customcert::get_company_rows($companyid, $visibleids) : [];

$filename = clean_filename('KBA_' . $company->name . '_certificates_' . date('Y-m-d'));
$csv = new csv_export_writer();
$csv->set_filename($filename);
$csv->add_data([
    get_string('company', 'local_kbacorporate'),
    get_string('fullname', 'local_kbacorporate'),
    get_string('email', 'local_kbacorporate'),
    get_string('position', 'local_kbacorporate'),
    get_string('course', 'local_kbacorporate'),
    get_string('certificate', 'local_kbacorporate'),
    get_string('status', 'local_kbacorporate'),
    get_string('certificateissuedat', 'local_kbacorporate'),
    get_string('certificatecode', 'local_kbacorporate'),
    get_string('certificateemailed', 'local_kbacorporate'),
    get_string('certificateissuecountcsv', 'local_kbacorporate'),
]);
foreach ($rows as $row) {
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
    $csv->add_data([
        $company->name,
        $row->fullname,
        $row->email,
        $row->position,
        $row->coursename,
        $row->certificatename,
        get_string($row->issued ? 'certificateissued' : 'certificatenotissued', 'local_kbacorporate'),
        $row->issuedat ? userdate($row->issuedat) : '',
        $row->code,
        $row->issued ? ($row->emailed ? get_string('yes') : get_string('no')) : '',
        $row->issuecount,
    ]);
}
$csv->download_file();
exit;
