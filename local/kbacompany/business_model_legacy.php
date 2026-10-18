<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\market_model_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$data = market_model_service::context($uuid, (int)$USER->id);
// 0.6.9.9: legacy textarea Canvas is read-only compatibility. Record CRUD is canonical for new edits.
$data['can_manage'] = false;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kbacompany/business_model_legacy.php', ['uuid'=>$uuid]));
$PAGE->set_title(get_string('marketmodel_title','local_kbacompany'));
$PAGE->set_heading(get_string('pluginname','local_kbacompany'));

$requirements_to_storage = static function(string $value): string {
    $value = trim($value);
    if ($value === '') { return ''; }
    preg_match_all('/(?:REQ\s*[-–—]?\s*)?0*(\d{1,2})/iu', $value, $matches);
    if (empty($matches[1])) { return $value; }
    $out = [];
    foreach ($matches[1] as $number) {
        $number = (int)$number;
        if ($number < 1 || $number > 99) { continue; }
        $code = 'REQ-' . sprintf('%02d', $number);
        if (!in_array($code, $out, true)) { $out[] = $code; }
    }
    return implode(' ', $out);
};
$requirements_for_ui = static function(string $value): string {
    preg_match_all('/REQ-(\d{2})/i', $value, $matches);
    if (empty($matches[1])) { return $value; }
    $out = [];
    foreach ($matches[1] as $number) { $out[] = (string)((int)$number); }
    return implode(', ', array_values(array_unique($out)));
};
$statuslabel = static function(string $status): string {
    return get_string('marketmodel_status_' . $status, 'local_kbacompany');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$data['can_manage']) {
        throw new required_capability_exception($context,'local/kbacompany:manageorganization','nopermissions','');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    $item = optional_param('item','',PARAM_RAW_TRIMMED);
    $revision = optional_param('revision',0,PARAM_INT);
    if ($action === 'savebizmodel') {
        $model = [
            'name'=>required_param('name',PARAM_RAW_TRIMMED),
            'ownerframeuuid'=>optional_param('ownerframeuuid','',PARAM_RAW_TRIMMED),
            'summary'=>optional_param('summary','',PARAM_RAW_TRIMMED),
            'horizon'=>optional_param('horizon','',PARAM_RAW_TRIMMED),
        ];
        foreach (['ideology_first','ideology_target','ideology_criterion','ideology_reqs','ideology_facilitator'] as $field) {
            $model[$field] = optional_param($field,'',PARAM_RAW_TRIMMED);
            if ($field === 'ideology_reqs') { $model[$field] = $requirements_to_storage($model[$field]); }
        }
        foreach (['segments','valueprop','relationships','channels','activities','resources','partners','revenue','costs'] as $block) {
            foreach (['first','current','data','hyp','target','criterion','reqs','gap','facilitator'] as $suffix) {
                $key=$block.'_'.$suffix; $model[$key]=optional_param($key,'',PARAM_RAW_TRIMMED);
                if ($suffix === 'reqs') { $model[$key] = $requirements_to_storage($model[$key]); }
            }
        }
        foreach (['market','client','economics','resources','ownerrequirements'] as $contour) {
            $model['check_'.$contour]=optional_param('check_'.$contour,'',PARAM_ALPHANUMEXT);
            $model['check_'.$contour.'_note']=optional_param('check_'.$contour.'_note','',PARAM_RAW_TRIMMED);
        }
        market_model_service::save_business_model($uuid,(int)$USER->id,$model,$item,$revision);
    } else if ($action === 'publishbizmodel') {
        market_model_service::publish_business_model($uuid,(int)$USER->id,$item,$revision);
    } else if ($action === 'archivebizmodel') {
        market_model_service::archive_business_model($uuid,(int)$USER->id,$item,$revision);
    } else {
        throw new moodle_exception('invalidparameter');
    }
    redirect(new moodle_url('/local/kbacompany/business_model_legacy.php',['uuid'=>$uuid]),get_string('marketmodel_saved','local_kbacompany'));
}

$edituuid=optional_param('edituuid','',PARAM_RAW_TRIMMED);
$historyuuid=optional_param('historyuuid','',PARAM_RAW_TRIMMED);
$editing=null;
foreach($data['business_models'] as $candidate){if($candidate['uuid']===$edituuid){$editing=$candidate;break;}}
$history=$historyuuid!==''?market_model_service::business_model_history($uuid,(int)$USER->id,$historyuuid):[];

$hidden=static function(string $action,?array $row=null)use($uuid){
    $html=html_writer::empty_tag('input',['type'=>'hidden','name'=>'uuid','value'=>$uuid]).
        html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]).
        html_writer::empty_tag('input',['type'=>'hidden','name'=>'action','value'=>$action]);
    if($row){$html.=html_writer::empty_tag('input',['type'=>'hidden','name'=>'item','value'=>$row['uuid']]).html_writer::empty_tag('input',['type'=>'hidden','name'=>'revision','value'=>$row['revision']]);}
    return $html;
};
$textarea=static function(string $name,string $value='',int $rows=3){return html_writer::tag('textarea',s($value),['id'=>'bm_'.$name,'name'=>$name,'rows'=>$rows,'class'=>'form-control']);};
$field=static function(string $name,string $value='',int $rows=3)use($textarea){return html_writer::tag('div',html_writer::label(get_string('marketmodel_'.$name,'local_kbacompany'),'bm_'.$name,false,['class'=>'font-weight-bold']).$textarea($name,$value,$rows),['class'=>'form-group']);};

$bmsshell=\local_kbacompany\local\bms_page_shell_service::begin($uuid,(int)$USER->id,'businessmodel',get_string('marketmodel_title','local_kbacompany'));
echo $OUTPUT->heading(get_string('marketmodel_title','local_kbacompany').': '.format_string($data['company']['name']),2);
echo html_writer::tag('p',get_string('marketmodel_intro_v2','local_kbacompany'),['class'=>'lead']);
echo $OUTPUT->notification(get_string('bmc_legacy_readonly','local_kbacompany'),'info');
echo $OUTPUT->notification(get_string('marketmodel_ownerreq_notice','local_kbacompany'),'info');

if($data['business_models']){
    $table=new html_table();$table->head=[get_string('marketmodel_name','local_kbacompany'),get_string('marketmodel_revision','local_kbacompany'),get_string('marketmodel_status','local_kbacompany'),get_string('marketmodel_source','local_kbacompany'),get_string('actions')];
    foreach($data['business_models'] as $row){
        $source='—';if(!empty($row['ownerframeuuid'])){$source=get_string('marketmodel_source_revision','local_kbacompany',(int)($row['ownerframerevision']??0));}
        $actions='';
        if($data['can_manage']&&$row['status']==='draft'){$actions.=html_writer::start_tag('form',['method'=>'post','class'=>'d-inline mr-1']).$hidden('publishbizmodel',$row).html_writer::tag('button',get_string('marketmodel_publish','local_kbacompany'),['type'=>'submit','class'=>'btn btn-sm btn-outline-primary']).html_writer::end_tag('form');}
        if($data['can_manage']){
            $actions.=html_writer::link(new moodle_url('/local/kbacompany/business_model_legacy.php',['uuid'=>$uuid,'edituuid'=>$row['uuid']]),get_string('edit'),['class'=>'btn btn-sm btn-outline-secondary mr-1']);
            $actions.=html_writer::link(new moodle_url('/local/kbacompany/business_model_legacy.php',['uuid'=>$uuid,'historyuuid'=>$row['uuid']]),get_string('marketmodel_history','local_kbacompany'),['class'=>'btn btn-sm btn-outline-secondary mr-1']);
            $actions.=html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']).$hidden('archivebizmodel',$row).html_writer::tag('button',get_string('delete'),['type'=>'submit','class'=>'btn btn-sm btn-outline-danger','onclick'=>"return confirm('".addslashes_js(get_string('marketmodel_deleteconfirm','local_kbacompany'))."')"]).html_writer::end_tag('form');
        }
        $table->data[]=[s($row['name']),(int)$row['revision'],s($statuslabel($row['status'])),s($source),$actions];
    }
    echo html_writer::table($table);
}else{echo $OUTPUT->notification(get_string('marketmodel_empty','local_kbacompany'),'info');}

if($history){
    echo $OUTPUT->heading(get_string('marketmodel_history','local_kbacompany'),3);
    $t=new html_table();$t->head=[get_string('marketmodel_revision','local_kbacompany'),get_string('marketmodel_status','local_kbacompany'),get_string('marketmodel_modified','local_kbacompany')];
    foreach($history as $row){$t->data[]=[(int)$row['revision'],s($statuslabel($row['status'])),userdate((int)$row['timemodified'])];}echo html_writer::table($t);
}

// Supporting market facts stay visible but are no longer confused with the Canvas itself.
echo $OUTPUT->heading(get_string('marketmodel_supportingdata','local_kbacompany'),3);
foreach([['marketmodel_markets',$data['markets'],['code','name','geography']],['marketmodel_segments',$data['segments'],['code','name','needs']],['marketmodel_offerings',$data['offerings'],['code','name','valueproposition']]] as $def){
    echo html_writer::tag('h4',get_string($def[0],'local_kbacompany'));if(!$def[1]){echo html_writer::tag('p','—',['class'=>'text-muted']);continue;}$t=new html_table();$t->head=array_map(function($c){return get_string('marketmodel_'.$c,'local_kbacompany');},$def[2]);foreach($def[1] as $row){$t->data[]=array_map(function($c)use($row){return s((string)($row[$c]??''));},$def[2]);}echo html_writer::table($t);
}

if($data['can_manage']){
    $r=$editing?:['name'=>get_string('marketmodel_defaultname','local_kbacompany'),'ownerframeuuid'=>'','canvas'=>[]];$c=$r['canvas']??[];
    echo $OUTPUT->heading($editing?get_string('marketmodel_editmodel','local_kbacompany'):get_string('marketmodel_newmodel','local_kbacompany'),3);
    echo html_writer::start_tag('form',['method'=>'post','class'=>'kbacompany-form mb-4']).$hidden('savebizmodel',$editing);
    echo html_writer::tag('div',html_writer::label(get_string('marketmodel_name','local_kbacompany'),'bm_name',false,['class'=>'font-weight-bold']).html_writer::empty_tag('input',['id'=>'bm_name','name'=>'name','class'=>'form-control','required'=>'required','value'=>$r['name']??'']),['class'=>'form-group']);
    $reqopts=[''=>get_string('marketmodel_noownerreq','local_kbacompany')];foreach($data['owner_requirement_sets'] as $set){$reqopts[$set['uuid']]=$set['label'].' — '.get_string('marketmodel_requirement_count','local_kbacompany',count($set['requirements']));}
    echo html_writer::tag('div',html_writer::label(get_string('marketmodel_ownerframeuuid','local_kbacompany'),'bm_ownerframeuuid',false,['class'=>'font-weight-bold']).html_writer::select($reqopts,'ownerframeuuid',$r['ownerframeuuid']??'',false,['id'=>'bm_ownerframeuuid','class'=>'form-control']),['class'=>'form-group']);
    echo $field('summary',(string)($c['summary']??''),3).$field('horizon',(string)($c['horizon']??''),2);
    echo html_writer::tag('h4',get_string('marketmodel_ideology','local_kbacompany'),['class'=>'mt-4']);
    foreach([['ideology_first',3],['ideology_target',3],['ideology_criterion',2],['ideology_reqs',2],['ideology_facilitator',2]] as $d){$value=(string)($c[$d[0]]??'');if($d[0]==='ideology_reqs'){$value=$requirements_for_ui($value);}echo $field($d[0],$value,$d[1]);}
    echo html_writer::tag('p',get_string('marketmodel_requirement_numbers_help','local_kbacompany'),['class'=>'text-muted']);
    $blocks=['segments','valueprop','relationships','channels','activities','resources','partners','revenue','costs'];
    foreach($blocks as $block){$b=$c[$block]??[];echo html_writer::tag('h4',get_string('marketmodel_block_'.$block,'local_kbacompany'),['class'=>'mt-4']);foreach([['first',2],['current',3],['data',2],['hyp',2],['target',3],['criterion',2],['reqs',2],['gap',2],['facilitator',2]] as $d){$name=$block.'_'.$d[0];$value=(string)($b[$d[0]]??'');if($d[0]==='reqs'){$value=$requirements_for_ui($value);}echo $field($name,$value,$d[1]);}}
    echo html_writer::tag('h4',get_string('marketmodel_checks','local_kbacompany'),['class'=>'mt-4']);
    $checkopts=[''=>get_string('marketmodel_check_none','local_kbacompany'),'confirmed'=>get_string('marketmodel_check_confirmed','local_kbacompany'),'partial'=>get_string('marketmodel_check_partial','local_kbacompany'),'hypothesis'=>get_string('marketmodel_check_hypothesis','local_kbacompany'),'needsdata'=>get_string('marketmodel_check_needsdata','local_kbacompany'),'open'=>get_string('marketmodel_check_open','local_kbacompany')];
    foreach(['market','client','economics','resources','ownerrequirements'] as $contour){$status=$c['checks'][$contour]??'';$note=$c['check_notes'][$contour]??'';echo html_writer::tag('div',html_writer::label(get_string('marketmodel_check_'.$contour,'local_kbacompany'),'bm_check_'.$contour,false,['class'=>'font-weight-bold']).html_writer::select($checkopts,'check_'.$contour,$status,false,['id'=>'bm_check_'.$contour,'class'=>'form-control']).$textarea('check_'.$contour.'_note',(string)$note,2),['class'=>'form-group']);}
    echo html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-primary mt-3']).html_writer::end_tag('form');
}

\local_kbacompany\local\bms_page_shell_service::finish($bmsshell);
