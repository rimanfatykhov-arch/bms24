<?php
require(__DIR__ . '/../../config.php');

require_login();
require_capability('local/bitrixsync:manage', context_system::instance());

$companyid = optional_param('companyid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/bitrixsync/bms_diagnostics.php', $companyid > 0 ? ['companyid' => $companyid] : []));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('bms_diagnostics_title', 'local_bitrixsync'));

$service = new \local_bitrixsync\services\bms_bridge_diagnostic_service();
$overview = $service->overview();
$inspection = null;
$inspectionerror = '';
if ($companyid > 0) {
    try {
        $inspection = $service->inspect_company($companyid, (int)$USER->id);
    } catch (\Throwable $e) {
        $inspectionerror = $e->getMessage();
    }
}

function local_bitrixsync_bmsdiag_badge($value, string $ok = 'OK', string $bad = 'Нет'): string {
    $yes = (bool)$value;
    return html_writer::tag('span', $yes ? $ok : $bad, [
        'class' => $yes ? 'badge badge-success' : 'badge badge-warning',
    ]);
}

function local_bitrixsync_bmsdiag_time($value): string {
    $timestamp = (int)$value;
    return $timestamp > 0 ? userdate($timestamp) : '—';
}

function local_bitrixsync_bmsdiag_json($value): string {
    if (empty($value)) {
        return '—';
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return '—';
    }
    return html_writer::tag('pre', s($json), ['class' => 'mb-0', 'style' => 'white-space:pre-wrap;max-height:320px;overflow:auto']);
}

function local_bitrixsync_bmsdiag_table(array $rows): string {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable table table-striped';
    $table->head = [get_string('bms_diagnostics_check', 'local_bitrixsync'), get_string('bms_diagnostics_value', 'local_bitrixsync')];
    foreach ($rows as $label => $value) {
        $table->data[] = [$label, $value];
    }
    return html_writer::table($table);
}

echo $OUTPUT->header();
echo \local_bitrixsync\ui\admin_shell::render('diagnostics');
echo html_writer::tag('h2', get_string('bms_diagnostics_title', 'local_bitrixsync'));
echo html_writer::tag('p', get_string('bms_diagnostics_intro', 'local_bitrixsync'), ['class' => 'text-muted']);

$field = $overview['bms_client_field'];
$corporatecontract = $overview['corporate_contract'];
$corporatestatus = $overview['corporate_status'];
$overviewrows = [
    get_string('bms_diagnostics_bridge_enabled', 'local_bitrixsync') => local_bitrixsync_bmsdiag_badge($overview['bridge_enabled'], get_string('bms_diagnostics_enabled', 'local_bitrixsync'), get_string('bms_diagnostics_disabled', 'local_bitrixsync')),
    get_string('bms_diagnostics_company_events', 'local_bitrixsync') => local_bitrixsync_bmsdiag_badge($overview['company_webhook_enabled'], get_string('bms_diagnostics_enabled', 'local_bitrixsync'), get_string('bms_diagnostics_disabled', 'local_bitrixsync')),
    get_string('bms_diagnostics_bitrix_rest', 'local_bitrixsync') => local_bitrixsync_bmsdiag_badge($overview['bitrix_configured'], get_string('configured', 'local_bitrixsync'), get_string('bms_diagnostics_not_configured', 'local_bitrixsync')),
    get_string('bms_diagnostics_bms_api', 'local_bitrixsync') => local_bitrixsync_bmsdiag_badge($overview['bms_available']) . ' ' . s((string)$overview['bms_version']),
    get_string('bms_diagnostics_corporate_api', 'local_bitrixsync') => local_bitrixsync_bmsdiag_badge($overview['corporate_available'] && $overview['corporate_compatible']) . ' API ' . s((string)($corporatecontract['api_release'] ?? '—')),
    get_string('bms_diagnostics_corporate_writesafe', 'local_bitrixsync') => local_bitrixsync_bmsdiag_badge(!empty($corporatestatus['writesafe'])),
    get_string('bms_diagnostics_employee_sync', 'local_bitrixsync') => local_bitrixsync_bmsdiag_badge($overview['corporate_employee_sync_enabled'], get_string('bms_diagnostics_enabled', 'local_bitrixsync'), get_string('bms_diagnostics_disabled', 'local_bitrixsync')),
    get_string('bms_diagnostics_bms_field', 'local_bitrixsync') => !empty($field['ready'])
        ? local_bitrixsync_bmsdiag_badge(true) . ' ' . s((string)($field['field_name'] ?? '')) . ' / ' . s((string)($field['xml_id'] ?? 'KBA_CONNECT_BMS_CLIENT'))
        : local_bitrixsync_bmsdiag_badge(false) . ' ' . s((string)($field['error'] ?? '')),
];

echo html_writer::tag('h3', get_string('bms_diagnostics_overview', 'local_bitrixsync'));
echo local_bitrixsync_bmsdiag_table($overviewrows);

$queue = $overview['queue'];
$queuehtml = [];
foreach (['pending', 'processing', 'completed', 'failed'] as $state) {
    $queuehtml[] = strtoupper($state) . ': ' . (int)($queue[$state] ?? 0);
}
echo html_writer::div(implode(' &nbsp; | &nbsp; ', $queuehtml), 'alert alert-light border');

echo html_writer::tag('h3', get_string('bms_diagnostics_company_lookup', 'local_bitrixsync'));
echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/bitrixsync/bms_diagnostics.php'))->out(false), 'class' => 'form-inline mb-4']);
echo html_writer::label(get_string('bms_diagnostics_company_id', 'local_bitrixsync'), 'bms-companyid', false, ['class' => 'mr-2']);
echo html_writer::empty_tag('input', [
    'type' => 'number', 'min' => 1, 'name' => 'companyid', 'id' => 'bms-companyid',
    'value' => $companyid > 0 ? $companyid : '', 'class' => 'form-control mr-2', 'required' => 'required',
]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('bms_diagnostics_check_company', 'local_bitrixsync'), 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if ($inspectionerror !== '') {
    echo $OUTPUT->notification($inspectionerror, 'notifyproblem');
}

if ($inspection) {
    $bitrix = $inspection['bitrix'];
    $company = $bitrix['company'] ?? [];
    $latestqueue = $inspection['latest_queue'];
    $bmsmap = $inspection['bms_mapping'];
    $corporatemap = $inspection['corporate_mapping'];
    $bmsdiag = $inspection['bms_diagnostics'];

    echo html_writer::tag('h3', get_string('bms_diagnostics_trace', 'local_bitrixsync', $companyid));

    $conclusion = '';
    $conclusionclass = 'alert alert-info';
    if (empty($bitrix['loaded'])) {
        $conclusion = get_string('bms_diagnostics_conclusion_bitrix_missing', 'local_bitrixsync');
        $conclusionclass = 'alert alert-danger';
    } else if (empty($inspection['field']['ready'])) {
        $conclusion = get_string('bms_diagnostics_conclusion_field_missing', 'local_bitrixsync');
        $conclusionclass = 'alert alert-warning';
    } else if ($inspection['bms_client'] === false) {
        $conclusion = get_string('bms_diagnostics_conclusion_not_client', 'local_bitrixsync');
        $conclusionclass = 'alert alert-warning';
    } else if (!$latestqueue) {
        $conclusion = get_string('bms_diagnostics_conclusion_no_event', 'local_bitrixsync');
        $conclusionclass = 'alert alert-warning';
    } else if ((string)$latestqueue->status === 'failed') {
        $conclusion = get_string('bms_diagnostics_conclusion_queue_failed', 'local_bitrixsync', (string)$latestqueue->error);
        $conclusionclass = 'alert alert-danger';
    } else if (in_array((string)$latestqueue->status, ['pending', 'processing'], true)) {
        $conclusion = get_string('bms_diagnostics_conclusion_queue_waiting', 'local_bitrixsync', (string)$latestqueue->status);
        $conclusionclass = 'alert alert-info';
    } else if (!$bmsmap) {
        $conclusion = get_string('bms_diagnostics_conclusion_mapping_missing', 'local_bitrixsync');
        $conclusionclass = 'alert alert-warning';
    } else {
        $conclusion = get_string('bms_diagnostics_conclusion_synced', 'local_bitrixsync');
        $conclusionclass = 'alert alert-success';
    }
    echo html_writer::div(s($conclusion), $conclusionclass);

    $tracerows = [
        'Bitrix Company ID' => (string)$companyid,
        get_string('bms_diagnostics_bitrix_company', 'local_bitrixsync') => !empty($bitrix['loaded'])
            ? local_bitrixsync_bmsdiag_badge(true) . ' ' . s((string)($company['TITLE'] ?? ''))
            : local_bitrixsync_bmsdiag_badge(false) . ' ' . s((string)($bitrix['error'] ?? '')),
        get_string('bms_diagnostics_bms_flag', 'local_bitrixsync') => $inspection['bms_client'] === null
            ? '—'
            : local_bitrixsync_bmsdiag_badge((bool)$inspection['bms_client'], get_string('yes'), get_string('no')) .
                ' ' . s(is_scalar($bitrix['bms_client_raw'] ?? null) ? (string)$bitrix['bms_client_raw'] : json_encode($bitrix['bms_client_raw'] ?? null)),
        get_string('bms_diagnostics_latest_event', 'local_bitrixsync') => $latestqueue
            ? local_bitrixsync_bmsdiag_badge(
                (string)$latestqueue->status === 'completed',
                strtoupper((string)$latestqueue->status),
                strtoupper((string)$latestqueue->status)
            ) . ' #' . (int)$latestqueue->id . ' / attempts=' . (int)$latestqueue->attempts .
                ' / ' . local_bitrixsync_bmsdiag_time($latestqueue->timemodified)
            : local_bitrixsync_bmsdiag_badge(false, 'Есть', 'Не найдено'),
        get_string('bms_diagnostics_bms_mapping', 'local_bitrixsync') => $bmsmap
            ? local_bitrixsync_bmsdiag_badge(true) . ' UUID ' . s((string)$bmsmap->localref)
            : local_bitrixsync_bmsdiag_badge(false, 'Есть', 'Не найдено'),
        get_string('bms_diagnostics_corporate_mapping', 'local_bitrixsync') => $corporatemap
            ? 'Corporate Company ID: ' . (int)$corporatemap->corporate_company_id .
                ' / status: ' . s((string)$corporatemap->status) .
                ' / last sync: ' . local_bitrixsync_bmsdiag_time($corporatemap->last_sync)
            : '—',
        get_string('bms_diagnostics_contacts', 'local_bitrixsync') => (string)(int)$inspection['contacts_total'] .
            (!empty($inspection['contacts_truncated']) ? ' +' : ''),
    ];
    if (!empty($inspection['bms_error'])) {
        $tracerows[get_string('bms_diagnostics_bms_error', 'local_bitrixsync')] = s((string)$inspection['bms_error']);
    }
    echo local_bitrixsync_bmsdiag_table($tracerows);

    if ($latestqueue) {
        echo html_writer::tag('h4', get_string('bms_diagnostics_latest_queue_result', 'local_bitrixsync'));
        if (!empty($latestqueue->error)) {
            echo $OUTPUT->notification(s((string)$latestqueue->error), 'notifyproblem');
        }
        echo local_bitrixsync_bmsdiag_json($latestqueue->decodedpayload ?? []);
    }

    if ($bmsdiag) {
        echo html_writer::tag('h4', get_string('bms_diagnostics_bms_state', 'local_bitrixsync'));
        $registry = $bmsdiag['registry'] ?? null;
        $summary = $bmsdiag['summary'] ?? [];
        echo local_bitrixsync_bmsdiag_table([
            'BMS Company UUID' => s((string)($bmsmap->localref ?? '')),
            get_string('bms_diagnostics_registry_active', 'local_bitrixsync') => $registry ? local_bitrixsync_bmsdiag_badge(!empty($registry->active)) : '—',
            get_string('bms_diagnostics_registry_bmsclient', 'local_bitrixsync') => $registry ? local_bitrixsync_bmsdiag_badge(!empty($registry->bmsclient), get_string('yes'), get_string('no')) : '—',
            get_string('bms_diagnostics_outbox', 'local_bitrixsync') => 'pending=' . (int)($summary['pending'] ?? 0) .
                ', error=' . (int)($summary['error'] ?? 0) . ', done=' . (int)($summary['done'] ?? 0),
        ]);
    }

    if (!empty($inspection['queue_items'])) {
        echo html_writer::tag('h4', get_string('bms_diagnostics_queue_history', 'local_bitrixsync'));
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table table-striped';
        $table->head = ['ID', get_string('status'), get_string('bms_diagnostics_attempts', 'local_bitrixsync'), get_string('bms_diagnostics_modified', 'local_bitrixsync'), get_string('error')];
        foreach ($inspection['queue_items'] as $item) {
            $table->data[] = [
                (int)$item->id,
                s((string)$item->status),
                (int)$item->attempts,
                local_bitrixsync_bmsdiag_time($item->timemodified),
                s((string)$item->error),
            ];
        }
        echo html_writer::table($table);
    }

    if (!empty($inspection['contacts'])) {
        echo html_writer::tag('h4', get_string('bms_diagnostics_contacts_title', 'local_bitrixsync'));
        $table = new html_table();
        $table->attributes['class'] = 'generaltable table table-striped';
        $table->head = ['Bitrix Contact ID', get_string('fullname'), 'Email', 'Moodle User ID', 'KBA Corporate'];
        foreach ($inspection['contacts'] as $contact) {
            $fullname = trim((string)$contact['name'] . ' ' . (string)$contact['lastname']);
            $table->data[] = [
                (int)$contact['id'], s($fullname), s((string)$contact['email']),
                (int)$contact['userid'] > 0 ? (int)$contact['userid'] : '—',
                local_bitrixsync_bmsdiag_badge(!empty($contact['corporate_employee'])),
            ];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
