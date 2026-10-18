<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_kbacompany\local\staffing_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$planuuid = optional_param('plan', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHA);

if ($action !== '') {
    try {
        require_sesskey();
        require_capability('local/kbacompany:manageorganization', $context);
        $messagekey = 'staffplansaved';
        if ($action === 'startedit') {
            $planuuid = staffing_service::start_editing_plan($uuid, (int)$USER->id,
                required_param('sourceplan', PARAM_RAW_TRIMMED));
            $messagekey = 'staffplaneditstarted';
        } else if ($action === 'createplan') {
            $planuuid = staffing_service::create_plan($uuid, (int)$USER->id, [
                'branchuuid' => required_param('branchuuid', PARAM_RAW_TRIMMED),
                'name' => required_param('name', PARAM_TEXT),
                'currency' => optional_param('currency', 'RUB', PARAM_ALPHANUMEXT),
                'effectivefrom' => optional_param('effectivefrom', '', PARAM_TEXT),
                'copycurrent' => optional_param('copycurrent', 0, PARAM_BOOL),
            ]);
            $messagekey = 'staffplancreated';
        } else if ($action === 'saveeditor') {
            $lineids = optional_param_array('lineids', [], PARAM_INT);
            $updates = [];
            foreach (array_unique(array_map('intval', $lineids)) as $lineid) {
                if ($lineid <= 0) {
                    continue;
                }
                $updates[$lineid] = [
                    'plannedfte' => optional_param('line_' . $lineid . '_plannedfte', 0, PARAM_FLOAT),
                    'salarymin' => optional_param('line_' . $lineid . '_salarymin', 0, PARAM_FLOAT),
                    'salarymax' => optional_param('line_' . $lineid . '_salarymax', 0, PARAM_FLOAT),
                    'budgetamount' => optional_param('line_' . $lineid . '_budgetamount', 0, PARAM_FLOAT),
                ];
            }
            staffing_service::save_draft_editor($uuid, (int)$USER->id, $planuuid, [
                'name' => required_param('name', PARAM_TEXT),
                'currency' => optional_param('currency', 'RUB', PARAM_ALPHANUMEXT),
                'effectivefrom' => optional_param('effectivefrom', '', PARAM_TEXT),
            ], $updates);
            $removeid = optional_param('removeid', 0, PARAM_INT);
            if ($removeid > 0) {
                staffing_service::delete_line($uuid, (int)$USER->id, $planuuid, $removeid);
                $messagekey = 'staffplanlineremoved';
            }
        } else if ($action === 'updateplan') {
            // Backward-compatible endpoint for bookmarked/older forms.
            staffing_service::update_plan($uuid, (int)$USER->id, $planuuid, [
                'name' => required_param('name', PARAM_TEXT),
                'currency' => optional_param('currency', 'RUB', PARAM_ALPHANUMEXT),
                'effectivefrom' => optional_param('effectivefrom', '', PARAM_TEXT),
            ]);
        } else if ($action === 'addline' || $action === 'updateline' || $action === 'restoreline') {
            staffing_service::add_line($uuid, (int)$USER->id, $planuuid, [
                'positionuuid' => required_param('positionuuid', PARAM_RAW_TRIMMED),
                'plannedfte' => required_param('plannedfte', PARAM_FLOAT),
                'salarymin' => optional_param('salarymin', 0, PARAM_FLOAT),
                'salarymax' => optional_param('salarymax', 0, PARAM_FLOAT),
                'budgetamount' => optional_param('budgetamount', 0, PARAM_FLOAT),
            ]);
            $messagekey = $action === 'restoreline' ? 'staffplanlinerestored' : 'staffplanlineadded';
        } else if ($action === 'deleteline') {
            staffing_service::delete_line($uuid, (int)$USER->id, $planuuid, required_param('lineid', PARAM_INT));
            $messagekey = 'staffplanlineremoved';
        } else if ($action === 'discarddraft') {
            staffing_service::discard_draft($uuid, (int)$USER->id, $planuuid);
            $planuuid = '';
            $messagekey = 'staffplandiscarded';
        } else if ($action === 'approve') {
            $preview = staffing_service::plan_context($uuid, (int)$USER->id, $planuuid);
            $impact = $preview['approval_impact'] ?? [];
            if (!empty($impact['conflict_count']) && !optional_param('ackimpact', 0, PARAM_BOOL)) {
                throw new \moodle_exception('staffplanimpactackrequired', 'local_kbacompany');
            }
            staffing_service::approve_plan($uuid, (int)$USER->id, $planuuid);
            $messagekey = 'staffplanapproved';
        }
        redirect(new moodle_url('/local/kbacompany/staffing_plan.php', ['uuid' => $uuid, 'plan' => $planuuid]),
            get_string($messagekey, 'local_kbacompany'));
    } catch (\moodle_exception $e) {
        if (in_array($e->errorcode, [
                'invalidsalaryrange', 'invalidplannedfte', 'staffplannameempty',
                'positionoutsideplanbranch', 'staffplanempty', 'staffplanimpactackrequired',
            ], true)) {
            $errorplan = optional_param('plan', $planuuid, PARAM_RAW_TRIMMED);
            redirect(new moodle_url('/local/kbacompany/staffing_plan.php', [
                'uuid' => $uuid, 'plan' => $errorplan,
            ]), get_string($e->errorcode, 'local_kbacompany'), null, \core\output\notification::NOTIFY_ERROR);
        }
        throw $e;
    }
}
$data = staffing_service::plan_context($uuid, (int)$USER->id, $planuuid);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/staffing_plan.php', ['uuid' => $uuid, 'plan' => $planuuid]));
$PAGE->set_title(get_string('staffingplan', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'organization',
    get_string('staffingplan', 'local_kbacompany'));

echo local_kbacompany_organization_tabs($uuid, 'staffing',
    \local_kbacompany\local\product_service::can_manage($uuid, (int)$USER->id));

$tabs = [
    new tabobject('positions', new moodle_url('/local/kbacompany/positions.php', ['uuid' => $uuid]),
        get_string('positiondirectory', 'local_kbacompany')),
    new tabobject('plan', new moodle_url('/local/kbacompany/staffing_plan.php', ['uuid' => $uuid]),
        get_string('staffingplan', 'local_kbacompany')),
    new tabobject('placement', new moodle_url('/local/kbacompany/staffing_placement.php', ['uuid' => $uuid]),
        get_string('staffingplacement', 'local_kbacompany')),
];
echo $OUTPUT->tabtree($tabs, 'plan');

echo html_writer::start_div('bms-staffing-page');
echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start mb-3');
echo html_writer::start_div();
echo $OUTPUT->heading(get_string('staffingplanfor', 'local_kbacompany', format_string($data['company']['name'])), 2);
echo html_writer::tag('p', get_string('staffplancrudintro', 'local_kbacompany'), ['class' => 'text-muted mb-0']);
echo html_writer::end_div();
echo html_writer::start_div('bms-staffing-topactions');
echo html_writer::link(new moodle_url('/local/kbacompany/positions.php', ['uuid' => $uuid]),
    get_string('positiondirectory', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::link(new moodle_url('/local/kbacompany/staffing_placement.php', ['uuid' => $uuid]),
    get_string('staffingplacement', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary']);
echo html_writer::end_div();
echo html_writer::end_div();

$canmanage = $data['can_manage'] && has_capability('local/kbacompany:manageorganization', $context);
$staffingstatushtml = static function(array $line): string {
    $status = (string)($line['staffing_status'] ?? 'vacant');
    if ($status === 'overstaffed') {
        $label = get_string('staffplanstatusoverstaffed', 'local_kbacompany',
            format_float((float)($line['overstaffed_fte'] ?? 0), 2));
    } else if ($status === 'staffed') {
        $label = get_string('staffplanstatusstaffed', 'local_kbacompany');
    } else {
        $label = get_string('staffplanstatusvacancies', 'local_kbacompany');
    }
    return html_writer::span($label, 'badge bms-staff-coverage bms-staff-coverage-' . $status);
};

// Version registry: the Read part of BMS CRUD.
if ($data['plans']) {
    echo html_writer::start_div('card bms-staffing-card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-center mb-3');
    echo $OUTPUT->heading(get_string('staffplanversions', 'local_kbacompany'), 3, 'mb-0');
    echo html_writer::span(get_string('staffplanversionshelp', 'local_kbacompany'), 'text-muted small');
    echo html_writer::end_div();
    echo html_writer::start_div('table-responsive bms-staffing-table-wrap');
    echo html_writer::start_tag('table', ['class' => 'table table-hover align-middle bms-staffing-table']);
    echo html_writer::tag('thead', html_writer::tag('tr',
        html_writer::tag('th', get_string('staffplanname', 'local_kbacompany')) .
        html_writer::tag('th', get_string('staffplanstatuslabel', 'local_kbacompany')) .
        html_writer::tag('th', get_string('staffplaneffectivefrom', 'local_kbacompany')) .
        html_writer::tag('th', get_string('staffplanlines', 'local_kbacompany'), ['class' => 'text-right']) .
        html_writer::tag('th', get_string('plannedfte', 'local_kbacompany'), ['class' => 'text-right']) .
        html_writer::tag('th', get_string('budgetamount', 'local_kbacompany'), ['class' => 'text-right']) .
        html_writer::tag('th', get_string('actions'), ['class' => 'text-right'])
    ));
    echo html_writer::start_tag('tbody');
    foreach ($data['plans'] as $plan) {
        $stats = $data['plan_stats'][$plan['uuid']] ?? ['lines' => 0, 'planned_fte' => 0, 'budget_amount' => 0];
        $active = !empty($data['plan']) && $data['plan']['uuid'] === $plan['uuid'];
        $rowclass = $active ? 'table-active' : '';
        echo html_writer::start_tag('tr', ['class' => $rowclass]);
        echo html_writer::tag('td', html_writer::tag('strong', s($plan['name'])));
        echo html_writer::tag('td', html_writer::span(get_string('staffplanstatus' . $plan['status'], 'local_kbacompany'),
            'badge bms-staff-status bms-staff-status-' . $plan['status']));
        $period = !empty($plan['effective_from']) ? userdate($plan['effective_from'], '%d.%m.%Y') : '—';
        if (!empty($plan['effective_to'])) {
            $period .= ' — ' . userdate($plan['effective_to'], '%d.%m.%Y');
        }
        echo html_writer::tag('td', s($period));
        echo html_writer::tag('td', (string)$stats['lines'], ['class' => 'text-right']);
        echo html_writer::tag('td', format_float($stats['planned_fte'], 2), ['class' => 'text-right']);
        echo html_writer::tag('td', format_float($stats['budget_amount'], 2) . ' ' . s($plan['currency']), ['class' => 'text-right']);
        $openlabel = $plan['status'] === 'draft' ? get_string('edit') : get_string('view');
        echo html_writer::tag('td', html_writer::link(
            new moodle_url('/local/kbacompany/staffing_plan.php', ['uuid' => $uuid, 'plan' => $plan['uuid']]),
            $openlabel, ['class' => 'btn btn-sm ' . ($active ? 'btn-primary' : 'btn-outline-primary')]),
            ['class' => 'text-right']);
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Create: copy the approved version by default, but allow an intentionally empty draft.
if ($canmanage && $data['branches']) {
    echo html_writer::start_tag('details', ['class' => 'card bms-staffing-card mb-4 bms-staffing-create']);
    echo html_writer::tag('summary', '+ ' . get_string('createstaffplan', 'local_kbacompany'),
        ['class' => 'card-header font-weight-bold']);
    echo html_writer::start_div('card-body');
    echo html_writer::tag('p', get_string('createstaffplancopyhelp', 'local_kbacompany'), ['class' => 'text-muted']);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'kbacompany-form']);
    foreach (['uuid' => $uuid, 'action' => 'createplan', 'sesskey' => sesskey()] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    $branches = [];
    foreach ($data['branches'] as $branch) {
        $branches[$branch['uuid']] = $branch['name'];
    }
    echo html_writer::start_div('row');
    echo html_writer::start_div('col-lg-4 col-md-6 mb-3');
    echo html_writer::label(get_string('branch', 'local_kbacompany'), 'branchuuid');
    echo html_writer::select($branches, 'branchuuid', '', false,
        ['id' => 'branchuuid', 'required' => 'required', 'class' => 'form-control']);
    echo html_writer::end_div();
    echo html_writer::start_div('col-lg-4 col-md-6 mb-3');
    echo html_writer::label(get_string('staffplanname', 'local_kbacompany'), 'staffplanname');
    echo html_writer::empty_tag('input', ['id' => 'staffplanname', 'name' => 'name', 'required' => 'required',
        'class' => 'form-control', 'value' => get_string('staffplanversiondefault', 'local_kbacompany', userdate(time(), '%d.%m.%Y'))]);
    echo html_writer::end_div();
    echo html_writer::start_div('col-lg-2 col-md-6 mb-3');
    echo html_writer::label(get_string('staffplaneffectivefrom', 'local_kbacompany'), 'effectivefrom');
    echo html_writer::empty_tag('input', ['id' => 'effectivefrom', 'name' => 'effectivefrom', 'type' => 'date',
        'class' => 'form-control', 'value' => userdate(time(), '%Y-%m-%d')]);
    echo html_writer::end_div();
    echo html_writer::start_div('col-lg-2 col-md-6 mb-3');
    echo html_writer::label(get_string('currency', 'local_kbacompany'), 'currency');
    echo html_writer::empty_tag('input', ['id' => 'currency', 'name' => 'currency', 'value' => 'RUB', 'maxlength' => 3,
        'class' => 'form-control']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::div(html_writer::checkbox('copycurrent', 1, true,
        get_string('staffplancopycurrent', 'local_kbacompany')), 'mb-3');
    echo html_writer::tag('button', get_string('staffplancreatebutton', 'local_kbacompany'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_tag('details');
}

if (!$data['plan']) {
    echo $OUTPUT->notification(get_string('nostaffplan', 'local_kbacompany'), 'info');
    echo html_writer::end_div();
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    exit;
}

$isDraft = $data['plan']['status'] === 'draft';

// Selected version header and management snapshot.
echo html_writer::start_div('card bms-staffing-card mb-4');
echo html_writer::start_div('card-body');
echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start');
echo html_writer::start_div();
echo html_writer::div(get_string('staffplanselectedversion', 'local_kbacompany'), 'text-muted small text-uppercase mb-1');
echo $OUTPUT->heading(format_string($data['plan']['name']), 3, 'mb-1');
echo html_writer::span(get_string('staffplanstatus' . $data['plan']['status'], 'local_kbacompany'),
    'badge bms-staff-status bms-staff-status-' . $data['plan']['status']);
echo html_writer::end_div();
if ($canmanage && $isDraft) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'ml-auto',
        'onsubmit' => "return confirm('" . addslashes(get_string('staffplandiscardconfirm', 'local_kbacompany')) . "');"]);
    foreach (['uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'action' => 'discarddraft', 'sesskey' => sesskey()] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::tag('button', get_string('staffplandiscard', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm']);
    echo html_writer::end_tag('form');
} else if ($canmanage && $data['plan']['status'] === 'approved') {
    echo html_writer::start_div('ml-auto bms-staffing-edit-approved');
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'm-0']);
    foreach (['uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'sourceplan' => $data['plan']['uuid'],
            'action' => 'startedit', 'sesskey' => sesskey()] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::tag('button', get_string('staffplaneditbutton', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
    echo html_writer::div(get_string('staffplanedithelp', 'local_kbacompany'), 'text-muted small mt-2');
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::start_div('bms-staffing-summary-grid mt-3');
foreach ([
    ['value' => $data['summary']['departments'], 'label' => get_string('staffplandepartments', 'local_kbacompany')],
    ['value' => $data['summary']['lines'], 'label' => get_string('staffplanpositionscount', 'local_kbacompany')],
    ['value' => format_float($data['summary']['planned_fte'], 2), 'label' => get_string('plannedfte', 'local_kbacompany')],
    ['value' => format_float($data['summary']['occupied_fte'], 2), 'label' => get_string('staffplanoccupiedfte', 'local_kbacompany')],
    ['value' => format_float($data['summary']['vacant_fte'], 2), 'label' => get_string('staffplanvacantfte', 'local_kbacompany')],
    ['value' => format_float($data['summary']['overstaffed_fte'], 2), 'label' => get_string('staffplanoverstaffedfte', 'local_kbacompany')],
    ['value' => format_float($data['summary']['budget_amount'], 2) . ' ' . $data['plan']['currency'], 'label' => get_string('staffplanmonthlypayroll', 'local_kbacompany')],
] as $card) {
    echo html_writer::start_div('bms-staffing-summary-item');
    echo html_writer::div(s((string)$card['value']), 'bms-staffing-summary-value');
    echo html_writer::div($card['label'], 'text-muted small');
    echo html_writer::end_div();
}
echo html_writer::end_div();
if (!empty($data['occupancy_source_plan'])) {
    $source = $data['occupancy_source_plan'];
    $sourcelabel = format_string($source['name']);
    echo html_writer::div(get_string($isDraft ? 'staffplanoccupancysourcedraft' : 'staffplanoccupancysourceversion',
        'local_kbacompany', $sourcelabel), 'bms-staffing-coverage-note mt-3');
}
echo html_writer::end_div();
echo html_writer::end_div();

// Diff / audit before editing. Removed lines can be restored in one click.
if ($isDraft && $data['previous_plan']) {
    $diff = $data['comparison'];
    $countdiff = count($diff['added']) + count($diff['changed']) + count($diff['removed']);
    echo html_writer::start_div('card bms-staffing-card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-center mb-2');
    echo $OUTPUT->heading(get_string('staffplanchanges', 'local_kbacompany'), 3, 'mb-0');
    echo html_writer::span(get_string('staffplanchangescount', 'local_kbacompany', $countdiff),
        'badge badge-light p-2');
    echo html_writer::end_div();
    echo html_writer::tag('p', get_string('staffplanchangesintro', 'local_kbacompany',
        format_string($data['previous_plan']['name'])), ['class' => 'text-muted']);
    if ($countdiff === 0) {
        echo html_writer::div(get_string('staffplannochanges', 'local_kbacompany'), 'alert alert-light mb-0');
    } else {
        echo html_writer::start_div('bms-staffing-diff-list');
        foreach ($diff['added'] as $item) {
            echo html_writer::div(html_writer::span('+', 'bms-staffing-diff-symbol') . ' ' . s($item['position']) .
                ' · ' . format_float($item['new']['fte'], 2) . ' ' . get_string('staffplanfteabbr', 'local_kbacompany'),
                'bms-staffing-diff bms-staffing-diff-added');
        }
        foreach ($diff['changed'] as $item) {
            echo html_writer::div(html_writer::span('~', 'bms-staffing-diff-symbol') . ' ' . s($item['position']) .
                ' · ' . format_float($item['old']['fte'], 2) . ' → ' . format_float($item['new']['fte'], 2) . ' ' .
                get_string('staffplanfteabbr', 'local_kbacompany'), 'bms-staffing-diff bms-staffing-diff-changed');
        }
        foreach ($diff['removed'] as $item) {
            echo html_writer::start_div('bms-staffing-diff bms-staffing-diff-removed d-flex flex-wrap justify-content-between align-items-center');
            echo html_writer::span('− ' . s($item['position']) . ' · ' . format_float($item['old']['fte'], 2) . ' ' .
                get_string('staffplanfteabbr', 'local_kbacompany'));
            if ($canmanage) {
                echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'm-0']);
                foreach ([
                    'uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'action' => 'restoreline', 'sesskey' => sesskey(),
                    'positionuuid' => $item['position_uuid'], 'plannedfte' => $item['old']['fte'],
                    'salarymin' => $item['old']['salary_min'], 'salarymax' => $item['old']['salary_max'],
                    'budgetamount' => $item['old']['budget'],
                ] as $key => $value) {
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
                }
                echo html_writer::tag('button', get_string('staffplanrestoreline', 'local_kbacompany'),
                    ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-secondary']);
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Update: one full-width editor with one Save action for the whole draft.
if ($canmanage && $isDraft) {
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-staffing-editor', 'id' => 'staffing-editor-form']);
    foreach (['uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'action' => 'saveeditor', 'sesskey' => sesskey()] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    echo html_writer::start_div('card bms-staffing-card mb-4');
    echo html_writer::start_div('card-body');
    echo $OUTPUT->heading(get_string('staffplanparameters', 'local_kbacompany'), 3);
    echo html_writer::start_div('row');
    echo html_writer::start_div('col-lg-5 col-md-6 mb-3');
    echo html_writer::label(get_string('staffplanname', 'local_kbacompany'), 'editplanname');
    echo html_writer::empty_tag('input', ['id' => 'editplanname', 'name' => 'name', 'value' => $data['plan']['name'],
        'class' => 'form-control', 'required' => 'required']);
    echo html_writer::end_div();
    echo html_writer::start_div('col-lg-4 col-md-6 mb-3');
    echo html_writer::label(get_string('staffplaneffectivefrom', 'local_kbacompany'), 'editeffectivefrom');
    echo html_writer::empty_tag('input', ['id' => 'editeffectivefrom', 'name' => 'effectivefrom', 'type' => 'date',
        'class' => 'form-control', 'value' => !empty($data['plan']['effective_from']) ? userdate($data['plan']['effective_from'], '%Y-%m-%d') : '']);
    echo html_writer::end_div();
    echo html_writer::start_div('col-lg-3 col-md-6 mb-3');
    echo html_writer::label(get_string('currency', 'local_kbacompany'), 'editcurrency');
    echo html_writer::empty_tag('input', ['id' => 'editcurrency', 'name' => 'currency', 'value' => $data['plan']['currency'],
        'class' => 'form-control', 'maxlength' => 3]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('card bms-staffing-card mb-4');
    echo html_writer::start_div('card-body p-0');
    echo html_writer::start_div('bms-staffing-section-head');
    echo html_writer::start_div();
    echo $OUTPUT->heading(get_string('staffplanlines', 'local_kbacompany'), 3, 'mb-1');
    echo html_writer::div(get_string('staffplanlineseditorhelp', 'local_kbacompany'), 'text-muted small');
    echo html_writer::end_div();
    echo html_writer::end_div();
    if (!$data['lines']) {
        echo html_writer::div(get_string('staffplanlinesempty', 'local_kbacompany'), 'alert alert-info m-3');
    } else {
        $groups = [];
        foreach ($data['lines'] as $line) {
            $groupkey = $line['org_node_uuid'] !== '' ? $line['org_node_uuid'] : '_none';
            if (!isset($groups[$groupkey])) {
                $groups[$groupkey] = ['name' => $line['org_node_name'] !== '' ? $line['org_node_name'] : '—', 'lines' => []];
            }
            $groups[$groupkey]['lines'][] = $line;
        }
        uasort($groups, function($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        echo html_writer::start_div('table-responsive bms-staffing-table-wrap');
        echo html_writer::start_tag('table', ['class' => 'table bms-staffing-table bms-staffing-editor-table mb-0']);
        echo html_writer::tag('thead', html_writer::tag('tr',
            html_writer::tag('th', get_string('positionname', 'local_kbacompany')) .
            html_writer::tag('th', get_string('plannedfte', 'local_kbacompany'), ['class' => 'bms-staffing-num']) .
            html_writer::tag('th', get_string('staffplanoccupiedfte', 'local_kbacompany'), ['class' => 'bms-staffing-num']) .
            html_writer::tag('th', get_string('staffplanvacantfte', 'local_kbacompany'), ['class' => 'bms-staffing-num']) .
            html_writer::tag('th', get_string('salarymin', 'local_kbacompany'), ['class' => 'bms-staffing-money']) .
            html_writer::tag('th', get_string('salarymax', 'local_kbacompany'), ['class' => 'bms-staffing-money']) .
            html_writer::tag('th', get_string('budgetamount', 'local_kbacompany'), ['class' => 'bms-staffing-money']) .
            html_writer::tag('th', get_string('staffplanchange', 'local_kbacompany'), ['class' => 'bms-staffing-change']) .
            html_writer::tag('th', get_string('actions'), ['class' => 'bms-staffing-actions'])
        ));
        echo html_writer::start_tag('tbody');
        foreach ($groups as $group) {
            $groupfte = 0.0;
            $groupoccupied = 0.0;
            $groupvacant = 0.0;
            $groupbudget = 0.0;
            foreach ($group['lines'] as $line) {
                $groupfte += $line['planned_fte'];
                $groupoccupied += $line['occupied_fte'];
                $groupvacant += $line['vacant_fte'];
                $groupbudget += $line['budget_amount'];
            }
            echo html_writer::tag('tr', html_writer::tag('td',
                html_writer::tag('strong', s($group['name'])) .
                html_writer::span(get_string('staffplangroupcoveragesummary', 'local_kbacompany', (object)[
                    'fte' => format_float($groupfte, 2), 'occupied' => format_float($groupoccupied, 2),
                    'vacant' => format_float($groupvacant, 2), 'budget' => format_float($groupbudget, 2),
                    'currency' => $data['plan']['currency'],
                ]), 'bms-staffing-group-summary'), ['colspan' => 9, 'class' => 'bms-staffing-group-row']));
            foreach ($group['lines'] as $line) {
                echo html_writer::start_tag('tr');
                echo html_writer::start_tag('td', ['class' => 'bms-staffing-position']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'lineids[]', 'value' => $line['id']]);
                echo html_writer::tag('strong', s($line['position_name']));
                echo html_writer::div(s($line['position_code']), 'text-muted small');
                echo html_writer::end_tag('td');
                echo html_writer::start_tag('td');
                echo html_writer::empty_tag('input', [
                    'name' => 'line_' . $line['id'] . '_plannedfte', 'type' => 'number', 'min' => 0,
                    'step' => '0.01', 'value' => $line['planned_fte'],
                    'class' => 'form-control form-control-sm bms-staffing-number-input',
                    'aria-label' => get_string('plannedfte', 'local_kbacompany') . ' ' . $line['position_name'],
                ]);
                echo html_writer::end_tag('td');
                echo html_writer::tag('td', format_float($line['occupied_fte'], 2),
                    ['class' => 'text-right bms-staffing-coverage-value']);
                echo html_writer::start_tag('td', ['class' => 'text-right bms-staffing-coverage-value']);
                echo format_float($line['vacant_fte'], 2);
                echo html_writer::div($staffingstatushtml($line), 'mt-1');
                echo html_writer::end_tag('td');
                foreach ([
                    'salarymin' => [$line['salary_min'], '0.01'],
                    'salarymax' => [$line['salary_max'], '0.01'],
                    'budgetamount' => [$line['budget_amount'], '0.01'],
                ] as $field => $meta) {
                    echo html_writer::start_tag('td');
                    echo html_writer::empty_tag('input', [
                        'name' => 'line_' . $line['id'] . '_' . $field, 'type' => 'number', 'min' => 0,
                        'step' => $meta[1], 'value' => $meta[0], 'class' => 'form-control form-control-sm bms-staffing-number-input',
                        'aria-label' => get_string($field, 'local_kbacompany') . ' ' . $line['position_name'],
                    ]);
                    echo html_writer::end_tag('td');
                }
                $status = $line['change_status'];
                $statuslabel = get_string('staffplanchange' . $status, 'local_kbacompany');
                echo html_writer::tag('td', html_writer::span($statuslabel,
                    'badge bms-staff-change bms-staff-change-' . $status));
                echo html_writer::tag('td', html_writer::tag('button', get_string('removefromstaffplan', 'local_kbacompany'), [
                    'type' => 'submit', 'name' => 'removeid', 'value' => $line['id'],
                    'class' => 'btn btn-sm btn-outline-danger',
                    'onclick' => "return confirm('" . addslashes(get_string('staffplanremoveconfirm', 'local_kbacompany')) . "');",
                ]));
                echo html_writer::end_tag('tr');
            }
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('bms-staffing-savebar mb-4');
    echo html_writer::start_div();
    echo html_writer::tag('strong', get_string('staffplanstatusdraft', 'local_kbacompany'));
    echo html_writer::div(get_string('staffplansavebarhelp', 'local_kbacompany'), 'small');
    echo html_writer::end_div();
    echo html_writer::start_div('d-flex flex-wrap align-items-center bms-staffing-save-actions');
    echo html_writer::link(new moodle_url('/local/kbacompany/staffing_plan.php', [
        'uuid' => $uuid, 'plan' => $data['plan']['uuid'],
    ]), get_string('staffplanresetunsaved', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary mr-2']);
    echo html_writer::tag('button', get_string('staffplansavedraft', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
} else {
    // Read-only view for approved / archived versions.
    echo html_writer::start_div('card bms-staffing-card mb-4');
    echo html_writer::start_div('card-body p-0');
    echo html_writer::start_div('bms-staffing-section-head');
    echo $OUTPUT->heading(get_string('staffplanlines', 'local_kbacompany'), 3, 'mb-0');
    echo html_writer::end_div();
    echo html_writer::start_div('table-responsive bms-staffing-table-wrap');
    echo html_writer::start_tag('table', ['class' => 'table bms-staffing-table mb-0']);
    echo html_writer::tag('thead', html_writer::tag('tr',
        html_writer::tag('th', get_string('staffplandepartment', 'local_kbacompany')) .
        html_writer::tag('th', get_string('positionname', 'local_kbacompany')) .
        html_writer::tag('th', get_string('plannedfte', 'local_kbacompany'), ['class' => 'text-right']) .
        html_writer::tag('th', get_string('staffplanoccupiedfte', 'local_kbacompany'), ['class' => 'text-right']) .
        html_writer::tag('th', get_string('staffplanvacantfte', 'local_kbacompany'), ['class' => 'text-right']) .
        html_writer::tag('th', get_string('salarymin', 'local_kbacompany'), ['class' => 'text-right']) .
        html_writer::tag('th', get_string('salarymax', 'local_kbacompany'), ['class' => 'text-right']) .
        html_writer::tag('th', get_string('budgetamount', 'local_kbacompany'), ['class' => 'text-right'])
    ));
    echo html_writer::start_tag('tbody');
    foreach ($data['lines'] as $line) {
        echo html_writer::tag('tr',
            html_writer::tag('td', s($line['org_node_name'])) .
            html_writer::tag('td', html_writer::tag('strong', s($line['position_name'])) . html_writer::div(s($line['position_code']), 'text-muted small')) .
            html_writer::tag('td', format_float($line['planned_fte'], 2), ['class' => 'text-right']) .
            html_writer::tag('td', format_float($line['occupied_fte'], 2), ['class' => 'text-right']) .
            html_writer::tag('td', format_float($line['vacant_fte'], 2) . html_writer::div($staffingstatushtml($line), 'mt-1'),
                ['class' => 'text-right']) .
            html_writer::tag('td', format_float($line['salary_min'], 2), ['class' => 'text-right']) .
            html_writer::tag('td', format_float($line['salary_max'], 2), ['class' => 'text-right']) .
            html_writer::tag('td', format_float($line['budget_amount'], 2) . ' ' . s($data['plan']['currency']), ['class' => 'text-right'])
        );
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Create a staffing unit inside the selected draft.
if ($canmanage && $isDraft && $data['positions']) {
    $used = array_column($data['lines'], 'position_uuid');
    $positiongroups = [];
    foreach ($data['positions'] as $position) {
        if (in_array($position['uuid'], $used, true)) {
            continue;
        }
        if ($position['branch_uuid'] !== $data['plan']['branch_uuid']) {
            continue;
        }
        $node = $position['org_node_name'] !== '' ? $position['org_node_name'] : '—';
        $positiongroups[$node][] = $position;
    }
    if ($positiongroups) {
        echo html_writer::start_div('card bms-staffing-card mb-4');
        echo html_writer::start_div('card-body');
        echo $OUTPUT->heading('+ ' . get_string('addstaffline', 'local_kbacompany'), 3);
        echo html_writer::tag('p', get_string('addstafflinehelp', 'local_kbacompany'), ['class' => 'text-muted']);
        echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-staffing-addline-form']);
        foreach (['uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'action' => 'addline', 'sesskey' => sesskey()] as $key => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
        }
        echo html_writer::start_div('bms-staffing-addline-grid');
        echo html_writer::start_div('bms-staffing-addline-field bms-staffing-addline-position');
        echo html_writer::label(get_string('positionname', 'local_kbacompany'), 'positionuuid');
        echo html_writer::start_tag('select', ['name' => 'positionuuid', 'id' => 'positionuuid', 'class' => 'form-control', 'required' => 'required']);
        echo html_writer::tag('option', get_string('choosedots'), ['value' => '']);
        ksort($positiongroups, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($positiongroups as $groupname => $positions) {
            echo html_writer::start_tag('optgroup', ['label' => $groupname]);
            foreach ($positions as $position) {
                echo html_writer::tag('option', s($position['code'] . ' — ' . $position['name']), ['value' => $position['uuid']]);
            }
            echo html_writer::end_tag('optgroup');
        }
        echo html_writer::end_tag('select');
        echo html_writer::end_div();
        foreach ([
            'plannedfte' => [1, get_string('plannedfte', 'local_kbacompany'), '0.01'],
            'salarymin' => [0, get_string('salarymin', 'local_kbacompany'), '1'],
            'salarymax' => [0, get_string('salarymax', 'local_kbacompany'), '1'],
            'budgetamount' => [0, get_string('budgetamount', 'local_kbacompany'), '1'],
        ] as $name => $meta) {
            echo html_writer::start_div('bms-staffing-addline-field');
            echo html_writer::label($meta[1], 'add_' . $name);
            echo html_writer::empty_tag('input', ['id' => 'add_' . $name, 'name' => $name, 'type' => 'number',
                'min' => 0, 'step' => $meta[2], 'value' => $meta[0], 'class' => 'form-control', 'inputmode' => 'decimal']);
            echo html_writer::end_div();
        }
        echo html_writer::start_div('bms-staffing-addline-action');
        echo html_writer::tag('button', get_string('addstafflineaction', 'local_kbacompany'), [
            'type' => 'submit', 'class' => 'btn btn-primary bms-staffing-addline-button'
        ]);
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

// Approval impact: make staffing/assignment consequences visible before approval.
if ($canmanage && $isDraft) {
    $impact = $data['approval_impact'] ?? [
        'total_assignments' => 0, 'total_fte' => 0, 'carried_assignments' => 0,
        'carried_fte' => 0, 'conflict_count' => 0, 'conflict_fte' => 0, 'conflicts' => [],
    ];
    echo html_writer::start_div('card bms-staffing-card bms-staffing-impact-card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start mb-3');
    echo html_writer::start_div();
    echo $OUTPUT->heading(get_string('staffplanapprovalimpact', 'local_kbacompany'), 3, 'mb-1');
    echo html_writer::div(get_string('staffplanapprovalimpacthelp', 'local_kbacompany'), 'text-muted');
    echo html_writer::end_div();
    echo html_writer::link(new moodle_url('/local/kbacompany/staffing_placement.php', ['uuid' => $uuid]),
        get_string('staffplanopenplacement', 'local_kbacompany'), ['class' => 'btn btn-outline-secondary btn-sm']);
    echo html_writer::end_div();

    echo html_writer::start_div('bms-staffing-impact-summary');
    foreach ([
        [format_float((float)$impact['total_fte'], 2), get_string('staffplanimpactcurrentfte', 'local_kbacompany')],
        [format_float((float)$impact['carried_fte'], 2), get_string('staffplanimpactcarriedfte', 'local_kbacompany')],
        [format_float((float)$impact['conflict_fte'], 2), get_string('staffplanimpactconflictfte', 'local_kbacompany')],
    ] as $card) {
        echo html_writer::start_div('bms-staffing-impact-summary-item');
        echo html_writer::div(s((string)$card[0]), 'bms-staffing-impact-value');
        echo html_writer::div($card[1], 'text-muted small');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    if (empty($impact['conflict_count'])) {
        echo html_writer::div(get_string('staffplanimpactok', 'local_kbacompany'),
            'alert alert-success mb-0 mt-3');
    } else {
        echo html_writer::div(get_string('staffplanimpactwarning', 'local_kbacompany',
            (object)['count' => (int)$impact['conflict_count'], 'fte' => format_float((float)$impact['conflict_fte'], 2)]),
            'alert alert-warning mt-3');
        echo html_writer::start_div('table-responsive');
        echo html_writer::start_tag('table', ['class' => 'table table-sm bms-staffing-impact-table mb-0']);
        echo html_writer::tag('thead', html_writer::tag('tr',
            html_writer::tag('th', get_string('employee', 'local_kbacompany')) .
            html_writer::tag('th', get_string('positionname', 'local_kbacompany')) .
            html_writer::tag('th', get_string('staffplanassignmentfte', 'local_kbacompany'), ['class' => 'text-right']) .
            html_writer::tag('th', get_string('staffplanimpactreason', 'local_kbacompany'))
        ));
        echo html_writer::start_tag('tbody');
        foreach ($impact['conflicts'] as $conflict) {
            $reasonkey = $conflict['reason'] === 'removed'
                ? 'staffplanimpactreasonremoved' : 'staffplanimpactreasoncapacity';
            $positionlabel = trim((string)$conflict['position_code'] . ' — ' . (string)$conflict['position_name'], ' —');
            echo html_writer::tag('tr',
                html_writer::tag('td', s((string)$conflict['employee_name'])) .
                html_writer::tag('td', s($positionlabel)) .
                html_writer::tag('td', format_float((float)$conflict['fte'], 2), ['class' => 'text-right']) .
                html_writer::tag('td', get_string($reasonkey, 'local_kbacompany'))
            );
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Lifecycle action: approval is explicit and separate from ordinary CRUD save.
if ($canmanage && $isDraft && $data['lines']) {
    $impact = $data['approval_impact'] ?? ['conflict_count' => 0];
    echo html_writer::start_div('card bms-staffing-card mb-4');
    echo html_writer::start_div('card-body');
    echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-start');
    echo html_writer::start_div('mr-3');
    echo $OUTPUT->heading(get_string('staffplanapproval', 'local_kbacompany'), 3, 'mb-1');
    echo html_writer::div(get_string('staffplanapprovalhelp', 'local_kbacompany'), 'text-muted');
    echo html_writer::end_div();

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'class' => 'bms-staffing-approval-form',
        'onsubmit' => "return confirm('" . addslashes(get_string('staffplanapprovalconfirm', 'local_kbacompany')) . "');",
    ]);
    foreach ([
        'uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'action' => 'approve', 'sesskey' => sesskey(),
    ] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    if (!empty($impact['conflict_count'])) {
        echo html_writer::start_div('bms-staffing-impact-ack mb-2');
        echo html_writer::checkbox('ackimpact', 1, false,
            get_string('staffplanimpactack', 'local_kbacompany'), ['required' => 'required']);
        echo html_writer::end_div();
    }
    echo html_writer::tag('button', get_string('approvestaffplan', 'local_kbacompany'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');

    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->notification(get_string('staffplanmethodnoticeeditor', 'local_kbacompany'), 'info');
echo html_writer::end_div();
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
