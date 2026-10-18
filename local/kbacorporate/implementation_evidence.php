<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/filelib.php');

require_login();
$context = context_system::instance();
$taskid = required_param('taskid', PARAM_INT);
$task = \local_kbacorporate\local\implementation_evidence::get_task($taskid);
\local_kbacorporate\local\implementation_task_discussion::require_access($task, (int)$USER->id);
$company = $DB->get_record('local_kbacorp_company', ['id' => (int)$task->companyid], '*', MUST_EXIST);

if (!\local_kbacorporate\local\implementation_evidence::is_available()) {
    throw new moodle_exception('implevidenceunavailable', 'local_kbacorporate');
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if (in_array($action, ['accept', 'reject'], true)) {
    require_sesskey();
    $evidenceid = required_param('evidenceid', PARAM_INT);
    $reviewevidence = \local_kbacorporate\local\implementation_evidence::get_record($evidenceid);
    if ((int)$reviewevidence->taskid !== $taskid) {
        throw new invalid_parameter_exception('Evidence does not belong to this implementation task.');
    }
    $reviewcomment = optional_param('reviewcomment', '', PARAM_TEXT);
    \local_kbacorporate\local\implementation_evidence::review(
        $evidenceid,
        (int)$USER->id,
        $action === 'accept',
        $reviewcomment
    );
    redirect(new moodle_url('/local/kbacorporate/implementation_evidence.php', ['taskid' => $taskid]),
        get_string($action === 'accept' ? 'implevidenceacceptsuccess' : 'implevidencerejectsuccess',
            'local_kbacorporate'));
}

$latest = \local_kbacorporate\local\implementation_evidence::get_latest($taskid);
$cansubmit = \local_kbacorporate\local\implementation_evidence::can_submit($task, (int)$USER->id);
$canreview = \local_kbacorporate\local\implementation_evidence::can_review($task, (int)$USER->id);
$submissionform = null;
if ($cansubmit) {
    $draftitemid = file_get_submitted_draft_itemid('evidencefiles');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $latest &&
            (string)$latest->status === \local_kbacorporate\local\implementation_evidence::STATUS_REJECTED) {
        file_prepare_draft_area(
            $draftitemid,
            $context->id,
            'local_kbacorporate',
            \local_kbacorporate\local\implementation_evidence::FILEAREA,
            (int)$latest->id,
            \local_kbacorporate\local\implementation_evidence::file_options()
        );
    }
    $submissionform = new \local_kbacorporate\form\implementation_evidence_submission_form(
        null,
        ['taskid' => $taskid]
    );
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $submissionform->set_data((object)[
            'taskid' => $taskid,
            'summary' => $latest && (string)$latest->status ===
                \local_kbacorporate\local\implementation_evidence::STATUS_REJECTED
                    ? (string)$latest->summary : '',
            'externalurl' => $latest && (string)$latest->status ===
                \local_kbacorporate\local\implementation_evidence::STATUS_REJECTED
                    ? (string)($latest->externalurl ?? '') : '',
            'evidencefiles' => $draftitemid,
        ]);
    }
    if ($submissionform->is_cancelled()) {
        redirect(new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $taskid]));
    }
    if ($data = $submissionform->get_data()) {
        $created = \local_kbacorporate\local\implementation_evidence::submit(
            $taskid,
            (int)$USER->id,
            (string)$data->summary,
            (string)($data->externalurl ?? ''),
            (int)$data->evidencefiles
        );
        redirect(new moodle_url('/local/kbacorporate/implementation_evidence.php', ['taskid' => $taskid]),
            get_string('implevidencesubmitsuccess', 'local_kbacorporate', (int)$created->versionno));
    }
}

$task = \local_kbacorporate\local\implementation_evidence::get_task($taskid);
$latest = \local_kbacorporate\local\implementation_evidence::get_latest($taskid);
$summary = \local_kbacorporate\local\implementation_evidence::get_summary($taskid);
$versions = \local_kbacorporate\local\implementation_evidence::get_versions($taskid);
$history = \local_kbacorporate\local\implementation_evidence::get_history($taskid);
$canreview = \local_kbacorporate\local\implementation_evidence::can_review($task, (int)$USER->id);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/implementation_evidence.php', ['taskid' => $taskid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('implevidencepage', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$personname = static function(\stdClass $record, string $prefix): string {
    $user = (object)[
        'firstname' => (string)($record->{$prefix . 'firstname'} ?? ''),
        'lastname' => (string)($record->{$prefix . 'lastname'} ?? ''),
        'firstnamephonetic' => (string)($record->{$prefix . 'firstnamephonetic'} ?? ''),
        'lastnamephonetic' => (string)($record->{$prefix . 'lastnamephonetic'} ?? ''),
        'middlename' => (string)($record->{$prefix . 'middlename'} ?? ''),
        'alternatename' => (string)($record->{$prefix . 'alternatename'} ?? ''),
    ];
    $name = trim(fullname($user));
    return $name !== '' ? $name : get_string('unknownuser');
};

$filelist = static function(int $evidenceid) use ($context): string {
    $files = \local_kbacorporate\local\implementation_evidence::get_files($evidenceid);
    if (!$files) {
        return html_writer::span(get_string('implevidencenofiles', 'local_kbacorporate'), 'text-muted');
    }
    $items = [];
    foreach ($files as $file) {
        $url = moodle_url::make_pluginfile_url(
            $context->id,
            'local_kbacorporate',
            \local_kbacorporate\local\implementation_evidence::FILEAREA,
            $evidenceid,
            $file->get_filepath(),
            $file->get_filename(),
            true
        );
        $items[] = html_writer::tag('li', html_writer::link($url, s($file->get_filename())));
    }
    return html_writer::tag('ul', implode('', $items), ['class' => 'kbacorp-task-evidence-list']);
};

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation((int)$task->companyid,
    (int)$task->ownerid === (int)$USER->id ? 'mytasks' : 'students');
echo local_kbacorporate_company_context_banner($company);
echo html_writer::div(html_writer::link(
    new moodle_url('/local/kbacorporate/implementation_task.php', ['id' => $taskid]),
    '← ' . get_string('implementationtask', 'local_kbacorporate')
), 'kbacorp-backlink');

echo html_writer::start_div('kbacorp-task-detail-hero');
echo html_writer::div(get_string('implevidenceeyebrow', 'local_kbacorporate'), 'kbacorp-eyebrow');
echo $OUTPUT->heading(get_string('implevidencepage', 'local_kbacorporate'), 2);
echo html_writer::div(format_string($task->title), 'kbacorp-pagelead');
echo html_writer::end_div();

echo html_writer::tag('p', get_string('implevidencepagedesc', 'local_kbacorporate'), [
    'class' => 'kbacorp-pagelead',
]);

echo html_writer::start_div('kbacorp-summary-grid');
foreach ([
    [$summary->total, 'implevidenceversionscount', 'blue'],
    [$summary->submitted, 'implevidencependingcount', 'amber'],
    [$summary->accepted, 'implevidenceacceptedcount', 'green'],
    [$summary->rejected, 'implevidencerejectedcount', 'red'],
] as [$value, $label, $tone]) {
    echo html_writer::div(
        html_writer::tag('strong', (int)$value) .
        html_writer::span(get_string($label, 'local_kbacorporate')),
        'kbacorp-summary-card kbacorp-tone-' . $tone
    );
}
echo html_writer::end_div();

if ((string)$task->status === 'planned' && (int)$task->ownerid === (int)$USER->id) {
    echo $OUTPUT->notification(get_string('implevidencestartfirst', 'local_kbacorporate'), 'info');
} else if ((string)$task->status === 'review' && $latest &&
        (string)$latest->status === \local_kbacorporate\local\implementation_evidence::STATUS_SUBMITTED) {
    echo $OUTPUT->notification(get_string('implevidenceawaitingreview', 'local_kbacorporate',
        (int)$latest->versionno), 'info');
} else if ((string)$task->status === 'completed') {
    echo $OUTPUT->notification(get_string('implevidencetaskcomplete', 'local_kbacorporate'), 'success');
} else if ($latest && (string)$latest->status ===
        \local_kbacorporate\local\implementation_evidence::STATUS_REJECTED) {
    echo $OUTPUT->notification(get_string('implevidenceresubmitnotice', 'local_kbacorporate',
        (int)$latest->versionno), 'warning');
}

if ($canreview && $latest &&
        (string)$latest->status === \local_kbacorporate\local\implementation_evidence::STATUS_SUBMITTED &&
        (string)$task->status === 'review') {
    echo html_writer::start_div('kbacorp-implementation-evidence-review');
    echo $OUTPUT->heading(get_string('implevidencereviewheading', 'local_kbacorporate',
        (int)$latest->versionno), 3);
    echo html_writer::tag('p', get_string('implevidencereviewdesc', 'local_kbacorporate'), [
        'class' => 'kbacorp-pagelead',
    ]);
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'taskid', 'value' => $taskid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'evidenceid',
        'value' => (int)$latest->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::label(get_string('implevidencereviewcomment', 'local_kbacorporate'),
        'implementation-evidence-review-comment');
    echo html_writer::tag('textarea', '', [
        'id' => 'implementation-evidence-review-comment',
        'name' => 'reviewcomment',
        'rows' => 5,
        'maxlength' => 20000,
        'class' => 'form-control',
        'placeholder' => get_string('implevidencereviewcommentplaceholder', 'local_kbacorporate'),
    ]);
    echo html_writer::start_div('kbacorp-task-workflow-actions mt-3');
    echo html_writer::tag('button', get_string('implevidenceaccept', 'local_kbacorporate'), [
        'type' => 'submit', 'name' => 'action', 'value' => 'accept', 'class' => 'btn btn-primary',
    ]);
    echo html_writer::tag('button', get_string('implevidencereject', 'local_kbacorporate'), [
        'type' => 'submit', 'name' => 'action', 'value' => 'reject', 'class' => 'btn btn-outline-danger',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}

if ($submissionform) {
    echo html_writer::start_div('kbacorp-implementation-evidence-submit');
    echo $OUTPUT->heading(get_string($latest && (string)$latest->status ===
        \local_kbacorporate\local\implementation_evidence::STATUS_REJECTED
            ? 'implevidenceresubmitheading' : 'implevidencesubmitheading', 'local_kbacorporate'), 3);
    echo html_writer::tag('p', get_string('implevidencesubmitdesc', 'local_kbacorporate'), [
        'class' => 'kbacorp-pagelead',
    ]);
    $submissionform->display();
    echo html_writer::end_div();
}

echo $OUTPUT->heading(get_string('implevidenceversionhistory', 'local_kbacorporate'), 3);
if (!$versions) {
    echo $OUTPUT->notification(get_string('implevidenceempty', 'local_kbacorporate'), 'info');
}
foreach ($versions as $version) {
    $status = \local_kbacorporate\local\implementation_evidence::status_string((string)$version->status);
    echo html_writer::start_div('kbacorp-implementation-evidence-version kbacorp-evidence-status-' .
        s((string)$version->status));
    echo html_writer::start_div('kbacorp-implementation-evidence-version-head');
    echo html_writer::tag('strong', get_string('implevidenceversiontitle', 'local_kbacorporate',
        (int)$version->versionno));
    echo html_writer::span($status, 'kbacorp-task-status kbacorp-evidence-status-' . s((string)$version->status));
    echo html_writer::end_div();
    echo html_writer::div(get_string('implevidencesubmittedmeta', 'local_kbacorporate', (object)[
        'user' => $personname($version, 'submitter'),
        'date' => userdate((int)$version->submittedat, get_string('strftimedatetimekba', 'local_kbacorporate')),
    ]), 'text-muted');
    echo html_writer::div(nl2br(s((string)$version->summary)), 'kbacorp-task-description mt-2');
    if (trim((string)($version->externalurl ?? '')) !== '') {
        echo html_writer::div(html_writer::link((string)$version->externalurl,
            get_string('implevidenceopenlink', 'local_kbacorporate'), [
                'target' => '_blank', 'rel' => 'noopener noreferrer',
            ]), 'mt-2');
    }
    echo html_writer::div($filelist((int)$version->id), 'mt-2');
    if ((int)$version->reviewedat > 0) {
        echo html_writer::start_div('kbacorp-implementation-evidence-decision');
        echo html_writer::tag('strong', get_string('implevidencereviewedmeta', 'local_kbacorporate', (object)[
            'user' => $personname($version, 'reviewer'),
            'date' => userdate((int)$version->reviewedat, get_string('strftimedatetimekba', 'local_kbacorporate')),
        ]));
        if (trim((string)($version->reviewcomment ?? '')) !== '') {
            echo html_writer::div(nl2br(s((string)$version->reviewcomment)), 'mt-1');
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

echo $OUTPUT->heading(get_string('implevidenceauditlog', 'local_kbacorporate'), 3);
if (!$history) {
    echo html_writer::tag('p', get_string('implevidenceauditlogempty', 'local_kbacorporate'), [
        'class' => 'kbacorp-pagelead',
    ]);
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable kbacorp-report';
    $table->head = [
        get_string('date'),
        get_string('implevidenceversioncolumn', 'local_kbacorporate'),
        get_string('implevidenceevent', 'local_kbacorporate'),
        get_string('implevidenceactor', 'local_kbacorporate'),
        get_string('comment'),
    ];
    foreach ($history as $event) {
        $actor = (int)$event->userid > 0 ? fullname($event) : get_string('iprsystemactor', 'local_kbacorporate');
        $eventkey = 'implevidenceevent' . (string)$event->action;
        $eventlabel = get_string_manager()->string_exists($eventkey, 'local_kbacorporate')
            ? get_string($eventkey, 'local_kbacorporate') : s((string)$event->action);
        $table->data[] = [
            userdate((int)$event->timecreated, get_string('strftimedatetimekba', 'local_kbacorporate')),
            'v' . (int)$event->versionno,
            $eventlabel,
            $actor,
            trim((string)($event->comment ?? '')) !== '' ? nl2br(s((string)$event->comment)) : '—',
        ];
    }
    echo html_writer::div(html_writer::table($table), 'kbacorp-table-scroll');
}

echo $OUTPUT->footer();
