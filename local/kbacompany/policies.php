<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\policy_service as policies;
use local_kbacompany\local\policy_ui_service as ui;
use local_kbacompany\local\document_request_service as forms;

use local_kbacompany\local\bms_workspace_ux_service;

require_login();
$company = required_param('uuid', PARAM_RAW_TRIMMED);
$actor = (int)$USER->id;
$access = policies::access($company, $actor);
$object = optional_param('object', '', PARAM_RAW_TRIMMED);
$revision = optional_param('rev', 0, PARAM_INT);
$view = optional_param('view', $object !== '' ? 'detail' : 'list', PARAM_ALPHA);
$tab = optional_param('tab', 'overview', PARAM_ALPHA);
$operation = optional_param('operation', '', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method,['GET','HEAD','POST'],true)) { throw new moodle_exception('productpostrequired','local_kbacompany'); }
if ($action !== '' && $method !== 'POST') { throw new moodle_exception('productpostrequired','local_kbacompany'); }
if (!in_array($view,['list','detail','create','edit','action','lookup'],true)) { $view=$object!==''?'detail':'list'; }
if (!in_array($tab,['overview','links','revisions','history'],true)) { $tab='overview'; }
if ($view==='list' && $object!=='') { $view='detail'; }
if ($view==='create' && $object!=='') { throw new moodle_exception('policy_reference','local_kbacompany'); }
if ($view==='lookup') {
    if ($method==='POST') { throw new moodle_exception('productpostrequired','local_kbacompany'); }
    $options=policies::reference_options($company,$actor,optional_param('target','',PARAM_ALPHA),optional_param('q','',PARAM_RAW_TRIMMED),$object);
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
    echo json_encode(['options'=>$options],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS);
    exit;
}
$filters=policies::filters(['q'=>optional_param('q','',PARAM_RAW_TRIMMED),'kind'=>optional_param('kind','',PARAM_ALPHA),
    'status'=>optional_param('status','',PARAM_ALPHA),'ownerref'=>optional_param('ownerref','',PARAM_RAW_TRIMMED)]);
$page=optional_param('page',0,PARAM_INT); $errors=[]; $values=[]; $proof=[]; $model=null;
if ($object!=='') { $model=policies::record($company,$actor,$object,$revision,$tab,$page); }
if (in_array($view,['detail','edit','action'],true) && $object==='') { throw new moodle_exception('policy_reference','local_kbacompany'); }
if (in_array($view,['create','edit','action'],true) && !$access['can_manage']) { throw new moodle_exception('policy_permission','local_kbacompany'); }

if ($method==='POST') {
    require_sesskey();
    if (!$access['can_manage']) { throw new moodle_exception('policy_permission','local_kbacompany'); }
    if (!in_array($action,['save','publish','newversion','withdraw'],true)) { throw new moodle_exception('policy_state','local_kbacompany'); }
    $submittedrevision=optional_param('revision',0,PARAM_INT);
    $key=optional_param('requestkey','',PARAM_ALPHANUM);
    if ($key==='') { throw new moodle_exception('pcu_refresh_form','local_kbacompany',ui::url($company,$object!==''?['object'=>$object]:[])->out(false)); }
    $proof=['requestkey'=>$key];
    // Reuse the existing session receipt utility; namespace/binding prevents cross-domain replay.
    $form=forms::verify($key,$company,$actor,$object,$submittedrevision,'policy_'.$action,'policies');
    foreach (['kind','title','purpose','scoperef','ownerref','effectivefrom','effectiveto','documenturl','basis','reason'] as $field) {
        $values[$field]=optional_param($field,'',PARAM_RAW_TRIMMED);
    }
    $confirmed=optional_param('confirmed',0,PARAM_INT);
    $digest=forms::digest($values+['confirmed'=>$confirmed]);
    $destination=forms::replay($form,$digest);
    if ($destination!==null) { redirect(ui::url($company,$destination),ui::tr('policy_saved')); }
    if (($object!=='' && (int)$model['current']->revision!==$submittedrevision) ||
            ($object==='' && $submittedrevision!==0)) { $errors['_form']=ui::tr('policy_conflict'); }
    if (!$errors && $action==='save') {
        if ($object!=='' && !$model['actions']['edit']) { throw new moodle_exception('policy_state','local_kbacompany'); }
        $data=$values;
        foreach (['scope','owner'] as $relation) {
            $parts=explode('|',$values[$relation.'ref'],2);
            $data[$relation.'type']=count($parts)===2?$parts[0]:'';
            $data[$relation.'uuid']=count($parts)===2?$parts[1]:'';
        }
        $errors=policies::validation_errors($company,$actor,$data);
        if ($object!=='' && $data['kind']!==$model['current']->kind) { $errors['_form']=ui::tr('policy_kindimmutable'); }
    } else if (!$errors) {
        if (empty($model['actions'][$action])) { throw new moodle_exception('policy_state','local_kbacompany'); }
        if (!$confirmed) { $errors['_form']=ui::tr('pcu_confirm_required'); }
        if ($values['reason']==='') { $errors['reason']=ui::tr('pcu_required'); }
        if (core_text::strlen($values['reason'])>4000) { $errors['reason']=ui::tr('pcu_toolong',4000); }
        $data=['reason'=>$values['reason']];
    }
    if (!$errors) {
        try {
            $saved=policies::command($company,$actor,$action,$data+['object'=>$object,'revision'=>$submittedrevision]);
            $destination=['object'=>$saved];
            forms::complete($key,$digest,$destination);
            redirect(ui::url($company,$destination),ui::tr('policy_saved'));
        } catch (moodle_exception $e) {
            if ($e->module!=='local_kbacompany') { throw $e; }
            $errors['_form']=$e->getMessage();
        }
    }
    $view=$action==='save'?($object!==''?'edit':'create'):'action'; $operation=$action;
}

if ($method!=='POST' && $view==='edit' && empty($model['actions']['edit'])) { throw new moodle_exception('policy_state','local_kbacompany'); }
if ($method!=='POST' && $view==='action' && (empty($model['actions'][$operation]) || !in_array($operation,['publish','newversion','withdraw'],true))) { throw new moodle_exception('policy_state','local_kbacompany'); }
$options=['scope'=>[],'owner'=>[],'labels'=>$model['labels']??[]];
if ($view==='create' || $view==='edit') {
    $options['scope']=policies::reference_options($company,$actor,'scope','',$object);
    $options['owner']=policies::reference_options($company,$actor,'owner','',$object);
    if ($method!=='POST') {
        $values=['kind'=>'policy','scoperef'=>'company|','ownerref'=>''];
        if ($view==='edit') {
            $row=$model['selected']; $values=(array)$row;
            $values['scoperef']=$row->scopetype.'|'.$row->scopeuuid; $values['ownerref']=$row->ownertype.'|'.$row->owneruuid; $values['reason']='';
        }
    }
}
if ($method!=='POST' && in_array($view,['create','edit','action'],true)) {
    $row=$model['selected']??null;
    $proof=forms::issue($company,$actor,$object,$row->revision??0,'policy_'.($view==='action'?$operation:'save'),$row?(string)$row->revision:'','policies');
}
$PAGE->set_context(context_system::instance()); $PAGE->set_url(ui::url($company,$object!==''?['object'=>$object]:[]));
$PAGE->set_title(ui::tr('policy_title')); $PAGE->set_heading(ui::tr('policy_title'));
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/policy_ui.js'));
$shell=\local_kbacompany\local\bms_page_shell_service::begin($company,$actor,'policies',ui::tr('policy_title'));
echo html_writer::start_div('bms-pcu');
echo bms_workspace_ux_service::governance_nav($company,'policies');
echo ui::errors($errors);
if ($view==='list') { echo ui::listing($company,policies::browse($company,$actor,$filters,$page)); }
else if ($view==='create'||$view==='edit') { echo ui::form($company,$options,$values,$errors,$proof,$model); }
else if ($view==='action') { echo ui::action($company,$model,$operation,$values,$proof,$errors); }
else { echo ui::detail($company,$model,$tab); }
echo html_writer::end_div();
\local_kbacompany\local\bms_page_shell_service::finish($shell);
