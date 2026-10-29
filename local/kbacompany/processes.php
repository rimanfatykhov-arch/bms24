<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\process_service as service;
use local_kbacompany\local\process_model_service;
use local_kbacompany\local\process_ui_service as ui;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\document_request_service as requests;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = company_context_service::normalise_uuid(required_param('uuid', PARAM_RAW_TRIMMED));
$actor = (int)$USER->id;
$company = company_context_service::context($uuid, $actor);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','HEAD','POST'], true)) { throw new moodle_exception('process_postrequired','local_kbacompany'); }
$proc = optional_param('process', '', PARAM_RAW_TRIMMED);
$legacyedit = optional_param('edit', '', PARAM_RAW_TRIMMED);
$filters = [];
foreach (['q','kind','owner','level','parentfilter','layout','ownerq'] as $key) { $filters[$key]=optional_param($key,$key==='layout'?'architecture':'',PARAM_RAW_TRIMMED); }
$filters = service::crud_filters($filters);
$nav = $filters + ['page'=>max(0,optional_param('page',0,PARAM_INT))];
if ($legacyedit!=='' && $method!=='POST') { redirect(ui::url($uuid,['view'=>'edit','process'=>$legacyedit],$nav)); }
$view = optional_param('view',$proc===''?'list':'detail',PARAM_ALPHA);
$action = optional_param('action','',PARAM_ALPHA);
if (!in_array($view,['list','detail','create','edit','delete','publish','links','unlink'],true)) { throw new moodle_exception('process_invalid','local_kbacompany'); }
if (($method!=='POST' && $action!=='') || ($method==='POST' && in_array($view,['list','detail'],true))) { throw new moodle_exception('process_postrequired','local_kbacompany'); }
$manager = service::can_manage($uuid,$actor);
if (!in_array($view,['list','detail'],true)) {
    require_capability('local/kbacompany:manageorganization',$context);
    if (!$manager) { throw new required_capability_exception($context,'local/kbacompany:manageorganization','nopermissions',''); }
}
$model = in_array($view,['detail','edit','delete','publish','links','unlink'],true)?service::crud_record($uuid,$actor,$proc):['company'=>$company,'can_manage'=>$manager];
$tab = optional_param('tab','passport',PARAM_ALPHANUMEXT);
if (!in_array($tab,['passport','idef0','sipoc','bpmn'],true)) { $tab='passport'; }
$errors=[]; $proof=[]; $destination=null;
if ($view==='create' || $view==='edit') {
    $creating=$view==='create'; $p=$model['process']??null;
    $values=$creating?['name'=>'','purpose'=>'','processtype'=>'core','processlevel'=>'L0','parentuuid'=>'','ownerorgunituuid'=>'','productuuid'=>'']:
        ['name'=>$p['name'],'purpose'=>$p['purpose'],'processtype'=>$p['type'],'processlevel'=>$p['level'],'parentuuid'=>$p['parent_uuid'],'ownerorgunituuid'=>$p['owner_orgunit_uuid'],'productuuid'=>$p['product_uuid']];
    $identity=$creating?'':'process:'.$proc;
    $lookup=optional_param('lookup','',PARAM_TEXT);
    if ($method==='POST') {
        require_sesskey();
        if (!in_array($action,['save','lookup'],true)) { throw new moodle_exception('process_invalid','local_kbacompany'); }
        foreach (array_keys($values) as $key) { $values[$key]=optional_param($key,'',PARAM_RAW_TRIMMED); }
        if (isset($_POST['code'])) { $values['code']=optional_param('code','',PARAM_RAW_TRIMMED); }
        $key=required_param('requestkey',PARAM_ALPHANUM);
        $request=requests::verify($key,$uuid,$actor,$identity,0,'process_'.$view);
        $digest=requests::digest($values);
        $destination=requests::replay($request,$digest);
        if ($destination!==null) { redirect(ui::url($uuid,$destination)); }
        if ($action==='save') {
            $errors=service::crud_errors($uuid,$actor,$values,$creating?'':$proc);
            if (!$errors) {
                try {
                    $saved=service::crud_save($uuid,$actor,$values,$creating?'':$proc,(int)$request['expected']);
                    $destination=['process'=>$saved]+$nav;
                    requests::complete($key,$digest,$destination);
                } catch (moodle_exception $e) {
                    if ($e->module!=='local_kbacompany') { throw $e; }
                    $errors['_domain']=$e->getMessage();
                }
                if ($destination!==null) { redirect(ui::url($uuid,$destination),ui::tr('pru_saved'),null,\core\output\notification::NOTIFY_SUCCESS); }
            }
        }
        $proof=['requestkey'=>$key];
    } else {
        if ($p && $p['status']==='archived') { throw new moodle_exception('process_state','local_kbacompany'); }
        if ($creating) {
            $type=optional_param('presettype','',PARAM_ALPHA);
            if (in_array($type,service::TYPES,true)) { $values['processtype']=$type; }
            $parent=optional_param('parent','',PARAM_RAW_TRIMMED);
            if ($parent!=='') {
                $pm=service::crud_record($uuid,$actor,$parent);$pr=$pm['process'];
                if ($pr['status']==='archived'||$pr['level']==='L2') { throw new moodle_exception('process_parent','local_kbacompany'); }
                $values['parentuuid']=$parent;$values['processlevel']=$pr['level']==='L0'?'L1':'L2';$values['processtype']=$pr['type'];
            }
        }
        $proof=requests::issue($uuid,$actor,$identity,0,'process_'.$view,$p?(string)$p['revision']:'');
    }
    $options=[];
    foreach (['owner'=>'ownerorgunituuid','product'=>'productuuid','parent'=>'parentuuid'] as $kind=>$field) {
        $options[$kind]=service::crud_lookup($uuid,$actor,$kind,$lookup,(string)$values[$field],$creating?'':$proc);
    }
    $content=ui::form($uuid,$creating,$model,$values,$nav,$options,$proof,$errors,$lookup,$method==='POST');
} else if (in_array($view,['delete','publish','unlink'],true)) {
    $p=$model['process'];$reason='';$confirmed=false;$relation=[];
    if ($view==='unlink') {
        $relation=['relation'=>required_param('relation',PARAM_RAW_TRIMMED),'relationrevision'=>required_param('relationrevision',PARAM_INT)];
    }
    $identity='process:'.$proc.($relation?':'.$relation['relation'].':'.$relation['relationrevision']:'');
    if ($method==='POST') {
        require_sesskey();
        if ($action!==$view) { throw new moodle_exception('process_invalid','local_kbacompany'); }
        $key=required_param('requestkey',PARAM_ALPHANUM);
        $request=requests::verify($key,$uuid,$actor,$identity,0,'process_'.$view);
        $reason=optional_param('reason','',PARAM_TEXT);$confirmed=optional_param('confirmed',0,PARAM_INT)===1;
        $digest=requests::digest(['reason'=>$reason,'confirmed'=>$confirmed]+$relation);
        $destination=requests::replay($request,$digest);
        if ($destination!==null) { redirect(ui::url($uuid,$destination)); }
        if (!$confirmed||trim($reason)===''||core_text::strlen($reason)>4000) { $errors['reason']=ui::tr('pru_confirmation'); }
        if (!$errors) {
            try {
                if ($view==='delete') { service::archive($uuid,$actor,$proc,(int)$request['expected'],$reason); }
                if ($view==='publish') { service::publish($uuid,$actor,$proc,(int)$request['expected'],$reason); }
                if ($view==='unlink') { service::crud_relation_command($uuid,$actor,$proc,(int)$request['expected'],'unlink',$relation+['reason'=>$reason]); }
                $destination=($view==='delete'?[]:['process'=>$proc]+($view==='unlink'?['view'=>'links']:[]))+$nav;
                requests::complete($key,$digest,$destination);
            } catch (moodle_exception $e) {
                if ($e->module!=='local_kbacompany') { throw $e; }
                $errors['_domain']=$e->getMessage();
            }
            if ($destination!==null) { redirect(ui::url($uuid,$destination),ui::tr($view==='delete'?'pru_deleted':($view==='publish'?'pru_published':'pru_linked')),null,\core\output\notification::NOTIFY_SUCCESS); }
        }
        $proof=['requestkey'=>$key];
    } else {
        if ($p['status']==='archived') { throw new moodle_exception('process_state','local_kbacompany'); }
        $proof=requests::issue($uuid,$actor,$identity,0,'process_'.$view,(string)$p['revision']);
    }
    $dependencies=$view==='delete'?service::crud_dependencies($uuid,$actor,$proc):[];
    if ($view==='unlink') { $model['relation_label']=service::crud_relation_label($uuid,$actor,$proc,$relation['relation'],(int)$relation['relationrevision']); }
    $content=ui::confirmation($uuid,$model,$nav,$view,$proof,$errors,$reason,$confirmed,$dependencies,$relation);
} else if ($view==='links') {
    $p=$model['process'];$values=['linktype'=>'supplier','target'=>'','reason'=>''];$lookup=optional_param('lookup','',PARAM_TEXT);
    if ($p['status']==='archived') { throw new moodle_exception('process_state','local_kbacompany'); }
    if ($method==='POST') {
        require_sesskey();
        if (!in_array($action,['link','lookup'],true)) { throw new moodle_exception('process_invalid','local_kbacompany'); }
        foreach (array_keys($values) as $key) { $values[$key]=optional_param($key,'',PARAM_RAW_TRIMMED); }
        $key=required_param('requestkey',PARAM_ALPHANUM);
        $request=requests::verify($key,$uuid,$actor,'process:'.$proc,0,'process_links');
        $digest=requests::digest($values);$destination=requests::replay($request,$digest);
        if ($destination!==null) { redirect(ui::url($uuid,$destination)); }
        if ($action==='link') {
            try {
                service::crud_relation_command($uuid,$actor,$proc,(int)$request['expected'],'link',$values);
                $destination=['process'=>$proc,'view'=>'links']+$nav;
                requests::complete($key,$digest,$destination);
            } catch (moodle_exception $e) {
                if ($e->module!=='local_kbacompany') { throw $e; }
                $errors['_domain']=$e->getMessage();
            }
            if ($destination!==null) { redirect(ui::url($uuid,$destination),ui::tr('pru_linked'),null,\core\output\notification::NOTIFY_SUCCESS); }
        }
        $proof=['requestkey'=>$key];
    } else { $proof=requests::issue($uuid,$actor,'process:'.$proc,0,'process_links',(string)$p['revision']); }
    $options=[];$selected=explode('|',$values['target'],2);
    foreach (['owner','metric'] as $kind) { $options[$kind]=service::crud_lookup($uuid,$actor,$kind,$lookup,($selected[0]??'')===$kind?($selected[1]??''):''); }
    $content=ui::link_form($uuid,$model,$nav,$proof,service::crud_links($uuid,$actor,$proc,optional_param('linkpage',0,PARAM_INT)),$values,$options,$errors,$lookup,$method==='POST');
} else if ($view==='detail') {
    if ($tab==='passport') {
        $children=service::crud_browse($uuid,$actor,['parentfilter'=>$proc],optional_param('childpage',0,PARAM_INT));
        $links=service::crud_links($uuid,$actor,$proc,optional_param('linkpage',0,PARAM_INT));
        $content=ui::passport($uuid,$model,$nav,$children,$links,service::history($uuid,$actor,$proc));
    } else {
        if ($model['process']['status']==='archived') {
            $content=ui::header($uuid,$model,$nav,$tab).\local_kbacompany\local\bms_workspace_ux_service::record_note(ui::tr('pru_archived'));
        } else {
            $data=process_model_service::context($uuid,$actor,$proc);
            if ($tab==='idef0') { $data['decomposition']=service::idef0_decomposition($uuid,$actor,$proc); }
            $content=$tab==='idef0'?ui::idef0($uuid,$model,$nav,$data):ui::model($uuid,$model,$nav,$tab,$data);
        }
    }
} else {
    $model=$filters['layout']==='architecture'?service::architecture_workspace($uuid,$actor,$filters):
        ($filters['layout']==='analytics'?service::architecture_analytics($uuid,$actor,$filters):
        service::crud_browse($uuid,$actor,$filters,$nav['page']));
    $owners=service::crud_lookup($uuid,$actor,'owner',$filters['ownerq'],$filters['owner']);
    $content=ui::listing($uuid,$model,$owners);
}
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid,['view'=>$view]+($proc!==''?['process'=>$proc,'tab'=>$tab]:[]),$nav));
$PAGE->set_title(ui::tr('process_title'));
$PAGE->set_heading(ui::tr('pluginname'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/process_crud.js'));
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/bpmn_visual.js'));
$shell=bms_page_shell_service::begin($uuid,$actor,'processes',ui::tr('process_title'));
echo html_writer::div($content,'bms-pdu bms-pru');
bms_page_shell_service::finish($shell);
