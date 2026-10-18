<?php
require_once(__DIR__ . '/../../config.php');
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\employee_schedule_service;
use local_kbacompany\local\bms_page_shell_service;
require_login();
$context=context_system::instance();
require_capability('local/kbacompany:viewowncompany',$context);
$uuid=company_context_service::normalise_uuid(required_param('uuid',PARAM_RAW_TRIMMED));
$userid=required_param('userid',PARAM_INT);
company_context_service::context($uuid,(int)$USER->id);
if (!task_service::can_manage($uuid,(int)$USER->id)) { throw new required_capability_exception($context,'local/kbacompany:managetasks','nopermissions',''); }
company_context_service::context($uuid,$userid);
$back=new moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$uuid,'user'=>$userid]);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    require_sesskey();
    employee_schedule_service::save($uuid,(int)$USER->id,$userid,required_param('weeklyhours',PARAM_INT));
    redirect($back,get_string('bms_schedule_saved','local_kbacompany'),null,\core\output\notification::NOTIFY_SUCCESS);
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/employee_work_schedule.php',['uuid'=>$uuid,'userid'=>$userid]));
$PAGE->set_title(get_string('bms_schedule_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));
$shell=bms_page_shell_service::begin($uuid,(int)$USER->id,'battleplan',get_string('bms_schedule_title','local_kbacompany'));
echo html_writer::div(html_writer::tag('h2',get_string('bms_schedule_title','local_kbacompany')) .
 html_writer::tag('p',get_string('bms_schedule_note','local_kbacompany')),'mb-3');
echo html_writer::start_tag('form',['method'=>'post','action'=>$PAGE->url->out(false),'class'=>'card p-4','style'=>'max-width:600px']);
echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]);
echo html_writer::label(get_string('bms_schedule_weeklyhours','local_kbacompany'),'weeklyhours');
$options='';$current=employee_schedule_service::hours($uuid,$userid);
foreach ([40,36,20] as $hours) { $options.=html_writer::tag('option', $hours.' '.get_string('bms_schedule_hours','local_kbacompany'), ['value'=>$hours,'selected'=>$current===$hours]); }
echo html_writer::tag('select',$options,['name'=>'weeklyhours','id'=>'weeklyhours','class'=>'form-control mb-3']);
echo html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-success']).' '.html_writer::link($back,get_string('cancel'),['class'=>'btn btn-outline-secondary']);
echo html_writer::end_tag('form');
bms_page_shell_service::finish($shell);
