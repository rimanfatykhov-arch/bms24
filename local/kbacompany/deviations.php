<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\deviation_service as deviations;
use local_kbacompany\local\deviation_ui_service as ui;
use local_kbacompany\local\document_request_service as forms;

use local_kbacompany\local\bms_workspace_ux_service;

require_login();
$company=\local_kbacompany\local\company_context_service::normalise_uuid(required_param('uuid',PARAM_RAW_TRIMMED));$actor=(int)$USER->id;
$access=deviations::access($company,$actor);
$object=optional_param('deviation','',PARAM_RAW_TRIMMED);
$view=optional_param('view',$object!==''?'detail':'list',PARAM_ALPHA);
$mode=optional_param('mode','manual',PARAM_ALPHA);
$tab=optional_param('tab','overview',PARAM_ALPHA);
$operation=optional_param('operation','',PARAM_ALPHA);
$action=optional_param('action','',PARAM_ALPHA);
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','HEAD','POST'],true)||($action!==''&&$method!=='POST')){throw new moodle_exception('productpostrequired','local_kbacompany');}
if(!in_array($view,['list','detail','create','edit','action','lookup'],true)){$view=$object!==''?'detail':'list';}
if(!in_array($tab,['overview','analysis','tasks','checks','history'],true)){$tab='overview';}
if(!in_array($mode,['manual','quantitative'],true)){throw new moodle_exception('dv_invalid','local_kbacompany');}
if($view==='list'&&$object!==''){$view='detail';}
if($view==='create'&&$object!==''){throw new moodle_exception('dv_reference','local_kbacompany');}
$page=optional_param('page',0,PARAM_INT);
$model=$object!==''?deviations::record($company,$actor,$object,$tab,$page,optional_param('legacypage',0,PARAM_INT)):null;
if(in_array($view,['detail','edit','action'],true)&&$object===''){throw new moodle_exception('dv_reference','local_kbacompany');}
if($view==='lookup'){
    if($method==='POST'){throw new moodle_exception('productpostrequired','local_kbacompany');}
    $target=optional_param('target','',PARAM_ALPHA);$q=optional_param('q','',PARAM_RAW_TRIMMED);
    if($target==='documents'){$options=deviations::document_options($company,$actor,$q,$page);}
    else if($target==='norms'){$options=deviations::norm_options($company,$actor,$q,$page);}
    else if($target==='positions'){$options=deviations::position_options($company,$actor,$q,$page);}
    else if($target==='tasks'){$options=deviations::task_options($company,$actor,$q,$page);}
    else if($target==='linked'){$options=deviations::linked_options($company,$actor,$object,$q,$page);}
    else{throw new moodle_exception('dv_invalid','local_kbacompany');}
    header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
    echo json_encode(['options'=>$options],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);exit;
}
$errors=[];$values=[];$proof=[];
if($method==='POST'){
    require_sesskey();
    if(!in_array($action,['report','editreport','create','confirm','reject','analyze','link','unlink','check','close','cancel'],true)){throw new moodle_exception('dv_invalid','local_kbacompany');}
    $revision=optional_param('revision',0,PARAM_INT);$key=optional_param('requestkey','',PARAM_ALPHANUM);
    $form=forms::verify($key,$company,$actor,$object,$revision,'deviation_'.$action,'deviations');$proof=['requestkey'=>$key];
    foreach(['title','description','requirement','location','observedon','evidence','documentref','criterion','ownerpositionuuid','duedate','reason','cause','basis','actual','task','outcome','taskengine'] as $field){$values[$field]=optional_param($field,'',PARAM_RAW_TRIMMED);}
    $confirmed=optional_param('confirmed',0,PARAM_INT);$digest=forms::digest($values+['confirmed'=>$confirmed]);$replay=forms::replay($form,$digest);
    if($replay!==null){if(!empty($replay['deviation'])){deviations::read_record($company,$actor,$replay['deviation']);}redirect(ui::url($company,$replay),ui::tr('saved'));}
    if($action==='report'){
        if($object!==''||!$access['can_report']){throw new moodle_exception('dv_permission','local_kbacompany');}
    }else if($action==='create'){
        if($object!==''||!$access['can_manage']){throw new moodle_exception('dv_permission','local_kbacompany');}
    }else if(!$model||empty($model['actions'][$action])){throw new moodle_exception('dv_state','local_kbacompany');}
    if(($object!==''&&(int)$model['selected']->revision!==$revision)||($object===''&&$revision!==0)){$errors['_form']=get_string('dv_state','local_kbacompany');}
    $input=$values+['object'=>$object,'revision'=>$revision];
    $parts=explode(':',$values['basis'],2);$input['approval']=$parts[0];$input['norm']=$parts[1]??'';
    if($action==='unlink'){$parts=explode('|',$values['task'],2);$input['taskengine']=$parts[0];$input['task']=$parts[1]??'';}
    if(!$errors){$errors=deviations::form_errors($company,$actor,$action,$input);}
    if(!in_array($action,['report','editreport','create'],true)&&!$confirmed){$errors['_form']=ui::tr('confirm_required');}
    if(!$errors){
        try{$saved=deviations::command($company,$actor,$action,$input);$destination=['deviation'=>$saved];forms::complete($key,$digest,$destination);redirect(ui::url($company,$destination),ui::tr('saved'));}
        catch(moodle_exception $e){if($e->module!=='local_kbacompany'){throw $e;}$errors['_form']=$e->getMessage();}
    }
    $operation=$action;$view=in_array($action,['report','create'],true)?'create':($action==='editreport'?'edit':'action');$mode=$action==='create'?'quantitative':'manual';
}
if($view==='create'){
    $operation=$mode==='quantitative'?'create':'report';
    if(($operation==='create'&&!$access['can_manage'])||($operation==='report'&&!$access['can_report'])){throw new moodle_exception('dv_permission','local_kbacompany');}
}else if($view==='edit'){$operation='editreport';}
if($method!=='POST'&&in_array($view,['edit','action'],true)&&empty($model['actions'][$operation])){throw new moodle_exception('dv_state','local_kbacompany');}
$options=[];
if(in_array($view,['create','edit','action'],true)){
    if($method!=='POST'){
        $values=['observedon'=>\local_kbacompany\local\business_date_service::day(time())];
        if($view==='edit'){$values=(array)$model['selected'];$ref=json_decode((string)$model['selected']->basisref,true);$values['documentref']=$ref?$ref['uuid'].'|'.$ref['revision']:'';}
        if($operation==='analyze'){$values['cause']=$model['selected']->cause;}
        $r=$model['selected']??null;$proof=forms::issue($company,$actor,$object,$r->revision??0,'deviation_'.$operation,$r?(string)$r->revision:'','deviations');
    }
    if(in_array($operation,['report','editreport'],true)){$options['documents']=deviations::document_options($company,$actor);}
    if($operation==='confirm'){$options['positions']=deviations::position_options($company,$actor);}
    if($operation==='link'){$options['tasks']=deviations::task_options($company,$actor);}
    if($operation==='unlink'){$options['tasks']=deviations::linked_options($company,$actor,$object);}
    if($operation==='create'||($operation==='check'&&$model['selected']->entrytype==='quantitative')){$options['norms']=deviations::norm_options($company,$actor);}
}
$PAGE->set_context(context_system::instance());$PAGE->set_url(ui::url($company,$object!==''?['deviation'=>$object]:[]));
$PAGE->set_title(ui::tr('title'));$PAGE->set_heading(ui::tr('title'));
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/deviation_ui.js'));
$shell=\local_kbacompany\local\bms_page_shell_service::begin($company,$actor,'deviations',ui::tr('title'));
echo html_writer::start_div('bms-dvu');echo ui::errors($errors);
echo bms_workspace_ux_service::governance_nav($company,'deviations');
if($view==='list'){
    $filters=['q'=>optional_param('q','',PARAM_RAW_TRIMMED),'status'=>optional_param('status','',PARAM_ALPHA),
        'entrytype'=>optional_param('entrytype','',PARAM_ALPHA),'scope'=>optional_param('scope','all',PARAM_ALPHA)];
    echo ui::listing($company,deviations::browse($company,$actor,$filters,$page));
}else if(in_array($view,['create','edit','action'],true)){echo ui::form($company,$operation,$options,$values,$errors,$proof,$model);}
else{echo ui::detail($company,$model,$tab);}
echo html_writer::end_div();\local_kbacompany\local\bms_page_shell_service::finish($shell);
