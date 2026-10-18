<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_login(); $context = context_system::instance();
$companyid = required_param('companyid', PARAM_INT); $auditid = required_param('auditid', PARAM_INT);
$itemid = required_param('itemid', PARAM_INT); $routeid = optional_param('routeid', 0, PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id);
$audit = \local_kbacorporate\local\implementation_audits::get_audit($auditid);
if ((int)$audit->companyid !== $companyid) { throw new moodle_exception('companynotallowed', 'local_kbacorporate'); }
$program = \local_kbacorporate\local\implementation_programs::get_for_audit($auditid);
if (!\local_kbacorporate\local\implementation_programs::plan_editable($program)) {
    throw new moodle_exception('implementationprogramnotactive', 'local_kbacorporate');
}
$item = $DB->get_record('local_kbacorp_impl_item', ['id' => $itemid, 'auditid' => $auditid], '*', MUST_EXIST);
$task = $id ? $DB->get_record('local_kbacorp_impl_task', ['id' => $id, 'companyid' => $companyid,
    'auditid' => $auditid, 'itemid' => $itemid, 'deleted' => 0], '*', MUST_EXIST) : null;
if ($task) { $routeid = (int)$task->routeid; }
$urlparams = ['companyid' => $companyid, 'auditid' => $auditid, 'itemid' => $itemid, 'routeid' => $routeid];
if ($id) { $urlparams['id'] = $id; }
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_task_edit.php', $urlparams));
$PAGE->set_pagelayout('admin'); $PAGE->set_title(get_string('implementationtask', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$fileoptions = ['subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 10];
$evidenceworkflow = \local_kbacorporate\local\implementation_evidence::is_available();
$hasversionedevidence = $task &&
    \local_kbacorporate\local\implementation_evidence::has_records((int)$task->id);
$draftitemid = 0;
if (!$evidenceworkflow) {
    $draftitemid = file_get_submitted_draft_itemid('evidencefiles');
    file_prepare_draft_area($draftitemid, $context->id, 'local_kbacorporate',
        'implementation_task_evidence', $id, $fileoptions);
}
$form = new \local_kbacorporate\form\implementation_task_form(null, $urlparams + [
    'owners' => \local_kbacorporate\local\implementation_audits::get_company_owners($companyid),
    'versionedevidence' => $evidenceworkflow,
    'lockworkflowstatus' => $hasversionedevidence,
    'currentstatus' => $task ? (string)$task->status : 'planned',
]);
if ($task) {
    if (!$evidenceworkflow) { $task->evidencefiles = $draftitemid; }
    $form->set_data($task);
}
else {
    $route = $routeid ? $DB->get_record('local_kbacorp_bms_route', ['id' => $routeid,
        'companyid' => $companyid, 'criterionid' => $item->criterionid], '*', MUST_EXIST) : null;
    $gap = ((int)$item->score >= 0 && (int)$item->targetscore >= 0) ? max(0, $item->targetscore - $item->score) : null;
    $calculatedpriority = \local_kbacorporate\local\implementation_audits::gap_priority($gap);
    if (!in_array($calculatedpriority, ['critical', 'core', 'additional'], true)) { $calculatedpriority = 'core'; }
    $form->set_data((object)($urlparams + ['title' => get_string('implementationtasktitledefault', 'local_kbacorporate', $item->criterionname),
        'priority' => $route ? $route->priority : $calculatedpriority,
        'status' => 'planned', 'expectedresult' => $route ? $route->note : '', 'evidencefiles' => $draftitemid]));
}
$returnurl = new moodle_url('/local/kbacorporate/implementation_plan.php', ['companyid' => $companyid, 'auditid' => $auditid]);
if ($form->is_cancelled()) { redirect($returnurl); }
if ($data = $form->get_data()) {
    $oldstatus = $task ? (string)$task->status : '';
    if ($evidenceworkflow) {
        // Versioned evidence is immutable and can only be changed in its dedicated workspace.
        $data->completioncomment = $task ? (string)$task->completioncomment : '';
        $data->resulturl = $task ? (string)($task->resulturl ?? '') : '';
        if ($hasversionedevidence && $task) {
            // Once the versioned workflow has started, status changes must go through its transitions.
            $data->status = (string)$task->status;
        }
    }
    $taskid = \local_kbacorporate\local\implementation_audits::save_task($data, $USER->id);
    if (!$evidenceworkflow && isset($data->evidencefiles)) {
        file_save_draft_area_files($data->evidencefiles, $context->id, 'local_kbacorporate',
            'implementation_task_evidence', $taskid, $fileoptions);
    }
    if ($task && $oldstatus !== (string)$data->status) {
        $note = get_string('taskstatuschangedbyadmin', 'local_kbacorporate');
        \local_kbacorporate\local\implementation_task_lifecycle::record(
            $taskid, (int)$USER->id, $oldstatus, (string)$data->status, $note);
        $updatedtask = \local_kbacorporate\local\implementation_task_discussion::get_task($taskid);
        \local_kbacorporate\local\implementation_task_notifier::send_status(
            $updatedtask, $oldstatus, (string)$data->status, (int)$USER->id, $note);
    }
    redirect(new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $taskid]),
        get_string('implementationtasksaved', 'local_kbacorporate'));
}
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'board');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php',
    ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')), 'kbacorp-backlink');
echo html_writer::div(html_writer::link($returnurl, '← ' . get_string('implementationplan', 'local_kbacorporate')), 'kbacorp-backlink');
echo $OUTPUT->heading(get_string($id ? 'editimplementationtask' : 'createimplementationtask', 'local_kbacorporate'));
echo html_writer::div(format_string($item->criterionname), 'kbacorp-pagelead'); $form->display(); echo $OUTPUT->footer();
