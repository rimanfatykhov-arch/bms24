<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\financial_model_service;
use local_kbacompany\local\financial_model_ui_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$tab = optional_param('tab', 'overview', PARAM_ALPHA);
if (!in_array($tab, ['overview', 'scenarios', 'centers'], true)) {
    $tab = 'overview';
}
$filters = [
    'q' => optional_param('q', '', PARAM_TEXT),
    'status' => optional_param('status', 'active', PARAM_ALPHA),
    'type' => optional_param('type', '', PARAM_ALPHA),
];
if (!in_array($filters['status'], ['active', 'draft', 'published', 'archived', 'all'], true)) {
    $filters['status'] = 'active';
}

$data = financial_model_service::context($uuid, (int)$USER->id);
$registry = null;
if ($tab === 'scenarios') {
    $registry = financial_model_service::registry($uuid, (int)$USER->id, 'scenario', $filters);
} else if ($tab === 'centers') {
    $registry = financial_model_service::registry($uuid, (int)$USER->id, 'center', $filters);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/financial_model.php', array_merge(['uuid' => $uuid, 'tab' => $tab], $filters)));
$PAGE->set_title(get_string('financialmodel_title', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));

$statuslabel = static function(string $status): string {
    return get_string('financialmodel_status_' . $status, 'local_kbacompany');
};
$typeoptions = static function(array $values, string $prefix): array {
    $out = ['' => get_string('financialcrud_filter_alltypes', 'local_kbacompany')];
    foreach ($values as $value) {
        $out[$value] = get_string($prefix . $value, 'local_kbacompany');
    }
    return $out;
};
$targetlabels = ['company:' . $uuid => format_string($data['company']['name'])];
foreach ($data['nodes'] as $row) {
    $targetlabels['orgunit:' . $row['uuid']] = $row['code'] . ' — ' . $row['name'];
}
foreach ($data['positions'] as $row) {
    $targetlabels['position:' . $row['uuid']] = $row['code'] . ' — ' . $row['name'];
}
foreach ($data['products'] as $row) {
    $targetlabels['product:' . $row['uuid']] = $row['code'] . ' — ' . $row['name'];
}
$positionlabels = [];
foreach ($data['positions'] as $row) {
    $positionlabels[$row['uuid']] = $row['code'] . ' — ' . $row['name'];
}

$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'financialmodel', get_string('financialmodel_title', 'local_kbacompany'));

echo \local_kbacompany\local\bms_workspace_ux_service::architecture_nav($uuid, 'finance');

echo html_writer::start_div('d-flex justify-content-between align-items-start flex-wrap mb-3');
echo html_writer::start_div('mr-3');
echo $OUTPUT->heading(get_string('financialmodel_title', 'local_kbacompany') . ': ' . format_string($data['company']['name']), 2, 'mb-1');
echo html_writer::tag('p', get_string('financialcrud_workspace_intro', 'local_kbacompany'), ['class' => 'text-muted mb-0']);
echo html_writer::end_div();
echo html_writer::start_div('d-flex flex-wrap mt-2 mt-md-0');
echo html_writer::link(new moodle_url('/local/kbacompany/financial_statements.php', ['uuid' => $uuid]),
    get_string('financialcrud_statements_action', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2 mb-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/budgeting.php', ['uuid' => $uuid]),
    get_string('financialcrud_budget_action', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2 mb-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/resource_gate.php', ['uuid' => $uuid]),
    get_string('financialcrud_resource_action', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mb-2']);
echo html_writer::end_div();
echo html_writer::end_div();

$tabs = [
    'overview' => get_string('financialcrud_tab_overview', 'local_kbacompany'),
    'scenarios' => get_string('financialmodel_scenarios', 'local_kbacompany'),
    'centers' => get_string('financialmodel_centers', 'local_kbacompany'),
];
echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs mb-4']);
foreach ($tabs as $key => $label) {
    $class = 'nav-link' . ($tab === $key ? ' active' : '');
    echo html_writer::tag('li', html_writer::link(new moodle_url('/local/kbacompany/financial_model.php',
        ['uuid' => $uuid, 'tab' => $key]), $label, ['class' => $class]), ['class' => 'nav-item']);
}
echo html_writer::end_tag('ul');

if ($tab === 'overview') {
    $scenarioactive = 0; $scenariopublished = 0; $scenariodraft = 0;
    foreach ($data['scenarios'] as $row) {
        $scenarioactive++;
        if ($row['status'] === 'published') { $scenariopublished++; }
        if ($row['status'] === 'draft') { $scenariodraft++; }
    }
    $centeractive = count($data['centers']);

    echo html_writer::start_div('row');
    $cards = [
        [get_string('financialmodel_scenarios', 'local_kbacompany'), (string)$scenarioactive,
            get_string('financialcrud_overview_scenarios_help', 'local_kbacompany', (object)['published' => $scenariopublished, 'draft' => $scenariodraft]),
            new moodle_url('/local/kbacompany/financial_model.php', ['uuid' => $uuid, 'tab' => 'scenarios'])],
        [get_string('financialmodel_centers', 'local_kbacompany'), (string)$centeractive,
            get_string('financialcrud_overview_centers_help', 'local_kbacompany'),
            new moodle_url('/local/kbacompany/financial_model.php', ['uuid' => $uuid, 'tab' => 'centers'])],
        [get_string('financialstatement_title', 'local_kbacompany'), 'БДР · БДДС · Баланс',
            get_string('financialcrud_overview_statements_help', 'local_kbacompany'),
            new moodle_url('/local/kbacompany/financial_statements.php', ['uuid' => $uuid])],
        [get_string('budgeting_title', 'local_kbacompany'), get_string('financialcrud_planfact', 'local_kbacompany'),
            get_string('financialcrud_overview_budget_help', 'local_kbacompany'),
            new moodle_url('/local/kbacompany/budgeting.php', ['uuid' => $uuid])],
    ];
    foreach ($cards as $card) {
        echo html_writer::start_div('col-xl-3 col-md-6 mb-3');
        echo html_writer::start_div('card h-100');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('div', $card[0], ['class' => 'text-muted small mb-2']);
        echo html_writer::tag('div', $card[1], ['class' => 'h4 mb-2']);
        echo html_writer::tag('p', $card[2], ['class' => 'text-muted small']);
        echo html_writer::link($card[3], get_string('financialcrud_open', 'local_kbacompany'), ['class' => 'stretched-link']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    echo html_writer::start_div('card mb-3');
    echo html_writer::div(get_string('financialcrud_boundary_title', 'local_kbacompany'), 'card-header font-weight-bold');
    echo html_writer::start_div('card-body');
    echo html_writer::tag('p', get_string('financialcrud_boundary_body', 'local_kbacompany'), ['class' => 'mb-0']);
    echo html_writer::end_div();
    echo html_writer::end_div();
} else {
    $entity = $tab === 'scenarios' ? 'scenario' : 'center';
    $records = $registry['records'];
    $createurl = $entity === 'scenario'
        ? new moodle_url('/local/kbacompany/financial_scenario_edit.php', ['uuid' => $uuid])
        : new moodle_url('/local/kbacompany/financial_center_edit.php', ['uuid' => $uuid]);
    $createlabel = $entity === 'scenario'
        ? get_string('financialcrud_create_scenario', 'local_kbacompany')
        : get_string('financialcrud_create_center', 'local_kbacompany');

    echo html_writer::start_div('d-flex justify-content-between align-items-start flex-wrap mb-3');
    echo html_writer::start_div('mr-3');
    echo $OUTPUT->heading($entity === 'scenario' ? get_string('financialmodel_scenarios', 'local_kbacompany') :
        get_string('financialmodel_centers', 'local_kbacompany'), 3, 'mb-1');
    echo html_writer::tag('p', get_string($entity === 'scenario' ? 'financialcrud_scenario_list_intro' :
        'financialcrud_center_list_intro', 'local_kbacompany'), ['class' => 'text-muted mb-0']);
    echo html_writer::end_div();
    if ($data['can_manage']) {
        echo html_writer::link($createurl, $createlabel, ['class' => 'btn btn-primary mt-2 mt-md-0']);
    }
    echo html_writer::end_div();

    echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'card card-body mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'uuid', 'value' => $uuid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => $tab]);
    echo html_writer::start_div('row align-items-end');
    echo html_writer::start_div('col-lg-5 col-md-6');
    echo html_writer::start_div('form-group mb-md-0');
    echo html_writer::tag('label', get_string('financialcrud_filter_search', 'local_kbacompany'), ['for' => 'financial-q']);
    echo html_writer::empty_tag('input', ['id' => 'financial-q', 'name' => 'q', 'type' => 'search', 'class' => 'form-control',
        'value' => $filters['q'], 'placeholder' => get_string('financialcrud_filter_search_placeholder', 'local_kbacompany')]);
    echo html_writer::end_div(); echo html_writer::end_div();

    echo html_writer::start_div('col-lg-3 col-md-3');
    echo html_writer::start_div('form-group mb-md-0');
    echo html_writer::tag('label', get_string('status'), ['for' => 'financial-status']);
    $statusopts = [
        'active' => get_string('financialcrud_filter_active', 'local_kbacompany'),
        'draft' => get_string('financialmodel_status_draft', 'local_kbacompany'),
        'published' => get_string('financialmodel_status_published', 'local_kbacompany'),
        'archived' => get_string('financialmodel_status_archived', 'local_kbacompany'),
        'all' => get_string('financialcrud_filter_all', 'local_kbacompany'),
    ];
    echo html_writer::select($statusopts, 'status', $filters['status'], false, ['id' => 'financial-status', 'class' => 'form-control']);
    echo html_writer::end_div(); echo html_writer::end_div();

    echo html_writer::start_div('col-lg-2 col-md-3');
    echo html_writer::start_div('form-group mb-md-0');
    echo html_writer::tag('label', get_string('financialcrud_filter_type', 'local_kbacompany'), ['for' => 'financial-type']);
    $typeopts = $entity === 'scenario' ? $typeoptions($data['scenario_types'], 'financialmodel_scenariotype_') :
        $typeoptions($data['center_types'], 'financialmodel_centertype_');
    echo html_writer::select($typeopts, 'type', $filters['type'], false, ['id' => 'financial-type', 'class' => 'form-control']);
    echo html_writer::end_div(); echo html_writer::end_div();

    echo html_writer::start_div('col-lg-2');
    echo html_writer::start_div('d-flex');
    echo html_writer::tag('button', get_string('bms_filter_apply', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-secondary mr-2']);
    echo html_writer::link(new moodle_url('/local/kbacompany/financial_model.php', ['uuid' => $uuid, 'tab' => $tab]),
        get_string('bms_filter_reset', 'local_kbacompany'), ['class' => 'btn btn-link px-1']);
    echo html_writer::end_div(); echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('form');

    if (!$records) {
        echo html_writer::div(get_string($entity === 'scenario' ? 'financialcrud_scenario_empty' :
            'financialcrud_center_empty', 'local_kbacompany'), 'card card-body text-muted');
    } else {
        $table = new html_table();
        $table->attributes['class'] = 'table table-hover bms-crud-list';
        if ($entity === 'scenario') {
            $table->head = [get_string('financialmodel_code', 'local_kbacompany'),
                get_string('financialmodel_name', 'local_kbacompany'), get_string('financialmodel_scenariotype', 'local_kbacompany'),
                get_string('financialmodel_horizon', 'local_kbacompany'), get_string('status'),
                get_string('bms_employee_updated_at', 'local_kbacompany')];
            foreach ($records as $row) {
                $viewurl = new moodle_url('/local/kbacompany/financial_scenario_view.php', ['uuid' => $uuid, 'item' => $row['uuid']]);
                $table->data[] = [
                    html_writer::link($viewurl, s($row['code']), ['class' => 'font-weight-bold']),
                    html_writer::link($viewurl, format_string($row['name'])),
                    s(get_string('financialmodel_scenariotype_' . $row['scenariotype'], 'local_kbacompany')),
                    s($row['periodstart'] . ' — ' . $row['periodend'] . ' / ' . $row['currency']),
                    html_writer::tag('span', $statuslabel($row['status']) . ' · v' . $row['revision'],
                        ['class' => financial_model_ui_service::scenario_status_badge_class($row['status'])]),
                    userdate((int)$row['timemodified']),
                ];
            }
        } else {
            $table->head = [get_string('financialmodel_code', 'local_kbacompany'),
                get_string('financialmodel_name', 'local_kbacompany'), get_string('financialmodel_centertype', 'local_kbacompany'),
                get_string('financialmodel_target', 'local_kbacompany'), get_string('financialmodel_ownerposition', 'local_kbacompany'),
                get_string('status'), get_string('bms_employee_updated_at', 'local_kbacompany')];
            foreach ($records as $row) {
                $viewurl = new moodle_url('/local/kbacompany/financial_center_view.php', ['uuid' => $uuid, 'item' => $row['uuid']]);
                $target = $targetlabels[$row['targettype'] . ':' . $row['targetuuid']] ?? $row['targetuuid'];
                $owner = $row['ownerpositionuuid'] !== '' ? ($positionlabels[$row['ownerpositionuuid']] ?? $row['ownerpositionuuid']) : '—';
                $table->data[] = [
                    html_writer::link($viewurl, s($row['code']), ['class' => 'font-weight-bold']),
                    html_writer::link($viewurl, format_string($row['name'])),
                    s(get_string('financialmodel_centertype_' . $row['centertype'], 'local_kbacompany')),
                    s($target), s($owner),
                    html_writer::tag('span', $statuslabel($row['status']) . ' · v' . $row['revision'],
                        ['class' => financial_model_ui_service::center_status_badge_class($row['status'])]),
                    userdate((int)$row['timemodified']),
                ];
            }
        }
        echo html_writer::table($table);
    }
}

\local_kbacompany\local\bms_page_shell_service::finish($shell);
