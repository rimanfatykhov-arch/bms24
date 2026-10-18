<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

global $DB;

$runid = optional_param('runid', 0, PARAM_INT);

if ($runid <= 0) {
    $latest = $DB->get_record_sql(
        "SELECT * FROM {local_bitrixsync_dealrun}
          ORDER BY timecreated DESC, id DESC",
        [],
        IGNORE_MULTIPLE
    );
    $runid = $latest ? (int)$latest->id : 0;
}

$PAGE->set_url(new moodle_url('/local/bitrixsync/timeline.php', ['runid' => $runid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect — Real Execution Timeline');

echo $OUTPUT->header();
echo html_writer::tag('h2', 'KBA Connect — Real Execution Timeline');

if ($runid <= 0) {
    echo $OUTPUT->notification('Запусков пока нет.', 'info');
    echo $OUTPUT->footer();
    exit;
}

$run = $DB->get_record('local_bitrixsync_dealrun', ['id' => $runid], '*', MUST_EXIST);
$payload = [];
if (!empty($run->payload)) {
    $decoded = json_decode((string)$run->payload, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$events = [];
$events[] = [
    'time' => (int)$run->timecreated,
    'step' => 'RUN_CREATED',
    'detail' => !empty($run->dryrun) ? 'DRY RUN' : 'REAL',
];

if (!empty($payload['user_resolution'])) {
    $events[] = [
        'time' => (int)$run->timecreated,
        'step' => 'USER_RESOLVED',
        'detail' => (string)($payload['user_resolution']['status'] ?? ''),
    ];
}

foreach (($payload['items'] ?? []) as $item) {
    $events[] = [
        'time' => (int)$run->timemodified,
        'step' => 'COURSE_' . (int)($item['courseid'] ?? 0),
        'detail' =>
            'product=' . (int)($item['productid'] ?? 0) .
            '; status=' . (string)($item['status'] ?? '') .
            '; corporate=' . (string)($item['corporate']['status'] ?? ''),
    ];
}

if (!empty($payload['crm'])) {
    $events[] = [
        'time' => (int)$run->timemodified,
        'step' => 'CRM_WRITEBACK',
        'detail' => !empty($payload['crm']['errors']) ? 'ERROR' : 'DONE',
    ];
}

$events[] = [
    'time' => (int)$run->timemodified,
    'step' => strtoupper((string)$run->status),
    'detail' => (string)($run->error ?? ''),
];

echo html_writer::tag('p',
    html_writer::tag('strong', 'RUN-' . (int)$run->id) .
    ' | Deal ' . (int)$run->dealid .
    ' | ' . s((string)$run->status)
);

echo html_writer::start_tag('table', ['class' => 'generaltable']);
echo html_writer::tag('tr',
    html_writer::tag('th', 'Time') .
    html_writer::tag('th', 'Step') .
    html_writer::tag('th', 'Detail')
);
foreach ($events as $e) {
    echo html_writer::tag('tr',
        html_writer::tag('td', userdate((int)$e['time'])) .
        html_writer::tag('td', s((string)$e['step'])) .
        html_writer::tag('td', s((string)$e['detail']))
    );
}
echo html_writer::end_tag('table');

echo $OUTPUT->footer();
