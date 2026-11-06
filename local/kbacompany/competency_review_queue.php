<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\competency_review_queue_service;

require_login();
$context = context_system::instance();
$page = optional_param('page', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$url = new moodle_url('/local/kbacompany/competency_review_queue.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('reviewqueue_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('reviewqueue_title', 'local_kbacompany'));

// Re-evaluate all permissions on click, including stale links and forged IDs.
if ($action === 'work') {
    $workurl = competency_review_queue_service::work_url($id);
    if ($workurl) {
        redirect($workurl);
    }
    redirect($url, get_string('reviewqueue_work_unavailable', 'local_kbacompany'), null,
        \core\output\notification::NOTIFY_INFO);
}

$queue = competency_review_queue_service::queue(max(0, $page));
echo $OUTPUT->header();
echo html_writer::tag('p', get_string('reviewqueue_intro', 'local_kbacompany'));
if (!$queue['rows']) {
    echo $OUTPUT->notification(get_string('reviewqueue_empty', 'local_kbacompany'), 'info');
} else {
    $table = new html_table();
    $table->head = [get_string('fullname'), get_string('reviewqueue_competency', 'local_kbacompany'),
        get_string('status'), get_string('actions')];
    foreach ($queue['rows'] as $row) {
        $actions = [];
        if ($row['workurl']) {
            $actions[] = html_writer::link(new moodle_url($url, ['action' => 'work', 'id' => $row['id']]),
                get_string('reviewqueue_open_work', 'local_kbacompany'), ['class' => 'btn btn-primary mr-2']);
            $actions[] = html_writer::span(get_string('reviewqueue_related_work_hint', 'local_kbacompany'),
                'text-muted d-block');
        } else {
            $actions[] = html_writer::span(get_string('reviewqueue_work_unavailable', 'local_kbacompany'),
                'text-muted d-block');
        }
        $actions[] = html_writer::link($row['reviewurl'],
            get_string('reviewqueue_review', 'local_kbacompany'), ['class' => 'btn btn-secondary']);
        $status = $row['status'] === \core_competency\user_competency::STATUS_IN_REVIEW
            ? 'reviewqueue_in_review' : 'reviewqueue_waiting';
        $table->data[] = [s($row['fullname']), s($row['competency']),
            get_string($status, 'local_kbacompany'), implode(' ', $actions)];
    }
    echo html_writer::div(html_writer::table($table), 'table-responsive');
}
echo $OUTPUT->paging_bar($queue['count'], max(0, $page), competency_review_queue_service::PAGE_SIZE, $url);
echo $OUTPUT->footer();
