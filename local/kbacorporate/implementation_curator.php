<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
$status = optional_param('status', '', PARAM_ALPHANUMEXT);
$query = optional_param('q', '', PARAM_TEXT);
$scope = optional_param('scope', '', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
$pagesize = 20;
$hasassignments = $DB->record_exists('local_kbacorp_impl_program', ['curatorid' => (int)$USER->id]);
$canviewall = has_capability('local/kbacorporate:manageimplementationaudits', $context);
if (!$hasassignments && !$canviewall) {
    throw new moodle_exception('curatorworkspacenotassigned', 'local_kbacorporate');
}
$allcurators = !$hasassignments && $canviewall;
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_curator.php', [
    'status' => $status, 'q' => $query, 'scope' => $scope, 'page' => $page,
]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('curatorworkspace', 'local_kbacorporate'));
$PAGE->set_heading(get_string('curatorworkspace', 'local_kbacorporate'));

$overview = \local_kbacorporate\local\implementation_task_discussion::get_curator_overview(
    (int)$USER->id, $allcurators);
// Filters and pagination are applied in SQL; no complete discussion list is materialized.
$result = \local_kbacorporate\local\implementation_task_discussion::get_curator_page(
    (int)$USER->id, $status, $query, $scope, $allcurators, $page, $pagesize);
$total = $result->total;
if ($total && $page * $pagesize >= $total) {
    $page = max(0, (int)ceil($total / $pagesize) - 1);
    $result = \local_kbacorporate\local\implementation_task_discussion::get_curator_page(
        (int)$USER->id, $status, $query, $scope, $allcurators, $page, $pagesize);
}
$threads = $result->threads;
$students = $overview->students;
$unread = $overview->unread;
$overdue = $overview->overdue;
$statuscounts = $overview->statuscounts;

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
$searchsuggestions = $overview->suggestions;
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
foreach ([[count($students), 'curatorstudents', ''], [$overview->taskcount, 'curatortasks', ''],
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
$pageurl = new moodle_url('/local/kbacorporate/implementation_curator.php', [
    'status' => $status, 'q' => $query, 'scope' => $scope,
]);
if ($total > $pagesize) {
    echo $OUTPUT->paging_bar($total, $page, $pagesize, $pageurl);
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
    $body = html_writer::div(html_writer::tag('strong', s($studentname)) .
        html_writer::span(format_string($thread->companyname), 'kbacorp-curator-company'), 'kbacorp-curator-thread-head') .
        html_writer::div(html_writer::link($url, format_string($thread->title)),
            'kbacorp-curator-task-title') .
        html_writer::div(s($preview), 'kbacorp-curator-preview');
    if ((string)$thread->status === 'review' && $thread->latestevidence &&
            (string)$thread->latestevidence->status ===
                \local_kbacorporate\local\implementation_evidence::STATUS_SUBMITTED &&
            \local_kbacorporate\local\implementation_evidence::can_review($thread, (int)$USER->id)) {
        $reviewurl = new moodle_url('/local/kbacorporate/implementation_evidence.php', [
            'taskid' => (int)$thread->id,
        ]);
        $body .= html_writer::div(html_writer::link($reviewurl,
            get_string('curatoropenreview', 'local_kbacorporate'),
            ['class' => 'btn btn-outline-primary btn-sm']), 'kbacorp-curator-review-action');
    }
    $meta = html_writer::div(userdate($activitytime,
        get_string('strftimedatetimekba', 'local_kbacorporate')) . $badge,
        'kbacorp-curator-thread-meta');
    echo html_writer::div($avatar . html_writer::div($body, 'kbacorp-curator-thread-body') . $meta,
        'kbacorp-curator-thread' . ($thread->unreadcount ? ' is-unread' : ''));
}
echo html_writer::end_div();
if ($total > $pagesize) {
    echo $OUTPUT->paging_bar($total, $page, $pagesize, $pageurl);
}
echo $OUTPUT->footer();
