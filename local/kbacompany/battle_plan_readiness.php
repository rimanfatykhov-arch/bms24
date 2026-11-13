<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\battle_plan_coverage_service;
use local_kbacompany\local\battle_plan_week_readiness_service;
use local_kbacompany\local\battle_plan_week_transition_service;
use local_kbacompany\local\bms24_ui_kit_service;

require_login();
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$orgnode = optional_param('orgnode', '', PARAM_ALPHANUMEXT);
$readiness = optional_param('readiness', 'all', PARAM_ALPHAEXT);

$transitionresult = null;
$transitionerror = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_sesskey();
    $bulkaction = optional_param('bulk_action', '', PARAM_ALPHA);
    $selectedplans = optional_param_array('plans', [], PARAM_ALPHANUMEXT);
    if ($bulkaction !== '') {
        try {
            $transitionresult = battle_plan_week_transition_service::execute($uuid, (int)$USER->id, $week, $bulkaction, $selectedplans);
        } catch (\Throwable $e) {
            $transitionerror = $e->getMessage();
        }
    }
}
$data = battle_plan_week_readiness_service::context($uuid, (int)$USER->id, $week, $orgnode, $readiness);
$coverage = battle_plan_coverage_service::context($uuid, (int)$USER->id, $data['week_start'], $data['orgnode_filter'], 'missing_plan');
$dashboarddata = $data['readiness_filter'] === 'all' ? $data :
    battle_plan_week_readiness_service::context($uuid, (int)$USER->id, $data['week_start'], $data['orgnode_filter'], 'all');
$readinesscounts = array_fill_keys(battle_plan_week_readiness_service::READINESS_FILTERS, 0);
foreach ($dashboarddata['rows'] as $dashboardrow) {
    if (isset($readinesscounts[$dashboardrow['readiness']])) { $readinesscounts[$dashboardrow['readiness']]++; }
}
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_readiness.php', ['uuid'=>$uuid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('battleplan_readiness_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'battleplan',
    get_string('battleplan_readiness_title','local_kbacompany'));
echo $OUTPUT->heading(get_string('battleplan_readiness_title','local_kbacompany'));
echo html_writer::tag('p', get_string('battleplan_readiness_intro','local_kbacompany'), ['class'=>'lead']);
echo html_writer::div(get_string('battleplan_readiness_rule','local_kbacompany'), 'alert alert-info');
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$uuid]),
    get_string('battleplan_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_overview.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_overview_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_coverage.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_coverage_title','local_kbacompany'), ['class'=>'btn btn-outline-primary']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_acceptance.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_acceptance_title','local_kbacompany'), ['class'=>'btn btn-outline-primary ml-2']);
if ($transitionresult !== null) {
    $a = (object)$transitionresult;
    echo $OUTPUT->notification(get_string('battleplan_week_transition_result','local_kbacompany',$a),
        $transitionresult['failed'] > 0 ? 'warning' : 'success');
}
if ($transitionerror !== '') {
    echo $OUTPUT->notification(s($transitionerror), 'error');
}

$nodeopts = [''=>get_string('battleplan_overview_all_units','local_kbacompany')];
foreach ($data['orgnodes'] as $node) { $nodeopts[$node['uuid']] = $node['name']; }
$readyopts = ['all'=>get_string('battleplan_readiness_all','local_kbacompany')];
foreach (battle_plan_week_readiness_service::READINESS_FILTERS as $code) {
    $readyopts[$code] = get_string('battleplan_readiness_'.$code,'local_kbacompany');
}
echo bms24_ui_kit_service::filterbar(
    new moodle_url('/local/kbacompany/battle_plan_readiness.php'),
    get_string('battleplan_readiness_title','local_kbacompany'),
    [
        ['id'=>'bprd_week','name'=>'week','label'=>get_string('battleplan_week','local_kbacompany'),'type'=>'date','value'=>$data['week_start']],
        ['id'=>'bprd_unit','name'=>'orgnode','label'=>get_string('battleplan_overview_unit','local_kbacompany'),'type'=>'select','value'=>$data['orgnode_filter'],'options'=>$nodeopts],
        ['id'=>'bprd_ready','name'=>'readiness','label'=>get_string('battleplan_readiness_filter','local_kbacompany'),'type'=>'select','value'=>$data['readiness_filter'],'options'=>$readyopts],
    ],
    ['uuid'=>$uuid],
    new moodle_url('/local/kbacompany/battle_plan_readiness.php',['uuid'=>$uuid]),
    get_string('battleplan_show','local_kbacompany'),
    get_string('reset','core')
);
echo html_writer::tag('p', s(battle_plan_service::period_label($data['week_start'],$data['week_end'])), ['class'=>'text-muted']);

$kpis = '';
$coverageurl = new moodle_url('/local/kbacompany/battle_plan_coverage.php', [
    'uuid'=>$uuid, 'week'=>$data['week_start'], 'orgnode'=>$data['orgnode_filter'], 'gap'=>'missing_plan'
]);
$kpis .= html_writer::link($coverageurl,
    html_writer::tag('strong',(string)$coverage['summary']['missing_plans']) .
    html_writer::tag('small',get_string('battleplan_coverage_missing_plans','local_kbacompany')),
    ['class'=>'bms-bpu-stat bms-bpu-stat-link']);
foreach ([
    'blocked'=>'battleplan_readiness_blocked',
    'ready_submit'=>'battleplan_readiness_ready_submit',
    'ready_selfapprove'=>'battleplan_readiness_ready_selfapprove',
    'ready_activate'=>'battleplan_readiness_ready_activate',
    'active'=>'battleplan_readiness_active',
    'closed'=>'battleplan_readiness_closed',
] as $state=>$langkey) {
    $target = new moodle_url('/local/kbacompany/battle_plan_readiness.php', [
        'uuid'=>$uuid, 'week'=>$data['week_start'], 'orgnode'=>$data['orgnode_filter'], 'readiness'=>$state
    ]);
    $kpis .= html_writer::link($target,
        html_writer::tag('strong',(string)($readinesscounts[$state] ?? 0)) .
        html_writer::tag('small',get_string($langkey,'local_kbacompany')),
        ['class'=>'bms-bpu-stat bms-bpu-stat-link']);
}
echo html_writer::div($kpis,'bms-bpu-stats bms-bpu-week-readiness-stats');

$missingrows = array_values(array_filter($coverage['rows'], static function(array $row): bool {
    return (string)$row['type'] === 'missing_plan';
}));
if ($missingrows) {
    echo $OUTPUT->heading(get_string('battleplan_coverage_missing_plans','local_kbacompany'),3);
    $missinghtml = '';
    foreach (array_slice($missingrows, 0, 8) as $row) {
        $identity = html_writer::tag('strong', s($row['employee_name'])) .
            html_writer::span(s($row['position']['name']), 'bms-bpu-note');
        $reason = s($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany'));
        $missinghtml .= html_writer::link($row['action_url'],
            html_writer::div($identity, 'bms-bpu-attention-main') .
            html_writer::span($reason, 'bms-bpu-attention-reason'),
            ['class'=>'bms-bpu-attention-row']);
    }
    echo html_writer::div($missinghtml, 'bms-bpu-attention-list');
    if (count($missingrows) > 8) {
        echo html_writer::div(html_writer::link($coverageurl,
            get_string('battleplan_coverage_title','local_kbacompany'), ['class'=>'btn btn-outline-primary btn-sm']),
            'bms-bpu-actions mt-2');
    }
}

if ($data['by_unit']) {
    echo $OUTPUT->heading(get_string('battleplan_readiness_by_unit','local_kbacompany'),3);
    $t = new html_table();
    $t->head = [get_string('battleplan_overview_unit','local_kbacompany'),
        get_string('battleplan_readiness_plans','local_kbacompany'),
        get_string('battleplan_readiness_blocked_count','local_kbacompany'),
        get_string('battleplan_readiness_ready_count','local_kbacompany'),
        get_string('battleplan_readiness_active_count','local_kbacompany'),
        get_string('battleplan_readiness_advisory_count','local_kbacompany')];
    foreach ($data['by_unit'] as $unit) {
        $t->data[] = [s($unit['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),
            (int)$unit['plans'], (int)$unit['blocked'], (int)$unit['ready_transition'],
            (int)$unit['active'], (int)$unit['with_advisories']];
    }
    echo html_writer::table($t);
}

echo $OUTPUT->heading(get_string('battleplan_readiness_plans_title','local_kbacompany'),3);
if (!$data['rows']) {
    echo $OUTPUT->notification(get_string('battleplan_readiness_empty','local_kbacompany'),'info');
} else {
    echo html_writer::start_tag('form',['method'=>'post','class'=>'mb-3']);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'week','value'=>$data['week_start']]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'orgnode','value'=>$data['orgnode_filter']]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'readiness','value'=>$data['readiness_filter']]);
    echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
    $t = new html_table();
    $t->head = [get_string('battleplan_week_transition_select','local_kbacompany'), get_string('battleplan_readiness_state','local_kbacompany'),
        get_string('battleplan_employee','local_kbacompany'), get_string('battleplan_position','local_kbacompany'),
        get_string('battleplan_overview_unit','local_kbacompany'), get_string('battleplan_status','local_kbacompany'),
        get_string('battleplan_reviewer','local_kbacompany'), get_string('battleplan_readiness_tasks','local_kbacompany'),
        get_string('battleplan_readiness_blockers','local_kbacompany'),
        get_string('battleplan_readiness_advisories','local_kbacompany'), get_string('indicator_actions','local_kbacompany')];
    foreach ($data['rows'] as $row) {
        $state = get_string('battleplan_readiness_'.$row['readiness'],'local_kbacompany');
        $cls = $row['readiness'] === 'blocked' ? 'badge badge-danger' :
            (in_array($row['readiness'],['ready_submit','ready_selfapprove','ready_activate'],true) ? 'badge badge-success' : 'badge badge-secondary');
        $blockers = [];
        foreach ($row['blockers'] as $code) { $blockers[] = get_string('battleplan_readiness_blocker_'.$code,'local_kbacompany'); }
        $advisories = [];
        foreach ($row['advisories'] as $code) { $advisories[] = get_string('battleplan_readiness_advisory_'.$code,'local_kbacompany'); }
        $actionlabel = $row['transition_action'] !== '' ?
            get_string('battleplan_readiness_action_'.$row['transition_action'],'local_kbacompany') :
            get_string('battleplan_open','local_kbacompany');
        $reviewer = $row['reviewer_name'] !== '' ? s($row['reviewer_name']) : '—';
        $selectable = in_array($row['readiness'], ['ready_submit','ready_selfapprove','ready_activate'], true);
        $checkbox = $selectable ? html_writer::empty_tag('input',[
            'type'=>'checkbox','name'=>'plans[]','value'=>$row['uuid'],'aria-label'=>get_string('battleplan_week_transition_select','local_kbacompany')
        ]) : '—';
        $t->data[] = [$checkbox, html_writer::span(s($state),$cls), s($row['employee_name']), s($row['position']['name']),
            s($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),
            get_string('battleplan_status_'.$row['status'],'local_kbacompany'), $reviewer, (int)$row['task_count'],
            $blockers ? s(implode('; ', $blockers)) : '—', $advisories ? s(implode('; ', $advisories)) : '—',
            html_writer::link($row['url'],$actionlabel)];
    }
    echo html_writer::table($t);
    echo html_writer::tag('button',get_string('battleplan_week_transition_submit','local_kbacompany'),[
        'type'=>'submit','name'=>'bulk_action','value'=>'submit','class'=>'btn btn-primary mr-2']);
    echo html_writer::tag('button',get_string('battleplan_week_transition_approve','local_kbacompany'),[
        'type'=>'submit','name'=>'bulk_action','value'=>'approve','class'=>'btn btn-success']);
    echo html_writer::end_tag('form');
}
\local_kbacompany\local\bms_page_shell_service::finish_and_exit($bmsshell);
