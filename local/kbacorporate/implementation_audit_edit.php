<?php
require_once(__DIR__ . '/../../config.php');
require_login(); $context = context_system::instance(); $companyid = required_param('companyid', PARAM_INT); $defaulttoolid = optional_param('toolid', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id);
$selectabletools = \local_kbacorporate\local\implementation_audits::get_selectable_tools($companyid, true);
if (!$selectabletools && !\local_kbacorporate\local\implementation_audits::get_tools($companyid, false)) {
    \local_kbacorporate\local\point_a_template::install($companyid);
    $selectabletools = \local_kbacorporate\local\implementation_audits::get_selectable_tools($companyid, true);
}
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_audit_edit.php',
    ['companyid' => $companyid, 'toolid' => $defaulttoolid])); $PAGE->set_pagelayout('admin'); $PAGE->set_title(get_string('createimplementationaudit', 'local_kbacorporate')); $PAGE->set_heading(format_string($company->name));
$form = new \local_kbacorporate\form\implementation_audit_form(null, [
    'companyid' => $companyid, 'defaulttoolid' => $defaulttoolid,
]);
if ($form->is_cancelled()) { redirect(new moodle_url('/local/kbacorporate/implementation_audits.php', ['companyid' => $companyid])); }
if ($data = $form->get_data()) { $id = \local_kbacorporate\local\implementation_audits::create_audit($data, $USER->id); redirect(new moodle_url('/local/kbacorporate/implementation_audit.php', ['id' => $id, 'companyid' => $companyid]), get_string('implementationauditcreated', 'local_kbacorporate')); }
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'audits');
echo local_kbacorporate_company_context_banner($company); echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php', ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')), 'kbacorp-backlink'); echo $OUTPUT->heading(get_string('createimplementationaudit', 'local_kbacorporate'));
if (!$selectabletools) {
    $message = get_string('noactivebmstools', 'local_kbacorporate');
    if (has_capability('local/kbacorporate:manageimplementationaudits', $context)) {
        $message .= ' ' . html_writer::link(
            new moodle_url('/local/kbacorporate/bms_tools.php', ['companyid' => $companyid]),
            get_string('openbmstools', 'local_kbacorporate')
        );
    }
    echo $OUTPUT->notification($message, 'warning');
}
$form->display(); echo $OUTPUT->footer();
