<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcertificates', $context);

$companyid = required_param('companyid', PARAM_INT);
$issueid = required_param('issueid', PARAM_INT);
\local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$issue = \local_kbacorporate\local\customcert::get_company_issue($companyid, $issueid);
if (!\local_kbacorporate\local\access::can_view_employee($companyid, $USER->id, (int)$issue->userid)) {
    throw new moodle_exception('employeenotallowed', 'local_kbacorporate');
}
\local_kbacorporate\local\customcert::output_pdf($issue);
