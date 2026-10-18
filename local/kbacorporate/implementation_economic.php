<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_login();
$context = context_system::instance();
$companyid = required_param('companyid', PARAM_INT);
$auditid = required_param('auditid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id);
$audit = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
if ((int)$audit->companyid !== $companyid) { throw new moodle_exception('companynotallowed', 'local_kbacorporate'); }
if ($audit->status === \local_kbacorporate\local\implementation_audits::STATUS_CONFIRMED) {
    throw new moodle_exception('bmsauditimmutable', 'local_kbacorporate');
}
$record = \local_kbacorporate\local\implementation_audits::get_economic_snapshot($auditid);
$itemid = $record ? (int)$record->id : 0;
$draftitemid = file_get_submitted_draft_itemid('evidencefiles');
$fileoptions = ['subdirs' => 0, 'maxfiles' => 10,
    'accepted_types' => ['.pdf', '.xlsx', '.xls', '.csv', '.jpg', '.png']];
file_prepare_draft_area($draftitemid, $context->id, 'local_kbacorporate',
    'implementation_economic_evidence', $itemid, $fileoptions);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_economic.php',
    ['auditid' => $auditid, 'companyid' => $companyid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('economicindicators', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$form = new \local_kbacorporate\form\implementation_economic_form(null,
    ['auditid' => $auditid, 'companyid' => $companyid, 'id' => $itemid]);
if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/kbacorporate/implementation_audit.php', ['id' => $auditid, 'companyid' => $companyid]));
}
if ($data = $form->get_data()) {
    $itemid = \local_kbacorporate\local\implementation_audits::save_economic_snapshot($data, $USER->id);
    file_save_draft_area_files($data->evidencefiles, $context->id, 'local_kbacorporate',
        'implementation_economic_evidence', $itemid, $fileoptions);
    redirect(new moodle_url('/local/kbacorporate/implementation_audit.php',
        ['id' => $auditid, 'companyid' => $companyid]), get_string('saved', 'local_kbacorporate'));
}
$defaults = $record ?: (object)['periodtype' => 'year', 'periodstart' => strtotime('first day of January'),
    'periodend' => time(), 'currency' => 'RUB', 'source' => 'manual', 'verified' => 0];
$defaults->auditid = $auditid; $defaults->companyid = $companyid; $defaults->id = $itemid;
$defaults->evidencefiles = $draftitemid;
$form->set_data($defaults);
echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'audits');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php',
    ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')), 'kbacorp-backlink');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php',
    ['id' => $auditid, 'companyid' => $companyid]), '← ' . format_string($audit->title)), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('economicindicators', 'local_kbacorporate'));
echo html_writer::div(get_string('economicindicatorsdesc', 'local_kbacorporate'), 'kbacorp-pagelead');
$form->display();
echo $OUTPUT->footer();
