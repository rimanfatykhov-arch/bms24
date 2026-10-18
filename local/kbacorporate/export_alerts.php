<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:exportreport', $context);
require_capability('local/kbacorporate:viewalerts', $context);

$companyid = required_param('companyid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_access($companyid, $USER->id);
$preset = optional_param('preset', 'month', PARAM_ALPHA);
$from = optional_param('from', '', PARAM_RAW_TRIMMED);
$to = optional_param('to', '', PARAM_RAW_TRIMMED);
$departmentid = optional_param('departmentid', 0, PARAM_INT);
$severity = optional_param('severity', '', PARAM_ALPHA);
$type = optional_param('type', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);

$visibleids = \local_kbacorporate\local\access::get_visible_employee_ids($companyid, $USER->id, $departmentid);
if ($userid && !in_array($userid, $visibleids, true)) {
    $userid = 0;
}
$management = \local_kbacorporate\local\management_report::build($companyid, $preset, $from, $to, $visibleids);
$alerts = \local_kbacorporate\local\attention::filter($management->alerts, $severity, $type, $userid);

$filename = clean_filename('KBA_' . $company->name . '_attention_' . $management->period->from . '_' . $management->period->to);
$csv = new csv_export_writer();
$csv->set_filename($filename);
$csv->add_data([
    get_string('company', 'local_kbacorporate'),
    get_string('reportperiod', 'local_kbacorporate'),
    get_string('riskseverity', 'local_kbacorporate'),
    get_string('risktype', 'local_kbacorporate'),
    get_string('fullname', 'local_kbacorporate'),
    get_string('email', 'local_kbacorporate'),
    get_string('position', 'local_kbacorporate'),
    get_string('department', 'local_kbacorporate'),
    get_string('course', 'local_kbacorporate'),
    get_string('attentionreason', 'local_kbacorporate'),
    get_string('currentvalue', 'local_kbacorporate'),
    get_string('thresholdvalue', 'local_kbacorporate'),
    get_string('avgprogress', 'local_kbacorporate'),
    get_string('avggrade', 'local_kbacorporate'),
    get_string('trainingplanpercent', 'local_kbacorporate'),
    get_string('bbbattendance', 'local_kbacorporate'),
    get_string('lastaccess', 'local_kbacorporate'),
]);

$periodlabel = $management->period->from . ' — ' . $management->period->to;
foreach ($alerts as $alert) {
    $csv->add_data([
        $company->name,
        $periodlabel,
        \local_kbacorporate\local\attention::severity_label($alert->severity),
        \local_kbacorporate\local\attention::type_label($alert->type),
        $alert->fullname,
        $alert->email,
        $alert->position,
        $alert->departments,
        $alert->coursename,
        $alert->reason,
        $alert->currentvalue === null ? '' : $alert->currentvalue,
        $alert->thresholdvalue === null ? '' : $alert->thresholdvalue,
        $alert->avgprogress === null ? '' : $alert->avgprogress,
        $alert->avggrade === null ? '' : $alert->avggrade,
        $alert->trainingpercent === null ? '' : $alert->trainingpercent,
        $alert->webinarattendancepercent === null ? '' : $alert->webinarattendancepercent,
        $alert->lastaccess ? userdate($alert->lastaccess) : '',
    ]);
}

$csv->download_file();
exit;
