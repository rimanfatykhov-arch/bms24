<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

global $DB;

$PAGE->set_url(new moodle_url('/local/bitrixsync/history.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('KBA Connect — Real Execution History');

echo $OUTPUT->header();
echo html_writer::link(
    new moodle_url('/local/bitrixsync/deal_contact_debug.php'),
    'Диагностика контактов сделки',
    ['class' => 'btn btn-warning mb-3']
);

echo \local_bitrixsync\ui\admin_shell::render('history');
echo html_writer::tag('h2', 'История обработки KBA Connect');

$records = $DB->get_records(
    'local_bitrixsync_dealrun',
    null,
    'timecreated DESC, id DESC',
    '*',
    0,
    50
);

if (!$records) {
    echo $OUTPUT->notification('Реальных/Dry Run запусков ещё нет.', 'info');
} else {
    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Run') .
        html_writer::tag('th', 'Deal') .
        html_writer::tag('th', 'Mode') .
        html_writer::tag('th', 'Status') .
        html_writer::tag('th', 'Moodle') .
        html_writer::tag('th', 'CRM') .
        html_writer::tag('th', 'Time')
    );

    foreach ($records as $r) {
        $moodle = 'enrolled=' . (int)$r->enrolled .
            ', already=' . (int)$r->already .
            ', failed=' . (int)$r->failed;

        echo html_writer::tag('tr',
            html_writer::tag('td',
                html_writer::link(
                    new moodle_url('/local/bitrixsync/timeline.php', ['runid' => $r->id]),
                    'RUN-' . (int)$r->id
                )
            ) .
            html_writer::tag('td', (string)(int)$r->dealid) .
            html_writer::tag('td', !empty($r->dryrun) ? 'DRY RUN' : 'REAL') .
            html_writer::tag('td', s((string)$r->status)) .
            html_writer::tag('td', s($moodle)) .
            html_writer::tag('td', s((string)($r->crm_status ?? ''))) .
            html_writer::tag('td', userdate((int)$r->timecreated))
        );
    }

    echo html_writer::end_tag('table');
}

echo $OUTPUT->footer();
