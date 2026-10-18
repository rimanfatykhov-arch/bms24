<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\integration_service;
use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\form\integration_mapping_form;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$company = integration_service::mapping_context($uuid, (int)$USER->id);
$uuid = (string)$company['uuid'];
$mappinguuid = optional_param('mapping', '', PARAM_RAW_TRIMMED);
$listurl = new moodle_url('/local/kbacompany/integration_center.php', ['uuid' => $uuid]);
$url = new moodle_url('/local/kbacompany/integration_mapping.php', ['uuid' => $uuid]);
$preview = null;
$error = '';
$row = null;
$mform = null;
$targets = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $action = optional_param('action', '', PARAM_ALPHA);
    try {
        if ($action === 'confirm' && $mappinguuid === '') {
            $data = [];
            foreach (['provider', 'environment', 'externalmodel', 'entitykind', 'localref', 'externalid'] as $field) {
                $data[$field] = required_param($field, PARAM_RAW_TRIMMED);
            }
            $saved = integration_service::confirm_mapping($uuid, (int)$USER->id, $data,
                required_param('fingerprint', PARAM_RAW_TRIMMED), required_param('confirmed', PARAM_BOOL));
            redirect(new moodle_url('/local/kbacompany/integration_mapping.php',
                ['uuid' => $uuid, 'mapping' => $saved->mappinguuid]),
                get_string('bms_int_saved', 'local_kbacompany'));
        } else if ($action === 'close' && $mappinguuid !== '') {
            integration_service::close_mapping($uuid, (int)$USER->id, $mappinguuid,
                required_param('confirmed', PARAM_BOOL));
            redirect($listurl, get_string('bms_int_closed', 'local_kbacompany'));
        } else if ($action !== '') {
            throw new moodle_exception('bms_int_invalid', 'local_kbacompany');
        }
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

if ($mappinguuid !== '') {
    $row = integration_service::scoped_mapping($uuid, (int)$USER->id, $mappinguuid);
    $url->param('mapping', $mappinguuid);
} else {
    $targets = integration_service::mapping_targets($uuid, (int)$USER->id);
    $mform = new integration_mapping_form($url, ['companyuuid' => $uuid, 'targets' => $targets['options']]);
    if ($mform->is_cancelled()) {
        redirect($listurl);
    }
    if ($data = $mform->get_data()) {
        try {
            $target = trim((string)$data->manualtarget) !== '' ? trim((string)$data->manualtarget) : (string)$data->target;
            $parts = explode(':', $target, 2);
            if (count($parts) !== 2) {
                throw new moodle_exception('bms_int_invalid', 'local_kbacompany');
            }
            $preview = integration_service::preview_mapping($uuid, (int)$USER->id, [
                'provider' => (string)$data->provider, 'environment' => (string)$data->environment,
                'externalmodel' => (string)$data->externalmodel, 'externalid' => (string)$data->externalid,
                'entitykind' => $parts[0], 'localref' => $parts[1],
            ]);
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
    }
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string($row ? 'bms_int_detail' : 'bms_int_add', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'settings',
    get_string('bms_int_title', 'local_kbacompany'));
echo html_writer::link($listurl, '← ' . get_string('bms_int_title', 'local_kbacompany'), ['class' => 'd-inline-block mb-3']);
echo $OUTPUT->heading(get_string($row ? 'bms_int_detail' : 'bms_int_add', 'local_kbacompany'), 2);
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}
echo $OUTPUT->notification(get_string('bms_int_no_transport', 'local_kbacompany'), 'info');
if ($row || $preview) {
    $values = $row ? (array)$row : $preview['values'];
    echo html_writer::start_div('card card-body mb-3', ['style' => 'overflow-wrap:anywhere']);
    if ($preview) {
        echo html_writer::tag('h3', s($preview['targetname']), ['class' => 'h5']);
        echo html_writer::tag('p', get_string('bms_int_preview_help', 'local_kbacompany'));
    }
    echo html_writer::start_tag('dl');
    foreach (['provider' => 'bms_int_provider', 'environment' => 'bms_int_environment',
            'entitykind' => 'bms_int_kind', 'localref' => 'bms_int_local_id',
            'externalmodel' => 'bms_int_external_model', 'externalid' => 'bms_int_external_id'] as $field => $label) {
        echo html_writer::tag('dt', get_string($label, 'local_kbacompany'));
        $value = $field === 'entitykind' ? get_string('bms_int_kind_' . $values[$field], 'local_kbacompany') : $values[$field];
        if ($field === 'provider') {
            $value = $value === 'odoo' ? 'BMS ERP' : ($value === 'bitrix24' ? 'Битрикс24' : $value);
        }
        echo html_writer::tag('dd', s($value));
    }
    if ($row) {
        echo html_writer::tag('dt', get_string('bms_int_mapping_uuid', 'local_kbacompany'));
        echo html_writer::tag('dd', s($row->mappinguuid));
        echo html_writer::tag('dt', get_string('bms_int_status', 'local_kbacompany'));
        echo html_writer::tag('dd', get_string('bms_int_status_' . ($row->active ? 'active' : 'closed'), 'local_kbacompany'));
        echo html_writer::tag('dt', get_string('bms_int_created', 'local_kbacompany'));
        echo html_writer::tag('dd', userdate($row->timecreated));
        echo html_writer::tag('dt', get_string('bms_int_modified', 'local_kbacompany'));
        echo html_writer::tag('dd', userdate($row->timemodified));
    }
    echo html_writer::end_tag('dl');
    echo html_writer::end_div();
    if ($preview || !empty($row->active)) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        $hidden = ['uuid' => $uuid, 'sesskey' => sesskey(), 'action' => $preview ? 'confirm' : 'close'];
        if ($preview) {
            $hidden += $preview['values'] + ['fingerprint' => $preview['fingerprint']];
        } else {
            $hidden['mapping'] = $mappinguuid;
        }
        foreach ($hidden as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::start_div('form-check mb-3');
        echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'bms-int-confirmed',
            'name' => 'confirmed', 'value' => 1, 'required' => 'required', 'class' => 'form-check-input']);
        echo html_writer::tag('label', get_string($preview ? 'bms_int_confirm_label' : 'bms_int_close_label',
            'local_kbacompany'), ['for' => 'bms-int-confirmed', 'class' => 'form-check-label']);
        echo html_writer::end_div();
        echo html_writer::tag('button', get_string($preview ? 'bms_int_save' : 'bms_int_close', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn ' . ($preview ? 'btn-primary' : 'btn-outline-danger') . ' mr-2 mb-2']);
        echo html_writer::link($preview ? $url : $listurl, get_string('cancel'), ['class' => 'btn btn-secondary mb-2']);
        echo html_writer::end_tag('form');
    }
    if ($row) {
        if (!empty($row->active) && (string)$row->provider === 'odoo' && (string)$row->entitykind === 'indicator') {
            echo html_writer::link(new moodle_url('/local/kbacompany/odoo_actual_import.php', [
                'uuid' => $uuid, 'mapping' => $mappinguuid,
            ]), get_string('bms_odoo_import_open', 'local_kbacompany'), ['class' => 'btn btn-outline-primary mr-2 mb-2']);
        }
        echo html_writer::tag('p', get_string('bms_int_history_help', 'local_kbacompany'), ['class' => 'text-muted mt-3']);
    }
} else {
    if ($targets['truncated']) {
        echo $OUTPUT->notification(get_string('bms_int_targets_truncated', 'local_kbacompany'), 'warning');
    }
    $mform->display();
}
bms_page_shell_service::finish($shell);
