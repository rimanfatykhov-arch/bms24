<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/filelib.php');

$context = context_system::instance();
$id = required_param('id', PARAM_INT);
$task = \local_kbacorporate\local\implementation_task_discussion::get_task($id);
\local_kbacorporate\local\implementation_task_discussion::require_access($task, (int)$USER->id);
$isowner = (int)$task->ownerid === (int)$USER->id;
$canedit = \local_kbacorporate\local\access::can_manage_company_implementation(
    (int)$task->companyid, (int)$USER->id);
$recordaction = optional_param('recordaction', '', PARAM_ALPHA);
if ($recordaction === 'delete') {
    require_sesskey();
    \local_kbacorporate\local\implementation_task_lifecycle::delete_task(
        $task, (int)$USER->id);
    redirect(new moodle_url('/local/kbacorporate/implementation_tasks.php', [
        'companyid' => (int)$task->companyid,
        'auditid' => (int)$task->auditid,
    ]), get_string('implementationtaskdeleted', 'local_kbacorporate'));
}
$checklistaction = optional_param('checklistaction', '', PARAM_ALPHA);
if ($checklistaction !== '') {
    require_sesskey();
    $checkitemid = optional_param('checkitemid', 0, PARAM_INT);
    if ($checklistaction === 'add') {
        \local_kbacorporate\local\implementation_task_checklist::add(
            $task, (int)$USER->id, optional_param('checklisttitle', '', PARAM_TEXT));
    } else if ($checklistaction === 'toggle') {
        \local_kbacorporate\local\implementation_task_checklist::toggle(
            $task, $checkitemid, (int)$USER->id);
    } else if ($checklistaction === 'delete') {
        \local_kbacorporate\local\implementation_task_checklist::delete(
            $task, $checkitemid, (int)$USER->id);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $id], 'checklist'),
        get_string('implementationchecklistsaved', 'local_kbacorporate'));
}
$transition = optional_param('transition', '', PARAM_ALPHA);
if ($transition !== '') {
    require_sesskey();
    $transitioncomment = optional_param('transitioncomment', '', PARAM_TEXT);
    \local_kbacorporate\local\implementation_task_lifecycle::transition(
        $task, (int)$USER->id, $transition, $transitioncomment);
    redirect(new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $id]),
        get_string('tasktransitionsuccess', 'local_kbacorporate'));
}
$company = $DB->get_record('local_kbacorp_company', ['id' => (int)$task->companyid], '*', MUST_EXIST);
$audit = \local_kbacorporate\local\implementation_audits::get_audit((int)$task->auditid);
$fileoptions = ['subdirs' => 0, 'maxbytes' => 10485760, 'maxfiles' => 10];
$evidenceavailable = \local_kbacorporate\local\implementation_evidence::is_available();
$evidencesummary = $evidenceavailable
    ? \local_kbacorporate\local\implementation_evidence::get_summary($id)
    : (object)['total' => 0, 'submitted' => 0, 'accepted' => 0, 'rejected' => 0, 'latest' => null];
$resultform = null;
// Backward-compatible fallback if the database upgrade has not created the
// versioned evidence tables yet. After v0.33.1 all new submissions use the
// dedicated evidence workspace below.
if (!$evidenceavailable && $isowner && $task->status === 'inprogress') {
    $draftitemid = file_get_submitted_draft_itemid('evidencefiles');
    file_prepare_draft_area($draftitemid, $context->id, 'local_kbacorporate',
        'implementation_task_evidence', $id, $fileoptions);
    $resultform = new \local_kbacorporate\form\implementation_task_result_form(null, ['id' => $id]);
    $resultform->set_data((object)[
        'id' => $id,
        'completioncomment' => (string)$task->completioncomment,
        'resulturl' => (string)($task->resulturl ?? ''),
        'evidencefiles' => $draftitemid,
    ]);
    if ($resultdata = $resultform->get_data()) {
        $DB->update_record('local_kbacorp_impl_task', (object)[
            'id' => $id,
            'completioncomment' => trim((string)$resultdata->completioncomment),
            'resulturl' => clean_param((string)$resultdata->resulturl, PARAM_URL) ?: null,
            'modifiedby' => (int)$USER->id,
            'timemodified' => time(),
        ]);
        file_save_draft_area_files($resultdata->evidencefiles, $context->id, 'local_kbacorporate',
            'implementation_task_evidence', $id, $fileoptions);
        $task = \local_kbacorporate\local\implementation_task_discussion::get_task($id);
        $task = \local_kbacorporate\local\implementation_task_lifecycle::transition(
            $task, (int)$USER->id, 'submit', get_string('taskresultsubmitted', 'local_kbacorporate'));
        redirect(new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $id]),
            get_string('taskresultsubmissionsuccess', 'local_kbacorporate'));
    }
}
$commentform = new \local_kbacorporate\form\implementation_task_comment_form();
$commentform->set_data(['id' => $id]);
if ($formdata = $commentform->get_data()) {
    $editor = $formdata->message_editor ?? [];
    $message = (string)($editor['text'] ?? '');
    // The Moodle editor submits HTML. Keep it as HTML even when a theme/editor
    // omits the nested format value from the submitted editor structure.
    $messageformat = FORMAT_HTML;
    if (trim(html_to_text($message)) === '') {
        redirect(new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $id]),
            get_string('taskdiscussionempty', 'local_kbacorporate'), null, \core\output\notification::NOTIFY_ERROR);
    }
    \local_kbacorporate\local\implementation_task_discussion::add_comment(
        $task, (int)$USER->id, $message, $messageformat);
    redirect(new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $id], 'discussion'),
        get_string('taskdiscussionmessagesent', 'local_kbacorporate'));
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $id]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(format_string($task->title));
$PAGE->set_heading(format_string($company->name));
$comments = \local_kbacorporate\local\implementation_task_discussion::get_comments($id);
$history = \local_kbacorporate\local\implementation_task_lifecycle::get_history($id);
$checklist = \local_kbacorporate\local\implementation_task_checklist::get_items($id);
$checkprogress = \local_kbacorporate\local\implementation_task_checklist::progress($id);
$iscurator = \local_kbacorporate\local\implementation_task_discussion::is_curator($task, (int)$USER->id);
\local_kbacorporate\local\implementation_task_discussion::mark_read($id, (int)$USER->id);
$owner = $task->ownerid ? \core_user::get_user((int)$task->ownerid) : null;
$isreviewer = $iscurator || $canedit;
$isoverdue = (int)$task->duedate > 0 && (int)$task->duedate < usergetmidnight(time()) &&
    !in_array($task->status, ['completed', 'cancelled'], true);
$reminderdays = get_config('local_kbacorporate', 'implementationreminderdays');
$reminderdays = $reminderdays === false ? 3 : max(1, min(30, (int)$reminderdays));
$isduesoon = !$isoverdue && (int)$task->duedate > 0 &&
    (int)$task->duedate <= time() + ($reminderdays * DAYSECS) &&
    !in_array($task->status, ['completed', 'cancelled'], true);

echo $OUTPUT->header();
$tasknavactive = $isowner ? 'mytasks' : ($iscurator ? 'students' : 'clients');
echo local_kbacorporate_implementation_navigation((int)$task->companyid, $tasknavactive);
echo local_kbacorporate_company_context_banner($company);
if ($iscurator) {
    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_curator.php'),
        '← ' . get_string('curatorworkspace', 'local_kbacorporate')), 'kbacorp-backlink');
}
if ($isowner) {
    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_my_tasks.php'),
        '← ' . get_string('myimplementationtasks', 'local_kbacorporate')), 'kbacorp-backlink');
}
if ($canedit) {
    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_tasks.php', [
        'companyid' => (int)$task->companyid, 'auditid' => (int)$task->auditid,
    ]), '← ' . get_string('implementationtasks', 'local_kbacorporate')), 'kbacorp-backlink');
}
if ($iscurator || has_capability('local/kbacorporate:manageimplementationaudits', $context)) {
    echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php', [
        'companyid' => (int)$task->companyid,
        'auditid' => (int)$task->auditid,
        'status' => (string)$task->status,
    ]), '← ' . get_string('kanbanopenfiltered', 'local_kbacorporate')), 'kbacorp-backlink');
}
echo html_writer::start_div('kbacorp-task-detail-hero');
echo html_writer::start_div('kbacorp-record-header');
echo html_writer::start_div('kbacorp-record-header__main');
echo html_writer::div(get_string('implementationtask', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(format_string($task->title), 2);
echo html_writer::div(get_string('implementationtaskstatus' . $task->status, 'local_kbacorporate'),
    'kbacorp-task-status kbacorp-task-status-' . s($task->status));
if ($isoverdue) {
    echo html_writer::div(get_string('taskoverdue', 'local_kbacorporate'), 'kbacorp-task-overdue');
} else if ($isduesoon) {
    echo html_writer::div(get_string('taskduesoon', 'local_kbacorporate', $reminderdays), 'kbacorp-task-due-soon');
}
echo html_writer::end_div();
if ($canedit) {
    $editurl = new moodle_url('/local/kbacorporate/implementation_task_edit.php', [
        'companyid' => (int)$task->companyid,
        'auditid' => (int)$task->auditid,
        'itemid' => (int)$task->itemid,
        'routeid' => (int)$task->routeid,
        'id' => (int)$task->id,
    ]);
    echo html_writer::start_div('kbacorp-record-actions');
    echo html_writer::link($editurl, get_string('editimplementationtask', 'local_kbacorporate'), [
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::start_tag('details', ['class' => 'kbacorp-record-actions__menu']);
    echo html_writer::tag('summary', get_string('actions', 'local_kbacorporate'), [
        'class' => 'btn btn-outline-secondary',
    ]);
    echo html_writer::start_div('kbacorp-record-actions__dropdown');
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => (new moodle_url('/local/kbacorporate/implementation_task.php'))->out(false),
        'onsubmit' => 'return confirm(' . json_encode(
            get_string('implementationtaskdeleteconfirm', 'local_kbacorporate', format_string($task->title))) . ');',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'recordaction', 'value' => 'delete']);
    echo html_writer::tag('button', get_string('deleteimplementationtask', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'kbacorp-record-actions__item kbacorp-record-actions__item--danger',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_tag('details');
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-task-detail-grid');
$details = [
    get_string('implementationtaskowner', 'local_kbacorporate') => $owner ? fullname($owner) : get_string('notassigned', 'local_kbacorporate'),
    get_string('routepriority', 'local_kbacorporate') => get_string('gappriority' . $task->priority, 'local_kbacorporate'),
    get_string('implementationtaskdue', 'local_kbacorporate') => $task->duedate ? userdate((int)$task->duedate,
        get_string('strftimedatefullshort')) : '—',
    get_string('implementationcriterion', 'local_kbacorporate') => format_string($task->criterionname),
];
foreach ($details as $label => $value) {
    echo html_writer::div(html_writer::span($label, 'kbacorp-task-detail-label') .
        html_writer::div($value, 'kbacorp-task-detail-value'), 'kbacorp-task-detail-item');
}
echo html_writer::end_div();
if ((string)$task->expectedresult !== '') {
    echo $OUTPUT->heading(get_string('implementationtaskexpected', 'local_kbacorporate'), 3);
    echo html_writer::div(format_text($task->expectedresult, FORMAT_PLAIN), 'kbacorp-task-description');
}

echo html_writer::start_div('kbacorp-task-checklist', ['id' => 'checklist']);
echo $OUTPUT->heading(get_string('implementationchecklist', 'local_kbacorporate'), 3);
echo html_writer::div(get_string('implementationchecklistprogress', 'local_kbacorporate', (object)[
    'done' => $checkprogress->done, 'total' => $checkprogress->total,
]), 'kbacorp-checklist-progress-label');
echo html_writer::div(html_writer::span('', '', [
    'style' => 'width:' . $checkprogress->percent . '%',
]), 'kbacorp-checklist-progressbar');
if (!$checklist) {
    echo html_writer::tag('p', get_string('implementationchecklistemptylist', 'local_kbacorporate'),
        ['class' => 'kbacorp-pagelead']);
}
foreach ($checklist as $checkitem) {
    echo html_writer::start_div('kbacorp-checklist-item' . ($checkitem->isdone ? ' is-done' : ''));
    if (!in_array((string)$task->status, ['completed', 'cancelled'], true)) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-checklist-toggle-form']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'checklistaction', 'value' => 'toggle']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'checkitemid',
            'value' => (int)$checkitem->id]);
        echo html_writer::tag('button', $checkitem->isdone ? '✓' : '○', [
            'type' => 'submit', 'class' => 'kbacorp-checklist-toggle',
            'title' => get_string('implementationchecklisttoggle', 'local_kbacorporate'),
        ]);
        echo html_writer::end_tag('form');
    } else {
        echo html_writer::span($checkitem->isdone ? '✓' : '○', 'kbacorp-checklist-toggle is-locked');
    }
    echo html_writer::span(format_string($checkitem->title), 'kbacorp-checklist-title');
    $candeletecheck = (int)$checkitem->createdby === (int)$USER->id || $isreviewer;
    if ($candeletecheck && !in_array((string)$task->status, ['completed', 'cancelled'], true)) {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-checklist-delete-form']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'checklistaction', 'value' => 'delete']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'checkitemid',
            'value' => (int)$checkitem->id]);
        echo html_writer::tag('button', get_string('delete'), [
            'type' => 'submit', 'class' => 'btn btn-link text-danger btn-sm',
            'onclick' => 'return confirm(' . json_encode(
                get_string('implementationchecklistconfirmdelete', 'local_kbacorporate')) . ');',
        ]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
}
if (!in_array((string)$task->status, ['completed', 'cancelled'], true)) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacorp-checklist-add-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'checklistaction', 'value' => 'add']);
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'name' => 'checklisttitle', 'maxlength' => 255, 'required' => 'required',
        'class' => 'form-control',
        'placeholder' => get_string('implementationchecklistplaceholder', 'local_kbacorporate'),
    ]);
    echo html_writer::tag('button', get_string('implementationchecklistadd', 'local_kbacorporate'), [
        'type' => 'submit', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_tag('form');
}
echo html_writer::end_div();

if ($evidenceavailable) {
    echo html_writer::start_div('kbacorp-implementation-evidence-panel');
    echo $OUTPUT->heading(get_string('implevidencepage', 'local_kbacorporate'), 3);
    echo html_writer::tag('p', get_string('implevidencetaskpaneldesc', 'local_kbacorporate'), [
        'class' => 'kbacorp-pagelead',
    ]);
    echo html_writer::start_div('kbacorp-summary-grid kbacorp-summary-grid-compact');
    foreach ([
        [$evidencesummary->total, 'implevidenceversionscount', 'blue'],
        [$evidencesummary->submitted, 'implevidencependingcount', 'amber'],
        [$evidencesummary->accepted, 'implevidenceacceptedcount', 'green'],
        [$evidencesummary->rejected, 'implevidencerejectedcount', 'red'],
    ] as [$value, $label, $tone]) {
        echo html_writer::div(html_writer::tag('strong', (int)$value) .
            html_writer::span(get_string($label, 'local_kbacorporate')),
            'kbacorp-summary-card kbacorp-tone-' . $tone);
    }
    echo html_writer::end_div();
    if ($evidencesummary->latest) {
        echo html_writer::div(get_string('implevidencelatesttaskstatus', 'local_kbacorporate', (object)[
            'version' => (int)$evidencesummary->latest->versionno,
            'status' => \local_kbacorporate\local\implementation_evidence::status_string(
                (string)$evidencesummary->latest->status),
        ]), 'kbacorp-pagelead');
    }
    echo $OUTPUT->single_button(new moodle_url('/local/kbacorporate/implementation_evidence.php', [
        'taskid' => $id,
    ]), get_string((string)$task->status === 'inprogress' && $isowner
        ? 'implevidenceaddbutton' : 'implevidenceopenbutton', 'local_kbacorporate'), 'get');
    echo html_writer::end_div();
}

// Evidence submitted before v0.33.1 remains readable and is never rewritten.
$resultfiles = get_file_storage()->get_area_files($context->id, 'local_kbacorporate',
    'implementation_task_evidence', $id, 'filename ASC', false);
if ((!$evidenceavailable || !$evidencesummary->total) &&
        (trim((string)$task->completioncomment) !== '' ||
            trim((string)($task->resulturl ?? '')) !== '' || $resultfiles)) {
    echo html_writer::start_div('kbacorp-task-result');
    echo $OUTPUT->heading(get_string('implevidencelegacyresult', 'local_kbacorporate'), 3);
    echo html_writer::tag('p', get_string('implevidencelegacydesc', 'local_kbacorporate'), [
        'class' => 'kbacorp-pagelead',
    ]);
    if (trim((string)$task->completioncomment) !== '') {
        echo html_writer::div(format_text($task->completioncomment, FORMAT_PLAIN), 'kbacorp-task-description');
    }
    if (trim((string)($task->resulturl ?? '')) !== '') {
        echo html_writer::div(html_writer::link(new moodle_url($task->resulturl),
            get_string('openresultlink', 'local_kbacorporate'), ['target' => '_blank', 'rel' => 'noopener']),
            'kbacorp-task-result-link');
    }
    if ($resultfiles) {
        echo html_writer::start_tag('ul', ['class' => 'kbacorp-task-evidence-list']);
        foreach ($resultfiles as $file) {
            $fileurl = moodle_url::make_pluginfile_url($context->id, 'local_kbacorporate',
                'implementation_task_evidence', $id, $file->get_filepath(), $file->get_filename(), true);
            echo html_writer::tag('li', html_writer::link($fileurl, s($file->get_filename())));
        }
        echo html_writer::end_tag('ul');
    }
    echo html_writer::end_div();
}
if ($resultform) {
    echo html_writer::start_div('kbacorp-task-result-submit');
    echo $OUTPUT->heading(get_string('submittaskresult', 'local_kbacorporate'), 3);
    echo html_writer::tag('p', get_string('submittaskresultdesc', 'local_kbacorporate'),
        ['class' => 'kbacorp-pagelead']);
    $resultform->display();
    echo html_writer::end_div();
}

$actions = [];
if ($isowner && $task->status === 'planned') { $actions['start'] = 'taskactionstart'; }
if ($isreviewer && $task->status === 'review' &&
        (!$evidenceavailable || !$evidencesummary->total)) {
    // Legacy tasks without a versioned evidence package keep the old review controls.
    $actions['approve'] = 'taskactionapprove';
    $actions['return'] = 'taskactionreturn';
}
if ($isreviewer && $task->status === 'review' && $evidenceavailable && $evidencesummary->total) {
    echo $OUTPUT->notification(get_string('implevidencereviewinworkspace', 'local_kbacorporate'), 'info');
}
if ($actions) {
    echo html_writer::start_div('kbacorp-task-workflow');
    echo $OUTPUT->heading(get_string('taskworkflow', 'local_kbacorporate'), 3);
    echo html_writer::tag('p', get_string('taskworkflowdesc', 'local_kbacorporate'),
        ['class' => 'kbacorp-pagelead']);
    echo html_writer::start_tag('form', ['method' => 'post',
        'action' => (new moodle_url('/local/kbacorporate/implementation_task.php'))->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    if ($task->status === 'review') {
        echo html_writer::label(get_string('tasktransitioncomment', 'local_kbacorporate'),
            'kbacorp-transition-comment');
        echo html_writer::tag('textarea', '', ['id' => 'kbacorp-transition-comment',
            'name' => 'transitioncomment', 'rows' => 3, 'class' => 'form-control']);
    }
    echo html_writer::start_div('kbacorp-task-workflow-actions');
    foreach ($actions as $action => $label) {
        $class = $action === 'return' ? 'btn btn-outline-danger' : 'btn btn-primary';
        echo html_writer::tag('button', get_string($label, 'local_kbacorporate'), [
            'type' => 'submit', 'name' => 'transition', 'value' => $action, 'class' => $class,
        ]);
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}

echo html_writer::start_div('kbacorp-task-history');
echo $OUTPUT->heading(get_string('taskhistory', 'local_kbacorporate'), 3);
if (!$history) {
    echo html_writer::tag('p', get_string('taskhistoryempty', 'local_kbacorporate'),
        ['class' => 'kbacorp-pagelead']);
}
foreach ($history as $entry) {
    $eventtype = (string)($entry->eventtype ?? 'status');
    if ($eventtype === 'kanban') {
        $content = html_writer::tag('strong', get_string('kanbanhistoryline',
            'local_kbacorporate', fullname($entry))) .
            html_writer::span(userdate((int)$entry->timecreated,
                get_string('strftimedatetimekba', 'local_kbacorporate')),
                'kbacorp-task-comment-time');
        $changes = \local_kbacorporate\local\implementation_kanban::decode_changes(
            $entry->detailsjson ?? null);
        $summary = $changes
            ? \local_kbacorporate\local\implementation_kanban::describe_changes($changes)
            : trim((string)$entry->comment);
        if ($summary !== '') {
            $content .= html_writer::div(s($summary), 'kbacorp-task-history-comment');
        }
    } else {
        $statusdata = (object)[
            'user' => fullname($entry),
            'from' => get_string('implementationtaskstatus' . $entry->fromstatus,
                'local_kbacorporate'),
            'to' => get_string('implementationtaskstatus' . $entry->tostatus,
                'local_kbacorporate'),
        ];
        $content = html_writer::tag('strong', get_string('taskhistoryline',
            'local_kbacorporate', $statusdata)) .
            html_writer::span(userdate((int)$entry->timecreated,
                get_string('strftimedatetimekba', 'local_kbacorporate')),
                'kbacorp-task-comment-time');
        if (trim((string)$entry->comment) !== '') {
            $content .= html_writer::div(s($entry->comment), 'kbacorp-task-history-comment');
        }
    }
    echo html_writer::div($content, 'kbacorp-task-history-entry');
}
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-task-discussion', ['id' => 'discussion']);
echo $OUTPUT->heading(get_string('taskdiscussion', 'local_kbacorporate'), 3);
echo html_writer::tag('p', get_string('taskdiscussiondesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);
if (!$comments) {
    echo $OUTPUT->notification(get_string('taskdiscussionemptylist', 'local_kbacorporate'), 'info');
}
foreach ($comments as $comment) {
    $picture = $OUTPUT->user_picture($comment, ['size' => 44]);
    $meta = html_writer::tag('strong', fullname($comment)) . html_writer::span(
        userdate((int)$comment->timecreated, get_string('strftimedatetimekba', 'local_kbacorporate')),
        'kbacorp-task-comment-time');
    $displayformat = (int)$comment->messageformat;
    $displaymessage = (string)$comment->message;
    $decodedmessage = $displaymessage;
    for ($decodepass = 0; $decodepass < 3; $decodepass++) {
        $nextmessage = html_entity_decode($decodedmessage, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($nextmessage === $decodedmessage) {
            break;
        }
        $decodedmessage = $nextmessage;
    }
    // v0.25.2 could save editor HTML with the legacy plain-text default on
    // some Moodle editor configurations. Older records can also contain an
    // entity-encoded HTML fragment, so normalise both forms before rendering.
    if (preg_match('/<\/?[a-z][^>]*>/i', $decodedmessage)) {
        $displayformat = FORMAT_HTML;
        $displaymessage = $decodedmessage;
    }
    echo html_writer::div($picture . html_writer::div($meta . html_writer::div(
        format_text($displaymessage, $displayformat), 'kbacorp-task-comment-message'),
        'kbacorp-task-comment-body'), 'kbacorp-task-comment');
}
echo html_writer::start_div('kbacorp-task-comment-form');
$commentform->display();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
