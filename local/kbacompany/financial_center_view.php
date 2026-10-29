<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\financial_model_service;
use local_kbacompany\local\financial_model_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$item = required_param('item', PARAM_RAW_TRIMMED);
$data = financial_model_service::center($uuid, (int)$USER->id, $item);
$center = $data['center'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$data['can_manage']) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $revision = required_param('revision', PARAM_INT);
    if ($action === 'publish') {
        financial_model_service::publish_center($uuid, (int)$USER->id, $item, $revision);
    } else if ($action === 'archive') {
        financial_model_service::archive_center($uuid, (int)$USER->id, $item, $revision);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/financial_center_view.php', ['uuid' => $uuid, 'item' => $item]),
        get_string('financialmodel_saved', 'local_kbacompany'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/financial_center_view.php', ['uuid' => $uuid, 'item' => $item]));
$PAGE->set_title($center['code'] . ' — ' . $center['name']);
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$targetlabels = ['company:' . $uuid => format_string($data['company']['name'])];
foreach ($data['nodes'] as $row) { $targetlabels['orgunit:' . $row['uuid']] = $row['code'] . ' — ' . $row['name']; }
foreach ($data['positions'] as $row) { $targetlabels['position:' . $row['uuid']] = $row['code'] . ' — ' . $row['name']; }
foreach ($data['products'] as $row) { $targetlabels['product:' . $row['uuid']] = $row['code'] . ' — ' . $row['name']; }
$positionlabels = [];
foreach ($data['positions'] as $row) { $positionlabels[$row['uuid']] = $row['code'] . ' — ' . $row['name']; }
$indicatorlabels = [];
foreach ($data['indicators'] as $row) { $indicatorlabels[$row['uuid']] = $row['code'] . ' — ' . $row['name']; }
$statuslabel = get_string('financialmodel_status_' . $center['status'], 'local_kbacompany');

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'financialmodel', get_string('financialmodel_title', 'local_kbacompany'));
$listurl = new moodle_url('/local/kbacompany/financial_model.php', ['uuid' => $uuid, 'tab' => 'centers']);
echo html_writer::div(html_writer::link($listurl, '← ' . get_string('financialcrud_back_to_centers', 'local_kbacompany')), 'mb-3');

// BMS Record Header.
echo html_writer::start_div('card mb-3');
echo html_writer::start_div('card-body');
echo html_writer::start_div('d-flex justify-content-between align-items-start flex-wrap');
echo html_writer::start_div('mr-3');
echo html_writer::tag('div', s($center['code']), ['class' => 'text-muted small font-weight-bold mb-1']);
echo $OUTPUT->heading(format_string($center['name']), 2, 'mb-2');
echo html_writer::tag('span', $statuslabel . ' · v' . $center['revision'],
    ['class' => financial_model_ui_service::center_status_badge_class($center['status'])]);
echo html_writer::tag('span', get_string('financialmodel_centertype_' . $center['centertype'], 'local_kbacompany'),
    ['class' => 'badge badge-light ml-2']);
echo html_writer::end_div();
if ($data['can_manage']) {
    echo html_writer::start_div('d-flex align-items-center mt-2 mt-md-0');
    if ($center['status'] !== 'archived') {
        echo html_writer::link(new moodle_url('/local/kbacompany/financial_center_edit.php', ['uuid' => $uuid, 'item' => $item]),
            get_string('edit'), ['class' => 'btn btn-primary mr-2']);
    }
    echo html_writer::start_div('dropdown');
    echo html_writer::tag('button', get_string('actions'), [
        'class' => 'btn btn-outline-secondary dropdown-toggle', 'type' => 'button',
        'data-toggle' => 'dropdown', 'aria-haspopup' => 'true', 'aria-expanded' => 'false',
    ]);
    echo html_writer::start_div('dropdown-menu dropdown-menu-right p-2');
    if ($center['status'] === 'draft') {
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mb-1']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'publish']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $center['revision']]);
        echo html_writer::tag('button', get_string('financialmodel_publish', 'local_kbacompany'),
            ['type' => 'submit', 'class' => 'btn btn-link dropdown-item']);
        echo html_writer::end_tag('form');
    }
    if ($center['status'] !== 'archived') {
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'archive']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revision', 'value' => $center['revision']]);
        echo html_writer::tag('button', get_string('financialmodel_archive', 'local_kbacompany'), [
            'type' => 'submit', 'class' => 'btn btn-link dropdown-item text-danger',
            'onclick' => "return confirm('" . addslashes(get_string('financialcrud_archive_confirm', 'local_kbacompany')) . "');",
        ]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div(); echo html_writer::end_div(); echo html_writer::end_div();
}
echo html_writer::end_div(); echo html_writer::end_div(); echo html_writer::end_div();

$target = $targetlabels[$center['targettype'] . ':' . $center['targetuuid']] ?? $center['targetuuid'];
$owner = $center['ownerpositionuuid'] !== '' ? ($positionlabels[$center['ownerpositionuuid']] ?? $center['ownerpositionuuid']) : '—';
$indicator = $center['mainstatuuid'] !== '' ? ($indicatorlabels[$center['mainstatuuid']] ?? $center['mainstatuuid']) : '—';

echo html_writer::start_div('row');
echo html_writer::start_div('col-lg-7');
echo html_writer::start_div('card mb-3');
echo html_writer::div(get_string('financialcrud_section_main', 'local_kbacompany'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body p-0');
$table = new html_table();
$table->attributes['class'] = 'table table-sm mb-0';
$table->data = [
    [get_string('financialmodel_centertype', 'local_kbacompany'), get_string('financialmodel_centertype_' . $center['centertype'], 'local_kbacompany')],
    [get_string('financialcrud_target_label', 'local_kbacompany'), s($target)],
    [get_string('status'), $statuslabel],
];
echo html_writer::table($table);
echo html_writer::end_div(); echo html_writer::end_div();

foreach ([['financialmodel_resultdescription', 'resultdescription'], ['financialmodel_authorityrule', 'authorityrule']] as $definition) {
    echo html_writer::start_div('card mb-3');
    echo html_writer::div(get_string($definition[0], 'local_kbacompany'), 'card-header font-weight-bold');
    echo html_writer::div($center[$definition[1]] !== '' ? format_text($center[$definition[1]], FORMAT_PLAIN) :
        html_writer::tag('span', get_string('financialcrud_not_set', 'local_kbacompany'), ['class' => 'text-muted']), 'card-body');
    echo html_writer::end_div();
}
echo html_writer::end_div();

echo html_writer::start_div('col-lg-5');
echo html_writer::start_div('card mb-3');
echo html_writer::div(get_string('financialcrud_section_responsibility', 'local_kbacompany'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body p-0');
$responsibility = new html_table();
$responsibility->attributes['class'] = 'table table-sm mb-0';
$responsibility->data = [
    [get_string('financialmodel_ownerposition', 'local_kbacompany'), s($owner)],
    [get_string('financialcrud_indicator_label', 'local_kbacompany'), s($indicator)],
    [get_string('financialmodel_reviewperiod', 'local_kbacompany'), get_string('financialmodel_reviewperiod_' . $center['reviewperiod'], 'local_kbacompany')],
];
echo html_writer::table($responsibility);
echo html_writer::end_div(); echo html_writer::end_div();

echo html_writer::start_div('card mb-3');
echo html_writer::div(get_string('financialcrud_related_work', 'local_kbacompany'), 'card-header font-weight-bold');
echo html_writer::start_div('list-group list-group-flush');
echo html_writer::link(new moodle_url('/local/kbacompany/budgeting.php', ['uuid' => $uuid]),
    get_string('financialcrud_related_budget', 'local_kbacompany'), ['class' => 'list-group-item list-group-item-action']);
echo html_writer::link(new moodle_url('/local/kbacompany/financial_statements.php', ['uuid' => $uuid]),
    get_string('financialcrud_related_statements', 'local_kbacompany'), ['class' => 'list-group-item list-group-item-action']);
echo html_writer::link(new moodle_url('/local/kbacompany/indicators.php', ['uuid' => $uuid]),
    get_string('financialcrud_related_indicators', 'local_kbacompany'), ['class' => 'list-group-item list-group-item-action']);
echo html_writer::end_div(); echo html_writer::end_div();
echo html_writer::end_div(); echo html_writer::end_div();

// History.
echo html_writer::start_div('card mb-3');
echo html_writer::div(get_string('financialcrud_section_history', 'local_kbacompany'), 'card-header font-weight-bold');
echo html_writer::start_div('card-body p-0');
$historytable = new html_table();
$historytable->attributes['class'] = 'table table-sm mb-0';
$historytable->head = [get_string('financialcrud_revision', 'local_kbacompany'), get_string('status'),
    get_string('bms_employee_updated_at', 'local_kbacompany')];
foreach ($data['history'] as $row) {
    $historytable->data[] = ['v' . $row['revision'], get_string('financialmodel_status_' . $row['status'], 'local_kbacompany'),
        userdate((int)$row['timemodified'])];
}
echo html_writer::table($historytable);
echo html_writer::end_div(); echo html_writer::end_div();

echo html_writer::start_tag('details', ['class' => 'card mb-3']);
echo html_writer::tag('summary', get_string('financialcrud_section_advanced', 'local_kbacompany'),
    ['class' => 'card-header font-weight-bold']);
echo html_writer::start_div('card-body p-0');
$advanced = new html_table();
$advanced->attributes['class'] = 'table table-sm mb-0';
$advanced->data = [
    ['UUID', s($center['uuid'])],
    [get_string('financialcrud_created_by', 'local_kbacompany'), (string)$center['createdby']],
    [get_string('financialcrud_modified_by', 'local_kbacompany'), (string)$center['modifiedby']],
];
echo html_writer::table($advanced);
echo html_writer::end_div(); echo html_writer::end_tag('details');

\local_kbacompany\local\bms_page_shell_service::finish($shell);
