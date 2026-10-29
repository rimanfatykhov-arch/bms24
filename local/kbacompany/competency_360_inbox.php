<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\competency_360_service;
require_login();
$context=context_system::instance();require_capability('local/kbacompany:viewowncompany',$context);
$uuid=required_param('uuid',PARAM_RAW_TRIMMED);
$PAGE->set_context($context);$PAGE->set_url(new moodle_url('/local/kbacompany/competency_360_inbox.php',['uuid'=>$uuid]));$PAGE->set_title(get_string('competency360_inbox_title','local_kbacompany'));$PAGE->set_heading(get_string('pluginname','local_kbacompany'));
$shell=\local_kbacompany\local\bms_page_shell_service::begin($uuid,(int)$USER->id,'employees',get_string('competency360_inbox_title','local_kbacompany'));
echo $OUTPUT->heading(get_string('competency360_inbox_title','local_kbacompany'),2);echo html_writer::tag('p',get_string('competency360_inbox_intro','local_kbacompany'),['class'=>'text-muted']);
$rows=competency_360_service::pending_for_respondent($uuid,(int)$USER->id);$t=new html_table();$t->head=[get_string('competency360_subject','local_kbacompany'),get_string('name'),get_string('competency360_relationship','local_kbacompany'),get_string('status'),get_string('actions')];foreach($rows as $r){$canopen=$r['cyclestatus']==='open'&&$r['status']!=='completed';$action=$canopen?html_writer::link(new moodle_url('/local/kbacompany/competency_360_respond.php',['uuid'=>$uuid,'cycle'=>$r['cycleuuid']]),get_string('competency360_fill_form','local_kbacompany'),['class'=>'btn btn-sm btn-primary']):'—';$t->data[]=[s($r['subject']),s($r['title']),get_string('competency360_relationship_'.$r['relationship'],'local_kbacompany'),get_string('competency360_response_'.$r['status'],'local_kbacompany'),$action];}if(!$rows){echo $OUTPUT->notification(get_string('competency360_inbox_empty','local_kbacompany'),'info');}else{echo html_writer::div(html_writer::table($t),'table-responsive');}
\local_kbacompany\local\bms_page_shell_service::finish($shell);
