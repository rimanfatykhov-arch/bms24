<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\process_service;
use local_kbacompany\local\process_model_service as models;
use local_kbacompany\local\process_ui_service as ui;
use local_kbacompany\local\document_request_service as requests;
use local_kbacompany\local\bms_page_shell_service;

require_login();
$context=context_system::instance();
require_capability('local/kbacompany:viewowncompany',$context);
$uuid=company_context_service::normalise_uuid(required_param('uuid',PARAM_RAW_TRIMMED));
$proc=required_param('process',PARAM_RAW_TRIMMED);
$actor=(int)$USER->id;
$record=process_service::crud_record($uuid,$actor,$proc);
$method=$_SERVER['REQUEST_METHOD']??'GET';
if (!in_array($method,['GET','HEAD','POST'],true)) { throw new moodle_exception('process_postrequired','local_kbacompany'); }
$nav=[];
foreach (['q','kind','owner','level','parentfilter','layout','ownerq'] as $key) { $nav[$key]=optional_param($key,$key==='layout'?'architecture':'',PARAM_RAW_TRIMMED); }
$nav=process_service::crud_filters($nav)+['page'=>max(0,optional_param('page',0,PARAM_INT))];
$tab=optional_param('tab','sipoc',PARAM_ALPHANUMEXT);
if (!in_array($tab,['idef0','sipoc','bpmn'],true)) { $tab='sipoc'; }
$mode=optional_param('mode','view',PARAM_ALPHA);
if ($mode!=='edit') {
    if ($method==='POST') { throw new moodle_exception('process_postrequired','local_kbacompany'); }
    redirect(ui::url($uuid,['process'=>$proc,'tab'=>$tab],$nav));
}
require_capability('local/kbacompany:manageorganization',$context);
if (!$record['can_manage']) { throw new required_capability_exception($context,'local/kbacompany:manageorganization','nopermissions',''); }
if ($record['process']['status']==='archived') { throw new moodle_exception('process_state','local_kbacompany'); }
$tool=optional_param('tool','summary',PARAM_ALPHA);
if (!in_array($tool,['summary','draft','metadata','element','icom','flow','sipoc','bpmn','bpmnreset','removeelement','removeicom','removeflow','publish','interconnect','removeinterconnect','icomlink','removeicomlink'],true)) { throw new moodle_exception('process_invalid','local_kbacompany'); }
$action=optional_param('action','',PARAM_ALPHA);
if (($method!=='POST' && $action!=='')||($method==='POST'&&($tool==='summary'||$action!==$tool))) { throw new moodle_exception('process_postrequired','local_kbacompany'); }
$data=models::context($uuid,$actor,$proc);
if ($tab==='idef0') { $data['decomposition']=process_service::idef0_decomposition($uuid,$actor,$proc); }
$model=$data['model'];
if ($tool==='summary' && $tab==='sipoc' && $model && $model['status']==='draft') { $tool='sipoc'; }
if ($tool==='summary' && $tab==='bpmn' && $model && $model['status']==='draft') { $tool='bpmn'; }
$item=optional_param('item','',PARAM_RAW_TRIMMED);
if (strlen($item)>36) { throw new moodle_exception('procmodel_element','local_kbacompany'); }
$relationrevision=max(0,optional_param('relationrevision',0,PARAM_INT));
$architecturetool=in_array($tool,['interconnect','removeinterconnect','icomlink','removeicomlink'],true);
$identity=($architecturetool?'processidef0:':'processmodel:').$proc.':'.$tool.':'.$item.(in_array($tool,['removeinterconnect','removeicomlink'],true)?':'.$relationrevision:'');
$expected=$architecturetool?(string)$record['process']['revision']:json_encode(['id'=>$model?$model['id']:0,'version'=>$model?$model['change_version']:0,'process'=>$record['process']['revision']]);
$requestscope=$architecturetool?'process_idef0':'process_model';
$values=[];$errors=[];$proof=[];$interedge=[];$icomrelation=[];$icomoptions=[];
if ($tool==='metadata') {
    $values=['scope'=>$model['scope']??'','triggertext'=>$model['trigger']??'','startcondition'=>$model['start_condition']??'','endcondition'=>$model['end_condition']??''];
} else if ($tool==='element') {
    $preset=optional_param('elementtype','task',PARAM_ALPHA);
    $values=['elementtype'=>$preset,'code'=>'','name'=>'','description'=>'','executor'=>'','laneuuid'=>'','productuuid'=>'','outputtext'=>'','activityuuid'=>'','sortorder'=>'0'];
    if ($item!=='') {
        $found=null;foreach ($data['elements'] as $el) { if ($el['uuid']===$item) { $found=$el;break; } }
        if ($found) { $values=['elementtype'=>$found['type'],'code'=>$found['code'],'name'=>$found['name'],'description'=>$found['description'],
            'executor'=>$found['executor_type']!==''?$found['executor_type'].'|'.$found['executor_uuid']:'','laneuuid'=>$found['lane_uuid'],
            'productuuid'=>$found['product_uuid'],'outputtext'=>$found['output'],'activityuuid'=>$found['activity_uuid'],'sortorder'=>(string)$found['sort_order']]; }
        else if ($method!=='POST') { throw new moodle_exception('procmodel_element','local_kbacompany'); }
    }
} else if ($tool==='icom') {
    $preset=optional_param('elementtype','input',PARAM_ALPHA);
    if (!in_array($preset,models::ICOM_TYPES,true)) { throw new moodle_exception('procmodel_elementtype','local_kbacompany'); }
    $values=['elementtype'=>$preset,'code'=>'','name'=>'','description'=>'','executor'=>'','laneuuid'=>'','productuuid'=>'','outputtext'=>'','activityuuid'=>'','sortorder'=>'0'];
    if ($item==='' && $preset==='input' && $method!=='POST') {
        $balanceproduct=optional_param('balanceproduct','',PARAM_RAW_TRIMMED);
        if (strlen($balanceproduct)>36) { throw new moodle_exception('process_reference','local_kbacompany'); }
        if ($balanceproduct!=='') {
            $foundproduct=null;
            foreach (($data['products']??[]) as $candidate) {
                if ((string)$candidate['uuid']===$balanceproduct) { $foundproduct=$candidate;break; }
            }
            if (!$foundproduct) { throw new moodle_exception('process_reference','local_kbacompany'); }
            $values['productuuid']=$balanceproduct;
            $values['name']=(string)$foundproduct['name'];
        }
    }
    if ($item!=='') {
        $found=null;foreach ($data['elements'] as $el) { if ($el['uuid']===$item) { $found=$el;break; } }
        if ($found) {
            if (!in_array($found['type'],models::ICOM_TYPES,true)) { throw new moodle_exception('procmodel_elementtype','local_kbacompany'); }
            $values=['elementtype'=>$found['type'],'code'=>$found['code'],'name'=>$found['name'],'description'=>$found['description'],
                'executor'=>'','laneuuid'=>$found['lane_uuid'],'productuuid'=>$found['product_uuid'],'outputtext'=>$found['output'],
                'activityuuid'=>$found['activity_uuid'],'sortorder'=>(string)$found['sort_order']];
        } else if ($method!=='POST') { throw new moodle_exception('procmodel_element','local_kbacompany'); }
    }
} else if ($tool==='sipoc') {
    $order=['input'=>[],'task'=>[],'output'=>[]];
    foreach ($data['elements'] as $el) {
        if (isset($order[$el['type']])) { $order[$el['type']][]=$el['uuid']; }
    }
    $values=['ordering'=>json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
} else if ($tool==='bpmn') {
    $values=['bpmnxml'=>(string)($data['bpmn']['xml']??'')];
} else if ($tool==='flow') {
    $values=['fromuuid'=>'','touuid'=>'','label'=>'','conditiontext'=>'','sortorder'=>'0'];
    if ($item!=='') {
        $found=null;foreach ($data['bpmn']['flows'] as $fl) { if ($fl['uuid']===$item) { $found=$fl;break; } }
        if ($found) { $values=['fromuuid'=>$found['from_uuid'],'touuid'=>$found['to_uuid'],'label'=>$found['label'],'conditiontext'=>$found['condition'],'sortorder'=>(string)$found['sort_order']]; }
        else if ($method!=='POST') { throw new moodle_exception('procmodel_flow','local_kbacompany'); }
    }
} else if ($tool==='interconnect') {
    $values=['fromuuid'=>'','touuid'=>'','reason'=>''];
    if (count($data['decomposition']['children']??[])<2 && $method!=='POST') { throw new moodle_exception('process_reference','local_kbacompany'); }
} else if ($tool==='removeinterconnect') {
    $values=['reason'=>'','confirmed'=>'0'];
    foreach (($data['decomposition']['interconnections']??[]) as $edge) {
        if ($edge['relation_uuid']===$item && (int)$edge['revision']===$relationrevision) { $interedge=$edge;break; }
    }
    if (!$interedge && $method!=='POST') { throw new moodle_exception('process_reference','local_kbacompany'); }
} else if ($tool==='icomlink') {
    $linkkind=optional_param('linkkind','control',PARAM_ALPHA);
    if (!in_array($linkkind,['control','mechanism'],true)) { throw new moodle_exception('process_reference','local_kbacompany'); }
    $values=['linkkind'=>$linkkind,'target'=>'','reason'=>''];
    if ($method!=='POST') { $icomoptions=process_service::idef0_control_mechanism_options($uuid,$actor,$proc,$linkkind); }
} else if ($tool==='removeicomlink') {
    $values=['reason'=>'','confirmed'=>'0'];
    foreach (['controls','mechanisms'] as $bucket) {
        foreach (($data['decomposition']['controls_mechanisms'][$bucket]??[]) as $edge) {
            if ($edge['relation_uuid']===$item && (int)$edge['revision']===$relationrevision) { $icomrelation=$edge;break 2; }
        }
    }
    if (!$icomrelation && $method!=='POST') { throw new moodle_exception('process_reference','local_kbacompany'); }
} else if ($tool!=='summary') {
    $values=['confirmed'=>0];
    if (in_array($tool,['removeelement','removeicom','removeflow'],true)) {
        $found=null;
        $collection=$tool==='removeflow'?$data['bpmn']['flows']:$data['elements'];
        foreach ($collection as $row) { if ($row['uuid']===$item) { $found=$row;break; } }
        if ($found && $tool==='removeicom' && !in_array($found['type'],models::ICOM_TYPES,true)) { throw new moodle_exception('procmodel_elementtype','local_kbacompany'); }
        if (!$found && $method!=='POST') { throw new moodle_exception('procmodel_element','local_kbacompany'); }
        // A completed receipt can still be replayed after the row was removed.
        $record['item_label']=$found?($found['name']??$found['label']??''):'';
    }
}
if ($method==='POST') {
    require_sesskey();
    $key=required_param('requestkey',PARAM_ALPHANUM);
    $request=requests::verify($key,$uuid,$actor,$identity,0,$requestscope);
    foreach (array_keys($values) as $field) { $values[$field]=optional_param($field,'',$field==='bpmnxml'?PARAM_RAW:PARAM_RAW_TRIMMED); }
    $digest=requests::digest($values);
    $dest=requests::replay($request,$digest);
    if ($dest!==null) { redirect(ui::url($uuid,$dest,[],'process_design')); }
    $snapshot=$architecturetool?null:json_decode($request['expected'],true);
    if (!$architecturetool && (!is_array($snapshot)||!isset($snapshot['id'],$snapshot['version'],$snapshot['process']))) { throw new moodle_exception('procmodel_conflict','local_kbacompany'); }
    if (in_array($tool,['element','icom'],true)) {
        if (trim($values['name'])===''||core_text::strlen($values['name'])>255) { $errors['name']=ui::tr('process_invalid'); }
        if ($tool==='icom' && !in_array($values['elementtype'],models::ICOM_TYPES,true)) { $errors['_domain']=ui::tr('procmodel_elementtype'); }
        if ($tool==='element' && $values['elementtype']==='task') {
            foreach (['description','executor'] as $field) { if (trim($values[$field])==='') { $errors[$field]=ui::tr('pru_taskrequirements'); } }
            if ($values['productuuid']===''&&trim($values['outputtext'])==='') { $errors['outputtext']=ui::tr('pru_taskrequirements'); }
        }
    }
    if ($tool==='flow') { foreach (['fromuuid','touuid'] as $field) { if ($values[$field]==='') { $errors[$field]=ui::tr('process_reference'); } } }
    if ($tool==='interconnect') {
        foreach (['fromuuid','touuid','reason'] as $field) { if (trim((string)$values[$field])==='') { $errors[$field]=ui::tr('process_invalid'); } }
        if ($values['fromuuid']!=='' && $values['fromuuid']===$values['touuid']) { $errors['touuid']=ui::tr('pru_idef0_interconnection_self'); }
        if (core_text::strlen((string)$values['reason'])>4000) { $errors['reason']=ui::tr('process_invalid'); }
    }
    if ($tool==='removeinterconnect') {
        if (trim((string)$values['reason'])==='' || core_text::strlen((string)$values['reason'])>4000) { $errors['reason']=ui::tr('process_invalid'); }
        if ((string)$values['confirmed']!=='1') { $errors['_domain']=ui::tr('pru_confirmation'); }
    }
    if ($tool==='icomlink') {
        if (!in_array((string)$values['linkkind'],['control','mechanism'],true)) { $errors['linkkind']=ui::tr('process_invalid'); }
        $parts=explode('|',(string)$values['target'],2);
        $allowed=(string)$values['linkkind']==='control'?['policy','document','metric']:['orgunit','position'];
        if (count($parts)!==2 || !in_array($parts[0],$allowed,true) || trim((string)$parts[1])==='' || strlen((string)$parts[1])>36) {
            $errors['target']=ui::tr('process_reference');
        }
        if (trim((string)$values['reason'])==='' || core_text::strlen((string)$values['reason'])>4000) { $errors['reason']=ui::tr('process_invalid'); }
    }
    if ($tool==='removeicomlink') {
        if (trim((string)$values['reason'])==='' || core_text::strlen((string)$values['reason'])>4000) { $errors['reason']=ui::tr('process_invalid'); }
        if ((string)$values['confirmed']!=='1') { $errors['_domain']=ui::tr('pru_confirmation'); }
    }
    $sipocordering=null;
    if ($tool==='bpmn') {
        if (strlen((string)$values['bpmnxml'])>3000000 || trim((string)$values['bpmnxml'])==='') { $errors['_domain']=ui::tr('pru_bpmn_invalid_xml'); }
    }
    if ($tool==='sipoc') {
        if (strlen((string)$values['ordering'])>200000) { $errors['_domain']=ui::tr('pru_sipoc_invalid_order'); }
        $sipocordering=json_decode((string)$values['ordering'],true);
        if (!is_array($sipocordering) || array_keys($sipocordering)!==['input','task','output']) {
            $errors['_domain']=ui::tr('pru_sipoc_invalid_order');
        }
    }
    if (in_array($tool,['draft','publish','bpmnreset','removeelement','removeicom','removeflow'],true) && (string)$values['confirmed']!=='1') { $errors['_domain']=ui::tr('pru_confirmation'); }
    if (!$errors) {
        try {
            if ($request['expected']!==$expected) { throw new moodle_exception('procmodel_conflict','local_kbacompany'); }
            $modelid=$architecturetool?0:(int)$snapshot['id'];$cv=$architecturetool?0:(int)$snapshot['version'];
            if ($tool==='interconnect') { process_service::idef0_connect($uuid,$actor,$proc,(int)$request['expected'],$values['fromuuid'],$values['touuid'],$values['reason']); }
            else if ($tool==='removeinterconnect') { process_service::idef0_disconnect($uuid,$actor,$proc,(int)$request['expected'],$item,$relationrevision,$values['reason']); }
            else if ($tool==='icomlink') { process_service::idef0_bind_control_mechanism($uuid,$actor,$proc,(int)$request['expected'],(string)$values['linkkind'],(string)$values['target'],(string)$values['reason']); }
            else if ($tool==='removeicomlink') { process_service::idef0_unbind_control_mechanism($uuid,$actor,$proc,(int)$request['expected'],$item,$relationrevision,(string)$values['reason']); }
            else if ($tool==='draft') { models::ensure_draft($uuid,$actor,$proc); }
            else if ($tool==='metadata') { models::save_metadata($uuid,$actor,$proc,$modelid,$cv,$values); }
            else if ($tool==='element') {
                $parts=explode('|',$values['executor'],2);
                if ($values['executor']!=='' && (count($parts)!==2 || !in_array($parts[0],models::EXECUTOR_TYPES,true))) { throw new moodle_exception('procmodel_invalidexecutor','local_kbacompany'); }
                $command=$values+['executortype'=>$parts[0]??'','executoruuid'=>$parts[1]??''];
                models::save_element($uuid,$actor,$proc,$modelid,$cv,$command,$item);
            } else if ($tool==='icom') {
                if (!in_array($values['elementtype'],models::ICOM_TYPES,true)) { throw new moodle_exception('procmodel_elementtype','local_kbacompany'); }
                $command=$values+['executortype'=>'','executoruuid'=>''];
                models::save_element($uuid,$actor,$proc,$modelid,$cv,$command,$item);
            } else if ($tool==='flow') { models::save_flow($uuid,$actor,$proc,$modelid,$cv,$values,$item); }
            else if ($tool==='sipoc') { models::reorder_sipoc($uuid,$actor,$proc,$modelid,$cv,$sipocordering); }
            else if ($tool==='bpmn') { models::save_bpmn_layout($uuid,$actor,$proc,$modelid,$cv,(string)$values['bpmnxml']); }
            else if ($tool==='bpmnreset') { models::reset_bpmn_layout($uuid,$actor,$proc,$modelid,$cv); }
            else if ($tool==='removeelement') { models::remove_element($uuid,$actor,$proc,$modelid,$cv,$item); }
            else if ($tool==='removeicom') {
                $current=null;foreach ($data['elements'] as $el) { if ($el['uuid']===$item) { $current=$el;break; } }
                if ($current && !in_array($current['type'],models::ICOM_TYPES,true)) { throw new moodle_exception('procmodel_elementtype','local_kbacompany'); }
                models::remove_element($uuid,$actor,$proc,$modelid,$cv,$item);
            }
            else if ($tool==='removeflow') { models::remove_flow($uuid,$actor,$proc,$modelid,$cv,$item); }
            else if ($tool==='publish') { models::publish($uuid,$actor,$proc,$modelid,$cv); }
            $dest=['process'=>$proc,'mode'=>'edit','tab'=>$tab]+$nav;
            requests::complete($key,$digest,$dest);
        } catch (moodle_exception $e) {
            if ($e->module!=='local_kbacompany') { throw $e; }
            $errors['_domain']=$e->getMessage();
        }
        if ($dest!==null) { redirect(ui::url($uuid,$dest,[],'process_design'),ui::tr('procmodel_saved'),null,\core\output\notification::NOTIFY_SUCCESS); }
    }
    $proof=['requestkey'=>$key];
} else if ($tool!=='summary') {
    if (!$architecturetool && $tool!=='draft' && (!$model||$model['status']!=='draft')) { throw new moodle_exception('procmodel_immutable','local_kbacompany'); }
    $proof=requests::issue($uuid,$actor,$identity,0,$requestscope,$expected);
}
if ($tool==='summary') { $content=$tab==='idef0'?ui::idef0($uuid,$record,$nav,$data):ui::model_editor($uuid,$record,$nav,$tab,$data); }
else if ($tool==='sipoc') { $content=ui::sipoc_editor($uuid,$record,$nav,$data,$values,$proof,$errors,$method==='POST'); }
else if ($tool==='bpmn') { $content=ui::bpmn_editor($uuid,$record,$nav,$data,$values,$proof,$errors,$method==='POST'); }
else if ($tool==='icom') { $content=ui::icom_form($uuid,$record,$nav,$data,$item,$values,$proof,$errors,$method==='POST'); }
else if ($tool==='interconnect') { $content=ui::idef0_interconnection_form($uuid,$record,$nav,$data,$values,$proof,$errors,$method==='POST'); }
else if ($tool==='removeinterconnect') {
    if (!$interedge) { foreach (($data['decomposition']['interconnections']??[]) as $edge) { if ($edge['relation_uuid']===$item) { $interedge=$edge;break; } } }
    if (!$interedge) { $interedge=['relation_uuid'=>$item,'revision'=>$relationrevision,'from_notation'=>'','from_code'=>'','from_name'=>ui::tr('pru_unavailable'),'to_notation'=>'','to_code'=>'','to_name'=>ui::tr('pru_unavailable')]; }
    $content=ui::idef0_interconnection_remove_form($uuid,$record,$nav,$interedge,$values,$proof,$errors,$method==='POST');
}
else if ($tool==='icomlink') {
    $renderkind=in_array((string)$values['linkkind'],['control','mechanism'],true)?(string)$values['linkkind']:'control';
    if ($renderkind!==$values['linkkind']) { $values['linkkind']=$renderkind; }
    if (!$icomoptions) { $icomoptions=process_service::idef0_control_mechanism_options($uuid,$actor,$proc,$renderkind); }
    $content=ui::idef0_control_mechanism_form($uuid,$record,$nav,$values,$icomoptions,$proof,$errors,$method==='POST');
}
else if ($tool==='removeicomlink') {
    if (!$icomrelation) {
        foreach (['controls','mechanisms'] as $bucket) {
            foreach (($data['decomposition']['controls_mechanisms'][$bucket]??[]) as $edge) {
                if ($edge['relation_uuid']===$item) { $icomrelation=$edge;break 2; }
            }
        }
    }
    if (!$icomrelation) { $icomrelation=['relation_uuid'=>$item,'revision'=>$relationrevision,'kind'=>'control','type'=>'','code'=>'','name'=>ui::tr('pru_unavailable')]; }
    $content=ui::idef0_control_mechanism_remove_form($uuid,$record,$nav,$icomrelation,$values,$proof,$errors,$method==='POST');
}
else { $shown=$values;if (isset($record['item_label'])) { $shown['label']=$record['item_label']; }
    $content=ui::model_form($uuid,$record,$nav,$tab,$data,$tool,$item,$shown,$proof,$errors,$method==='POST'); }
$PAGE->set_context($context);
$PAGE->set_url(ui::url($uuid,['process'=>$proc,'mode'=>'edit','tool'=>$tool,'tab'=>$tab],$nav,'process_design'));
$PAGE->set_title(ui::tr('procmodel_title'));
$PAGE->set_heading(ui::tr('pluginname'));
$PAGE->requires->css(new moodle_url('/local/kbacompany/styles.css'));
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/process_crud.js'));
$PAGE->requires->js(new moodle_url('/local/kbacompany/js/bpmn_visual.js'));
$shell=bms_page_shell_service::begin($uuid,$actor,'processes',ui::tr('procmodel_title'));
echo html_writer::div($content,'bms-pdu bms-pru');
bms_page_shell_service::finish($shell);
