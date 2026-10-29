<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\financial_model_service;
use local_kbacompany\local\financial_model_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$item = optional_param('item', '', PARAM_RAW_TRIMMED);
$data = financial_model_service::context($uuid, (int)$USER->id);
if (!$data['can_manage']) {
    throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
}
$editing = null;
if ($item !== '') {
    $recordcontext = financial_model_service::center($uuid, (int)$USER->id, $item);
    $editing = $recordcontext['center'];
    if ($editing['status'] === 'archived') {
        throw new moodle_exception('financialmodel_state', 'local_kbacompany');
    }
}

$center = $editing ?: [
    'uuid' => '', 'revision' => 0, 'code' => '', 'name' => '', 'centertype' => 'profit',
    'targettype' => 'orgunit', 'targetuuid' => '', 'ownerpositionuuid' => '', 'mainstatuuid' => '',
    'resultdescription' => '', 'authorityrule' => '', 'reviewperiod' => 'monthly',
];
$values = $center;
$values['targetcompound'] = $center['targetuuid'] !== '' ? $center['targettype'] . ':' . $center['targetuuid'] : '';
foreach (['name', 'centertype', 'targetcompound', 'ownerpositionuuid', 'mainstatuuid', 'resultdescription',
        'authorityrule', 'reviewperiod'] as $field) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $paramtype = in_array($field, ['centertype', 'reviewperiod'], true) ? PARAM_ALPHA : PARAM_RAW_TRIMMED;
        $values[$field] = optional_param($field, (string)$values[$field], $paramtype);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $parts = explode(':', (string)$values['targetcompound'], 2);
    if (count($parts) !== 2) {
        throw new moodle_exception('financialmodel_invalid', 'local_kbacompany');
    }
    $saveduuid = financial_model_service::save_center($uuid, (int)$USER->id, [
        'name' => $values['name'],
        'centertype' => $values['centertype'],
        'targettype' => $parts[0],
        'targetuuid' => $parts[1],
        'ownerpositionuuid' => $values['ownerpositionuuid'],
        'mainstatuuid' => $values['mainstatuuid'],
        'resultdescription' => $values['resultdescription'],
        'authorityrule' => $values['authorityrule'],
        'reviewperiod' => $values['reviewperiod'],
    ], $item, $editing ? (int)$editing['revision'] : 0);
    redirect(new moodle_url('/local/kbacompany/financial_center_view.php', ['uuid' => $uuid, 'item' => $saveduuid]),
        get_string('financialmodel_saved', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/financial_center_edit.php', ['uuid' => $uuid, 'item' => $item]));
$PAGE->set_title(get_string($editing ? 'financialcrud_edit_center' : 'financialcrud_create_center', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$centeropts = [];
foreach ($data['center_types'] as $type) {
    $centeropts[$type] = get_string('financialmodel_centertype_' . $type, 'local_kbacompany');
}
$targetopts = ['' => get_string('financialmodel_none', 'local_kbacompany')];
$targetopts['company:' . $uuid] = get_string('financialmodel_targettype_company', 'local_kbacompany') . ' — ' . $data['company']['name'];
foreach ($data['nodes'] as $row) {
    $targetopts['orgunit:' . $row['uuid']] = get_string('financialmodel_targettype_orgunit', 'local_kbacompany') .
        ' — ' . $row['code'] . ' — ' . $row['name'];
}
foreach ($data['positions'] as $row) {
    $targetopts['position:' . $row['uuid']] = get_string('financialmodel_targettype_position', 'local_kbacompany') .
        ' — ' . $row['code'] . ' — ' . $row['name'];
}
foreach ($data['products'] as $row) {
    $targetopts['product:' . $row['uuid']] = get_string('financialmodel_targettype_product', 'local_kbacompany') .
        ' — ' . $row['code'] . ' — ' . $row['name'];
}
$positionopts = ['' => get_string('financialmodel_none', 'local_kbacompany')];
foreach ($data['positions'] as $row) {
    $positionopts[$row['uuid']] = $row['code'] . ' — ' . $row['name'];
}
$indicatoropts = ['' => get_string('financialmodel_none', 'local_kbacompany')];
foreach ($data['indicators'] as $row) {
    $indicatoropts[$row['uuid']] = $row['code'] . ' — ' . $row['name'];
}
$reviewopts = [];
foreach ($data['review_periods'] as $period) {
    $reviewopts[$period] = get_string('financialmodel_reviewperiod_' . $period, 'local_kbacompany');
}

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'financialmodel', get_string('financialmodel_title', 'local_kbacompany'));
$backurl = $editing
    ? new moodle_url('/local/kbacompany/financial_center_view.php', ['uuid' => $uuid, 'item' => $item])
    : new moodle_url('/local/kbacompany/financial_model.php', ['uuid' => $uuid, 'tab' => 'centers']);
echo html_writer::div(html_writer::link($backurl, '← ' . get_string('financialcrud_back', 'local_kbacompany')), 'mb-3');
echo $OUTPUT->heading(get_string($editing ? 'financialcrud_edit_center' : 'financialcrud_create_center', 'local_kbacompany'), 2, 'mb-1');
echo html_writer::tag('p', get_string('financialcrud_center_edit_intro', 'local_kbacompany'), ['class' => 'text-muted mb-3']);

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-record-editview']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
foreach (financial_model_ui_service::center_form_schema() as $section) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::div(get_string(financial_model_ui_service::section_label_key($section['key']), 'local_kbacompany'),
        'card-header font-weight-bold');
    echo html_writer::start_div('card-body');
    if ($section['key'] === 'main') {
        echo html_writer::start_div('row');
        echo html_writer::start_div('col-md-4');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_code', 'local_kbacompany'));
        echo html_writer::div($editing ? s($editing['code']) : get_string('financialcrud_stablecode_auto', 'local_kbacompany'),
            'form-control bg-light text-muted');
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::start_div('col-md-8');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_name', 'local_kbacompany') . ' *', ['for' => 'cfo-name']);
        echo html_writer::empty_tag('input', ['id' => 'cfo-name', 'name' => 'name', 'type' => 'text', 'class' => 'form-control',
            'value' => $values['name'], 'required' => 'required']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::start_div('col-md-6');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_centertype', 'local_kbacompany'), ['for' => 'cfo-type']);
        echo html_writer::select($centeropts, 'centertype', $values['centertype'], false,
            ['id' => 'cfo-type', 'class' => 'form-control', 'required' => 'required']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::start_div('col-md-6');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialcrud_target_label', 'local_kbacompany'), ['for' => 'cfo-target']);
        echo html_writer::select($targetopts, 'targetcompound', $values['targetcompound'], false,
            ['id' => 'cfo-target', 'class' => 'form-control', 'required' => 'required']);
        echo html_writer::tag('small', get_string('financialcrud_target_help', 'local_kbacompany'), ['class' => 'form-text text-muted']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::end_div();
    } else if ($section['key'] === 'responsibility') {
        echo html_writer::start_div('row');
        echo html_writer::start_div('col-md-6');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_ownerposition', 'local_kbacompany'), ['for' => 'cfo-owner']);
        echo html_writer::select($positionopts, 'ownerpositionuuid', $values['ownerpositionuuid'], false,
            ['id' => 'cfo-owner', 'class' => 'form-control']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::start_div('col-md-6');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialcrud_indicator_label', 'local_kbacompany'), ['for' => 'cfo-indicator']);
        echo html_writer::select($indicatoropts, 'mainstatuuid', $values['mainstatuuid'], false,
            ['id' => 'cfo-indicator', 'class' => 'form-control']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::start_div('col-md-6');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_reviewperiod', 'local_kbacompany'), ['for' => 'cfo-review']);
        echo html_writer::select($reviewopts, 'reviewperiod', $values['reviewperiod'], false,
            ['id' => 'cfo-review', 'class' => 'form-control', 'required' => 'required']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::end_div();
    } else {
        foreach ([['resultdescription', 'financialmodel_resultdescription', 5], ['authorityrule', 'financialmodel_authorityrule', 5]] as $definition) {
            echo html_writer::start_div('form-group');
            echo html_writer::tag('label', get_string($definition[1], 'local_kbacompany'), ['for' => 'cfo-' . $definition[0]]);
            echo html_writer::tag('textarea', s($values[$definition[0]]), ['id' => 'cfo-' . $definition[0], 'name' => $definition[0],
                'rows' => $definition[2], 'class' => 'form-control']);
            echo html_writer::end_div();
        }
    }
    echo html_writer::end_div(); echo html_writer::end_div();
}

echo html_writer::start_div('d-flex justify-content-end mt-3');
echo html_writer::link($backurl, get_string('cancel'), ['class' => 'btn btn-secondary mr-2']);
echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

\local_kbacompany\local\bms_page_shell_service::finish($shell);
