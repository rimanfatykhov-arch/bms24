<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_login();

$context = context_system::instance();
require_capability('local/kbacorporate:vieweconomicindicators', $context);

$companyid = required_param('companyid', PARAM_INT);
$category = optional_param('category', '', PARAM_ALPHANUMEXT);
$status = optional_param('status', 'active', PARAM_ALPHA);
if (!in_array($status, ['active', 'archived', 'all'], true)) {
    $status = 'active';
}
$auditid = optional_param('auditid', 0, PARAM_INT);
$source = optional_param('source', '', PARAM_ALPHANUMEXT);
$periodtype = optional_param('periodtype', '', PARAM_ALPHA);
$scenario = optional_param('scenario', '', PARAM_ALPHA);
$query = optional_param('q', '', PARAM_TEXT);
$action = optional_param('action', '', PARAM_ALPHA);
$indicatorid = optional_param('indicatorid', 0, PARAM_INT);

$company = \local_kbacorporate\local\access::require_full_company_access($companyid, (int)$USER->id);
$canmanage = has_capability('local/kbacorporate:manageeconomicindicators', $context);

if ($action === 'archive' && $indicatorid > 0) {
    require_capability('local/kbacorporate:manageeconomicindicators', $context);
    require_sesskey();
    \local_kbacorporate\local\economic_indicators::archive_indicator(
        $indicatorid, $companyid, (int)$USER->id);
    redirect(new moodle_url('/local/kbacorporate/economic_indicators.php', ['companyid' => $companyid]),
        get_string('economicindicatorarchived', 'local_kbacorporate'));
}

$catalogfilters = ['category' => $category, 'query' => $query];
if ($status === 'active') {
    $catalogfilters['active'] = 1;
} else if ($status === 'archived') {
    $catalogfilters['active'] = 0;
}
$catalog = \local_kbacorporate\local\economic_indicators::get_catalog($companyid, $catalogfilters);
$valuefilters = [
    'auditid' => $auditid,
    'source' => $source,
    'periodtype' => $periodtype,
    'scenario' => $scenario,
    'category' => $category,
    'query' => $query,
];
if ($status === 'active') {
    $valuefilters['indicatoractive'] = 1;
} else if ($status === 'archived') {
    $valuefilters['indicatoractive'] = 0;
}
$values = \local_kbacorporate\local\economic_indicators::get_values($companyid, $valuefilters);
$summary = \local_kbacorporate\local\economic_indicators::get_summary($companyid);

$params = ['companyid' => $companyid];
foreach (['category' => $category, 'status' => $status, 'auditid' => $auditid,
        'source' => $source, 'periodtype' => $periodtype, 'scenario' => $scenario,
        'q' => $query] as $key => $value) {
    if ($value !== '' && $value !== 0) {
        $params[$key] = $value;
    }
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacorporate/economic_indicators.php', $params));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('economicregistry', 'local_kbacorporate'));
$PAGE->set_heading(format_string($company->name));

$categoryoptions = [
    '' => get_string('all'),
    'finance' => get_string('economiccategoryfinance', 'local_kbacorporate'),
    'sales' => get_string('economiccategorysales', 'local_kbacorporate'),
    'operations' => get_string('economiccategoryoperations', 'local_kbacorporate'),
    'people' => get_string('economiccategorypeople', 'local_kbacorporate'),
    'clients' => get_string('economiccategoryclients', 'local_kbacorporate'),
];
$statusoptions = [
    'active' => get_string('active'),
    'archived' => get_string('economicarchived', 'local_kbacorporate'),
    'all' => get_string('all'),
];
$sourceoptions = [
    '' => get_string('all'),
    'manual' => get_string('sourcemanual', 'local_kbacorporate'),
    'erp' => get_string('sourceerp', 'local_kbacorporate'),
    'import' => get_string('sourceimport', 'local_kbacorporate'),
    'api' => get_string('sourceapi', 'local_kbacorporate'),
];
$scenariooptions = [
    '' => get_string('all'),
    'actual' => get_string('economicscenarioactual', 'local_kbacorporate'),
    'plan' => get_string('economicscenarioplan', 'local_kbacorporate'),
    'forecast' => get_string('economicscenarioforecast', 'local_kbacorporate'),
];
$periodoptions = [
    '' => get_string('all'),
    'month' => get_string('economicperiodmonth', 'local_kbacorporate'),
    'quarter' => get_string('economicperiodquarter', 'local_kbacorporate'),
    'year' => get_string('economicperiodyear', 'local_kbacorporate'),
    'custom' => get_string('economicperiodcustom', 'local_kbacorporate'),
];
$auditoptions = \local_kbacorporate\local\economic_indicators::get_audit_options($companyid);
$auditoptions[0] = get_string('all');

$select = static function(string $name, array $options, $selected): string {
    return html_writer::select($options, $name, $selected, false, ['class' => 'form-control']);
};

echo $OUTPUT->header();
echo local_kbacorporate_implementation_navigation($companyid, 'economics');
echo local_kbacorporate_company_context_banner($company);
echo $OUTPUT->heading(get_string('economicregistry', 'local_kbacorporate'));
echo html_writer::div(get_string('economicregistrydesc', 'local_kbacorporate'), 'kbacorp-pagelead');

$buttons = [];
$buttons[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_dashboard.php', [
    'companyid' => $companyid,
    'auditid' => $auditid,
]), get_string('economicdashboard', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
if ($canmanage) {
    $buttons[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_value_edit.php', [
        'companyid' => $companyid,
        'auditid' => $auditid,
    ]), get_string('economicaddvalue', 'local_kbacorporate'), ['class' => 'btn btn-primary']);
    $buttons[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_indicator_edit.php', [
        'companyid' => $companyid,
    ]), get_string('economicaddindicator', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
}
$buttons[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_indicators_export.php', $params),
    get_string('exportcsv', 'local_kbacorporate'), ['class' => 'btn btn-secondary']);
echo html_writer::div(implode(' ', $buttons), 'mb-3');

echo html_writer::start_div('kbacorp-summary-grid');
foreach ([
    [$summary->indicators, get_string('economicregistryactivecount', 'local_kbacorporate'), 'blue'],
    [$summary->values, get_string('economicregistryvaluecount', 'local_kbacorporate'), 'green'],
    [$summary->verified, get_string('economicregistryverifiedcount', 'local_kbacorporate'), 'violet'],
    [$summary->linkedaudits, get_string('economicregistryauditcount', 'local_kbacorporate'), 'amber'],
] as $card) {
    echo html_writer::div(html_writer::tag('strong', (string)$card[0]) . html_writer::span($card[1]),
        'kbacorp-summary-card kbacorp-tone-' . $card[2]);
}
echo html_writer::end_div();

echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'kbacorp-filterbar mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'companyid', 'value' => $companyid]);
echo html_writer::div(html_writer::tag('label', get_string('search')) .
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'q', 'value' => s($query),
        'class' => 'form-control', 'placeholder' => get_string('economicregistrysearch', 'local_kbacorporate')]),
    'kbacorp-filter-field');
echo html_writer::div(html_writer::tag('label', get_string('economicregistrycategory', 'local_kbacorporate')) .
    $select('category', $categoryoptions, $category), 'kbacorp-filter-field');
echo html_writer::div(html_writer::tag('label', get_string('status')) .
    $select('status', $statusoptions, $status), 'kbacorp-filter-field');
echo html_writer::div(html_writer::tag('label', get_string('economicregistryaudit', 'local_kbacorporate')) .
    $select('auditid', $auditoptions, $auditid), 'kbacorp-filter-field');
echo html_writer::div(html_writer::tag('label', get_string('economicsource', 'local_kbacorporate')) .
    $select('source', $sourceoptions, $source), 'kbacorp-filter-field');
echo html_writer::div(html_writer::tag('label', get_string('economicregistryperiodicity', 'local_kbacorporate')) .
    $select('periodtype', $periodoptions, $periodtype), 'kbacorp-filter-field');
echo html_writer::div(html_writer::tag('label', get_string('economicscenario', 'local_kbacorporate')) .
    $select('scenario', $scenariooptions, $scenario), 'kbacorp-filter-field');
echo html_writer::tag('button', get_string('applyfilters', 'local_kbacorporate'),
    ['type' => 'submit', 'class' => 'btn btn-secondary align-self-end']);
echo html_writer::end_tag('form');

echo $OUTPUT->heading(get_string('economiccatalogue', 'local_kbacorporate'), 2);
$catalogtable = new html_table();
$catalogtable->attributes['class'] = 'generaltable kbacorp-report';
$catalogtable->head = [
    get_string('economicregistrycode', 'local_kbacorporate'),
    get_string('economicregistryname', 'local_kbacorporate'),
    get_string('economicregistrycategory', 'local_kbacorporate'),
    get_string('economicregistryunit', 'local_kbacorporate'),
    get_string('economicregistryperiodicity', 'local_kbacorporate'),
    get_string('economiclatestactual', 'local_kbacorporate'),
    get_string('actions'),
];
foreach ($catalog as $indicator) {
    $latest = $indicator->latest;
    $latesttext = '—';
    if ($latest) {
        $latesttext = \local_kbacorporate\local\economic_indicators::format_value(
            $latest->value, (string)$indicator->valuetype, (string)$indicator->unit,
            (string)$latest->currency) . html_writer::empty_tag('br') .
            html_writer::span(\local_kbacorporate\local\economic_indicators::period_label($latest), 'text-muted');
    }
    $actions = [];
    if ($canmanage && !empty($indicator->active)) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_value_edit.php', [
            'companyid' => $companyid,
            'indicatorid' => (int)$indicator->id,
            'auditid' => $auditid,
        ]), get_string('economicaddvalue', 'local_kbacorporate'));
    }
    $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_history.php', [
        'companyid' => $companyid,
        'indicatorid' => (int)$indicator->id,
    ]), get_string('history'));
    if ($canmanage && (int)$indicator->companyid === $companyid && empty($indicator->systemdefined)) {
        $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_indicator_edit.php', [
            'companyid' => $companyid,
            'id' => (int)$indicator->id,
        ]), get_string('edit'));
        if (!empty($indicator->active)) {
            $actions[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_indicators.php', [
                'companyid' => $companyid,
                'action' => 'archive',
                'indicatorid' => (int)$indicator->id,
                'sesskey' => sesskey(),
            ]), get_string('archive'), ['data-confirmation' => 'modal',
                'data-confirmation-title-str' => json_encode(['areyousure', 'core']),
                'data-confirmation-content-str' => json_encode(['economicarchiveconfirm', 'local_kbacorporate']),
                'data-confirmation-yes-button-str' => json_encode(['yes', 'core']),
                'data-confirmation-no-button-str' => json_encode(['no', 'core'])]);
        }
    }
    $name = format_string($indicator->name);
    if (!empty($indicator->systemdefined)) {
        $name .= html_writer::empty_tag('br') . html_writer::span(
            get_string('economicbuiltinindicator', 'local_kbacorporate'), 'badge badge-light');
    }
    if (empty($indicator->active)) {
        $name .= ' ' . html_writer::span(get_string('economicarchived', 'local_kbacorporate'), 'badge badge-secondary');
    }
    $catalogtable->data[] = [
        s($indicator->code),
        $name,
        get_string('economiccategory' . $indicator->category, 'local_kbacorporate'),
        s($indicator->unit === 'currency' ? get_string('economiccurrencyunit', 'local_kbacorporate') : $indicator->unit),
        get_string('economicperiod' . $indicator->periodtype, 'local_kbacorporate'),
        $latesttext,
        $actions ? implode(' · ', $actions) : '—',
    ];
}
echo html_writer::div(html_writer::table($catalogtable), 'kbacorp-table-scroll');

echo $OUTPUT->heading(get_string('economicvalueshistory', 'local_kbacorporate'), 2);
if (!$values) {
    echo $OUTPUT->notification(get_string('economicregistrynovalues', 'local_kbacorporate'), 'info', false);
} else {
    $valuetable = new html_table();
    $valuetable->attributes['class'] = 'generaltable kbacorp-report';
    $valuetable->head = [
        get_string('economicindicator', 'local_kbacorporate'),
        get_string('economicperiod', 'local_kbacorporate'),
        get_string('economicscenario', 'local_kbacorporate'),
        get_string('economicvalue', 'local_kbacorporate'),
        get_string('economicsource', 'local_kbacorporate'),
        get_string('economicregistryaudit', 'local_kbacorporate'),
        get_string('status'),
        get_string('actions'),
    ];
    foreach ($values as $value) {
        $valueactions = [];
        if ($canmanage) {
            $valueactions[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_value_edit.php', [
                'companyid' => $companyid,
                'id' => (int)$value->id,
            ]), get_string('edit'));
        }
        $valueactions[] = html_writer::link(new moodle_url('/local/kbacorporate/economic_history.php', [
            'companyid' => $companyid,
            'indicatorid' => (int)$value->indicatorid,
        ]), get_string('history'));
        $auditlink = '—';
        if (!empty($value->auditid) && !empty($value->audittitle)) {
            $auditlink = html_writer::link(new moodle_url('/local/kbacorporate/implementation_audit.php', [
                'id' => (int)$value->auditid,
                'companyid' => $companyid,
            ]), format_string($value->audittitle));
        }
        $valuetable->data[] = [
            html_writer::tag('strong', s($value->indicatorcode)) . html_writer::empty_tag('br') .
                format_string($value->indicatorname),
            \local_kbacorporate\local\economic_indicators::period_label($value),
            get_string('economicscenario' . $value->scenario, 'local_kbacorporate'),
            \local_kbacorporate\local\economic_indicators::format_value(
                $value->value, (string)$value->valuetype, (string)$value->unit, (string)$value->currency),
            get_string('source' . $value->source, 'local_kbacorporate'),
            $auditlink,
            get_string($value->verified ? 'economicverifiedyes' : 'economicverifiedno', 'local_kbacorporate'),
            implode(' · ', $valueactions),
        ];
    }
    echo html_writer::div(html_writer::table($valuetable), 'kbacorp-table-scroll');
}

echo $OUTPUT->footer();
