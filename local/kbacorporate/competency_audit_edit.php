<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/kbacorporate:managecompetencyaudits', $context);

$auditid = required_param('id', PARAM_INT);
$audit = \local_kbacorporate\local\competency_audits::get_audit($auditid);
$companyid = (int)$audit->companyid;
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$url = new moodle_url('/local/kbacorporate/competency_audit_edit.php', ['id' => $auditid]);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('editaudittitle', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$form = new \local_kbacorporate\form\competency_audit_title_form($url, [
    'auditid' => $auditid,
    'companyid' => $companyid,
]);
$form->set_data($audit);
$returnurl = new moodle_url('/local/kbacorporate/competency_audits.php', ['companyid' => $companyid]);
if ($form->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $form->get_data()) {
    \local_kbacorporate\local\competency_audits::rename_audit($auditid, (string)$data->title);
    redirect($returnurl, get_string('auditrenamed', 'local_kbacorporate'));
}

echo $OUTPUT->header();
echo local_kbacorporate_company_dashboard_navigation($companyid);
echo html_writer::div(html_writer::link($returnurl,
    '← ' . get_string('audithistory', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string('editaudittitle', 'local_kbacorporate'));
$form->display();
echo $OUTPUT->footer();
