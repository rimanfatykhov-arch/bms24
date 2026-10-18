<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_coverage_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\battle_plan_week_launch_service;

require_login();
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$orgnode = optional_param('orgnode', '', PARAM_ALPHANUMEXT);
$gap = optional_param('gap', 'all', PARAM_ALPHAEXT);
$error = '';

$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'launchweek') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new moodle_exception('battleplan_post', 'local_kbacompany');
    }
    require_sesskey();
    try {
        $result = battle_plan_week_launch_service::launch($uuid, (int)$USER->id,
            required_param('week', PARAM_RAW_TRIMMED),
            optional_param_array('assignments', [], PARAM_RAW_TRIMMED),
            required_param('reviewerid', PARAM_INT),
            optional_param('observerid', 0, PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/battle_plan_coverage.php', [
            'uuid'=>$uuid, 'week'=>$result['week_start'], 'orgnode'=>$orgnode, 'gap'=>$gap,
        ]), get_string('battleplan_week_launch_result', 'local_kbacompany', (object)[
            'created'=>$result['created_count'], 'reused'=>$result['reused_count'],
        ]));
    } catch (moodle_exception $e) {
        if ($e->module !== 'local_kbacompany') { throw $e; }
        $error = $e->getMessage();
    }
}

$data = battle_plan_coverage_service::context($uuid, (int)$USER->id, $week, $orgnode, $gap);
$people = battle_plan_week_launch_service::people_options($uuid, (int)$USER->id);
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_coverage.php', ['uuid'=>$uuid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('battleplan_coverage_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'battleplan',
    get_string('battleplan_coverage_title','local_kbacompany'));
echo $OUTPUT->heading(get_string('battleplan_coverage_title','local_kbacompany'));
echo html_writer::tag('p', get_string('battleplan_coverage_intro','local_kbacompany'), ['class'=>'lead']);
echo html_writer::div(get_string('battleplan_coverage_rule','local_kbacompany'), 'alert alert-info');
if ($error !== '') { echo $OUTPUT->notification(s($error), 'error'); }
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_overview.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_overview_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_readiness.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_readiness_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/staffing_placement.php',['uuid'=>$uuid]),
    get_string('staffingplacement','local_kbacompany'), ['class'=>'btn btn-outline-secondary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/indicators.php',['uuid'=>$uuid,'tab'=>'tree','treeview'=>'organization']),
    get_string('indicator_title','local_kbacompany'), ['class'=>'btn btn-outline-secondary']);

$nodeopts = [''=>get_string('battleplan_overview_all_units','local_kbacompany')];
foreach ($data['orgnodes'] as $node) { $nodeopts[$node['uuid']] = $node['name']; }
$gapopts = ['all'=>get_string('battleplan_coverage_gap_all','local_kbacompany')];
foreach (battle_plan_coverage_service::GAP_FILTERS as $code) {
    $gapopts[$code] = get_string('battleplan_coverage_gap_'.$code,'local_kbacompany');
}
echo html_writer::start_tag('form',['method'=>'get','class'=>'form-inline my-3']);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
echo html_writer::label(get_string('battleplan_week','local_kbacompany'),'bpcov_week',false,['class'=>'mr-2']);
echo html_writer::empty_tag('input',['type'=>'date','id'=>'bpcov_week','name'=>'week','value'=>$data['week_start'],'class'=>'form-control mr-3']);
echo html_writer::label(get_string('battleplan_overview_unit','local_kbacompany'),'bpcov_unit',false,['class'=>'mr-2']);
echo html_writer::select($nodeopts,'orgnode',$data['orgnode_filter'],false,['id'=>'bpcov_unit','class'=>'form-control mr-3']);
echo html_writer::label(get_string('battleplan_coverage_gap_filter','local_kbacompany'),'bpcov_gap',false,['class'=>'mr-2']);
echo html_writer::select($gapopts,'gap',$data['gap_filter'],false,['id'=>'bpcov_gap','class'=>'form-control mr-3']);
echo html_writer::tag('button',get_string('battleplan_show','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
echo html_writer::end_tag('form');
echo html_writer::tag('p', s(battle_plan_service::period_label($data['week_start'],$data['week_end'])), ['class'=>'text-muted']);

$kpis = '';
foreach ([
    'expected_assignments'=>'battleplan_coverage_expected_assignments',
    'covered_assignments'=>'battleplan_coverage_covered_assignments',
    'missing_plans'=>'battleplan_coverage_missing_plans',
    'positions_without_critical'=>'battleplan_coverage_positions_without_critical',
    'critical_without_norm'=>'battleplan_coverage_critical_without_norm',
    'units_with_gaps'=>'battleplan_coverage_units_with_gaps',
] as $key=>$langkey) {
    $kpis .= html_writer::div(html_writer::tag('strong',(string)$data['summary'][$key]) .
        html_writer::tag('small',get_string($langkey,'local_kbacompany')), 'kba-indicator-kpi');
}
echo html_writer::div($kpis,'kba-indicator-kpis mb-4');

$missingrows = array_values(array_filter($data['rows'], static function(array $row): bool {
    return (string)$row['type'] === 'missing_plan';
}));
if ($missingrows) {
    echo $OUTPUT->heading(get_string('battleplan_week_launch_title','local_kbacompany'),3);
    echo html_writer::div(get_string('battleplan_week_launch_rule','local_kbacompany'), 'alert alert-warning');
    echo html_writer::start_tag('form', ['method'=>'post', 'class'=>'border rounded p-3 mb-4']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'action','value'=>'launchweek']);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'week','value'=>$data['week_start']]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'orgnode','value'=>$data['orgnode_filter']]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'gap','value'=>$data['gap_filter']]);
    echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    $lt = new html_table();
    $lt->head = [get_string('battleplan_week_launch_select','local_kbacompany'),
        get_string('battleplan_employee','local_kbacompany'), get_string('battleplan_position','local_kbacompany'),
        get_string('battleplan_overview_unit','local_kbacompany')];
    foreach ($missingrows as $row) {
        $token = battle_plan_week_launch_service::candidate_token((int)$row['employee_id'], (string)$row['position']['uuid']);
        $checkbox = html_writer::empty_tag('input', [
            'type'=>'checkbox', 'name'=>'assignments[]', 'value'=>$token, 'aria-label'=>get_string('battleplan_week_launch_select','local_kbacompany'),
        ]);
        $lt->data[] = [$checkbox, s($row['employee_name']), s($row['position']['name']),
            s($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany'))];
    }
    echo html_writer::table($lt);
    $reviewerdefault = isset($people[(int)$USER->id]) ? (int)$USER->id : 0;
    echo html_writer::start_tag('div', ['class'=>'form-row align-items-end']);
    echo html_writer::start_tag('div', ['class'=>'form-group col-md-4']);
    echo html_writer::label(get_string('battleplan_reviewer','local_kbacompany'), 'bpweek_reviewer');
    echo html_writer::select($people, 'reviewerid', $reviewerdefault,
        [''=>get_string('battleplan_selectreviewer','local_kbacompany')],
        ['id'=>'bpweek_reviewer','class'=>'form-control','required'=>'required']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'form-group col-md-4']);
    echo html_writer::label(get_string('battleplan_observer','local_kbacompany'), 'bpweek_observer');
    echo html_writer::select([0=>get_string('battleplan_noobserver','local_kbacompany')] + $people,
        'observerid', 0, false, ['id'=>'bpweek_observer','class'=>'form-control']);
    echo html_writer::end_tag('div');
    echo html_writer::start_tag('div', ['class'=>'form-group col-md-4']);
    echo html_writer::tag('button', get_string('battleplan_week_launch_submit','local_kbacompany'),
        ['type'=>'submit','class'=>'btn btn-primary']);
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('div');
    echo html_writer::end_tag('form');
}

echo $OUTPUT->heading(get_string('battleplan_coverage_by_unit','local_kbacompany'),3);
if (!$data['by_unit']) {
    echo html_writer::div('—','text-muted mb-3');
} else {
    $t = new html_table();
    $t->head = [get_string('battleplan_overview_unit','local_kbacompany'),
        get_string('battleplan_coverage_expected_assignments','local_kbacompany'),
        get_string('battleplan_coverage_covered_assignments','local_kbacompany'),
        get_string('battleplan_coverage_missing_plans','local_kbacompany'),
        get_string('battleplan_coverage_positions_without_critical','local_kbacompany'),
        get_string('battleplan_coverage_critical_without_norm','local_kbacompany')];
    foreach ($data['by_unit'] as $unit) {
        $t->data[] = [s($unit['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),
            (int)$unit['expected_assignments'], (int)$unit['covered_assignments'], (int)$unit['missing_plans'],
            (int)$unit['positions_without_critical'], (int)$unit['critical_without_norm']];
    }
    echo html_writer::table($t);
}

echo $OUTPUT->heading(get_string('battleplan_coverage_gaps','local_kbacompany'),3);
if (!$data['rows']) {
    echo $OUTPUT->notification(get_string('battleplan_coverage_empty','local_kbacompany'),'success');
} else {
    $t = new html_table();
    $t->head = [get_string('battleplan_coverage_gap_type','local_kbacompany'),
        get_string('battleplan_employee','local_kbacompany'), get_string('battleplan_position','local_kbacompany'),
        get_string('battleplan_overview_unit','local_kbacompany'), get_string('battleplan_coverage_details','local_kbacompany'),
        get_string('indicator_actions','local_kbacompany')];
    foreach ($data['rows'] as $row) {
        $type = get_string('battleplan_coverage_gap_'.$row['type'],'local_kbacompany');
        $details = $row['details'] !== '' ? s($row['details']) : '—';
        $actionlabel = $row['action_kind'] === 'battleplan' ?
            get_string('battleplan_coverage_open_create_plan','local_kbacompany') :
            get_string('battleplan_coverage_open_indicators','local_kbacompany');
        $t->data[] = [s($type), s($row['employee_name'] ?: '—'), s($row['position']['name']),
            s($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),
            $details, html_writer::link($row['action_url'],$actionlabel)];
    }
    echo html_writer::table($t);
}
\local_kbacompany\local\bms_page_shell_service::finish_and_exit($bmsshell);
