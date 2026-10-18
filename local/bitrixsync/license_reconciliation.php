<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/bitrixsync:manage', $context);

$dealid = optional_param('dealid', 0, PARAM_INT);
$apply = optional_param('apply', 0, PARAM_BOOL);
if ($apply) {
    require_sesskey();
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/bitrixsync/license_reconciliation.php', ['dealid' => $dealid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('license_reconciliation_title', 'local_bitrixsync'));
$PAGE->set_heading(get_string('license_reconciliation_title', 'local_bitrixsync'));

$result = null;
$error = '';
if ($dealid > 0) {
    try {
        $result = (new \local_bitrixsync\services\license_reconciliation_service())->reconcile($dealid, (bool)$apply);
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('license_reconciliation_title', 'local_bitrixsync'), 2);
echo html_writer::div(get_string('license_reconciliation_desc', 'local_bitrixsync'), 'mb-3');

$formurl = new moodle_url('/local/bitrixsync/license_reconciliation.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $formurl->out(false), 'class' => 'form-inline mb-3']);
echo html_writer::label(get_string('license_reconciliation_dealid', 'local_bitrixsync'), 'id_dealid', false, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'number', 'min' => 1, 'name' => 'dealid', 'id' => 'id_dealid', 'value' => $dealid ?: '', 'class' => 'form-control mr-2', 'required' => 'required']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('license_reconciliation_check', 'local_bitrixsync')]);
echo html_writer::end_tag('form');

if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}

if ($result) {
    if (empty($result['iscorporate'])) {
        echo $OUTPUT->notification(get_string('license_reconciliation_notcorporate', 'local_bitrixsync'), 'warning');
    } else {
        echo html_writer::div(
            s((string)$result['companytitle']) . ' · Bitrix Company #' . (int)$result['bitrixcompanyid'] .
            ' · Deal #' . (int)$result['dealid'],
            'alert alert-info'
        );

        $summary = [
            get_string('license_reconciliation_checked', 'local_bitrixsync') => (int)$result['checked'],
            get_string('license_reconciliation_insync', 'local_bitrixsync') => (int)$result['insync'],
            get_string('license_reconciliation_drift', 'local_bitrixsync') => (int)$result['drift'],
            get_string('license_reconciliation_missing', 'local_bitrixsync') => (int)$result['missing'],
            get_string('license_reconciliation_unmapped', 'local_bitrixsync') => (int)$result['unmapped'],
            get_string('license_reconciliation_changed', 'local_bitrixsync') => (int)$result['changed'],
            get_string('license_reconciliation_failed', 'local_bitrixsync') => (int)$result['failed'],
        ];
        $summaryhtml = '';
        foreach ($summary as $label => $value) {
            $summaryhtml .= html_writer::span(s($label) . ': ' . (int)$value, 'badge badge-light mr-2 p-2');
        }
        echo html_writer::div($summaryhtml, 'mb-3');

        $table = new html_table();
        $table->head = [
            'Row', get_string('license_reconciliation_product', 'local_bitrixsync'), 'Course ID',
            get_string('license_reconciliation_expected', 'local_bitrixsync'),
            get_string('license_reconciliation_actual', 'local_bitrixsync'),
            get_string('license_reconciliation_status', 'local_bitrixsync'),
            get_string('license_reconciliation_action', 'local_bitrixsync'),
        ];
        foreach ($result['items'] as $item) {
            $table->data[] = [
                (int)$item['productrowid'],
                s((string)$item['productname']),
                (int)$item['courseid'] ?: '—',
                (int)$item['expectedseats'],
                $item['actualseats'] === null ? '—' : (int)$item['actualseats'],
                s((string)$item['status']) . ($item['error'] !== '' ? html_writer::div(s($item['error']), 'text-danger small') : ''),
                s((string)$item['action']),
            ];
        }
        echo html_writer::table($table);

        if (((int)$result['drift'] + (int)$result['missing']) > 0 && !$apply) {
            $applyurl = new moodle_url('/local/bitrixsync/license_reconciliation.php', [
                'dealid' => $dealid,
                'apply' => 1,
                'sesskey' => sesskey(),
            ]);
            echo $OUTPUT->single_button($applyurl, get_string('license_reconciliation_apply', 'local_bitrixsync'), 'post');
            echo html_writer::div(get_string('license_reconciliation_applyhelp', 'local_bitrixsync'), 'text-muted small mt-2');
        }
    }
}

echo $OUTPUT->footer();
