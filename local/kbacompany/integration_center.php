<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\integration_service;
use local_kbacompany\local\odoo_adapter_service;
use local_kbacompany\local\bitrix_adapter_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$status = optional_param('status', 'all', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$model = integration_service::scoped_mappings($uuid, (int)$USER->id, $status, $page);
$uuid = (string)$model['company']['uuid'];
$url = new moodle_url('/local/kbacompany/integration_center.php', ['uuid' => $uuid, 'status' => $status]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('bms_int_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'settings',
    get_string('bms_int_title', 'local_kbacompany'));

echo $OUTPUT->heading(get_string('bms_int_title', 'local_kbacompany'), 2);
echo html_writer::tag('p', get_string('bms_int_intro', 'local_kbacompany'), ['class' => 'text-muted']);
// Customer-facing product catalogue; unavailable adapters are informational only.
$catalogue = [
    ['name' => 'BMS ERP', 'status' => 'existing', 'description' => get_string('bms_catalog_erp', 'local_kbacompany')],
    ['name' => 'Битрикс24', 'status' => 'existing', 'description' => get_string('bms_catalog_bitrix', 'local_kbacompany')],
    ['name' => 'amoCRM', 'status' => 'planned', 'description' => get_string('bms_catalog_crm', 'local_kbacompany')],
    ['name' => 'Huntflow', 'status' => 'planned', 'description' => get_string('bms_catalog_huntflow', 'local_kbacompany')],
    ['name' => 'hh.ru', 'status' => 'planned', 'description' => get_string('bms_catalog_jobs', 'local_kbacompany')],
    ['name' => 'Авито', 'status' => 'planned', 'description' => get_string('bms_catalog_avito', 'local_kbacompany')],
];
echo html_writer::start_div('bms-integration-catalog');
foreach ($catalogue as $item) {
    $label = get_string($item['status'] === 'planned' ? 'bms_catalog_planned' : 'bms_catalog_configuration', 'local_kbacompany');
    echo html_writer::tag('article',
        html_writer::tag('h3', s($item['name']), ['class' => 'h5 mb-2']) .
        html_writer::tag('p', $item['description'], ['class' => 'text-muted small mb-2']) .
        html_writer::tag('span', $label, ['class' => 'badge badge-light']),
        ['class' => 'card card-body']);
}
echo html_writer::end_div();
// Technical implementation and diagnostic readback intentionally retain existing adapters.

$odooenvs = odoo_adapter_service::environments($uuid, (int)$USER->id);
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('bms_catalog_erp_title', 'local_kbacompany'), ['class' => 'h5']);
echo html_writer::tag('p', get_string('bms_catalog_erp_detail', 'local_kbacompany'), ['class' => 'text-muted']);
if (!$odooenvs) {
    echo html_writer::tag('p', get_string('bms_odoo_no_environment', 'local_kbacompany'));
} else {
    foreach ($odooenvs as $odooenv) {
        $odoo = odoo_adapter_service::readiness($uuid, (int)$USER->id, $odooenv);
        $summary = (object)$odoo['mapping_counts'];
        echo html_writer::start_div('border rounded p-2 mb-2');
        echo html_writer::tag('strong', s($odooenv) . ' — ' . get_string($odoo['ready'] ? 'bms_odoo_ready' : 'bms_odoo_blocked', 'local_kbacompany'));
        echo html_writer::tag('div', get_string('bms_odoo_mapping_summary', 'local_kbacompany', $summary), ['class' => 'small text-muted']);
        echo html_writer::end_div();
    }
}




echo html_writer::end_div();
echo html_writer::end_div();
$bitrixenvs = bitrix_adapter_service::environments($uuid, (int)$USER->id);
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::tag('h3', get_string('bms_bitrix_provider_title', 'local_kbacompany'), ['class' => 'h5']);
echo html_writer::tag('p', get_string('bms_bitrix_provider_intro', 'local_kbacompany'), ['class' => 'text-muted']);
if (!$bitrixenvs) {
    echo html_writer::tag('p', get_string('bms_bitrix_provider_no_environment', 'local_kbacompany'));
} else {
    foreach ($bitrixenvs as $bitrixenv) {
        $bitrix = bitrix_adapter_service::readiness($uuid, (int)$USER->id, $bitrixenv);
        $summary = (object)$bitrix['mapping_counts'];
        $statekey = $bitrix['ready'] ? 'bms_bitrix_provider_ready' : 'bms_bitrix_provider_blocked';
        echo html_writer::start_div('border rounded p-2 mb-2');
        echo html_writer::tag('strong', s($bitrixenv) . ' — ' . get_string($statekey, 'local_kbacompany'));
        echo html_writer::tag('div', get_string('bms_bitrix_provider_mapping_summary', 'local_kbacompany', $summary), ['class' => 'small text-muted']);
        echo html_writer::tag('div', get_string('bms_bitrix_provider_queue_summary', 'local_kbacompany', (object)$bitrix['legacy_command_outbox']), ['class' => 'small text-muted']);
        echo html_writer::end_div();
    }
}
echo html_writer::tag('p', get_string('bms_bitrix_provider_transport_note', 'local_kbacompany'), ['class' => 'small text-muted mb-0']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::start_div('d-flex flex-wrap mb-3');
echo html_writer::link(new moodle_url('/local/kbacompany/integration_mapping.php', ['uuid' => $uuid]),
    get_string('bms_int_add', 'local_kbacompany'), ['class' => 'btn btn-primary mr-2 mb-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/employee_diagnostics.php', ['uuid' => $uuid]),
    get_string('bms_int_legacy', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2 mb-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/bms_settings.php', ['uuid' => $uuid]),
    get_string('bms_settings_title', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mb-2']);
echo html_writer::end_div();
echo html_writer::tag('p', get_string('bms_int_parent_help', 'local_kbacompany'));
echo html_writer::start_tag('nav', ['class' => 'mb-3',
    'aria-label' => get_string('bms_int_filter', 'local_kbacompany')]);
foreach (['all', 'active', 'closed'] as $filter) {
    echo html_writer::link(new moodle_url('/local/kbacompany/integration_center.php',
        ['uuid' => $uuid, 'status' => $filter]), get_string('bms_int_status_' . $filter, 'local_kbacompany'),
        ['class' => 'btn btn-sm mr-2 mb-2 ' . ($status === $filter ? 'btn-primary' : 'btn-outline-secondary'),
            'aria-current' => $status === $filter ? 'page' : 'false']);
}
echo html_writer::end_tag('nav');
echo html_writer::tag('p', get_string('bms_int_total', 'local_kbacompany', $model['total']));
if (!$model['rows']) {
    echo $OUTPUT->notification(get_string('bms_int_empty', 'local_kbacompany'), 'info');
}
foreach ($model['rows'] as $row) {
    $detailurl = new moodle_url('/local/kbacompany/integration_mapping.php',
        ['uuid' => $uuid, 'mapping' => $row->mappinguuid]);
    echo html_writer::start_div('card mb-3');
    echo html_writer::start_div('card-body', ['style' => 'overflow-wrap:anywhere']);
    echo html_writer::tag('h3', html_writer::link($detailurl,
        s(($row->provider === 'odoo' ? 'BMS ERP' : 'Битрикс24') . ' / ' . $row->environment)), ['class' => 'h5']);
    echo html_writer::tag('p', s(get_string('bms_int_kind_' . $row->entitykind, 'local_kbacompany') .
        ' → ' . $row->externalmodel . ' / ' . $row->externalid));
    echo html_writer::tag('p', get_string('bms_int_status_' . ($row->active ? 'active' : 'closed'),
        'local_kbacompany'), ['class' => 'font-weight-bold']);
    echo html_writer::link($detailurl, get_string('bms_int_open', 'local_kbacompany'), ['class' => 'btn btn-outline-primary']);
    echo html_writer::end_div();
    echo html_writer::end_div();
}
echo $OUTPUT->paging_bar($model['total'], $page, $model['pagesize'], $url);
bms_page_shell_service::finish($shell);
