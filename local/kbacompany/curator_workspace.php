<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\management_cycle_template_service;
use local_kbacompany\local\management_attention_workspace_service;
require_login();
$context=context_system::instance(); require_capability('local/kbacompany:viewowncompany',$context);
$uuid=optional_param('uuid','',PARAM_RAW_TRIMMED);
if($uuid===''){
    $data=management_attention_workspace_service::curator_portfolio((int)$USER->id);
    $PAGE->set_context($context);$PAGE->set_url(new moodle_url('/local/kbacompany/curator_workspace.php'));
    $PAGE->set_title(get_string('curator_portfolio_title','local_kbacompany'));$PAGE->set_heading(get_string('pluginname','local_kbacompany'));
    echo $OUTPUT->header();
    echo html_writer::tag('h1',get_string('curator_portfolio_title','local_kbacompany'));
    echo html_writer::tag('p',get_string('curator_portfolio_intro','local_kbacompany'),['class'=>'text-muted']);
    if(!$data['rows']){echo $OUTPUT->notification(get_string('curator_portfolio_empty','local_kbacompany'),'info');}
    else{$t=new html_table();$t->head=[get_string('owner_ws_company','local_kbacompany'),get_string('mcycle_name','local_kbacompany'),get_string('mcycle_period','local_kbacompany'),get_string('owner_ws_col_attention','local_kbacompany')];foreach($data['rows'] as $r){$t->data[]=[html_writer::link($r['url'],s($r['companyname'])),s($r['title']),s($r['period']),(string)$r['attention']];}echo html_writer::table($t);}echo $OUTPUT->footer();exit;
}
$data=management_cycle_template_service::curator_context($uuid,(int)$USER->id);
$attention=management_attention_workspace_service::owner_context($uuid,(int)$USER->id,true);
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacompany/curator_workspace.php',['uuid'=>$uuid]));
$PAGE->set_title(get_string('curator_workspace_title','local_kbacompany')); $PAGE->set_heading(get_string('pluginname','local_kbacompany'));
$shell=\local_kbacompany\local\bms_page_shell_service::begin($uuid,(int)$USER->id,'management',get_string('curator_workspace_title','local_kbacompany'));
echo bms_workspace_ux_service::management_nav($uuid,'curator');
$actions=html_writer::link(new moodle_url('/local/kbacompany/curator_workspace.php'),get_string('curator_portfolio_open','local_kbacompany'),['class'=>'btn btn-outline-secondary btn-sm']);
echo bms_workspace_ux_service::record_header(get_string('curator_workspace_title','local_kbacompany'),get_string('curator_workspace_intro_v2','local_kbacompany'),get_string('management_workspace_eyebrow','local_kbacompany'),'',$actions);
echo html_writer::start_div('kbacompany-summary-grid');foreach ([['active','curator_kpi_active'],['draft','curator_kpi_draft'],['overdue_checkpoints','curator_kpi_overdue'],['ready_to_close','curator_kpi_close']] as $c){echo html_writer::start_div('kbacompany-summary-card');echo html_writer::div((string)$data['summary'][$c[0]],'kbacompany-summary-value');echo html_writer::div(get_string($c[1],'local_kbacompany'),'kbacompany-summary-label');echo html_writer::end_div();}echo html_writer::end_div();
echo html_writer::tag('h3',get_string('curator_attention_title','local_kbacompany'),['class'=>'mt-4']);
if(!$attention['items']){echo html_writer::div(get_string('curator_attention_empty','local_kbacompany'),'alert alert-success');}else{$t=new html_table();$t->attributes['class']='table table-sm table-hover';$t->head=[get_string('owner_ws_col_attention','local_kbacompany'),get_string('owner_ws_col_context','local_kbacompany'),get_string('owner_ws_col_next','local_kbacompany')];foreach(array_slice($attention['items'],0,15) as $r){$t->data[]=[html_writer::link($r['url'],s($r['title'])),s($r['detail']),html_writer::tag('strong',s($r['next']))];}echo html_writer::table($t);}
echo html_writer::tag('h3',get_string('curator_cycles_title','local_kbacompany'),['class'=>'mt-4']);
if(!$data['rows']){echo html_writer::div(get_string('curator_empty','local_kbacompany'),'alert alert-secondary mt-3');}else{$table=new html_table();$table->attributes['class']='table table-sm table-hover mt-3';$table->head=[get_string('mcycle_name','local_kbacompany'),get_string('mcycle_period','local_kbacompany'),get_string('mcycle_owner','local_kbacompany'),get_string('mcycle_status','local_kbacompany'),get_string('mcycle_checkpoints_title','local_kbacompany')];foreach($data['rows'] as $r){$table->data[]=[html_writer::link(new moodle_url('/local/kbacompany/management_cycles.php',['uuid'=>$uuid,'cycle'=>$r['uuid']]),s($r['title'])),s($r['periodstart'].' — '.$r['periodend']),s($r['ownername']),get_string('mcycle_status_'.$r['status'],'local_kbacompany'),s($r['checkpoint_done'].'/'.$r['checkpoint_total'].($r['checkpoint_overdue']?' · '.get_string('curator_overdue_count','local_kbacompany',$r['checkpoint_overdue']):''))];}echo html_writer::table($table);} 
\local_kbacompany\local\bms_page_shell_service::finish($shell);
