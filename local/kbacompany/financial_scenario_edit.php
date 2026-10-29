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
    $recordcontext = financial_model_service::scenario($uuid, (int)$USER->id, $item);
    $editing = $recordcontext['scenario'];
    if ($editing['status'] === 'archived') {
        throw new moodle_exception('financialmodel_state', 'local_kbacompany');
    }
}

$scenario = $editing ?: [
    'uuid' => '', 'revision' => 0, 'code' => '', 'name' => '', 'scenariotype' => 'base',
    'periodstart' => date('Y-01-01'), 'periodend' => date('Y-12-31'), 'currency' => 'RUB',
    'description' => '', 'assumptions' => '', 'strategyuuid' => '', 'businessmodeluuid' => '',
];
$values = $scenario;
foreach (['name', 'scenariotype', 'periodstart', 'periodend', 'currency', 'description', 'assumptions',
        'strategyuuid', 'businessmodeluuid'] as $field) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $paramtype = in_array($field, ['scenariotype', 'currency'], true) ? PARAM_ALPHA : PARAM_RAW_TRIMMED;
        $values[$field] = optional_param($field, (string)$values[$field], $paramtype);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $saveduuid = financial_model_service::save_scenario($uuid, (int)$USER->id, [
        'name' => $values['name'],
        'scenariotype' => $values['scenariotype'],
        'periodstart' => $values['periodstart'],
        'periodend' => $values['periodend'],
        'currency' => $values['currency'],
        'description' => $values['description'],
        'assumptions' => $values['assumptions'],
        'strategyuuid' => $values['strategyuuid'],
        'businessmodeluuid' => $values['businessmodeluuid'],
    ], $item, $editing ? (int)$editing['revision'] : 0);
    redirect(new moodle_url('/local/kbacompany/financial_scenario_view.php', ['uuid' => $uuid, 'item' => $saveduuid]),
        get_string('financialmodel_saved', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/financial_scenario_edit.php', ['uuid' => $uuid, 'item' => $item]));
$PAGE->set_title(get_string($editing ? 'financialcrud_edit_scenario' : 'financialcrud_create_scenario', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$scenarioopts = [];
foreach ($data['scenario_types'] as $type) {
    $scenarioopts[$type] = get_string('financialmodel_scenariotype_' . $type, 'local_kbacompany');
}
$strategyopts = ['' => get_string('financialmodel_none', 'local_kbacompany')];
foreach ($data['strategies'] as $row) {
    $strategyopts[$row['uuid']] = $row['code'] . ' — ' . $row['name'] . ' · v' . $row['revision'];
}
$modelopts = ['' => get_string('financialmodel_none', 'local_kbacompany')];
foreach ($data['business_models'] as $row) {
    $label = (!empty($row['code']) ? $row['code'] . ' — ' : '') . $row['name'] . ' · v' . $row['revision'];
    $modelopts[$row['uuid']] = $label;
}

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'financialmodel', get_string('financialmodel_title', 'local_kbacompany'));
$backurl = $editing
    ? new moodle_url('/local/kbacompany/financial_scenario_view.php', ['uuid' => $uuid, 'item' => $item])
    : new moodle_url('/local/kbacompany/financial_model.php', ['uuid' => $uuid, 'tab' => 'scenarios']);
echo html_writer::div(html_writer::link($backurl, '← ' . get_string('financialcrud_back', 'local_kbacompany')), 'mb-3');
echo $OUTPUT->heading(get_string($editing ? 'financialcrud_edit_scenario' : 'financialcrud_create_scenario', 'local_kbacompany'), 2, 'mb-1');
echo html_writer::tag('p', get_string('financialcrud_scenario_edit_intro', 'local_kbacompany'), ['class' => 'text-muted mb-3']);

echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-record-editview']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

foreach (financial_model_ui_service::scenario_form_schema() as $section) {
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
        echo html_writer::tag('label', get_string('financialmodel_name', 'local_kbacompany') . ' *', ['for' => 'fin-name']);
        echo html_writer::empty_tag('input', ['id' => 'fin-name', 'name' => 'name', 'type' => 'text', 'class' => 'form-control',
            'value' => $values['name'], 'required' => 'required']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::start_div('col-md-4');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_scenariotype', 'local_kbacompany'), ['for' => 'fin-type']);
        echo html_writer::select($scenarioopts, 'scenariotype', $values['scenariotype'], false,
            ['id' => 'fin-type', 'class' => 'form-control', 'required' => 'required']);
        echo html_writer::end_div(); echo html_writer::end_div();
        foreach ([['periodstart', 'financialmodel_periodstart'], ['periodend', 'financialmodel_periodend']] as $definition) {
            echo html_writer::start_div('col-md-3');
            echo html_writer::start_div('form-group');
            echo html_writer::tag('label', get_string($definition[1], 'local_kbacompany'), ['for' => 'fin-' . $definition[0]]);
            echo html_writer::empty_tag('input', ['id' => 'fin-' . $definition[0], 'name' => $definition[0], 'type' => 'date',
                'class' => 'form-control', 'value' => $values[$definition[0]], 'required' => 'required']);
            echo html_writer::end_div(); echo html_writer::end_div();
        }
        echo html_writer::start_div('col-md-2');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_currency', 'local_kbacompany'), ['for' => 'fin-currency']);
        echo html_writer::empty_tag('input', ['id' => 'fin-currency', 'name' => 'currency', 'type' => 'text', 'maxlength' => 3,
            'class' => 'form-control text-uppercase', 'value' => $values['currency'], 'required' => 'required']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::end_div();
    } else if ($section['key'] === 'sources') {
        echo html_writer::tag('p', get_string('financialcrud_sources_help', 'local_kbacompany'), ['class' => 'text-muted small']);
        echo html_writer::start_div('row');
        echo html_writer::start_div('col-md-6');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_strategy', 'local_kbacompany'), ['for' => 'fin-strategy']);
        echo html_writer::select($strategyopts, 'strategyuuid', $values['strategyuuid'], false,
            ['id' => 'fin-strategy', 'class' => 'form-control']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::start_div('col-md-6');
        echo html_writer::start_div('form-group');
        echo html_writer::tag('label', get_string('financialmodel_businessmodel', 'local_kbacompany'), ['for' => 'fin-bmc']);
        echo html_writer::select($modelopts, 'businessmodeluuid', $values['businessmodeluuid'], false,
            ['id' => 'fin-bmc', 'class' => 'form-control']);
        echo html_writer::end_div(); echo html_writer::end_div();
        echo html_writer::end_div();
    } else {
        foreach ([['description', 'financialmodel_description', 4], ['assumptions', 'financialmodel_assumptions', 7]] as $definition) {
            echo html_writer::start_div('form-group');
            echo html_writer::tag('label', get_string($definition[1], 'local_kbacompany'), ['for' => 'fin-' . $definition[0]]);
            echo html_writer::tag('textarea', s($values[$definition[0]]), ['id' => 'fin-' . $definition[0],
                'name' => $definition[0], 'rows' => $definition[2], 'class' => 'form-control']);
            if ($definition[0] === 'assumptions') {
                echo html_writer::tag('small', get_string('financialcrud_assumptions_help', 'local_kbacompany'), ['class' => 'form-text text-muted']);
            }
            echo html_writer::end_div();
        }
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::start_div('d-flex justify-content-end mt-3');
echo html_writer::link($backurl, get_string('cancel'), ['class' => 'btn btn-secondary mr-2']);
echo html_writer::tag('button', get_string('savechanges'), ['type' => 'submit', 'class' => 'btn btn-primary']);
echo html_writer::end_div();
echo html_writer::end_tag('form');

\local_kbacompany\local\bms_page_shell_service::finish($shell);
