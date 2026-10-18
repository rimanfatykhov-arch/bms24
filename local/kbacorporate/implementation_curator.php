<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$status = optional_param('status', '', PARAM_ALPHANUMEXT);
$query = optional_param('q', '', PARAM_TEXT);
$scope = optional_param('scope', '', PARAM_ALPHA);
$hasassignments = $DB->record_exists('local_kbacorp_impl_program', ['curatorid' => (int)$USER->id]);
$canviewall = has_capability('local/kbacorporate:manageimplementationaudits', $context);
if (!$hasassignments && !$canviewall) {
    throw new moodle_exception('curatorworkspacenotassigned', 'local_kbacorporate');
}
$allcurators = !$hasassignments && $canviewall;
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_curator.php', [
    'status' => $status, 'q' => $query, 'scope' => $scope,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('curatorworkspace', 'local_kbacorporate'));
$PAGE->set_heading(get_string('curatorworkspace', 'local_kbacorporate'));

$allthreads = \local_kbacorporate\local\implementation_task_discussion::get_curator_threads(
    (int)$USER->id, '', '', $allcurators);
$threads = \local_kbacorporate\local\implementation_task_discussion::get_curator_threads(
    (int)$USER->id, $status, $query, $allcurators);
$students = []; $unread = 0; $overdue = 0;
$today = usergetmidnight(time());
$statuscounts = array_fill_keys(['planned', 'inprogress', 'review', 'completed', 'cancelled'], 0);
foreach ($allthreads as $thread) {
    if ((int)$thread->ownerid > 0) { $students[(int)$thread->ownerid] = true; }
    $unread += (int)$thread->unreadcount;
    if (isset($statuscounts[$thread->status])) { $statuscounts[$thread->status]++; }
    if ($thread->duedate && (int)$thread->duedate < $today &&
            !in_array($thread->status, ['completed', 'cancelled'], true)) { $overdue++; }
}
if ($scope === 'unread') {
    $threads = array_values(array_filter($threads, static function($thread) {
        return (int)$thread->unreadcount > 0;
    }));
} else if ($scope === 'overdue') {
    $threads = array_values(array_filter($threads, static function($thread) use ($today) {
        return $thread->duedate && (int)$thread->duedate < $today &&
            !in_array($thread->status, ['completed', 'cancelled'], true);
    }));
}

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation(0, 'students');
echo html_writer::start_div('kbacorp-curator-hero');
echo html_writer::div(get_string('bmsimplementation', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('curatorworkspace', 'local_kbacorporate'), 2);
echo html_writer::tag('p', get_string('curatorworkspacedesc', 'local_kbacorporate'), ['class' => 'kbacorp-pagelead']);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_board.php'),
    get_string('openimplementationboard', 'local_kbacorporate'), ['class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_analytics.php'),
    get_string('openimplementationanalytics', 'local_kbacorporate'), ['class' => 'btn btn-outline-primary ml-2']);
if ($allcurators) {
    echo html_writer::div(get_string('curatorworkspaceallmode', 'local_kbacorporate'), 'alert alert-info');
}
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-curator-filters']);
echo html_writer::empty_tag('input', ['type' => 'search', 'name' => 'q', 'value' => $query,
    'placeholder' => get_string('curatorsearchplaceholder', 'local_kbacorporate'), 'class' => 'form-control',
    'id' => 'kbacorp-curator-search', 'list' => 'kbacorp-curator-search-options', 'autocomplete' => 'off']);
$searchsuggestions = [];
foreach ($allthreads as $suggestionthread) {
    if ((int)$suggestionthread->ownerid <= 0) {
        continue;
    }
    $suggestionowner = clone $suggestionthread;
    $suggestionowner->id = (int)$suggestionthread->ownerid;
    $suggestionname = fullname($suggestionowner);
    $searchsuggestions[$suggestionname] = format_string($suggestionthread->companyname);
}
echo html_writer::start_tag('datalist', ['id' => 'kbacorp-curator-search-options']);
foreach ($searchsuggestions as $suggestionname => $suggestioncompany) {
    echo html_writer::empty_tag('option', ['value' => $suggestionname, 'label' => $suggestioncompany]);
}
echo html_writer::end_tag('datalist');
$options = ['' => get_string('allstatuses', 'local_kbacorporate')];
foreach (['planned', 'inprogress', 'review', 'completed', 'cancelled'] as $value) {
    $options[$value] = get_string('implementationtaskstatus' . $value, 'local_kbacorporate');
}
echo html_writer::select($options, 'status', $status, false, ['class' => 'custom-select']);
echo html_writer::tag('button', get_string('applyfilter', 'local_kbacorporate'), ['class' => 'btn btn-primary']);
echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_curator.php'),
    get_string('resetfilters', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

echo html_writer::start_div('kbacorp-curator-stats');
foreach ([[count($students), 'curatorstudents', ''], [count($allthreads), 'curatortasks', ''],
        [$unread, 'curatorunread', 'unread'], [$overdue, 'curatoroverdue', 'overdue']] as [$value, $label, $cardscope]) {
    $cardurl = new moodle_url('/local/kbacorporate/implementation_curator.php',
        $cardscope ? ['scope' => $cardscope] : []);
    echo html_writer::link($cardurl, html_writer::tag('strong', (string)$value) . html_writer::span(
        get_string($label, 'local_kbacorporate')), ['class' => 'kbacorp-curator-stat']);
}
echo html_writer::end_div();

echo html_writer::start_div('kbacorp-curator-status-links');
foreach ($statuscounts as $statuskey => $count) {
    echo html_writer::link(new moodle_url('/local/kbacorporate/implementation_curator.php', ['status' => $statuskey]),
        html_writer::span((string)$count, 'kbacorp-curator-status-count') .
        get_string('implementationtaskstatus' . $statuskey, 'local_kbacorporate'),
        ['class' => 'kbacorp-curator-status-link' . ($status === $statuskey ? ' is-active' : '')]);
}
echo html_writer::end_div();

echo $OUTPUT->heading(get_string('curatorconversations', 'local_kbacorporate'), 3);
if (!$threads) {
    echo $OUTPUT->notification(get_string('curatorworkspaceempty', 'local_kbacorporate'), 'info');
}
echo html_writer::start_div('kbacorp-curator-threads');
foreach ($threads as $thread) {
    $url = new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => (int)$thread->id]);
    if ($thread->ownerid) {
        $owner = clone $thread; $owner->id = (int)$thread->ownerid;
        $avatar = $OUTPUT->user_picture($owner, ['size' => 50]);
        $studentname = fullname($owner);
    } else {
        $avatar = html_writer::span('?', 'kbacorp-curator-avatar-empty');
        $studentname = get_string('notassigned', 'local_kbacorporate');
    }
    if ($thread->lastcomment) {
        $previewmessage = (string)$thread->lastcomment->message;
        for ($decodepass = 0; $decodepass < 3; $decodepass++) {
            $nextmessage = html_entity_decode($previewmessage, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($nextmessage === $previewmessage) {
                break;
            }
            $previewmessage = $nextmessage;
        }
        $previewtext = trim(preg_replace('/\s+/u', ' ', html_to_text($previewmessage)));
        $preview = fullname($thread->lastcomment) . ': ' . shorten_text($previewtext, 100);
    } else {
        $previewtext = get_string('curatornomessages', 'local_kbacorporate');
        $preview = get_string('curatornomessages', 'local_kbacorporate');
    }
    $activitytime = $thread->lastcomment ? (int)$thread->lastcomment->timecreated : (int)$thread->timemodified;
    $badge = $thread->unreadcount ? html_writer::span((string)$thread->unreadcount, 'kbacorp-curator-unread') : '';
    $body = html_writer::div(html_writer::tag('strong', $studentname) .
        html_writer::span(format_string($thread->companyname), 'kbacorp-curator-company'), 'kbacorp-curator-thread-head') .
        html_writer::div(html_writer::link($url, format_string($thread->title)),
            'kbacorp-curator-task-title') .
        html_writer::div($preview, 'kbacorp-curator-preview');
    $meta = html_writer::div(userdate($activitytime,
        get_string('strftimedatetimekba', 'local_kbacorporate')) . $badge,
        'kbacorp-curator-thread-meta');
    $searchtext = core_text::strtolower($studentname . ' ' . $thread->companyname . ' ' .
        $thread->title . ' ' . $previewtext);
    echo html_writer::div($avatar . html_writer::div($body, 'kbacorp-curator-thread-body') . $meta,
        'kbacorp-curator-thread' . ($thread->unreadcount ? ' is-unread' : ''), [
            'data-search' => $searchtext,
        ]);
}
echo html_writer::end_div();
echo html_writer::div(get_string('curatorlivesearchempty', 'local_kbacorporate'),
    'alert alert-info kbacorp-curator-live-empty', ['id' => 'kbacorp-curator-live-empty', 'hidden' => 'hidden']);
$PAGE->requires->js_init_code(<<<'JS'
(function() {
    var input = document.getElementById('kbacorp-curator-search');
    var rows = Array.prototype.slice.call(document.querySelectorAll('.kbacorp-curator-thread'));
    var empty = document.getElementById('kbacorp-curator-live-empty');
    if (!input || !rows.length) { return; }
    var filter = function() {
        var needle = input.value.trim().toLocaleLowerCase();
        var visible = 0;
        rows.forEach(function(row) {
            var match = !needle || (row.getAttribute('data-search') || '').indexOf(needle) !== -1;
            row.hidden = !match;
            if (match) { visible++; }
        });
        if (empty) { empty.hidden = visible !== 0; }
    };
    input.addEventListener('input', filter);
    filter();
}());
JS
);
echo $OUTPUT->footer();
