<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:vieweconomicindicators', $context);

$companyid = required_param('companyid', PARAM_INT);
$category = optional_param('category', '', PARAM_ALPHANUMEXT);
$periodtype = optional_param('periodtype', '', PARAM_ALPHA);
$auditid = optional_param('auditid', 0, PARAM_INT);
$verifiedonly = optional_param('verifiedonly', 0, PARAM_BOOL);
$frominput = optional_param('fromdate', '', PARAM_RAW_TRIMMED);
$toinput = optional_param('todate', '', PARAM_RAW_TRIMMED);
$indicatorids = optional_param_array('indicatorids', [], PARAM_INT);

$company = \local_kbacorporate\local\access::require_full_company_access(
    $companyid,
    (int)$USER->id
);
$from = \local_kbacorporate\local\economic_dashboard::parse_date_input($frominput, false);
$to = \local_kbacorporate\local\economic_dashboard::parse_date_input($toinput, true);
$model = \local_kbacorporate\local\economic_dashboard::build($companyid, [
    'category' => $category,
    'periodtype' => $periodtype,
    'auditid' => $auditid,
    'verifiedonly' => $verifiedonly,
    'from' => $from,
    'to' => $to,
    'datesexplicit' => $frominput !== '' || $toinput !== '',
    'indicatorids' => $indicatorids,
]);

$filename = clean_filename('economic_dashboard_' . $companyid . '_' . userdate(time(), '%Y%m%d_%H%M'));
$csv = new csv_export_writer('comma');
$csv->set_filename($filename);
$csv->add_data([
    'company_id',
    'company',
    'indicator_code',
    'indicator_name',
    'category',
    'period_type',
    'period_key',
    'scenario',
    'value',
    'currency',
    'unit',
    'source',
    'verified',
    'audit_id',
    'audit',
]);
foreach ($model->panels as $panel) {
    foreach ($panel->values as $value) {
        $csv->add_data([
            $companyid,
            \local_kbacorporate\local\economic_indicators::csv_safe(format_string($company->name)),
            \local_kbacorporate\local\economic_indicators::csv_safe((string)$panel->indicator->code),
            \local_kbacorporate\local\economic_indicators::csv_safe(format_string($panel->indicator->name)),
            (string)$panel->indicator->category,
            (string)$value->periodtype,
            (string)$value->periodkey,
            (string)$value->scenario,
            (string)$value->value,
            (string)$value->currency,
            (string)$panel->indicator->unit,
            (string)$value->source,
            (int)$value->verified,
            (int)$value->auditid,
            \local_kbacorporate\local\economic_indicators::csv_safe((string)($value->audittitle ?? '')),
        ]);
    }
}
$csv->download_file();
exit;
