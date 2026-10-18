<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:manageeconomicindicators', $context);

$companyid = required_param('companyid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, (int)$USER->id);
$indicator = $id ? \local_kbacorporate\local\economic_indicators::get_indicator($id, $companyid) : null;
if ($indicator && ((int)$indicator->companyid === 0 || !empty($indicator->systemdefined))) {
    throw new moodle_exception('economicbuiltinreadonly', 'local_kbacorporate');
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/economic_indicator_edit.php',
    ['companyid' => $companyid] + ($id ? ['id' => $id] : [])));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string($id ? 'economiceditindicator' : 'economicaddindicator', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$form = new \local_kbacorporate\form\economic_indicator_form(null, ['companyid' => $companyid]);
if ($indicator) {
    $form->set_data($indicator);
}
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbacorporate/economic_indicators.php', ['companyid' => $companyid]));
}
if ($data = $form->get_data()) {
    $indicatorid = \local_kbacorporate\local\economic_indicators::save_indicator($data, (int)$USER->id);
    redirect(new moodle_url('/local/kbacorporate/economic_history.php', [
        'companyid' => $companyid,
        'indicatorid' => $indicatorid,
    ]), get_string('saved', 'local_kbacorporate'));
}

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'economics');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/economic_indicators.php', [
    'companyid' => $companyid,
]), '← ' . get_string('economicregistry', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string($id ? 'economiceditindicator' : 'economicaddindicator', 'local_kbacorporate'));
$form->display();
echo $OUTPUT->footer();
