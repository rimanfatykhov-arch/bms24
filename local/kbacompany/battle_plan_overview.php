<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_management_overview_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\task_service;

require_login();
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$orgnode = optional_param('orgnode', '', PARAM_ALPHANUMEXT);
$reviewer = optional_param('reviewer', 0, PARAM_INT);
$attention = optional_param('attention', 'all', PARAM_ALPHAEXT);

$data = battle_plan_management_overview_service::context($uuid, (int)$USER->id, $week, $orgnode, $reviewer, $attention);
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_overview.php', ['uuid'=>$uuid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('battleplan_overview_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'battleplan', get_string('battleplan_overview_title','local_kbacompany'));
echo \local_kbacompany\local\bms_workspace_ux_service::management_nav($uuid, 'week', $data['week_start']);
echo $OUTPUT->heading(get_string('battleplan_overview_title','local_kbacompany'));
echo html_writer::tag('p', get_string('battleplan_overview_intro','local_kbacompany'), ['class'=>'lead']);
echo html_writer::div(get_string('battleplan_overview_rule','local_kbacompany'), 'alert alert-info');
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$uuid]), get_string('battleplan_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
if (task_service::can_manage($uuid, (int)$USER->id)) {
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_coverage.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
        get_string('battleplan_coverage_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
    echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_readiness.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
        get_string('battleplan_readiness_title','local_kbacompany'), ['class'=>'btn btn-outline-primary mr-2']);
}
echo html_writer::link(new moodle_url('/local/kbacompany/indicators.php',['uuid'=>$uuid,'tab'=>'exceptions']), get_string('indicator_tab_exceptions','local_kbacompany'), ['class'=>'btn btn-outline-secondary']);

// Filters.
$nodeopts = [''=>get_string('battleplan_overview_all_units','local_kbacompany')];
foreach ($data['orgnodes'] as $node) { $nodeopts[$node['uuid']] = $node['name']; }
$reviewopts = [0=>get_string('battleplan_overview_all_reviewers','local_kbacompany')] + $data['reviewers'];
$attopts = ['all'=>get_string('battleplan_overview_attention_all','local_kbacompany')];
foreach (battle_plan_management_overview_service::ATTENTION_FILTERS as $code) {
    $attopts[$code] = get_string('battleplan_overview_attention_'.$code,'local_kbacompany');
}
echo html_writer::start_tag('form',['method'=>'get','class'=>'form-inline my-3']);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]);
echo html_writer::label(get_string('battleplan_week','local_kbacompany'),'bpov_week',false,['class'=>'mr-2']);
echo html_writer::empty_tag('input',['type'=>'date','id'=>'bpov_week','name'=>'week','value'=>$data['week_start'],'class'=>'form-control mr-3']);
echo html_writer::label(get_string('battleplan_overview_unit','local_kbacompany'),'bpov_unit',false,['class'=>'mr-2']);
echo html_writer::select($nodeopts,'orgnode',$data['orgnode_filter'],false,['id'=>'bpov_unit','class'=>'form-control mr-3']);
echo html_writer::label(get_string('battleplan_overview_reviewer','local_kbacompany'),'bpov_reviewer',false,['class'=>'mr-2']);
echo html_writer::select($reviewopts,'reviewer',$data['reviewer_filter'],false,['id'=>'bpov_reviewer','class'=>'form-control mr-3']);
echo html_writer::label(get_string('battleplan_overview_attention','local_kbacompany'),'bpov_attention',false,['class'=>'mr-2']);
echo html_writer::select($attopts,'attention',$data['attention_filter'],false,['id'=>'bpov_attention','class'=>'form-control mr-3']);
echo html_writer::tag('button',get_string('battleplan_show','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);
echo html_writer::end_tag('form');

echo html_writer::tag('p', s(battle_plan_service::period_label($data['week_start'],$data['week_end'])), ['class'=>'text-muted']);

$kpis = '';
foreach ([
    'active_plans'=>'battleplan_overview_active_plans',
    'critical_indicators'=>'battleplan_overview_critical_indicators',
    'critical_exceptions'=>'battleplan_overview_critical_exceptions',
    'needs_decision'=>'battleplan_overview_needs_decision',
    'open_tasks'=>'battleplan_overview_open_tasks',
    'overdue_tasks'=>'battleplan_overview_overdue_tasks',
] as $key=>$langkey) {
    $kpis .= html_writer::div(html_writer::tag('strong',(string)$data['summary'][$key]) . html_writer::tag('small',get_string($langkey,'local_kbacompany')), 'kba-indicator-kpi');
}
echo html_writer::div($kpis,'kba-indicator-kpis mb-4');

$renderagg = static function(array $items, string $title) use ($OUTPUT) {
    echo $OUTPUT->heading($title,3);
    if (!$items) { echo html_writer::div('—','text-muted mb-3'); return; }
    $t = new html_table();
    $t->head = [get_string('battleplan_overview_group','local_kbacompany'),get_string('battleplan_overview_active_plans','local_kbacompany'),get_string('battleplan_overview_needs_decision','local_kbacompany'),get_string('battleplan_overview_overdue_tasks','local_kbacompany'),get_string('battleplan_overview_critical_exceptions','local_kbacompany')];
    foreach ($items as $item) { $t->data[]=[s($item['name']),$item['plans'],$item['needs_decision'],$item['overdue_tasks'],$item['critical_exceptions']]; }
    echo html_writer::table($t);
};
$renderagg($data['by_unit'], get_string('battleplan_overview_by_unit','local_kbacompany'));
$renderagg($data['by_reviewer'], get_string('battleplan_overview_by_reviewer','local_kbacompany'));

echo $OUTPUT->heading(get_string('battleplan_overview_plans','local_kbacompany'),3);
if (!$data['rows']) {
    echo $OUTPUT->notification(get_string('battleplan_overview_empty','local_kbacompany'),'info');
} else {
    $t = new html_table();
    $t->head = [get_string('battleplan_overview_attention','local_kbacompany'),get_string('battleplan_employee','local_kbacompany'),get_string('battleplan_position','local_kbacompany'),get_string('battleplan_overview_unit','local_kbacompany'),get_string('battleplan_overview_reviewer','local_kbacompany'),get_string('battleplan_status','local_kbacompany'),get_string('battleplan_overview_critical_indicators','local_kbacompany'),get_string('battleplan_overview_exceptions','local_kbacompany'),get_string('battleplan_overview_tasks','local_kbacompany'),get_string('indicator_actions','local_kbacompany')];
    foreach ($data['rows'] as $row) {
        $att = get_string('battleplan_overview_attention_'.$row['attention'],'local_kbacompany');
        $cls = in_array($row['attention'],['needs_decision','overdue'],true) ? 'badge badge-danger' : ($row['attention']==='critical' ? 'badge badge-warning' : ($row['attention']==='clear' ? 'badge badge-success' : 'badge badge-secondary'));
        $exceptions = get_string('battleplan_overview_exception_counts','local_kbacompany',(object)['all'=>$row['exceptions'],'critical'=>$row['critical_exceptions'],'decision'=>$row['needs_decision']]);
        $tasks = get_string('battleplan_overview_task_counts','local_kbacompany',(object)['open'=>$row['tasks']['open'],'overdue'=>$row['tasks']['overdue'],'done'=>$row['tasks']['done']]);
        $t->data[] = [html_writer::span(s($att),$cls),s($row['employee_name']),s($row['position']['name']),s($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),s($row['reviewer_name'] ?: get_string('battleplan_overview_no_reviewer','local_kbacompany')),get_string('battleplan_status_'.$row['status'],'local_kbacompany'),(int)$row['critical_indicators'],s($exceptions),s($tasks),html_writer::link($row['url'],get_string('battleplan_open','local_kbacompany'))];
    }
    echo html_writer::table($t);
}
\local_kbacompany\local\bms_page_shell_service::finish_and_exit($bmsshell);
