<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

$taskid = required_param('taskid', PARAM_INT);
$action = required_param('boardaction', PARAM_ALPHA);
$returnurl = optional_param('returnurl', '/local/kbacorporate/implementation_board.php', PARAM_LOCALURL);
$redirecturl = new moodle_url($returnurl);

if ($action === 'save') {
    $ownerid = optional_param('newownerid', 0, PARAM_INT);
    $priority = optional_param('newpriority', 'core', PARAM_ALPHA);
    $duedate = \local_kbacorporate\local\implementation_kanban::parse_due_date(
        optional_param('duedate', '', PARAM_RAW_TRIMMED)
    );
    $result = \local_kbacorporate\local\implementation_kanban::update_card(
        $taskid,
        (int)$USER->id,
        $ownerid,
        $duedate,
        $priority
    );
    redirect(
        $redirecturl,
        get_string($result->changed ? 'kanbancardsaved' : 'kanbancardunchanged', 'local_kbacorporate'),
        null,
        $result->changed ? \core\output\notification::NOTIFY_SUCCESS :
            \core\output\notification::NOTIFY_INFO
    );
}

if ($action === 'start') {
    $task = \local_kbacorporate\local\implementation_task_discussion::get_task($taskid);
    \local_kbacorporate\local\implementation_task_lifecycle::transition(
        $task,
        (int)$USER->id,
        'start',
        get_string('kanbanstartedfromboard', 'local_kbacorporate')
    );
    redirect($redirecturl, get_string('kanbantaskstarted', 'local_kbacorporate'));
}

throw new moodle_exception('invalidparameter');
