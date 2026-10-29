<?php
require_once(__DIR__ . '/../../config.php');

use local_kbacompany\local\bms_ai_bridge_service;
use local_kbacompany\local\company_context_domain_service;

require_login();
$context = context_system::instance();
require_capability('local/kbacompany:viewowncompany', $context);
$uuid = required_param('uuid', PARAM_RAW_TRIMMED);
$item = optional_param('item', '', PARAM_RAW_TRIMMED);
$revision = optional_param('revision', 0, PARAM_INT);
$screen = optional_param('screen', '', PARAM_ALPHANUMEXT);
$data = company_context_domain_service::context($uuid, (int)$USER->id);
$canmanage = !empty($data['can_manage']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (!$canmanage) {
        throw new required_capability_exception($context, 'local/kbacompany:manageorganization', 'nopermissions', '');
    }
    $action = required_param('action', PARAM_ALPHANUMEXT);
    if ($action === 'save') {
        $fields = ['name','geography','description','productclass','clientsegment','periodstart','periodend','unit',
            'totalsize','availablesize','targetsize','totalcompanies','positionrank','companiesabove','companiesbelow',
            'peersize','marketgrowth','companygrowth','salesmarginbenchmark','evidencesummary'];
        $payload=[];
        foreach($fields as $field){$payload[$field]=optional_param($field,'',PARAM_RAW_TRIMMED);}
        $payload['name']=required_param('name',PARAM_RAW_TRIMMED);
        $saved=company_context_domain_service::save_market($uuid,(int)$USER->id,$payload,
            optional_param('item','',PARAM_RAW_TRIMMED),optional_param('revision',0,PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid,'item'=>$saved]),get_string('companycontext_saved','local_kbacompany'));
    } else if ($action === 'publish') {
        $target=required_param('item',PARAM_RAW_TRIMMED); company_context_domain_service::publish_market($uuid,(int)$USER->id,$target,required_param('revision',PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid,'item'=>$target]),get_string('companycontext_published','local_kbacompany'));
    } else if ($action === 'archive') {
        company_context_domain_service::archive_market($uuid,(int)$USER->id,required_param('item',PARAM_RAW_TRIMMED),required_param('revision',PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid]),get_string('companycontext_archived','local_kbacompany'));
    } else if ($action === 'saveevidence') {
        $marketuuid=required_param('marketuuid',PARAM_RAW_TRIMMED); $market=company_context_domain_service::market($uuid,(int)$USER->id,$marketuuid);
        company_context_domain_service::save_evidence($uuid,(int)$USER->id,[
            'marketuuid'=>$marketuuid,'marketrevision'=>(int)$market['revision'],'marker'=>required_param('marker',PARAM_ALPHANUMEXT),
            'title'=>required_param('title',PARAM_RAW_TRIMMED),'provider'=>optional_param('provider','',PARAM_RAW_TRIMMED),
            'sourceref'=>optional_param('sourceref','',PARAM_RAW_TRIMMED),'period'=>optional_param('period','',PARAM_RAW_TRIMMED),
            'value'=>optional_param('value','',PARAM_RAW_TRIMMED),'unit'=>optional_param('unit','',PARAM_RAW_TRIMMED),
            'note'=>optional_param('note','',PARAM_RAW_TRIMMED),'fetchedat'=>optional_param('fetchedat','',PARAM_RAW_TRIMMED),
        ]);
        redirect(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid,'item'=>$marketuuid]),get_string('companycontext_saved','local_kbacompany'));
    } else if ($action === 'publishevidence') {
        company_context_domain_service::publish_evidence($uuid,(int)$USER->id,required_param('evidence',PARAM_RAW_TRIMMED),required_param('evidencerevision',PARAM_INT));
        redirect(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid,'item'=>required_param('item',PARAM_RAW_TRIMMED)]),get_string('companycontext_published','local_kbacompany'));
    }
}

$record=$item!==''?company_context_domain_service::market($uuid,(int)$USER->id,$item,$revision):null;
$PAGE->set_context($context);$PAGE->set_url(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid]));
$PAGE->set_title(get_string('companycontext_market','local_kbacompany'));$PAGE->set_heading(get_string('pluginname','local_kbacompany'));
$shell=\local_kbacompany\local\bms_page_shell_service::begin($uuid,(int)$USER->id,'market',get_string('companycontext_market','local_kbacompany'));

echo \local_kbacompany\local\bms_workspace_ux_service::architecture_nav($uuid, 'market');

echo html_writer::start_div('d-flex justify-content-between align-items-start mb-4');
echo html_writer::div($OUTPUT->heading(get_string('companycontext_market','local_kbacompany').': '.format_string($data['company']['name']),2,'mb-1').html_writer::tag('p',get_string('companycontext_market_intro','local_kbacompany'),['class'=>'text-muted mb-0']));
if($canmanage&&$screen===''&&!$record){echo html_writer::link(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid,'screen'=>'edit']),get_string('companycontext_create_market','local_kbacompany'),['class'=>'btn btn-primary']);}
echo html_writer::end_div();

$aistatus=\local_kbacompany\local\bms_ai_bridge_service::status();
$aienabled=!empty($aistatus['available']);

if($screen==='edit'){
    if(!$canmanage){throw new required_capability_exception($context,'local/kbacompany:manageorganization','nopermissions','');}
    $defaults=['uuid'=>'','revision'=>0,'stablecode'=>'','name'=>'','geography'=>'','description'=>'','productclass'=>'','clientsegment'=>'','periodstart'=>'','periodend'=>'','unit'=>'','totalsize'=>'','availablesize'=>'','targetsize'=>'','totalcompanies'=>'','positionrank'=>'','companiesabove'=>'','companiesbelow'=>'','peersize'=>'','marketgrowth'=>'','companygrowth'=>'','salesmarginbenchmark'=>'','evidencesummary'=>''];
    $v=array_merge($defaults,$record?:[]);
    echo html_writer::start_tag('form',['method'=>'post','class'=>'bms-record-editview']);
    foreach([['sesskey',sesskey()],['action','save']] as $h)echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);
    if($record){echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'item','value'=>$record['uuid']]);echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'revision','value'=>$record['revision']]);}
    $sections=[
        ['companycontext_section_identity',[['name','companycontext_market_name','text',true],['productclass','companycontext_productclass','text',false],['clientsegment','companycontext_clientsegment','text',false],['geography','companycontext_geography','text',false],['periodstart','companycontext_periodstart','date',false],['periodend','companycontext_periodend','date',false]]],
        ['companycontext_section_capacity',[['unit','companycontext_unit','text',false],['totalsize','companycontext_totalsize','text',false],['availablesize','companycontext_availablesize','text',false],['targetsize','companycontext_targetsize','text',false],['totalcompanies','companycontext_totalcompanies','text',false]]],
        ['companycontext_section_position',[['positionrank','companycontext_positionrank','text',false],['companiesabove','companycontext_companiesabove','text',false],['companiesbelow','companycontext_companiesbelow','text',false],['peersize','companycontext_peersize','text',false],['marketgrowth','companycontext_marketgrowth','text',false],['companygrowth','companycontext_companygrowth','text',false],['salesmarginbenchmark','companycontext_marginbenchmark','text',false]]],
    ];
    echo html_writer::start_div('card mb-3');echo html_writer::div(get_string('companycontext_stablecode','local_kbacompany'),'card-header font-weight-bold');echo html_writer::div($record?s($record['stablecode']??$record['code']):get_string('financialcrud_stablecode_auto','local_kbacompany'),'card-body text-muted');echo html_writer::end_div();
    foreach($sections as $section){echo html_writer::start_div('card mb-3');echo html_writer::div(get_string($section[0],'local_kbacompany'),'card-header font-weight-bold');echo html_writer::start_div('card-body row');foreach($section[1] as $f){echo html_writer::start_div('col-md-6');echo html_writer::start_div('form-group');echo html_writer::tag('label',get_string($f[1],'local_kbacompany'),['for'=>'mkt-'.$f[0]]);$attrs=['id'=>'mkt-'.$f[0],'name'=>$f[0],'type'=>$f[2],'class'=>'form-control','value'=>$v[$f[0]]];if($f[3])$attrs['required']='required';echo html_writer::empty_tag('input',$attrs);echo html_writer::end_div();echo html_writer::end_div();}echo html_writer::end_div();echo html_writer::end_div();}
    foreach([['description','companycontext_market_description',6],['evidencesummary','companycontext_evidence_summary',5]] as $f){echo html_writer::start_div('card mb-3');echo html_writer::start_div('card-header d-flex justify-content-between align-items-center');echo html_writer::tag('span',get_string($f[1],'local_kbacompany'),['class'=>'font-weight-bold']);if($aienabled){echo html_writer::tag('button','✨ '.get_string('bmsai_help','local_kbacompany'),['type'=>'button','class'=>'btn btn-outline-secondary btn-sm bms-ai-help','data-field'=>$f[0],'data-target'=>'mkt-'.$f[0]]);}else{echo html_writer::tag('span',get_string('bmsai_unavailable_short','local_kbacompany'),['class'=>'badge badge-light text-muted']);}echo html_writer::end_div();echo html_writer::start_div('card-body');echo html_writer::tag('textarea',s($v[$f[0]]),['id'=>'mkt-'.$f[0],'name'=>$f[0],'rows'=>$f[2],'class'=>'form-control']);echo html_writer::end_div();echo html_writer::end_div();}
    echo html_writer::start_div('d-flex justify-content-end');echo html_writer::link(new moodle_url('/local/kbacompany/market_assessment.php',$record?['uuid'=>$uuid,'item'=>$record['uuid']]:['uuid'=>$uuid]),get_string('cancel'),['class'=>'btn btn-secondary mr-2']);echo html_writer::tag('button',get_string('savechanges'),['type'=>'submit','class'=>'btn btn-primary']);echo html_writer::end_div();echo html_writer::end_tag('form');
    echo html_writer::div('','bms-ai-drawer d-none',['id'=>'bms-ai-panel','role'=>'dialog','aria-modal'=>'true']);
}else if($record){
    echo html_writer::start_div('card mb-3');echo html_writer::start_div('card-body');echo html_writer::start_div('d-flex justify-content-between');$code=!empty($record['stablecode'])?$record['stablecode']:$record['code'];echo html_writer::div($OUTPUT->heading(s($code.' — '.$record['name']),3,'mb-1').html_writer::tag('span',s($record['status']),['class'=>'badge badge-secondary']));if($canmanage){echo html_writer::link(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid,'item'=>$record['uuid'],'revision'=>$record['revision'],'screen'=>'edit']),get_string('edit'),['class'=>'btn btn-primary']);}echo html_writer::end_div();
    $fields=[['companycontext_productclass','productclass'],['companycontext_clientsegment','clientsegment'],['companycontext_geography','geography'],['companycontext_totalsize','totalsize'],['companycontext_availablesize','availablesize'],['companycontext_targetsize','targetsize'],['companycontext_totalcompanies','totalcompanies'],['companycontext_positionrank','positionrank'],['companycontext_companiesabove','companiesabove'],['companycontext_companiesbelow','companiesbelow'],['companycontext_marketgrowth','marketgrowth'],['companycontext_companygrowth','companygrowth'],['companycontext_marginbenchmark','salesmarginbenchmark']];
    $table=new html_table();$table->data=[];foreach($fields as $f){$table->data[]=[get_string($f[0],'local_kbacompany'),s((string)($record[$f[1]]??''))];}echo html_writer::table($table);echo html_writer::tag('h5',get_string('companycontext_market_description','local_kbacompany'),['class'=>'mt-3']);echo html_writer::div(nl2br(s((string)$record['description'])));echo html_writer::end_div();echo html_writer::end_div();
    if($canmanage&&$record['status']==='draft'){echo html_writer::start_tag('form',['method'=>'post','class'=>'d-inline']);foreach([['sesskey',sesskey()],['action','publish'],['item',$record['uuid']],['revision',$record['revision']]] as $h)echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);echo html_writer::tag('button',get_string('companycontext_publish','local_kbacompany'),['type'=>'submit','class'=>'btn btn-success']);echo html_writer::end_tag('form');}
    if($canmanage){
        echo html_writer::start_div('card mt-4 mb-3');
        echo html_writer::div(get_string('marketdata_title','local_kbacompany'),'card-header font-weight-bold');
        echo html_writer::start_div('card-body');
        echo html_writer::tag('p',get_string('marketdata_intro','local_kbacompany'),['class'=>'text-muted']);
        echo html_writer::start_div('input-group');
        echo html_writer::empty_tag('input',['id'=>'marketdata-query','type'=>'search','class'=>'form-control','placeholder'=>get_string('marketdata_query_placeholder','local_kbacompany')]);
        echo html_writer::start_div('input-group-append');
        echo html_writer::tag('button',get_string('marketdata_search','local_kbacompany'),['id'=>'marketdata-search','type'=>'button','class'=>'btn btn-outline-primary']);
        echo html_writer::end_div();
        echo html_writer::end_div();
        echo html_writer::tag('div',get_string('marketdata_readonly_note','local_kbacompany'),['class'=>'small text-muted mt-2']);
        echo html_writer::div('','mt-3',['id'=>'marketdata-results']);
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
    echo $OUTPUT->heading(get_string('companycontext_evidence','local_kbacompany'),3,'mt-4');$ev=company_context_domain_service::market_evidence($uuid,(int)$USER->id,$record['uuid']);foreach($ev as $e){echo html_writer::start_div('card mb-2');echo html_writer::div(html_writer::tag('strong',s($e['stablecode'].' — '.$e['title'])).html_writer::tag('div',s(get_string('bmc_marker_'.$e['marker'],'local_kbacompany')).' · '.s($e['provider']).' · '.s($e['period']),['class'=>'small text-muted mt-1']).html_writer::tag('div',s($e['value'].' '.$e['unit']),['class'=>'mt-2']), 'card-body');echo html_writer::end_div();}
    if($canmanage){echo html_writer::start_div('card mt-3');echo html_writer::div(get_string('companycontext_add_evidence','local_kbacompany'),'card-header font-weight-bold');echo html_writer::start_div('card-body');echo html_writer::start_tag('form',['method'=>'post']);foreach([['sesskey',sesskey()],['action','saveevidence'],['marketuuid',$record['uuid']]] as $h)echo html_writer::empty_tag('input',['type'=>'hidden','name'=>$h[0],'value'=>$h[1]]);$markeropts=[];foreach($data['evidence_states'] as $state)$markeropts[$state]=get_string('bmc_marker_'.$state,'local_kbacompany');echo html_writer::div(html_writer::tag('label',get_string('companycontext_evidence_state','local_kbacompany')).html_writer::select($markeropts,'marker','fact',false,['class'=>'form-control']),'form-group');foreach([['title','companycontext_evidence_title'],['provider','companycontext_evidence_provider'],['sourceref','companycontext_evidence_source'],['period','companycontext_evidence_period'],['value','companycontext_evidence_value'],['unit','companycontext_unit']] as $f){$attrs=['id'=>'evidence-'.$f[0],'name'=>$f[0],'class'=>'form-control'];if($f[0]==='title'){$attrs['required']='required';}echo html_writer::div(html_writer::tag('label',get_string($f[1],'local_kbacompany'),['for'=>'evidence-'.$f[0]]).html_writer::empty_tag('input',$attrs),'form-group');}echo html_writer::div(html_writer::tag('label',get_string('companycontext_notes','local_kbacompany')).html_writer::tag('textarea','',['name'=>'note','rows'=>3,'class'=>'form-control']),'form-group');echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'fetchedat','value'=>(string)time()]);echo html_writer::tag('button',get_string('companycontext_add_evidence','local_kbacompany'),['type'=>'submit','class'=>'btn btn-primary']);echo html_writer::end_tag('form');echo html_writer::end_div();echo html_writer::end_div();}
}else{
    if(!$data['markets']){echo $OUTPUT->notification(get_string('companycontext_market_empty','local_kbacompany'),'info');}else{$table=new html_table();$table->head=[get_string('companycontext_stablecode','local_kbacompany'),get_string('companycontext_market_name','local_kbacompany'),get_string('companycontext_geography','local_kbacompany'),get_string('companycontext_totalcompanies','local_kbacompany'),get_string('status')];foreach($data['markets'] as $row){$code=!empty($row['stablecode'])?$row['stablecode']:$row['code'];$table->data[]=[html_writer::link(new moodle_url('/local/kbacompany/market_assessment.php',['uuid'=>$uuid,'item'=>$row['uuid']]),s($code)),format_string($row['name']),s($row['geography']),s($row['totalcompanies']??''),s($row['status'])];}echo html_writer::table($table);}
}

if($record&&$canmanage){
    $marketdataendpoint=(new moodle_url('/local/kbacompany/market_data_query.php'))->out(false);
    $PAGE->requires->js_init_code("(function(){var button=document.getElementById('marketdata-search');var input=document.getElementById('marketdata-query');var results=document.getElementById('marketdata-results');if(!button||!input||!results){return;}var run=async function(){var q=input.value.trim();if(!q){input.focus();return;}button.disabled=true;results.textContent='" . addslashes(get_string('marketdata_loading','local_kbacompany')) . "';var body=new URLSearchParams({sesskey:M.cfg.sesskey,uuid:'" . addslashes($uuid) . "',provider:'rosstat_open_data',query:q,limit:'10'});try{var r=await fetch('" . addslashes($marketdataendpoint) . "',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body});var j=await r.json();if(!j.ok){throw new Error(j.error||'Market data error');}var items=(j.result&&j.result.items)||[];results.innerHTML='';if(!items.length){results.textContent='" . addslashes(get_string('marketdata_no_results','local_kbacompany')) . "';return;}items.forEach(function(item){var card=document.createElement('div');card.className='border rounded p-2 mb-2';var title=document.createElement('div');title.className='font-weight-bold';title.textContent=item.title||'';card.appendChild(title);if(item.updated){var meta=document.createElement('div');meta.className='small text-muted';meta.textContent=item.updated;card.appendChild(meta);}var link=document.createElement('a');link.href=item.source_ref;link.target='_blank';link.rel='noopener noreferrer';link.textContent=item.source_ref;link.className='small d-block text-break';card.appendChild(link);var use=document.createElement('button');use.type='button';use.className='btn btn-sm btn-outline-primary mt-2';use.textContent='" . addslashes(get_string('marketdata_use_source','local_kbacompany')) . "';use.onclick=function(){var t=document.getElementById('evidence-title');var p=document.getElementById('evidence-provider');var sr=document.getElementById('evidence-sourceref');if(t&&!t.value){t.value=item.title||'';}if(p){p.value='rosstat_open_data';}if(sr){sr.value=item.source_ref||'';}var ev=document.getElementById('evidence-title');if(ev){ev.scrollIntoView({behavior:'smooth',block:'center'});}};card.appendChild(use);results.appendChild(card);});}catch(e){results.textContent=e.message;}finally{button.disabled=false;}};button.addEventListener('click',run);input.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();run();}});})();");
}

if ($screen === 'edit') {
    \local_kbacompany\local\bms_ai_ui_service::init($uuid, 'market', $record);
}
\local_kbacompany\local\bms_page_shell_service::finish($shell);
