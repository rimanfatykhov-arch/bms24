<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\management_cycle_report_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$cycleuuid = required_param('cycle', PARAM_RAW_TRIMMED);
$data = management_cycle_report_service::context($uuid, (int)$USER->id, $cycleuuid);
$c = $data['cycle'];

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/management_cycle_report.php', ['uuid'=>$uuid,'cycle'=>$cycleuuid]));
$PAGE->set_title(get_string('mcycle_report_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));
$shell = \local_kbacompany\local\bms_page_shell_service::begin($uuid, (int)$USER->id, 'managementcycles', get_string('mcycle_report_title','local_kbacompany'));

echo bms_workspace_ux_service::management_nav($uuid, 'cycles');
$back = html_writer::link(new moodle_url('/local/kbacompany/management_cycles.php',['uuid'=>$uuid,'cycle'=>$cycleuuid]),
    get_string('mcycle_report_back','local_kbacompany'), ['class'=>'btn btn-outline-secondary btn-sm']);
echo bms_workspace_ux_service::record_header(
    get_string('mcycle_report_title','local_kbacompany'),
    get_string($data['is_advisory'] ? 'mcycle_report_intro_advisory' : 'mcycle_report_intro','local_kbacompany'),
    get_string('management_workspace_eyebrow','local_kbacompany'),
    s($c['title'].' · '.$c['periodstart'].' — '.$c['periodend']), $back
);

echo html_writer::div(get_string('mcycle_report_human_review_notice','local_kbacompany'), 'alert alert-info');

echo html_writer::start_div('kbacompany-summary-grid');
foreach ([
    [$data['meetings']['summary']['meetings_closed'].'/'.$data['meetings']['summary']['meetings'],'mcycle_report_kpi_sessions'],
    [$data['meetings']['summary']['accepted_decisions'],'mcycle_report_kpi_decisions'],
    [$data['execution']['summary']['tasks_done'].'/'.$data['execution']['summary']['tasks'],'mcycle_report_kpi_tasks'],
    [$data['a3']['summary']['closed'].'/'.$data['a3']['summary']['total'],'mcycle_report_kpi_a3'],
] as $card) {
    echo html_writer::start_div('kbacompany-summary-card');
    echo html_writer::div((string)$card[0], 'kbacompany-summary-value');
    echo html_writer::div(get_string($card[1],'local_kbacompany'), 'kbacompany-summary-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

// 1. Delivery / management activity.
echo $OUTPUT->heading(get_string($data['is_advisory'] ? 'mcycle_report_delivery_advisory' : 'mcycle_report_delivery','local_kbacompany'), 2, 'h4 mt-4');
$delivery = new html_table();
$delivery->attributes['class'] = 'table table-sm table-hover';
$delivery->head = [get_string('mcycle_checkpoint','local_kbacompany'),get_string('mcycle_report_meeting','local_kbacompany'),get_string('mcycle_status','local_kbacompany'),get_string('mcycle_result','local_kbacompany')];
foreach ($data['meetings']['rows'] as $row) {
    $meeting = $row['meeting'];
    $meetingcell = $meeting ? html_writer::link($meeting['url'], s($meeting['title'])) : '—';
    $status = $meeting ? (string)$meeting['status'] : (string)$row['checkpoint_status'];
    $result = $meeting && trim((string)$meeting['result']) !== '' ? (string)$meeting['result'] : (string)$row['checkpoint_result'];
    $delivery->data[] = [s($row['checkpoint']), $meetingcell, s($status), s($result)];
}
echo html_writer::table($delivery);

// 2. Observed business result, explicitly not causal attribution.
echo $OUTPUT->heading(get_string('mcycle_report_effect_title','local_kbacompany'), 2, 'h4 mt-4');
echo html_writer::div(get_string('mcycle_report_effect_note','local_kbacompany'), 'alert alert-light border');
if (!$data['indicators']) {
    echo html_writer::div(get_string('mcycle_report_no_indicators','local_kbacompany'),'text-muted');
} else {
    $t = new html_table(); $t->attributes['class']='table table-sm table-hover';
    $t->head=[get_string('mcycle_report_indicator','local_kbacompany'),get_string('mcycle_report_first_fact','local_kbacompany'),get_string('mcycle_report_last_fact','local_kbacompany'),get_string('mcycle_report_plan','local_kbacompany'),get_string('mcycle_report_delta','local_kbacompany')];
    foreach ($data['indicators'] as $m) {
        $first = $m['first_fact'] ? $m['first_fact']['value'].' · '.$m['first_fact']['period'] : '—';
        $last = $m['last_fact'] ? $m['last_fact']['value'].' · '.$m['last_fact']['period'] : '—';
        $plan = $m['latest_plan'] ? $m['latest_plan']['value'].' · '.$m['latest_plan']['period'] : '—';
        $delta = $m['delta'] === null ? '—' : (string)$m['delta'];
        $t->data[]=[s(trim($m['code'].' '.$m['name'])),s($first),s($last),s($plan),s($delta.' '.$m['unit'])];
    }
    echo html_writer::table($t);
}

// 3. Execution evidence.
echo $OUTPUT->heading(get_string('mcycle_report_execution_title','local_kbacompany'),2,'h4 mt-4');
$summary = $data['execution']['summary'];
echo html_writer::div(get_string('mcycle_report_execution_summary','local_kbacompany',(object)[
    'done'=>$summary['tasks_done'],'total'=>$summary['tasks'],'open'=>$summary['tasks_open'],
    'plan'=>round($summary['plan_minutes']/60,1),'fact'=>round($summary['fact_minutes']/60,1),
]), 'mb-2');
if ($data['execution']['tasks']) {
    $t=new html_table();$t->attributes['class']='table table-sm table-hover';
    $t->head=[get_string('mcycle_report_task_title','local_kbacompany'),get_string('mcycle_report_task_product','local_kbacompany'),get_string('mcycle_report_task_status','local_kbacompany'),get_string('mcycle_report_task_result','local_kbacompany')];
    foreach ($data['execution']['tasks'] as $task) {
        $t->data[]=[html_writer::link($task['url'],s($task['title'])),s($task['product']),s($task['status']),s($task['result'])];
    }
    echo html_writer::table($t);
}

// 4. A3 and standardization evidence.
echo $OUTPUT->heading(get_string('mcycle_report_a3_title','local_kbacompany'),2,'h4 mt-4');
if (!$data['a3']['rows']) {
    echo html_writer::div(get_string('mcycle_report_no_a3','local_kbacompany'),'text-muted');
} else {
    $t=new html_table();$t->attributes['class']='table table-sm table-hover';
    $t->head=[get_string('a3_name','local_kbacompany'),get_string('a3_status','local_kbacompany'),get_string('a3_baseline_fact','local_kbacompany'),get_string('a3_repeat_fact','local_kbacompany'),get_string('mcycle_report_standardization','local_kbacompany')];
    foreach($data['a3']['rows'] as $a3){
        $confirmed=[];foreach($a3['standardizations'] as $std){if($std['status']==='confirmed'){$confirmed[]=trim($std['targetlabel'].' '.$std['proposal']);}}
        $t->data[]=[html_writer::link($a3['url'],s($a3['title'])),s($a3['status']),s($a3['baseline_fact']),s($a3['repeat_fact']),s(implode('; ',$confirmed))];
    }
    echo html_writer::table($t);
}

// 5. Accepted/proposed decisions.
echo $OUTPUT->heading(get_string('mcycle_report_decisions_title','local_kbacompany'),2,'h4 mt-4');
$decisionrows=[];
foreach($data['meetings']['rows'] as $row){if(!$row['meeting']){continue;}foreach($row['meeting']['decisions'] as $d){$decisionrows[]=$d;}}
if(!$decisionrows){echo html_writer::div(get_string('mcycle_report_no_decisions','local_kbacompany'),'text-muted');}
else{$t=new html_table();$t->attributes['class']='table table-sm table-hover';$t->head=[get_string('mcycle_report_decision_title','local_kbacompany'),get_string('mcycle_report_decision_owner','local_kbacompany'),get_string('mcycle_report_decision_status','local_kbacompany'),get_string('mcycle_report_decision_expected','local_kbacompany')];foreach($decisionrows as $d){$t->data[]=[s($d['title']),s($d['owner_name']),s($d['status']),s($d['expected_result'])];}echo html_writer::table($t);}

// 6. Continuation / next period.
echo $OUTPUT->heading(get_string('mcycle_report_next_title','local_kbacompany'),2,'h4 mt-4');
if(!$data['next_cycles']){echo html_writer::div(get_string('mcycle_report_next_empty','local_kbacompany'),'text-muted');}
else{foreach($data['next_cycles'] as $next){echo html_writer::div(html_writer::link($next['url'],s($next['title'])).' · '.s($next['periodstart'].' — '.$next['periodend'].' · '.$next['status']),'mb-1');}}

// 7. Human-reviewed copy draft. It intentionally contains no inferred attribution.
echo $OUTPUT->heading(get_string('mcycle_report_draft_title','local_kbacompany'),2,'h4 mt-4');
echo html_writer::tag('textarea',s($data['draft_text']),['class'=>'form-control','rows'=>18,'readonly'=>'readonly']);
echo html_writer::div(get_string('mcycle_report_draft_footer','local_kbacompany'),'small text-muted mt-2');

\local_kbacompany\local\bms_page_shell_service::finish($shell);
