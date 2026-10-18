<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$query = trim(optional_param('q', '', PARAM_TEXT));
$companyid = optional_param('companyid', 0, PARAM_INT);
$curatorid = optional_param('curatorid', 0, PARAM_INT);
$auditid = optional_param('auditid', 0, PARAM_INT);
$ownerid = optional_param('ownerid', 0, PARAM_INT); // -1 means unassigned.
$status = optional_param('status', '', PARAM_ALPHA);
$scope = optional_param('scope', '', PARAM_ALPHANUM);
$period = optional_param('period', 'all', PARAM_ALPHANUM);
$priority = optional_param('priority', '', PARAM_ALPHA);
$evidence = optional_param('evidence', '', PARAM_ALPHA);

$statuses = ['planned', 'inprogress', 'review', 'completed', 'cancelled'];
if (!in_array($status, array_merge([''], $statuses), true)) {
    $status = '';
}
if (!in_array($scope, ['', 'overdue', 'due7'], true)) {
    $scope = '';
}
if (!in_array($period, \local_kbacorporate\local\implementation_analytics::PERIODS, true)) {
    $period = 'all';
}
if (!in_array($priority, array_merge([''],
        \local_kbacorporate\local\implementation_kanban::PRIORITIES), true)) {
    $priority = '';
}
if (!in_array($evidence, \local_kbacorporate\local\implementation_kanban::EVIDENCE_FILTERS, true)) {
    $evidence = '';
}

$hasassignments = $DB->record_exists('local_kbacorp_impl_program',
    ['curatorid' => (int)$USER->id]);
$canviewall = has_capability('local/kbacorporate:manageimplementationaudits', $context);
if (!$hasassignments && !$canviewall) {
    throw new moodle_exception('curatorworkspacenotassigned', 'local_kbacorporate');
}

$boardparams = [
    'q' => $query,
    'companyid' => $companyid,
    'curatorid' => $curatorid,
    'auditid' => $auditid,
    'ownerid' => $ownerid,
    'status' => $status,
    'scope' => $scope,
    'period' => $period,
    'priority' => $priority,
    'evidence' => $evidence,
];
$boardurl = new moodle_url('/local/kbacorporate/implementation_board.php', $boardparams);

$PAGE->set_context($context);
$PAGE->set_url($boardurl);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('implementationboard', 'local_kbacorporate'));
$PAGE->set_heading(get_string('implementationboard', 'local_kbacorporate'));

$allthreads = \local_kbacorporate\local\implementation_task_discussion::get_curator_threads(
    (int)$USER->id, '', '', $canviewall);
$companies = [];
$curators = [];
$audits = [];
$auditcompanies = [];
$owners = [];
foreach ($allthreads as $thread) {
    $companies[(int)$thread->companyid] = format_string($thread->companyname);
    if ((int)$thread->programcuratorid > 0) {
        $curators[(int)$thread->programcuratorid] = trim(format_string(
            $thread->curatorfirstname . ' ' . $thread->curatorlastname));
    }
    $audits[(int)$thread->auditid] = format_string($thread->companyname) . ' — ' .
        format_string($thread->audittitle);
    $auditcompanies[(int)$thread->auditid] = (int)$thread->companyid;
    if ((int)$thread->ownerid > 0) {
        $owner = clone $thread;
        $owner->id = (int)$thread->ownerid;
        $owners[(int)$thread->ownerid] = fullname($owner);
    }
}
asort($companies, SORT_NATURAL | SORT_FLAG_CASE);
asort($curators, SORT_NATURAL | SORT_FLAG_CASE);
asort($audits, SORT_NATURAL | SORT_FLAG_CASE);
asort($owners, SORT_NATURAL | SORT_FLAG_CASE);

$needle = core_text::strtolower($query);
$today = usergetmidnight(time());
$due7limit = $today + (8 * DAYSECS);
$periodstart = $period === 'all' ? 0 : $today - ((int)$period * DAYSECS);
$threads = array_values(array_filter($allthreads, static function($thread) use (
        $companyid, $curatorid, $auditid, $ownerid, $status, $scope, $periodstart,
        $today, $due7limit, $priority, $evidence, $needle) {
    if ($companyid && (int)$thread->companyid !== $companyid) {
        return false;
    }
    if ($curatorid && (int)$thread->programcuratorid !== $curatorid) {
        return false;
    }
    if ($auditid && (int)$thread->auditid !== $auditid) {
        return false;
    }
    if ($ownerid === -1 && (int)$thread->ownerid !== 0) {
        return false;
    }
    if ($ownerid > 0 && (int)$thread->ownerid !== $ownerid) {
        return false;
    }
    if ($status !== '' && (string)$thread->status !== $status) {
        return false;
    }
    if ($priority !== '' && (string)$thread->priority !== $priority) {
        return false;
    }
    if ($evidence !== '' &&
            \local_kbacorporate\local\implementation_kanban::evidence_filter_value(
                $thread->latestevidence ?? null) !== $evidence) {
        return false;
    }
    $active = !in_array((string)$thread->status, ['completed', 'cancelled'], true);
    if ($scope === 'overdue' &&
            (!((int)$thread->duedate > 0 && (int)$thread->duedate < $today) || !$active)) {
        return false;
    }
    if ($scope === 'due7' &&
            (!((int)$thread->duedate >= $today && (int)$thread->duedate < $due7limit) || !$active)) {
        return false;
    }
    if ($periodstart && (int)$thread->timecreated < $periodstart) {
        return false;
    }
    if ($needle === '') {
        return true;
    }
    $owner = clone $thread;
    $owner->id = (int)$thread->ownerid;
    $ownername = $thread->ownerid ? fullname($owner) : '';
    $haystack = core_text::strtolower(implode(' ', [
        $thread->title,
        $thread->companyname,
        $thread->audittitle,
        $thread->criterioncode,
        $thread->criterionname,
        $ownername,
    ]));
    return core_text::strpos($haystack, $needle) !== false;
}));

$columns = array_fill_keys($statuses, []);
foreach ($threads as $thread) {
    if (isset($columns[$thread->status])) {
        $columns[$thread->status][] = $thread;
    }
}
foreach ($columns as &$items) {
    usort($items, static function($left, $right) {
        $leftdue = (int)$left->duedate ?: PHP_INT_MAX;
        $rightdue = (int)$right->duedate ?: PHP_INT_MAX;
        return $leftdue === $rightdue
            ? ((int)$right->timemodified <=> (int)$left->timemodified)
            : ($leftdue <=> $rightdue);
    });
}
unset($items);

$filteredtotal = count($threads);
$overduecount = count(array_filter($threads, static function($task) use ($today) {
    return (int)$task->duedate > 0 && (int)$task->duedate < $today &&
        !in_array((string)$task->status, ['completed', 'cancelled'], true);
}));
$unassignedcount = count(array_filter($threads, static function($task) {
    return (int)$task->ownerid === 0 &&
        !in_array((string)$task->status, ['completed', 'cancelled'], true);
}));
$reviewcount = count(array_filter($threads, static function($task) {
    return (string)$task->status === 'review';
}));
$evidencependingcount = count(array_filter($threads, static function($task) {
    return ($task->latestevidence ?? null) &&
        (string)$task->latestevidence->status ===
            \local_kbacorporate\local\implementation_evidence::STATUS_SUBMITTED;
}));

$owneroptionsbycompany = [];
$returnlocalurl = $boardurl->out_as_local_url(false);

$hiddenreturn = static function(int $taskid, string $action) use ($returnlocalurl): string {
    return html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'taskid', 'value' => $taskid]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'boardaction', 'value' => $action]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'returnurl', 'value' => $returnlocalurl]);
};

$evidencebadge = static function(?\stdClass $latest): string {
    if (!$latest) {
        return html_writer::span(get_string('kanbanevidencenone', 'local_kbacorporate'),
            'kbacorp-kanban-evidence kbacorp-kanban-evidence-none');
    }
    $key = 'kanbanevidence' . (string)$latest->status;
    return html_writer::span(get_string($key, 'local_kbacorporate', (int)$latest->versionno),
        'kbacorp-kanban-evidence kbacorp-kanban-evidence-' . s((string)$latest->status));
};

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation(0, 'board');
echo html_writer::start_div('kbacorp-curator-hero');
echo html_writer::div(get_string('bmsimplementation', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('implementationboard', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('implementationboarddescv0332', 'local_kbacorporate'),
    ['class' => 'kbacorp-pagelead']);
echo html_writer::start_div('kbacorp-toolbar');
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_analytics.php'),
    get_string('openimplementationanalytics', 'local_kbacorporate'),
    ['class' => 'btn btn-outline-primary']);
if ($companyid > 0) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_section_progress.php', [
        'companyid' => $companyid,
        'auditid' => $auditid,
    ]), get_string('implementationsectionprogress', 'local_kbacorporate'),
        ['class' => 'btn btn-outline-primary']);
}
if ($auditid > 0 && isset($audits[$auditid])) {
    $selectedauditcompanyid = $companyid ?: ($auditcompanies[$auditid] ?? 0);
    if ($selectedauditcompanyid > 0) {
        echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
            'companyid' => $selectedauditcompanyid,
            'id' => $auditid,
        ]), get_string('kanbanopenaudit', 'local_kbacorporate'),
            ['class' => 'btn btn-outline-secondary']);
    }
}
echo html_writer::end_div();
echo html_writer::end_div();

echo $OUTPUT->notification(get_string('kanbanworkflownotice', 'local_kbacorporate'), 'info');

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-board-filters']);
echo html_writer::empty_tag('input', [
    'type' => 'search',
    'name' => 'q',
    'value' => $query,
    'placeholder' => get_string('kanbansearchplaceholder', 'local_kbacorporate'),
    'class' => 'form-control',
]);
echo html_writer::select([0 => get_string('allcompanies', 'local_kbacorporate')] + $companies,
    'companyid', $companyid, false, ['class' => 'custom-select']);
if ($canviewall) {
    echo html_writer::select([0 => get_string('allcurators', 'local_kbacorporate')] + $curators,
        'curatorid', $curatorid, false, ['class' => 'custom-select']);
}
echo html_writer::select([0 => get_string('kanbanallaudits', 'local_kbacorporate')] + $audits,
    'auditid', $auditid, false, ['class' => 'custom-select']);
echo html_writer::select([0 => get_string('allowners', 'local_kbacorporate'),
        -1 => get_string('kanbanunassigned', 'local_kbacorporate')] + $owners,
    'ownerid', $ownerid, false, ['class' => 'custom-select']);
$statusoptions = ['' => get_string('allstatuses', 'local_kbacorporate')];
foreach ($statuses as $statuskey) {
    $statusoptions[$statuskey] = get_string('implementationtaskstatus' . $statuskey,
        'local_kbacorporate');
}
echo html_writer::select($statusoptions, 'status', $status, false, ['class' => 'custom-select']);
echo html_writer::select([
    '' => get_string('kanbanallpriorities', 'local_kbacorporate'),
    'critical' => get_string('gapprioritycritical', 'local_kbacorporate'),
    'core' => get_string('gapprioritycore', 'local_kbacorporate'),
    'additional' => get_string('gappriorityadditional', 'local_kbacorporate'),
], 'priority', $priority, false, ['class' => 'custom-select']);
echo html_writer::select([
    '' => get_string('kanbanevidenceall', 'local_kbacorporate'),
    'none' => get_string('kanbanevidencenone', 'local_kbacorporate'),
    'submitted' => get_string('implevidencestatussubmitted', 'local_kbacorporate'),
    'accepted' => get_string('implevidencestatusaccepted', 'local_kbacorporate'),
    'rejected' => get_string('implevidencestatusrejected', 'local_kbacorporate'),
], 'evidence', $evidence, false, ['class' => 'custom-select']);
echo html_writer::select([
    '' => get_string('implementationboardscopeall', 'local_kbacorporate'),
    'overdue' => get_string('implementationanalyticsoverdue', 'local_kbacorporate'),
    'due7' => get_string('kanbandue7', 'local_kbacorporate'),
], 'scope', $scope, false, ['class' => 'custom-select']);
$periodoptions = [];
foreach (\local_kbacorporate\local\implementation_analytics::PERIODS as $periodkey) {
    $periodoptions[$periodkey] = get_string('implementationanalyticsperiod' . $periodkey,
        'local_kbacorporate');
}
echo html_writer::select($periodoptions, 'period', $period, false, ['class' => 'custom-select']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php'),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::start_div('kbacorp-summary-grid');
foreach ([
    [$filteredtotal, 'kanbansummarytotal', 'blue'],
    [$overduecount, 'kanbansummaryoverdue', 'red'],
    [$unassignedcount, 'kanbansummaryunassigned', 'amber'],
    [$reviewcount, 'kanbansummaryreview', 'violet'],
    [$evidencependingcount, 'kanbansummaryevidence', 'green'],
] as [$value, $label, $tone]) {
    echo html_writer::div(html_writer::tag('strong', (int)$value) .
        html_writer::span(get_string($label, 'local_kbacorporate')),
        'kbacorp-summary-card kbacorp-tone-' . $tone);
}
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-kanban-board');
foreach ($columns as $columnstatus => $items) {
    echo html_writer::start_div('kbacorp-kanban-column kbacorp-kanban-' . $columnstatus);
    echo html_writer::div(html_writer::tag('strong', get_string(
        'implementationtaskstatus' . $columnstatus, 'local_kbacorporate')) .
        html_writer::span((string)count($items), 'kbacorp-kanban-count'),
        'kbacorp-kanban-column-head');
    if (!$items) {
        echo html_writer::div(get_string('implementationboardcolumnempty', 'local_kbacorporate'),
            'kbacorp-kanban-empty');
    }

    foreach ($items as $task) {
        $taskurl = new moodle_url('/local/kbacorporate/implementation_task.php',
            ['id' => (int)$task->id]);
        $auditurl = new moodle_url('/local/kbacorporate/implementation_audit.php', [
            'companyid' => (int)$task->companyid,
            'id' => (int)$task->auditid,
        ]);
        $owner = clone $task;
        $owner->id = (int)$task->ownerid;
        $ownername = $task->ownerid ? fullname($owner) :
            get_string('notassigned', 'local_kbacorporate');
        $active = !in_array((string)$task->status, ['completed', 'cancelled'], true);
        $overdue = (int)$task->duedate > 0 && (int)$task->duedate < $today && $active;
        $canmanage = \local_kbacorporate\local\implementation_kanban::can_manage_card(
            $task, (int)$USER->id);
        $latest = $task->latestevidence ?? null;

        echo html_writer::start_div('kbacorp-kanban-card' . ($overdue ? ' is-overdue' : ''));
        echo html_writer::div(format_string($task->companyname), 'kbacorp-kanban-company');
        echo html_writer::link($auditurl, format_string($task->audittitle), [
            'class' => 'kbacorp-kanban-audit',
        ]);
        echo html_writer::link($taskurl, format_string($task->title), [
            'class' => 'kbacorp-kanban-title',
        ]);
        echo html_writer::div(s($task->criterioncode) . ' · ' .
            format_string($task->criterionname), 'kbacorp-kanban-criterion');
        if ((int)$task->currentscore >= 0 && (int)$task->targetscore >= 0) {
            echo html_writer::div(get_string('kanbanscoreline', 'local_kbacorporate', (object)[
                'current' => (int)$task->currentscore,
                'target' => (int)$task->targetscore,
                'gap' => max(0, (int)$task->gapvalue),
            ]), 'kbacorp-kanban-score');
        }
        echo html_writer::div(get_string('kanbanownerline', 'local_kbacorporate', $ownername),
            'kbacorp-kanban-owner');

        echo html_writer::start_div('kbacorp-kanban-meta');
        echo html_writer::span(get_string('gappriority' . $task->priority, 'local_kbacorporate'),
            'kbacorp-kanban-priority kbacorp-kanban-priority-' . s($task->priority));
        if ((int)$task->duedate > 0) {
            echo html_writer::span(userdate((int)$task->duedate,
                get_string('strftimedatefullshort')), $overdue ? 'kbacorp-task-overdue' : '');
        } else {
            echo html_writer::span(get_string('kanbannoduedate', 'local_kbacorporate'),
                'text-muted');
        }
        echo $evidencebadge($latest);
        if ((int)$task->unreadcount > 0) {
            echo html_writer::span(get_string('implementationboardunread', 'local_kbacorporate',
                (int)$task->unreadcount), 'kbacorp-curator-unread');
        }
        echo html_writer::end_div();

        if ((int)$task->checklisttotal > 0) {
            $checkpercent = (int)round(((int)$task->checklistdone * 100) /
                (int)$task->checklisttotal);
            echo html_writer::div(
                html_writer::span(get_string('implementationchecklistshort', 'local_kbacorporate')) .
                html_writer::span((int)$task->checklistdone . '/' . (int)$task->checklisttotal),
                'kbacorp-kanban-checklist-label');
            echo html_writer::div(html_writer::span('', '', [
                'style' => 'width:' . $checkpercent . '%',
            ]), 'kbacorp-kanban-checklist-bar');
        }

        echo html_writer::start_div('kbacorp-kanban-actions');
        echo html_writer::link($taskurl, get_string('openimplementationtask', 'local_kbacorporate'),
            ['class' => 'btn btn-sm btn-outline-primary']);
        if (\local_kbacorporate\local\implementation_evidence::is_available() &&
                ($latest || in_array((string)$task->status, ['inprogress', 'review'], true))) {
            echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_evidence.php', [
                'taskid' => (int)$task->id,
            ]), get_string($task->status === 'review'
                ? 'kanbanreviewevidence' : 'implevidenceopenbutton', 'local_kbacorporate'),
                ['class' => 'btn btn-sm btn-outline-secondary']);
        }
        if ((int)$task->ownerid === (int)$USER->id && (string)$task->status === 'planned') {
            echo html_writer::start_tag('form', [
                'method' => 'post',
                'action' => (new moodle_url('/local/kbacorporate/implementation_board_update.php'))->out(false),
                'class' => 'kbacorp-kanban-inline-form',
            ]);
            echo $hiddenreturn((int)$task->id, 'start');
            echo html_writer::tag('button', get_string('taskactionstart', 'local_kbacorporate'), [
                'type' => 'submit',
                'class' => 'btn btn-sm btn-primary',
            ]);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_div();

        if ($canmanage && $active) {
            $taskcompanyid = (int)$task->companyid;
            if (!isset($owneroptionsbycompany[$taskcompanyid])) {
                $owneroptionsbycompany[$taskcompanyid] = [
                    0 => get_string('notassigned', 'local_kbacorporate'),
                ];
                foreach (\local_kbacorporate\local\implementation_audits::get_company_owners(
                        $taskcompanyid) as $companyowner) {
                    $owneroptionsbycompany[$taskcompanyid][(int)$companyowner->id] =
                        fullname($companyowner);
                }
            }
            echo html_writer::start_tag('details', ['class' => 'kbacorp-kanban-quickedit']);
            echo html_writer::tag('summary', get_string('kanbanquickedit', 'local_kbacorporate'));
            echo html_writer::start_tag('form', [
                'method' => 'post',
                'action' => (new moodle_url('/local/kbacorporate/implementation_board_update.php'))->out(false),
                'class' => 'kbacorp-kanban-quickedit-form',
            ]);
            echo $hiddenreturn((int)$task->id, 'save');
            echo html_writer::label(get_string('implementationtaskowner', 'local_kbacorporate'),
                'kanban-owner-' . (int)$task->id);
            echo html_writer::select($owneroptionsbycompany[$taskcompanyid], 'newownerid',
                (int)$task->ownerid, false, [
                    'id' => 'kanban-owner-' . (int)$task->id,
                    'class' => 'custom-select custom-select-sm',
                ]);
            echo html_writer::label(get_string('implementationtaskdue', 'local_kbacorporate'),
                'kanban-due-' . (int)$task->id);
            echo html_writer::empty_tag('input', [
                'type' => 'date',
                'id' => 'kanban-due-' . (int)$task->id,
                'name' => 'duedate',
                'value' => \local_kbacorporate\local\implementation_kanban::due_date_input(
                    (int)$task->duedate),
                'class' => 'form-control form-control-sm',
            ]);
            echo html_writer::label(get_string('routepriority', 'local_kbacorporate'),
                'kanban-priority-' . (int)$task->id);
            echo html_writer::select([
                'critical' => get_string('gapprioritycritical', 'local_kbacorporate'),
                'core' => get_string('gapprioritycore', 'local_kbacorporate'),
                'additional' => get_string('gappriorityadditional', 'local_kbacorporate'),
            ], 'newpriority', (string)$task->priority, false, [
                'id' => 'kanban-priority-' . (int)$task->id,
                'class' => 'custom-select custom-select-sm',
            ]);
            echo html_writer::tag('button', get_string('kanbansavecard', 'local_kbacorporate'), [
                'type' => 'submit',
                'class' => 'btn btn-sm btn-primary',
            ]);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('details');
        }

        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo $OUTPUT->footer();
