<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$status = optional_param('status', '', PARAM_ALPHA);
if (!in_array($status, ['', 'active', 'duesoon', 'overdue', 'planned', 'inprogress', 'review', 'completed', 'cancelled'], true)) {
    $status = '';
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_my_tasks.php', ['status' => $status]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myimplementationtasks', 'local_kbacorporate'));
$PAGE->set_heading(get_string('myimplementationtasks', 'local_kbacorporate'));

$alltasks = \local_kbacorporate\local\implementation_task_lifecycle::get_user_tasks((int)$USER->id);
$tasks = $status === '' ? $alltasks :
    \local_kbacorporate\local\implementation_task_lifecycle::get_user_tasks((int)$USER->id, $status);
$now = time();
$reminderdays = get_config('local_kbacorporate', 'implementationreminderdays');
$reminderdays = $reminderdays === false ? 3 : max(1, min(30, (int)$reminderdays));
$today = usergetmidnight($now);
$soonlimit = $today + (($reminderdays + 1) * DAYSECS);
$counts = ['all' => count($alltasks), 'active' => 0, 'review' => 0, 'duesoon' => 0, 'overdue' => 0];
foreach ($alltasks as $item) {
    if (in_array($item->status, ['planned', 'inprogress'], true)) { $counts['active']++; }
    if ($item->status === 'review') { $counts['review']++; }
    if ((int)$item->duedate >= $today && (int)$item->duedate < $soonlimit &&
            !in_array($item->status, ['completed', 'cancelled'], true)) { $counts['duesoon']++; }
    if ((int)$item->duedate > 0 && (int)$item->duedate < $today &&
            !in_array($item->status, ['completed', 'cancelled'], true)) { $counts['overdue']++; }
}

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation(0, 'mytasks');
echo html_writer::start_div('kbacorp-pagehero kbacorp-pagehero-green');
echo html_writer::div(get_string('bmsimplementation', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('myimplementationtasks', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('myimplementationtasksdesc', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-my-task-stats');
$cards = [
    '' => ['all', 'mytaskall'],
    'active' => ['active', 'mytaskactive'],
    'review' => ['review', 'mytaskreview'],
    'duesoon' => ['duesoon', 'mytaskduesoon'],
];
foreach ($cards as $filter => [$key, $label]) {
    $url = new moodle_url('/local/kbacorporate/implementation_my_tasks.php', ['status' => $filter]);
    echo html_writer::link($url, html_writer::tag('strong', (string)$counts[$key]) .
        html_writer::span(get_string($label, 'local_kbacorporate')), ['class' => 'kbacorp-my-task-stat' .
        ($status === $filter ? ' is-active' : '')]);
}
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_my_tasks.php', ['status' => 'overdue']),
    html_writer::tag('strong', (string)$counts['overdue']) .
    html_writer::span(get_string('mytaskoverdue', 'local_kbacorporate')),
    ['class' => 'kbacorp-my-task-stat is-overdue' . ($status === 'overdue' ? ' is-active' : '')]);
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-my-task-filters');
foreach (['' => 'mytaskall', 'planned' => 'implementationtaskstatusplanned',
        'inprogress' => 'implementationtaskstatusinprogress', 'review' => 'implementationtaskstatusreview',
        'completed' => 'implementationtaskstatuscompleted', 'cancelled' => 'implementationtaskstatuscancelled',
        'duesoon' => 'mytaskduesoon', 'overdue' => 'mytaskoverdue'] as
        $value => $label) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_my_tasks.php', ['status' => $value]),
        get_string($label, 'local_kbacorporate'), ['class' => 'kbacorp-status-filter' .
            ($status === $value ? ' is-active' : '')]);
}
echo html_writer::end_div();

if (!$tasks) {
    echo $OUTPUT->notification(get_string('mytasksempty', 'local_kbacorporate'), 'info');
}
foreach ($tasks as $task) {
    $url = new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => (int)$task->id]);
    $overdue = (int)$task->duedate > 0 && (int)$task->duedate < $today &&
        !in_array($task->status, ['completed', 'cancelled'], true);
    echo html_writer::start_div('kbacorp-my-task-card' . ($overdue ? ' is-overdue' : ''));
    echo html_writer::div(format_string($task->companyname), 'kbacorp-my-task-company');
    echo html_writer::link($url, format_string($task->title), ['class' => 'kbacorp-my-task-title']);
    echo html_writer::start_div('kbacorp-my-task-meta');
    echo html_writer::span(get_string('implementationtaskstatus' . $task->status, 'local_kbacorporate'),
        'kbacorp-task-status kbacorp-task-status-' . s($task->status));
    if ((int)$task->duedate > 0) {
        echo html_writer::span(get_string('implementationtaskdue', 'local_kbacorporate') . ': ' .
            userdate((int)$task->duedate, get_string('strftimedatefullshort')),
            $overdue ? 'kbacorp-task-overdue' : '');
    }
    echo html_writer::span(get_string('lastmodified') . ': ' . userdate((int)$task->timemodified,
        get_string('strftimedatetimekba', 'local_kbacorporate')));
    echo html_writer::end_div();
    if ((int)$task->checklisttotal > 0) {
        $checkpercent = (int)round(((int)$task->checklistdone * 100) / (int)$task->checklisttotal);
        echo html_writer::div(
            html_writer::span(get_string('implementationchecklistshort', 'local_kbacorporate')) .
            html_writer::span((int)$task->checklistdone . '/' . (int)$task->checklisttotal),
            'kbacorp-my-task-checklist-label');
        echo html_writer::div(html_writer::span('', '', ['style' => 'width:' . $checkpercent . '%']),
            'kbacorp-my-task-checklist-bar');
    }
    echo html_writer::end_div();
}
echo $OUTPUT->footer();
