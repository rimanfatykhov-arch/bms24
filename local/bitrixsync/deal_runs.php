<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$PAGE->set_url(new moodle_url('/local/bitrixsync/deal_runs.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('deal_runs', 'local_bitrixsync'));
$PAGE->set_heading(get_string('pluginname', 'local_bitrixsync'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('deal_runs', 'local_bitrixsync'));

$records = $DB->get_records(
    'local_bitrixsync_dealrun',
    null,
    'id DESC',
    '*',
    0,
    100
);

$table = new html_table();
$table->head = [
    'ID',
    get_string('deal_id', 'local_bitrixsync'),
    get_string('bitrix_company_id', 'local_bitrixsync'),
    get_string('bitrix_contact_id', 'local_bitrixsync'),
    get_string('moodle_user_id', 'local_bitrixsync'),
    get_string('dry_run', 'local_bitrixsync'),
    get_string('product_rows', 'local_bitrixsync'),
    get_string('resolved_courses', 'local_bitrixsync'),
    get_string('enrolled_count', 'local_bitrixsync'),
    get_string('failed_count', 'local_bitrixsync'),
    get_string('crm_status', 'local_bitrixsync'),
    get_string('timeline_id', 'local_bitrixsync'),
    get_string('status'),
    get_string('time'),
];

foreach ($records as $record) {
    $table->data[] = [
        (int)$record->id,
        (int)$record->dealid,
        $record->companyid ? (int)$record->companyid : '—',
        $record->contactid ? (int)$record->contactid : '—',
        $record->userid ? (int)$record->userid : '—',
        !empty($record->dryrun) ? get_string('yes') : get_string('no'),
        (int)$record->productrows,
        (int)$record->resolved,
        (int)$record->enrolled,
        (int)$record->failed,
        !empty($record->crm_status)
            ? s($record->crm_status)
            : '—',
        !empty($record->timeline_id)
            ? (int)$record->timeline_id
            : '—',
        s($record->status),
        userdate((int)$record->timecreated),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
