<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\management_attention_workspace_service;
require_login();
$context=context_system::instance(); require_capability('local/kbacompany:viewowncompany',$context);
$uuid=required_param('uuid',PARAM_RAW_TRIMMED);
$data=management_attention_workspace_service::owner_context($uuid,(int)$USER->id);
$PAGE->set_context($context); $PAGE->set_url(new moodle_url('/local/kbacompany/owner_workspace.php',['uuid'=>$uuid]));
$PAGE->set_title(get_string('owner_ws_title','local_kbacompany')); $PAGE->set_heading(get_string('pluginname','local_kbacompany'));
$shell=\local_kbacompany\local\bms_page_shell_service::begin($uuid,(int)$USER->id,'management',get_string('owner_ws_title','local_kbacompany'));
echo bms_workspace_ux_service::management_nav($uuid,'ownerws');
echo bms_workspace_ux_service::record_header(get_string('owner_ws_title','local_kbacompany'),get_string('owner_ws_intro','local_kbacompany'),get_string('management_workspace_eyebrow','local_kbacompany'));
echo html_writer::start_div('kbacompany-summary-grid');
foreach ([['decisions','owner_ws_kpi_decisions'],['plan_reviews','owner_ws_kpi_plans'],['checkpoints','owner_ws_kpi_checkpoints'],['coordination','owner_ws_kpi_coordination'],['a3','owner_ws_kpi_a3']] as $c){echo html_writer::start_div('kbacompany-summary-card');echo html_writer::div((string)$data['summary'][$c[0]],'kbacompany-summary-value');echo html_writer::div(get_string($c[1],'local_kbacompany'),'kbacompany-summary-label');echo html_writer::end_div();}echo html_writer::end_div();
if(!$data['items']){echo html_writer::div(get_string('owner_ws_empty','local_kbacompany'),'alert alert-success mt-3');}else{$table=new html_table();$table->attributes['class']='table table-sm table-hover mt-3';$table->head=[get_string('owner_ws_col_attention','local_kbacompany'),get_string('owner_ws_col_context','local_kbacompany'),get_string('owner_ws_col_next','local_kbacompany')];foreach($data['items'] as $r){$table->data[]=[html_writer::link($r['url'],s($r['title'])),s($r['detail']),html_writer::tag('strong',s($r['next']))];}echo html_writer::table($table);}
\local_kbacompany\local\bms_page_shell_service::finish($shell);
