<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:manageeconomicindicators', $context);

$companyid = required_param('companyid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$requestedindicatorid = optional_param('indicatorid', 0, PARAM_INT);
$requestedauditid = optional_param('auditid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, (int)$USER->id);
$value = $id ? \local_kbacorporate\local\economic_indicators::get_value($id, $companyid) : null;

$indicatoroptions = [];
foreach (\local_kbacorporate\local\economic_indicators::get_catalog($companyid, ['active' => 1]) as $indicator) {
    $indicatoroptions[(int)$indicator->id] = s($indicator->code) . ' — ' . format_string($indicator->name);
}
if ($value && !isset($indicatoroptions[(int)$value->indicatorid])) {
    $indicator = \local_kbacorporate\local\economic_indicators::get_indicator((int)$value->indicatorid, $companyid);
    $indicatoroptions[(int)$indicator->id] = s($indicator->code) . ' — ' . format_string($indicator->name);
}
$auditoptions = \local_kbacorporate\local\economic_indicators::get_audit_options($companyid);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/economic_value_edit.php',
    ['companyid' => $companyid] + ($id ? ['id' => $id] : [])));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string($id ? 'economiceditvalue' : 'economicaddvalue', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$form = new \local_kbacorporate\form\economic_value_form(null, [
    'id' => $id,
    'companyid' => $companyid,
    'indicatoroptions' => $indicatoroptions,
    'auditoptions' => $auditoptions,
    'freezeindicator' => $id > 0,
]);
if ($value) {
    $form->set_data($value);
} else {
    $defaultindicatorid = $requestedindicatorid && isset($indicatoroptions[$requestedindicatorid])
        ? $requestedindicatorid : (int)array_key_first($indicatoroptions);
    $indicator = $defaultindicatorid
        ? \local_kbacorporate\local\economic_indicators::get_indicator($defaultindicatorid, $companyid) : null;
    $form->set_data((object)[
        'id' => 0,
        'companyid' => $companyid,
        'indicatorid' => $defaultindicatorid,
        'auditid' => isset($auditoptions[$requestedauditid]) ? $requestedauditid : 0,
        'periodtype' => $indicator ? $indicator->periodtype : 'month',
        'periodstart' => time(),
        'periodend' => time(),
        'scenario' => 'actual',
        'currency' => 'RUB',
        'source' => $indicator ? $indicator->defaultsource : 'manual',
        'verified' => 0,
    ]);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbacorporate/economic_indicators.php', ['companyid' => $companyid]));
}
if ($data = $form->get_data()) {
    $valueid = \local_kbacorporate\local\economic_indicators::save_value($data, (int)$USER->id);
    $saved = \local_kbacorporate\local\economic_indicators::get_value($valueid, $companyid);
    redirect(new moodle_url('/local/kbacorporate/economic_history.php', [
        'companyid' => $companyid,
        'indicatorid' => (int)$saved->indicatorid,
    ]), get_string('saved', 'local_kbacorporate'));
}

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'economics');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/economic_indicators.php', [
    'companyid' => $companyid,
]), '← ' . get_string('economicregistry', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string($id ? 'economiceditvalue' : 'economicaddvalue', 'local_kbacorporate'));
$form->display();
echo $OUTPUT->footer();
