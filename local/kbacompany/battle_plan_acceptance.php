<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\weekly_management_acceptance_service;
use local_kbacompany\local\bms24_ui_kit_service;

require_login();
$uuid = required_param('uuid', PARAM_ALPHANUMEXT);
$week = optional_param('week', '', PARAM_TEXT);
$orgnode = optional_param('orgnode', '', PARAM_ALPHANUMEXT);

$data = weekly_management_acceptance_service::context($uuid, (int)$USER->id, $week, $orgnode);
$PAGE->set_url(new moodle_url('/local/kbacompany/battle_plan_acceptance.php', ['uuid'=>$uuid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('battleplan_acceptance_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));

$bmsshell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'battleplan',
    get_string('battleplan_acceptance_title','local_kbacompany'));
echo $OUTPUT->heading(get_string('battleplan_acceptance_title','local_kbacompany'));
echo html_writer::tag('p',get_string('battleplan_acceptance_intro','local_kbacompany'),['class'=>'lead']);
echo html_writer::div(get_string('battleplan_acceptance_rule','local_kbacompany'),'alert alert-info');
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$uuid]),
    get_string('battleplan_title','local_kbacompany'),['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_readiness.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_readiness_title','local_kbacompany'),['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_coverage.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_coverage_title','local_kbacompany'),['class'=>'btn btn-outline-primary mr-2']);
echo html_writer::link(new moodle_url('/local/kbacompany/battle_plan_overview.php',['uuid'=>$uuid,'week'=>$data['week_start']]),
    get_string('battleplan_overview_title','local_kbacompany'),['class'=>'btn btn-outline-primary']);

$nodeopts=[''=>get_string('battleplan_overview_all_units','local_kbacompany')];
foreach($data['orgnodes'] as $node){$nodeopts[$node['uuid']]=$node['name'];}
echo bms24_ui_kit_service::filterbar(
    new moodle_url('/local/kbacompany/battle_plan_acceptance.php'),
    get_string('battleplan_acceptance_title','local_kbacompany'),
    [
        ['id'=>'bpac_week','name'=>'week','label'=>get_string('battleplan_week','local_kbacompany'),'type'=>'date','value'=>$data['week_start']],
        ['id'=>'bpac_unit','name'=>'orgnode','label'=>get_string('battleplan_overview_unit','local_kbacompany'),'type'=>'select','value'=>$data['orgnode_filter'],'options'=>$nodeopts],
    ],
    ['uuid'=>$uuid],
    new moodle_url('/local/kbacompany/battle_plan_acceptance.php',['uuid'=>$uuid]),
    get_string('battleplan_show','local_kbacompany'),
    get_string('reset','core')
);
echo html_writer::tag('p',s(battle_plan_service::period_label($data['week_start'],$data['week_end'])),['class'=>'text-muted']);

$stateclass = $data['state']==='accepted' ? 'alert alert-success' : ($data['state']==='blocked' ? 'alert alert-danger' : 'alert alert-warning');
echo html_writer::div(
    html_writer::tag('strong',get_string('battleplan_acceptance_state_'.$data['state'],'local_kbacompany')) . ' — ' .
    get_string('battleplan_acceptance_counts','local_kbacompany',(object)['hard'=>$data['hard_count'],'attention'=>$data['attention_count']]),
    $stateclass
);

$kpis='';
foreach([
    ['coverage','expected_assignments','battleplan_coverage_expected_assignments'],
    ['coverage','missing_plans','battleplan_coverage_missing_plans'],
    ['readiness','blocked','battleplan_readiness_blocked_count'],
    ['readiness','ready_transition','battleplan_readiness_ready_count'],
    ['readiness','active','battleplan_readiness_active_count'],
    ['overview','needs_decision','battleplan_overview_needs_decision'],
    ['overview','overdue_tasks','battleplan_overview_overdue_tasks'],
] as $spec){
    list($group,$key,$langkey)=$spec;
    $kpis .= html_writer::div(html_writer::tag('strong',(string)$data[$group][$key]).
        html_writer::tag('small',get_string($langkey,'local_kbacompany')),'kba-indicator-kpi');
}
echo html_writer::div($kpis,'kba-indicator-kpis mb-4');

echo $OUTPUT->heading(get_string('battleplan_acceptance_gates','local_kbacompany'),3);
$t=new html_table();
$t->head=[get_string('battleplan_acceptance_gate','local_kbacompany'),get_string('battleplan_acceptance_level','local_kbacompany'),
    get_string('battleplan_acceptance_count','local_kbacompany'),get_string('battleplan_acceptance_result','local_kbacompany')];
foreach($data['gates'] as $gate){
    $level=get_string('battleplan_acceptance_level_'.$gate['level'],'local_kbacompany');
    $result=$gate['passed']?get_string('battleplan_acceptance_pass','local_kbacompany'):get_string('battleplan_acceptance_fail','local_kbacompany');
    $badge=$gate['passed']?'badge badge-success':($gate['level']==='hard'?'badge badge-danger':'badge badge-warning');
    $t->data[]=[get_string('battleplan_acceptance_gate_'.$gate['code'],'local_kbacompany'),$level,(int)$gate['count'],html_writer::span($result,$badge)];
}
echo html_writer::table($t);

echo html_writer::div(get_string('battleplan_acceptance_not_persisted','local_kbacompany'),'alert alert-secondary mt-3');
\local_kbacompany\local\bms_page_shell_service::finish_and_exit($bmsshell);
