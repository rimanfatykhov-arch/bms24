<?php
require_once(__DIR__ . '/../../config.php');
require_login(); $context = context_system::instance(); require_capability('local/kbacorporate:manageimplementationaudits', $context);
$companyid = required_param('companyid', PARAM_INT); $toolid = required_param('toolid', PARAM_INT); $id = optional_param('id', 0, PARAM_INT);
\local_kbacorporate\local\access::require_full_company_access($companyid, $USER->id);
$tool = $DB->get_record_select('local_kbacorp_bms_tool', 'id = :id AND companyid IN (0, :companyid)', ['id' => $toolid, 'companyid' => $companyid], '*', MUST_EXIST);
if ((int)$tool->companyid === 0) {
    throw new moodle_exception('bmstoolreadonly', 'local_kbacorporate');
}
$criterion = $id ? $DB->get_record('local_kbacorp_bms_crit', ['id' => $id, 'toolid' => $toolid], '*', MUST_EXIST) : null;
if (empty($tool->currentversionid) && \local_kbacorporate\local\implementation_audits::get_criteria($toolid)) {
    \local_kbacorporate\local\implementation_audits::ensure_versioned($toolid, $USER->id);
    $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $toolid], '*', MUST_EXIST);
}
$params = ['companyid' => $companyid, 'toolid' => $toolid] + ($id ? ['id' => $id] : []);
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/bms_criterion_edit.php', $params)); $PAGE->set_pagelayout('admin'); $PAGE->set_title(get_string($id ? 'editbmscriterion' : 'addbmscriterion', 'local_kbacorporate')); $PAGE->set_heading(format_string($tool->name));
$form = new \local_kbacorporate\form\bms_criterion_form(null, ['companyid' => $companyid, 'toolid' => $toolid, 'scalemax' => (int)$tool->scalemax]); if ($criterion) { $criterion->companyid = $companyid; $form->set_data($criterion); }
if ($form->is_cancelled()) { redirect(new moodle_url('/local/kbacorporate/bms_tool.php', ['id' => $toolid, 'companyid' => $companyid])); }
if ($data = $form->get_data()) { \local_kbacorporate\local\implementation_audits::save_criterion($data); redirect(new moodle_url('/local/kbacorporate/bms_tool.php', ['id' => $toolid, 'companyid' => $companyid]), get_string('saved', 'local_kbacorporate')); }
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'tools');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/bms_tool.php',
    ['id' => $toolid, 'companyid' => $companyid]), '← ' . format_string($tool->name)), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string($id ? 'editbmscriterion' : 'addbmscriterion', 'local_kbacorporate'));
$form->display(); echo $OUTPUT->footer();
