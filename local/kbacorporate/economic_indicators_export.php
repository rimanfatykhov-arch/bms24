<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:vieweconomicindicators', $context);

$companyid = required_param('companyid', PARAM_INT);
$auditid = optional_param('auditid', 0, PARAM_INT);
$source = optional_param('source', '', PARAM_ALPHANUMEXT);
$periodtype = optional_param('periodtype', '', PARAM_ALPHA);
$scenario = optional_param('scenario', '', PARAM_ALPHA);
$category = optional_param('category', '', PARAM_ALPHANUMEXT);
$status = optional_param('status', 'active', PARAM_ALPHA);
if (!in_array($status, ['active', 'archived', 'all'], true)) {
    $status = 'active';
}
$query = optional_param('q', '', PARAM_TEXT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, (int)$USER->id);
$valuefilters = [
    'auditid' => $auditid,
    'source' => $source,
    'periodtype' => $periodtype,
    'scenario' => $scenario,
    'category' => $category,
    'query' => $query,
];
if ($status === 'active') {
    $valuefilters['indicatoractive'] = 1;
} else if ($status === 'archived') {
    $valuefilters['indicatoractive'] = 0;
}
$values = \local_kbacorporate\local\economic_indicators::get_values($companyid, $valuefilters);

$filename = clean_filename('economic_indicators_' . $companyid . '_' . userdate(time(), '%Y%m%d_%H%M'));
$csv = new csv_export_writer('comma');
$csv->set_filename($filename);
$csv->add_data([
    'company_id', 'company', 'indicator_code', 'indicator_name', 'category', 'value_type', 'unit',
    'period_type', 'period_key', 'period_start', 'period_end', 'scenario', 'value', 'currency', 'source',
    'verified', 'audit_id', 'audit', 'comment', 'created_at', 'updated_at',
]);
foreach ($values as $value) {
    $csv->add_data([
        $companyid,
        \local_kbacorporate\local\economic_indicators::csv_safe(format_string($company->name)),
        \local_kbacorporate\local\economic_indicators::csv_safe((string)$value->indicatorcode),
        \local_kbacorporate\local\economic_indicators::csv_safe(format_string($value->indicatorname)),
        (string)$value->category,
        (string)$value->valuetype,
        (string)$value->unit,
        (string)$value->periodtype,
        (string)$value->periodkey,
        userdate((int)$value->periodstart, '%Y-%m-%d'),
        userdate((int)$value->periodend, '%Y-%m-%d'),
        (string)$value->scenario,
        (string)$value->value,
        (string)$value->currency,
        (string)$value->source,
        (int)$value->verified,
        (int)$value->auditid,
        \local_kbacorporate\local\economic_indicators::csv_safe((string)($value->audittitle ?? '')),
        \local_kbacorporate\local\economic_indicators::csv_safe((string)($value->comment ?? '')),
        userdate((int)$value->timecreated, '%Y-%m-%d %H:%M:%S'),
        userdate((int)$value->timemodified, '%Y-%m-%d %H:%M:%S'),
    ]);
}
$csv->download_file();
exit;
