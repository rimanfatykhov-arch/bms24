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
$editid = optional_param('edit', 0, PARAM_INT);
$newslot = optional_param('newslot', '', PARAM_RAW_TRIMMED);
$formerror = '';

if ($action !== '') {
    require_sesskey();
    require_capability('local/kbacompany:manageorganization', $context);
    try {
        if ($action === 'saveassignment') {
            staffing_service::save_slot_assignment($uuid, (int)$USER->id,
                optional_param('assignmentid', 0, PARAM_INT), [
                    'slotuuid' => required_param('slotuuid', PARAM_RAW_TRIMMED),
                    'employeeid' => required_param('employeeid', PARAM_INT),
                    'assignmenttype' => required_param('assignmenttype', PARAM_ALPHA),
                    'assignmentfte' => required_param('assignmentfte', PARAM_RAW_TRIMMED),
                    'effectivefrom' => optional_param('effectivefrom', '', PARAM_RAW_TRIMMED),
                    'effectiveto' => optional_param('effectiveto', '', PARAM_RAW_TRIMMED),
                ]);
            redirect(new moodle_url('/local/kbacompany/staffing_placement.php',
                ['uuid' => $uuid, 'plan' => $planuuid]),
                get_string('staffingassignmentsaved', 'local_kbacompany'));
        } else if ($action === 'end') {
            staffing_service::end_slot_assignment($uuid, (int)$USER->id,
                required_param('assignmentid', PARAM_INT));
            redirect(new moodle_url('/local/kbacompany/staffing_placement.php',
                ['uuid' => $uuid, 'plan' => $planuuid]),
                get_string('assignmentended', 'local_kbacompany'));
        }
    } catch (moodle_exception $e) {
        $formerror = $e->getMessage();
    }
}

$data = staffing_service::placement_context($uuid, (int)$USER->id, $planuuid);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/staffing_placement.php', ['uuid' => $uuid, 'plan' => $planuuid]));
$PAGE->set_title(get_string('staffingplacement', 'local_kbacompany'));
$PAGE->set_heading(get_string('pluginname', 'local_kbacompany'));
$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id,
    'organization', get_string('staffingplacement', 'local_kbacompany'));

echo local_kbacompany_organization_tabs($uuid, 'staffing',
    \local_kbacompany\local\product_service::can_manage($uuid, (int)$USER->id));
echo $OUTPUT->heading(get_string('staffingplacementfor', 'local_kbacompany', format_string($data['company']['name'])));
echo html_writer::tag('p', get_string('staffingplacementintro', 'local_kbacompany'), ['class' => 'lead']);
$tabs = [
    new tabobject('positions', new moodle_url('/local/kbacompany/positions.php', ['uuid' => $uuid]),
        get_string('positiondirectory', 'local_kbacompany')),
    new tabobject('plan', new moodle_url('/local/kbacompany/staffing_plan.php', ['uuid' => $uuid]),
        get_string('staffingplan', 'local_kbacompany')),
    new tabobject('placement', new moodle_url('/local/kbacompany/staffing_placement.php', ['uuid' => $uuid]),
        get_string('staffingplacement', 'local_kbacompany')),
];
echo $OUTPUT->tabtree($tabs, 'placement');

$options = [];
foreach ($data['plans'] as $plan) { $options[$plan['uuid']] = $plan['name']; }
if ($options) {
    echo $OUTPUT->single_select(new moodle_url('/local/kbacompany/staffing_placement.php', ['uuid' => $uuid]),
        'plan', $options, $data['plan']['uuid'], null, 'placementplanselector');
}
if (!$data['plan']) {
    echo $OUTPUT->notification(get_string('noapprovedstaffplan', 'local_kbacompany'), 'info');
    \local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
    return;
}
if ($formerror !== '') {
    echo $OUTPUT->notification(s($formerror), 'error');
}
if (!$data['is_current_plan']) {
    echo $OUTPUT->notification(get_string('staffingassignmenthistoricalplan', 'local_kbacompany'), 'info');
}

// Compact management summary.
$summarycards = [
    get_string('plannedfte', 'local_kbacompany') => format_float($data['summary']['planned_fte'], 2),
    get_string('staffingassignmentallocation', 'local_kbacompany') => format_float($data['summary']['occupied_fte'], 2),
    get_string('staffingassignmentavailable', 'local_kbacompany') => format_float($data['summary']['vacant_fte'], 2),
    get_string('unplaced', 'local_kbacompany') => format_float($data['summary']['unplaced_fte'], 2),
];
echo html_writer::start_div('bms-staffing-placement-summary');
foreach ($summarycards as $label => $value) {
    echo html_writer::div(html_writer::tag('span', s($label), ['class' => 'bms-staffing-placement-summary-label']) .
        html_writer::tag('strong', s($value), ['class' => 'bms-staffing-placement-summary-value']),
        'bms-staffing-placement-summary-card');
}
echo html_writer::end_div();
if (!empty($data['summary']['unplaced_fte'])) {
    echo $OUTPUT->notification(get_string('staffingplacementunplaced', 'local_kbacompany',
        format_float($data['summary']['unplaced_fte'], 2)), 'warning');
}

$canmanage = $data['can_manage'] && has_capability('local/kbacompany:manageorganization', $context);
$employeeoptions = [];
foreach ($data['employees'] as $employee) { $employeeoptions[(int)$employee['user_id']] = $employee['full_name']; }
$assignmentmap = [];
foreach ($data['assignments'] as $assignment) { $assignmentmap[(int)$assignment['id']] = $assignment; }
$editing = ($editid > 0 && isset($assignmentmap[$editid])) ? $assignmentmap[$editid] : null;

// Create / edit assignment workspace.
if ($canmanage) {
    echo html_writer::start_div('bms-staffing-placement-editor card');
    echo html_writer::start_div('card-body');
    echo $OUTPUT->heading(get_string($editing ? 'staffingassignmentedit' : 'staffingassignmentnew', 'local_kbacompany'), 3);
    echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'bms-staffing-assignment-form']);
    foreach (['uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'action' => 'saveassignment',
            'sesskey' => sesskey(), 'assignmentid' => $editing ? (int)$editing['id'] : 0] as $key => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]);
    }
    $slotoptions = [];
    foreach ($data['slots'] as $slot) {
        if (!$editing && $slot['available_fte'] <= 0.0001 && (string)$slot['uuid'] !== $newslot) { continue; }
        $slotoptions[$slot['uuid']] = $slot['org_node_name'] . ' — ' . $slot['position_name'] . ' · ' .
            $slot['code'] . ' · ' . get_string('staffingassignmentcapacity', 'local_kbacompany',
                format_float($slot['available_fte'], 2));
    }
    $selectedslot = $editing ? $editing['slot_uuid'] : $newslot;
    $selectedemployee = $editing ? (int)$editing['employee_id'] : 0;
    $selectedtype = $editing ? $editing['type'] : 'primary';
    $selectedfte = $editing ? format_float($editing['fte'], 2, false, false) : '1.00';
    $fromvalue = $editing && $editing['effective_from'] ? userdate($editing['effective_from'], '%Y-%m-%d') : userdate(time(), '%Y-%m-%d');
    $tovalue = $editing && $editing['effective_to'] ? userdate($editing['effective_to'], '%Y-%m-%d') : '';

    echo html_writer::start_div('bms-staffing-assignment-grid');
    $field = function($label, $control, $help = '') {
        return html_writer::div(html_writer::tag('label', $label) . $control .
            ($help !== '' ? html_writer::tag('div', $help, ['class' => 'form-text text-muted']) : ''),
            'bms-staffing-assignment-field');
    };
    if ($editing) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'employeeid', 'value' => $selectedemployee]);
        echo $field(get_string('staffingassignmentemployee', 'local_kbacompany'),
            html_writer::empty_tag('input', ['type' => 'text', 'value' => $employeeoptions[$selectedemployee] ?? ('#' . $selectedemployee),
                'class' => 'form-control', 'readonly' => 'readonly']));
    } else {
        echo $field(get_string('staffingassignmentemployee', 'local_kbacompany'),
            html_writer::select($employeeoptions, 'employeeid', $selectedemployee,
                ['' => get_string('selectemployee', 'local_kbacompany')], ['required' => 'required', 'class' => 'form-control']));
    }
    echo $field(get_string('staffingassignmentslot', 'local_kbacompany'),
        html_writer::select($slotoptions, 'slotuuid', $selectedslot,
            ['' => get_string('staffingassignmentslot', 'local_kbacompany')], ['required' => 'required', 'class' => 'form-control']));
    echo $field(get_string('staffingassignmentfte', 'local_kbacompany'),
        html_writer::empty_tag('input', ['type' => 'number', 'name' => 'assignmentfte', 'value' => $selectedfte,
            'min' => '0.01', 'max' => '999', 'step' => '0.01', 'required' => 'required', 'class' => 'form-control']));
    echo $field(get_string('staffingassignmenttype', 'local_kbacompany'),
        html_writer::select([
            'primary' => get_string('assignmenttypeprimary', 'local_kbacompany'),
            'acting' => get_string('assignmenttypeacting', 'local_kbacompany'),
            'additional' => get_string('assignmenttypeadditional', 'local_kbacompany'),
        ], 'assignmenttype', $selectedtype, false, ['class' => 'form-control']));
    echo $field(get_string('staffingassignmentfrom', 'local_kbacompany'),
        html_writer::empty_tag('input', ['type' => 'date', 'name' => 'effectivefrom', 'value' => $fromvalue,
            'class' => 'form-control']));
    echo $field(get_string('staffingassignmentto', 'local_kbacompany'),
        html_writer::empty_tag('input', ['type' => 'date', 'name' => 'effectiveto', 'value' => $tovalue,
            'class' => 'form-control']), get_string('staffingassignmenttohelp', 'local_kbacompany'));
    echo html_writer::end_div();
    echo html_writer::start_div('bms-staffing-assignment-actions');
    echo html_writer::tag('button', get_string('savechanges'),
        ['type' => 'submit', 'class' => 'btn btn-primary']);
    if ($editing) {
        echo html_writer::link(new moodle_url('/local/kbacompany/staffing_placement.php',
            ['uuid' => $uuid, 'plan' => $data['plan']['uuid']]),
            get_string('staffingassignmentcancel', 'local_kbacompany'), ['class' => 'btn btn-secondary']);
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Current assignments.
echo $OUTPUT->heading(get_string('staffingplacementcurrent', 'local_kbacompany'), 3);
if (!$data['assignments']) {
    echo $OUTPUT->notification(get_string('staffingassignmentnone', 'local_kbacompany'), 'info');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable bms-staffing-assignment-table';
    $table->head = [
        get_string('staffingassignmentemployee', 'local_kbacompany'),
        get_string('staffingassignmentdepartment', 'local_kbacompany'),
        get_string('staffingassignmentposition', 'local_kbacompany'),
        get_string('staffingassignmentfte', 'local_kbacompany'),
        get_string('staffingassignmenttype', 'local_kbacompany'),
        get_string('staffingassignmentperiod', 'local_kbacompany'),
        get_string('staffingassignmentstatus', 'local_kbacompany'),
        get_string('actions', 'core'),
    ];
    foreach ($data['assignments'] as $assignment) {
        $statuskey = $assignment['status'] === 'scheduled' ? 'staffingassignmentscheduled' : 'staffingassignmentcurrent';
        $status = get_string($statuskey, 'local_kbacompany');
        if ($assignment['unplaced']) { $status .= ' · ' . get_string('unplaced', 'local_kbacompany'); }
        $period = ($assignment['effective_from'] ? userdate($assignment['effective_from'], '%d.%m.%Y') : '—') . ' — ' .
            ($assignment['effective_to'] ? userdate($assignment['effective_to'], '%d.%m.%Y') : '∞');
        $actions = '';
        if ($canmanage) {
            $actions .= html_writer::link(new moodle_url('/local/kbacompany/staffing_placement.php', [
                'uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'edit' => $assignment['id']]),
                get_string('staffingassignmenteditaction', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary mr-1']);
            $endurl = new moodle_url('/local/kbacompany/staffing_placement.php', [
                'uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'action' => 'end',
                'assignmentid' => $assignment['id'], 'sesskey' => sesskey()]);
            $actions .= $OUTPUT->single_button($endurl, get_string('endassignment', 'local_kbacompany'), 'post',
                ['class' => 'btn btn-sm btn-outline-danger']);
        }
        $table->data[] = [
            s($assignment['employee_name']), s($assignment['org_node_name']), s($assignment['position_name']),
            format_float($assignment['fte'], 2), get_string('assignmenttype' . $assignment['type'], 'local_kbacompany'),
            s($period), s($status), $actions,
        ];
    }
    echo html_writer::table($table);
}

// Vacancies / partial capacity.
echo $OUTPUT->heading(get_string('staffingplacementvacancies', 'local_kbacompany'), 3);
$vacancytable = new html_table();
$vacancytable->attributes['class'] = 'generaltable bms-staffing-vacancy-table';
$vacancytable->head = [get_string('staffslot', 'local_kbacompany'), get_string('staffingassignmentdepartment', 'local_kbacompany'),
    get_string('staffingassignmentposition', 'local_kbacompany'), get_string('fte', 'local_kbacompany'),
    get_string('staffingassignmentallocation', 'local_kbacompany'), get_string('staffingassignmentavailable', 'local_kbacompany'),
    get_string('actions', 'core')];
foreach ($data['slots'] as $slot) {
    if ($slot['available_fte'] <= 0.0001) { continue; }
    $actionhtml = $canmanage ? html_writer::link(new moodle_url('/local/kbacompany/staffing_placement.php', [
        'uuid' => $uuid, 'plan' => $data['plan']['uuid'], 'newslot' => $slot['uuid']]),
        get_string('staffingassignmentnew', 'local_kbacompany'), ['class' => 'btn btn-sm btn-outline-primary']) : '';
    $vacancytable->data[] = [s($slot['code']), s($slot['org_node_name']), s($slot['position_name']),
        format_float($slot['fte'], 2), format_float($slot['allocated_fte'], 2), format_float($slot['available_fte'], 2), $actionhtml];
}
if ($vacancytable->data) { echo html_writer::table($vacancytable); }
else { echo $OUTPUT->notification(get_string('staffingassignmentnovacancies', 'local_kbacompany'), 'info'); }

// Assignment history.
echo $OUTPUT->heading(get_string('staffingplacementhistory', 'local_kbacompany'), 3);
if (!$data['history']) {
    echo html_writer::tag('p', get_string('staffingassignmentnohistory', 'local_kbacompany'), ['class' => 'text-muted']);
} else {
    $historytable = new html_table();
    $historytable->head = [get_string('staffingassignmentemployee', 'local_kbacompany'),
        get_string('staffingassignmentdepartment', 'local_kbacompany'), get_string('staffingassignmentposition', 'local_kbacompany'),
        get_string('staffingassignmentfte', 'local_kbacompany'), get_string('staffingassignmenttype', 'local_kbacompany'),
        get_string('staffingassignmentperiod', 'local_kbacompany')];
    foreach ($data['history'] as $assignment) {
        $period = ($assignment['effective_from'] ? userdate($assignment['effective_from'], '%d.%m.%Y') : '—') . ' — ' .
            ($assignment['effective_to'] ? userdate($assignment['effective_to'], '%d.%m.%Y') : '—');
        $historytable->data[] = [s($assignment['employee_name']), s($assignment['org_node_name']),
            s($assignment['position_name']), format_float($assignment['fte'], 2),
            get_string('assignmenttype' . $assignment['type'], 'local_kbacompany'), s($period)];
    }
    echo html_writer::table($historytable);
}

echo $OUTPUT->notification(get_string('staffingplacementnotice', 'local_kbacompany'), 'info');
\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
