<?php
require_once(__DIR__ . '/../../config.php');
require_login(); $context = context_system::instance();
$companyid = required_param('companyid', PARAM_INT); $auditid = optional_param('auditid', 0, PARAM_INT);
$status = optional_param('status', '', PARAM_ALPHA); $ownerid = optional_param('ownerid', 0, PARAM_INT);
$overdue = optional_param('overdue', 0, PARAM_BOOL);
$company = \local_kbacorporate\local\access::require_company_implementation_access($companyid, (int)$USER->id);
$program = null;
if ($auditid) {
    $program = \local_kbacorporate\local\implementation_programs::get_for_audit($auditid);
    if (!\local_kbacorporate\local\implementation_programs::plan_visible($program)) {
        throw new moodle_exception('implementationprogramnotactive', 'local_kbacorporate');
    }
}
$canopenkanban = has_capability('local/kbacorporate:manageimplementationaudits', $context) ||
    ($program && (int)$program->curatorid === (int)$USER->id);
$params = ['companyid' => $companyid, 'auditid' => $auditid, 'status' => $status, 'ownerid' => $ownerid, 'overdue' => $overdue];
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_tasks.php', $params));
$PAGE->set_pagelayout('admin'); $PAGE->set_title(get_string('implementationtasks', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));
$owners = \local_kbacorporate\local\implementation_audits::get_company_owners($companyid);
$tasks = \local_kbacorporate\local\implementation_audits::get_tasks($companyid, $params);
$completed = count(array_filter($tasks, static function($task) { return $task->status === 'completed'; }));
$late = count(array_filter($tasks, static function($task) { return $task->duedate && $task->duedate < usergetmidnight(time())
    && !in_array($task->status, ['completed', 'cancelled'], true); }));
echo $OUTPUT->header(); echo local_kbacorporate_implementation_navigation($companyid, 'clients');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation.php',
    ['companyid' => $companyid]), '← ' . get_string('bmsimplementation', 'local_kbacorporate')), 'kbacorp-backlink');
if ($auditid) { echo html_writer::div(html_writer::link(new moodle_url('/local/kbacorporate/implementation_plan.php',
    ['companyid' => $companyid, 'auditid' => $auditid]), '← ' . get_string('implementationplan', 'local_kbacorporate')), 'kbacorp-backlink'); }
echo $OUTPUT->heading(get_string('implementationtasks', 'local_kbacorporate'));
if ($canopenkanban) {
    echo html_writer::div(html_writer::link(new moodle_url(
        '/local/kbacorporate/implementation_board.php', [
            'companyid' => $companyid,
            'auditid' => $auditid,
        ]), get_string('kanbanopenfiltered', 'local_kbacorporate'),
        ['class' => 'btn btn-outline-primary']), 'kbacorp-toolbar');
}
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filterbar']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
if ($auditid) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'auditid', 'value' => $auditid]); }
echo html_writer::select(['' => get_string('allstatuses', 'local_kbacorporate'), 'planned' => get_string('implementationtaskstatusplanned', 'local_kbacorporate'),
    'inprogress' => get_string('implementationtaskstatusinprogress', 'local_kbacorporate'), 'review' => get_string('implementationtaskstatusreview', 'local_kbacorporate'),
    'completed' => get_string('implementationtaskstatuscompleted', 'local_kbacorporate'), 'cancelled' => get_string('implementationtaskstatuscancelled', 'local_kbacorporate')], 'status', $status, false);
$owneroptions = [0 => get_string('allowners', 'local_kbacorporate')]; foreach ($owners as $owner) { $owneroptions[$owner->id] = fullname($owner); }
echo html_writer::select($owneroptions, 'ownerid', $ownerid, false);
echo html_writer::checkbox('overdue', 1, $overdue, get_string('onlyoverdue', 'local_kbacorporate'));
echo html_writer::tag('button', get_string('applyfilters', 'local_kbacorporate'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::start_div('kbacorp-summary-grid');
echo html_writer::div(html_writer::tag('strong', count($tasks)) . html_writer::span(get_string('implementationtasks', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-blue');
echo html_writer::div(html_writer::tag('strong', $completed) . html_writer::span(get_string('implementationtaskstatuscompleted', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-green');
echo html_writer::div(html_writer::tag('strong', $late) . html_writer::span(get_string('overduetasks', 'local_kbacorporate')), 'kbacorp-summary-card kbacorp-tone-red');
echo html_writer::end_div();
$table = new html_table(); $table->attributes['class'] = 'generaltable kbacorp-report';
$table->head = [get_string('implementationtasktitle', 'local_kbacorporate'), get_string('implementationtaskowner', 'local_kbacorporate'),
    get_string('routepriority', 'local_kbacorporate'), get_string('implementationtaskstatus', 'local_kbacorporate'),
    get_string('implementationtaskdue', 'local_kbacorporate'), get_string('actions', 'local_kbacorporate')];
foreach ($tasks as $task) {
    $owner = $task->ownerid ? fullname($task) : get_string('notassigned', 'local_kbacorporate');
    $due = $task->duedate ? userdate($task->duedate, get_string('strftimedatefullshort')) : '—';
    if ($task->duedate && $task->duedate < usergetmidnight(time()) && !in_array($task->status, ['completed', 'cancelled'], true)) { $due .= ' · ' . get_string('overdue', 'local_kbacorporate'); }
    $edit = new moodle_url('/local/kbacorporate/implementation_task_edit.php', ['companyid' => $companyid,
        'auditid' => $task->auditid, 'itemid' => $task->itemid, 'routeid' => $task->routeid, 'id' => $task->id]);
    $view = new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $task->id]);
    $actions = html_writer::link($view, get_string('opentaskwithmessages', 'local_kbacorporate',
        (int)$task->discussioncount)) . ' · ' .
        html_writer::link($edit, get_string('edit'));
    $table->data[] = [html_writer::link($view, format_string($task->title)), $owner,
        get_string('gappriority' . $task->priority, 'local_kbacorporate'),
        get_string('implementationtaskstatus' . $task->status, 'local_kbacorporate'), $due, $actions];
}
echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll'); echo $OUTPUT->footer();
