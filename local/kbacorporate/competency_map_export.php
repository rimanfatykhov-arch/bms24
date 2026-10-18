<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:viewcompetencies', $context);
require_capability('local/kbacorporate:exportreport', $context);

$companyid = required_param('companyid', PARAM_INT);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$roleid = optional_param('roleid', 0, PARAM_INT);
$frameworkid = optional_param('frameworkid', 0, PARAM_INT);
$status = optional_param('status', 'all', PARAM_ALPHANUMEXT);
$query = trim((string)optional_param('q', '', PARAM_TEXT));
$allowedstatuses = ['all', 'attention', 'gap', 'notassessed', 'targetreached'];
if (!in_array($status, $allowedstatuses, true)) {
    $status = 'all';
}
\local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$map = \local_kbacorporate\local\competency_map::build($companyid, $USER->id, $departmentid);
$rows = \local_kbacorporate\local\competency_map::filter(
    $map['rows'], $roleid, $frameworkid, $status, $query
);

$csv = new csv_export_writer();
$csv->set_filename('competency-map-company-' . $companyid . '-' . userdate(time(), '%Y%m%d'));
$csv->add_data([
    get_string('employee', 'local_kbacorporate'),
    get_string('department', 'local_kbacorporate'),
    get_string('businessrole', 'local_kbacorporate'),
    get_string('competencylabel', 'local_kbacorporate'),
    get_string('competencyframework', 'local_kbacorporate'),
    get_string('currentlevel', 'local_kbacorporate'),
    get_string('targetlevel', 'local_kbacorporate'),
    get_string('competencygap', 'local_kbacorporate'),
    get_string('rolepriority', 'local_kbacorporate'),
    get_string('status'),
]);
foreach ($rows as $row) {
    $current = $row->currentlevel === null
        ? get_string('competencynotassessed', 'local_kbacorporate')
        : \local_kbacorporate\local\competencies::level_label($row->currentlevel);
    $csv->add_data(array_map(['\\local_kbacorporate\\local\\competency_map', 'csv_value'], [
        $row->employee,
        implode(', ', $row->departments),
        implode(', ', $row->roles),
        $row->competency,
        $row->framework,
        $current,
        \local_kbacorporate\local\competencies::level_label($row->targetlevel),
        $row->gap === null ? '' : (string)$row->gap,
        get_string('rolepriority_' . $row->priority, 'local_kbacorporate'),
        get_string('competencystatus_' . $row->status, 'local_kbacorporate'),
    ]));
}
$csv->download_file();
exit;
