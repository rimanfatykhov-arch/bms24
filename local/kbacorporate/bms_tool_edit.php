<?php
require_once(__DIR__ . '/../../config.php');
require_login(); $context = context_system::instance(); require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT); $id = optional_param('id', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$tool = $id ? $DB->get_record('local_kbacorp_bms_tool', ['id' => $id, 'companyid' => $companyid], '*', MUST_EXIST) : null;
if ($tool && empty($tool->currentversionid) && \local_kbacorporate\local\implementation_audits::get_criteria($id)) {
    \local_kbacorporate\local\implementation_audits::ensure_versioned($id, $USER->id);
    $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $id], '*', MUST_EXIST);
}
$params = ['companyid' => $companyid] + ($id ? ['id' => $id] : []);
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/bms_tool_edit.php', $params)); $PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string($id ? 'editbmstool' : 'addbmstool', 'local_kbacorporate')); $PAGE->set_heading(format_string($company->name));
$form = new \local_kbacorporate\form\bms_tool_form(null, [
    'companyid' => $companyid,
    'codefrozen' => $tool && \local_kbacorporate\local\implementation_audits::tool_has_audits((int)$tool->id),
]); if ($tool) { $form->set_data($tool); }
if ($form->is_cancelled()) { redirect(new moodle_url('/local/kbacorporate/bms_tools.php', ['companyid' => $companyid])); }
if ($data = $form->get_data()) { $toolid = \local_kbacorporate\local\implementation_audits::save_tool($data); redirect(new moodle_url('/local/kbacorporate/bms_tool.php', ['id' => $toolid, 'companyid' => $companyid]), get_string('saved', 'local_kbacorporate')); }
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'tools');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/bms_tools.php',
    ['companyid' => $companyid]), '← ' . get_string('bmstools', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string($id ? 'editbmstool' : 'addbmstool', 'local_kbacorporate'));
if ($tool && \local_kbacorporate\local\implementation_audits::tool_has_audits((int)$tool->id)) {
    echo $OUTPUT->notification(get_string('bmstoolcodefrozen', 'local_kbacorporate'), 'info');
}
$form->display(); echo $OUTPUT->footer();
