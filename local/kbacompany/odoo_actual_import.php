<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\integration_service;
use local_kbacompany\local\odoo_actual_staging_service;
use local_kbacompany\local\odoo_actual_import_service;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$mappinguuid = required_param('mapping', PARAM_RAW_TRIMMED);
$row = integration_service::scoped_mapping($uuid, (int)$USER->id, $mappinguuid);
$uuid = (string)$row->companyuuid;
if ((string)$row->provider !== 'odoo' || (string)$row->entitykind !== 'indicator' || empty($row->active)) {
    throw new moodle_exception('bms_odoo_import_mapping_invalid', 'local_kbacompany');
}
$environment = (string)$row->environment;
$statuuid = (string)$row->localref;
$period = optional_param('period', '', PARAM_RAW_TRIMMED);
$baseurl = (string)getenv('BMS_ODOO_BASE_URL');
$apikey = (string)getenv('BMS_ODOO_API_KEY');
$database = (string)getenv('BMS_ODOO_DATABASE');
$url = new moodle_url('/local/kbacompany/odoo_actual_import.php', ['uuid' => $uuid, 'mapping' => $mappinguuid]);
$backurl = new moodle_url('/local/kbacompany/integration_mapping.php', ['uuid' => $uuid, 'mapping' => $mappinguuid]);
$stage = null;
$error = '';
$result = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_sesskey();
        $period = required_param('period', PARAM_RAW_TRIMMED);
        $result = odoo_actual_import_service::confirm(
            $uuid, (int)$USER->id, $environment, $statuuid, $period,
            $baseurl, $apikey, $database,
            required_param('provenancehash', PARAM_RAW_TRIMMED),
            required_param('idempotencykey', PARAM_RAW_TRIMMED),
            required_param('candidate', PARAM_RAW_TRIMMED),
            required_param('canonicalrevision', PARAM_INT),
            optional_param('reason', '', PARAM_TEXT),
            required_param('confirmed', PARAM_BOOL)
        );
        $stage = $result['stage'];
    } else if ($period !== '') {
        $stage = odoo_actual_staging_service::stage($uuid, (int)$USER->id, $environment,
            $statuuid, $period, $baseurl, $apikey, $database);
    }
} catch (moodle_exception $e) {
    $error = $e->getMessage();
}

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('bms_odoo_import_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$shell = bms_page_shell_service::begin($uuid, (int)$USER->id, 'settings',
    get_string('bms_odoo_import_title', 'local_kbacompany'));

echo html_writer::link($backurl, '← ' . get_string('bms_int_detail', 'local_kbacompany'), ['class' => 'd-inline-block mb-3']);
echo $OUTPUT->heading(get_string('bms_odoo_import_title', 'local_kbacompany'), 2);
echo html_writer::tag('p', get_string('bms_odoo_import_intro', 'local_kbacompany'), ['class' => 'text-muted']);
if ($error !== '') {
    echo $OUTPUT->notification(s($error), 'error');
}
if ($result) {
    echo $OUTPUT->notification(get_string($result['written'] ? 'bms_odoo_import_written' : 'bms_odoo_import_noop',
        'local_kbacompany'), $result['written'] ? 'success' : 'info');
}

echo html_writer::start_div('card card-body mb-3');
echo html_writer::tag('div', get_string('bms_odoo_environment', 'local_kbacompany') . ': ' . s($environment));
echo html_writer::tag('div', get_string('bms_int_external_model', 'local_kbacompany') . ': ' . s($row->externalmodel));
echo html_writer::tag('div', get_string('bms_int_external_id', 'local_kbacompany') . ': ' . s($row->externalid));
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mapping', 'value' => $mappinguuid]);
echo html_writer::start_div('form-group');
echo html_writer::tag('label', get_string('bms_odoo_import_period', 'local_kbacompany'), ['for' => 'bms-odoo-period']);
echo html_writer::empty_tag('input', ['type' => 'date', 'id' => 'bms-odoo-period', 'name' => 'period',
    'value' => $period, 'required' => 'required', 'class' => 'form-control', 'style' => 'max-width:18rem']);
echo html_writer::end_div();
echo html_writer::tag('button', get_string('bms_odoo_import_read', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_tag('form');

if ($stage) {
    echo html_writer::start_div('card card-body mt-3');
    echo html_writer::tag('h3', get_string('bms_odoo_import_candidate', 'local_kbacompany'), ['class' => 'h5']);
    $candidate = $stage['candidate_value'];
    echo html_writer::tag('p', get_string('bms_odoo_import_candidate_value', 'local_kbacompany') . ': ' .
        s($candidate === null ? get_string('bms_missing_fact', 'local_kbacompany') : $candidate));
    echo html_writer::tag('p', get_string('bms_odoo_import_current_value', 'local_kbacompany') . ': ' .
        s($stage['canonical_fact']['current_value'] === null ? get_string('bms_missing_fact', 'local_kbacompany') : $stage['canonical_fact']['current_value']));
    echo html_writer::tag('p', get_string('bms_odoo_import_conflict', 'local_kbacompany') . ': ' .
        s($stage['canonical_fact']['conflict_state']));
    echo html_writer::tag('p', get_string('bms_odoo_import_source_time', 'local_kbacompany') . ': ' .
        s($stage['provenance']['source_timestamp']), ['class' => 'text-muted']);
    if ($candidate !== null && !$result) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
        foreach ([
            'uuid' => $uuid, 'mapping' => $mappinguuid, 'period' => $period, 'sesskey' => sesskey(),
            'provenancehash' => $stage['provenance_hash'], 'idempotencykey' => $stage['idempotency_key'],
            'candidate' => $candidate, 'canonicalrevision' => (int)$stage['canonical_fact']['current_revision'],
        ] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('bms_odoo_import_reason', 'local_kbacompany'), ['for' => 'bms-odoo-reason']);
        echo html_writer::tag('textarea', '', ['id' => 'bms-odoo-reason', 'name' => 'reason', 'rows' => 3,
            'class' => 'form-control', 'maxlength' => 1000]);
        echo html_writer::tag('small', get_string('bms_odoo_import_reason_help', 'local_kbacompany'), ['class' => 'form-text text-muted']);
        echo html_writer::end_div();
        echo html_writer::start_div('form-check mb-3');
        echo html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'bms-odoo-confirmed',
            'name' => 'confirmed', 'value' => 1, 'required' => 'required', 'class' => 'form-check-input']);
        echo html_writer::tag('label', get_string('bms_odoo_import_confirm_label', 'local_kbacompany'),
            ['for' => 'bms-odoo-confirmed', 'class' => 'form-check-label']);
        echo html_writer::end_div();
        echo html_writer::tag('button', get_string('bms_odoo_import_confirm', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-primary']);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();
}
bms_page_shell_service::finish($shell);
