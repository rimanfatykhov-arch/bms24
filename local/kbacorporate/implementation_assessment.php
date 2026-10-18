<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_login(); $context = context_system::instance(); $companyid = required_param('companyid', PARAM_INT); $auditid = required_param('auditid', PARAM_INT); $itemid = required_param('itemid', PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id); $audit = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
if ((int)$audit->companyid !== $companyid) { throw new moodle_exception('companynotallowed', 'local_kbacorporate'); }
$item = $DB->get_record('local_kbacorp_impl_item', ['id' => $itemid, 'auditid' => $auditid], '*', MUST_EXIST);
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_assessment.php', ['itemid' => $itemid, 'auditid' => $auditid, 'companyid' => $companyid])); $PAGE->set_pagelayout('admin'); $PAGE->set_title(format_string($item->criterionname)); $PAGE->set_heading(format_string($company->name));
$fileoptions = ['subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 10];
$draftitemid = file_get_submitted_draft_itemid('evidencefiles');
file_prepare_draft_area($draftitemid, $context->id, 'local_kbacorporate', 'implementation_evidence', $itemid, $fileoptions);
$form = new \local_kbacorporate\form\implementation_assessment_form(null, [
    'itemid' => $itemid, 'auditid' => $auditid, 'companyid' => $companyid,
    'scalemax' => (int)$audit->scalemax,
]); $item->itemid = $itemid; $item->companyid = $companyid; $item->evidencefiles = $draftitemid; $form->set_data($item);
if ($form->is_cancelled()) { redirect(new moodle_url('/local/kbacorporate/implementation_audit.php', ['id' => $auditid, 'companyid' => $companyid])); }
if ($data = $form->get_data()) { file_save_draft_area_files($data->evidencefiles, $context->id, 'local_kbacorporate', 'implementation_evidence', $itemid, $fileoptions); \local_kbacorporate\local\implementation_audits::save_item($itemid, $data, $USER->id); redirect(new moodle_url('/local/kbacorporate/implementation_audit.php', ['id' => $auditid, 'companyid' => $companyid]), get_string('saved', 'local_kbacorporate')); }
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'audits');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php',
    ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')), 'kbacorp-backlink');
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
    'id' => $auditid, 'companyid' => $companyid,
]), '← ' . get_string('backtoimplementationaudit', 'local_kbacorporate')), 'kbacorp-backlink');
if ($item->sectionname) { echo html_writer::div(format_string($item->sectionname), 'kbacorp-eyebrow'); }
echo $OUTPUT->heading(format_string($item->criterionname));
if ($item->minlabel || $item->maxlabel) {
    echo html_writer::start_div('kbacorp-summary-grid');
    echo html_writer::div(html_writer::tag('strong', '0') . html_writer::span(format_string($item->minlabel ?: '—')),
        'kbacorp-summary-card kbacorp-tone-red');
    echo html_writer::div(html_writer::tag('strong', (int)$audit->scalemax)
        . html_writer::span(format_string($item->maxlabel ?: '—')), 'kbacorp-summary-card kbacorp-tone-green');
    echo html_writer::end_div();
}
if ($item->evidencehint) { echo $OUTPUT->notification(get_string('bmsevidenceexpected', 'local_kbacorporate', s($item->evidencehint)), 'info'); }
$form->display(); echo $OUTPUT->footer();
