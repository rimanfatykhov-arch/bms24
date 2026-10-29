<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS CRUD presentation; permissions and lifecycle decisions come from deviation_service. */
class deviation_ui_service {
    public static function tr(string $key,$a=null): string { return get_string('dvu_'.$key,'local_kbacompany',$a); }
    public static function url(string $company,array $params=[]): \moodle_url { return new \moodle_url('/local/kbacompany/deviations.php',['uuid'=>$company]+$params); }
    public static function link($url,string $text,bool $primary=false): string { return \html_writer::link($url,s($text),['class'=>'btn '.($primary?'btn-primary':'btn-outline-secondary')]); }
    private static function note(string $text): string { return \html_writer::tag('p',s($text),['class'=>'bms-dvu-note']); }
    private static function panel(string $title,string $body): string { return \html_writer::tag('section',\html_writer::tag('h2',s($title),['class'=>'bms-crm-section-title']).$body,['class'=>'bms-crm-section bms-dvu-panel']); }
    private static function field(string $title,$value): string { return \html_writer::div(\html_writer::tag('dt',s($title)).\html_writer::tag('dd',nl2br(s((string)$value!==''?(string)$value:self::tr('not_set')))),'bms-dvu-field'); }
    private static function hidden(array $values): string { $out='';foreach($values as $key=>$value){$out.=\html_writer::empty_tag('input',['type'=>'hidden','name'=>$key,'value'=>$value]);}return $out; }
    public static function badge(string $status): string { $safe=in_array($status,deviation_service::STATES,true)?$status:'reported';return \html_writer::tag('span',s(self::tr('status_'.$safe)),['class'=>'bms-dvu-badge bms-dvu-'.$safe]); }
    public static function errors(array $errors): string { return !$errors?'':\html_writer::div(s($errors['_form']??self::tr('validation')),'alert alert-danger',['role'=>'alert']); }
    private static function table(array $headers,array $rows,string $label): string {
        $table=new \html_table();$table->attributes['class']='table bms-crm-list-table bms-dvu-table';$table->head=array_map('s',$headers);
        foreach($rows as $row){$cells=[];foreach($row as $i=>$cell){$cells[]=\html_writer::tag('span',s($headers[$i]),['class'=>'bms-dvu-cell-label','aria-hidden'=>'true']).$cell;}$table->data[]=$cells;}
        return \html_writer::div(\html_writer::table($table),'bms-dvu-table-wrap',['tabindex'=>'0','role'=>'region','aria-label'=>$label]);
    }
    private static function pager(string $company,array $model,array $params,string $pagekey='page'): string {
        if($model['total']<=$model['pagesize']){return '';}$p=$model['page'];$size=$model['pagesize'];
        $out=$p>0?self::link(self::url($company,array_merge($params,[$pagekey=>$p-1])),self::tr('previous')):'';
        $out.=self::note(self::tr('page',(object)['page'=>$p+1,'pages'=>(int)ceil($model['total']/$size)]));
        if(($p+1)*$size<$model['total']){$out.=self::link(self::url($company,array_merge($params,[$pagekey=>$p+1])),self::tr('next_page'));}
        return \html_writer::tag('nav',$out,['class'=>'bms-dvu-actions','aria-label'=>self::tr('list')]);
    }
    public static function listing(string $company,array $m): string {
        $actions=$m['can_report']?self::link(self::url($company,['view'=>'create']),self::tr('report'),true):'';
        if($m['can_manage']){$actions.=self::link(self::url($company,['view'=>'create','mode'=>'quantitative']),self::tr('quantitative'));}
        $out=bms_workspace_ux_service::record_header(s(self::tr('title')),s(self::tr('intro')),'','',$actions);
        $f=$m['filters'];$views='';foreach(['all','my','pending','overdue'] as $v){$views.=\html_writer::link(self::url($company,['scope'=>$v]),s(self::tr($v)),['class'=>'bms-dvu-tab'.($f['scope']===$v?' is-active':''),'aria-current'=>$f['scope']===$v?'page':null]);}
        $out.=\html_writer::tag('nav',$views,['class'=>'bms-dvu-tabs','aria-label'=>self::tr('list')]);
        $states=[''=>self::tr('all_status')];foreach(deviation_service::STATES as $st){$states[$st]=self::tr('status_'.$st);}
        $form=self::hidden(['uuid'=>$company,'scope'=>$f['scope']]).self::control('q',['label'=>'search','type'=>'search','maxlength'=>200],$f['q']).
            self::control('status',['label'=>'status','options'=>$states],$f['status']).self::control('entrytype',['label'=>'entrytype','options'=>[''=>self::tr('all_types'),'manual'=>self::tr('manual'),'quantitative'=>self::tr('quantitative')]],$f['entrytype']);
        $form.=\html_writer::div(\html_writer::tag('button',s(self::tr('apply')),['type'=>'submit','class'=>'btn btn-primary']).self::link(self::url($company),self::tr('reset')),'bms-dvu-actions');
        $out.=\html_writer::tag('form',$form,['method'=>'get','action'=>self::url($company)->out(false),'class'=>'bms-dvu-filters']);
        $out.=self::note(self::tr($m['can_manage']?'manager_scope':'own_scope'));
        if(!$m['total']){$out.=self::panel(self::tr('empty'),self::note(self::tr($f['q']!==''||$f['status']!==''||$f['scope']!=='all'?'empty_filtered':'empty_help')));}
        else{
            $rows=[];foreach($m['rows'] as $r){$rows[]=[s($r->code?:self::tr('legacy_code')),\html_writer::link(self::url($company,['deviation'=>$r->uuid]),s($r->title)).self::note(self::tr($r->entrytype==='manual'?'manual':'quantitative')),self::badge($r->status),s($r->ownername?:self::tr('unassigned')),s($r->observedon),s($r->duedate?:'—'),s(userdate($r->timemodified))];}
            $out.=self::note(self::tr('found',$m['total'])).self::table(array_map([self::class,'tr'],['code','name','status','ownerpositionuuid','observedon','duedate','updated']),$rows,self::tr('title'));
            $out.=self::pager($company,$m,$f);
        }
        return $out.self::limits();
    }
    private static function limits(): string { return \html_writer::tag('details',\html_writer::tag('summary',s(self::tr('limits'))).self::note(self::tr('limits_text')),['class'=>'bms-dvu-details']); }
    private static function control(string $name,array $spec,$value='',string $error=''): string {
        $id='dvu_'.$name;$attrs=['id'=>$id,'name'=>$name,'class'=>'form-control'.($error!==''?' is-invalid':'')];$description=[];
        if(!empty($spec['required'])){$attrs['required']='required';}if(isset($spec['maxlength'])){$attrs['maxlength']=$spec['maxlength'];}
        if(isset($spec['help'])){$description[]=$id.'_help';}if($error!==''){$description[]=$id.'_error';$attrs['aria-invalid']='true';}if($description){$attrs['aria-describedby']=implode(' ',$description);}
        $out=\html_writer::label(s(self::tr($spec['label']).(!empty($spec['required'])?' *':'')),$id);
        if(isset($spec['options'])){$out.=\html_writer::select($spec['options'],$name,$value,false,$attrs);}
        else if(($spec['type']??'')==='textarea'){$out.=\html_writer::tag('textarea',s((string)$value),$attrs+['rows'=>$spec['rows']??4]);}
        else{$out.=\html_writer::empty_tag('input',$attrs+['type'=>$spec['type']??'text','value'=>$value]+(($spec['type']??'')==='number'?['step'=>'0.000001']:[]));}
        if(isset($spec['help'])){$out.=\html_writer::div(s(self::tr($spec['help'])),'bms-dvu-note',['id'=>$id.'_help']);}if($error!==''){$out.=\html_writer::div(s($error),'invalid-feedback d-block',['id'=>$id.'_error']);}
        return \html_writer::div($out,'bms-dvu-control'.(($spec['type']??'')==='textarea'||!empty($spec['wide'])?' bms-dvu-wide':''));
    }
    private static function options(array $rows,string $empty,string $current=''): array {
        $out=[''=>$empty];foreach($rows as $r){$out[$r['value']]=$r['label'];}if($current!==''&&!isset($out[$current])){$out[$current]=self::tr('reference_unavailable');}return $out;
    }
    public static function schema(string $action,array $o,array $v,bool $manual=true): array {
        $text=static function($label,$required=false,$help=''){return ['label'=>$label,'type'=>'textarea','maxlength'=>4000,'required'=>$required]+($help!==''?['help'=>$help]:[]);};
        if($action==='report'||$action==='editreport'){
            return ['main'=>['title'=>['label'=>'name','required'=>true,'maxlength'=>255,'wide'=>true],
                'description'=>$text('description',true),'location'=>['label'=>'location','required'=>true,'maxlength'=>255],
                'observedon'=>['label'=>'observedon','type'=>'date','required'=>true]],
                'basis'=>['requirement'=>$text('requirement',false,'requirement_help'),
                    'documentref'=>['label'=>'documentref','options'=>self::options($o['documents']??[],self::tr('no_document'),$v['documentref']??''),'lookup'=>'documents','wide'=>true,'help'=>'document_help'],
                    'evidence'=>$text('evidence',false,'evidence_help')]];
        }
        if($action==='create'||($action==='check'&&!$manual)){
            return ['basis'=>['basis'=>['label'=>'norm','required'=>true,'options'=>self::options($o['norms']??[],self::tr('choose'),$v['basis']??''),'lookup'=>'norms','wide'=>true],
                'actual'=>['label'=>'actual','type'=>'number','required'=>true],'observedon'=>['label'=>'observedon','type'=>'date','required'=>true],'evidence'=>$text('evidence',true)]];
        }
        if($action==='confirm'){
            return ['basis'=>['criterion'=>$text('criterion',true,'criterion_help'),'reason'=>$text('reason',true)],
                'responsibility'=>['ownerpositionuuid'=>['label'=>'ownerpositionuuid','options'=>self::options($o['positions']??[],self::tr('unassigned'),$v['ownerpositionuuid']??''),'lookup'=>'positions','help'=>'owner_help'],
                    'duedate'=>['label'=>'duedate','type'=>'date']]];
        }
        if($action==='analyze'){return ['analysis'=>['cause'=>$text('cause',true,'cause_help')]];}
        if($action==='link'||$action==='unlink'){
            return ['tasks'=>['task'=>['label'=>'task','required'=>true,'options'=>self::options($o['tasks']??[],self::tr('choose'),$v['task']??''),'lookup'=>$action==='link'?'tasks':'linked','wide'=>true,'help'=>'task_help'],'reason'=>$text('reason',true)]];
        }
        if($action==='check'){
            return ['checks'=>['observedon'=>['label'=>'observedon','type'=>'date','required'=>true],
                'outcome'=>['label'=>'outcome','required'=>true,'options'=>[''=>self::tr('choose'),'pass'=>self::tr('outcome_pass'),'fail'=>self::tr('outcome_fail')]],'evidence'=>$text('evidence',true,'evidence_help')]];
        }
        return ['basis'=>['reason'=>$text('reason',true)]];
    }
    private static function lookup(string $company,string $target,string $name,string $object): string {
        $id='dvu_lookup_'.$name;
        $body=\html_writer::label(s(self::tr('lookup')),$id).\html_writer::empty_tag('input',['id'=>$id,'type'=>'search','class'=>'form-control','maxlength'=>200]).
            \html_writer::tag('button',s(self::tr('find')),['type'=>'button','class'=>'btn btn-outline-secondary','data-dvu-find'=>'1']).
            \html_writer::tag('button',s(self::tr('next_page')),['type'=>'button','class'=>'btn btn-outline-secondary','data-dvu-more'=>'1']).
            \html_writer::tag('span','',['role'=>'status','aria-live'=>'polite','data-dvu-result'=>'1']);
        return \html_writer::tag('details',\html_writer::tag('summary',s(self::tr('lookup'))).self::note(self::tr('lookup_help')).$body,
            ['class'=>'bms-dvu-lookup','data-dvu-url'=>self::url($company,['view'=>'lookup','target'=>$target,'deviation'=>$object])->out(false),
             'data-dvu-select'=>'dvu_'.$name,'data-dvu-error'=>self::tr('lookup_error'),'data-dvu-found'=>self::tr('lookup_found')]);
    }
    public static function form(string $company,string $action,array $options,array $values,array $errors,array $proof,?array $model=null): string {
        $row=$model['selected']??null;$object=$row?$row->uuid:'';$manual=!$row||$row->entrytype==='manual';
        $out=self::link(self::url($company,$row?['deviation'=>$object]:[]),self::tr($row?'back':'list'));
        $help=in_array($action,['report','editreport'],true)?($row?'edit_help':'no_norm_needed'):($action==='create'?'quant_hint':($action==='check'?'check_hint':($action==='close'?'close_help':($action==='reject'?'reject_help':($action==='cancel'?'cancel_help':($action==='unlink'?'unlink_help':'criterion_help'))))));
        $out.=bms_workspace_ux_service::record_header(s(self::tr($action)),s(self::tr($help)),$row?s($row->code?:self::tr('legacy_code')):'');
        if(!$row){$out.=self::note(self::tr('code').': '.self::tr('auto_code'));}
        if($row&&$row->criterion){$out.=self::panel(self::tr('criterion'),self::note($row->criterion));}
        if(($action==='create'||($action==='check'&&!$manual))&&empty($options['norms'])){
            $out.=self::note(self::tr('no_norms')).self::link(self::url($company,['view'=>'create']),self::tr('report'));
        }
        $body=self::hidden(['uuid'=>$company,'deviation'=>$object,'view'=>$row?'action':'create','mode'=>$action==='create'?'quantitative':'manual',
            'operation'=>$action,'action'=>$action,'revision'=>$row->revision??0,'sesskey'=>sesskey(),'requestkey'=>$proof['requestkey'],'taskengine'=>'bms']);
        foreach(self::schema($action,$options,$values,$manual) as $title=>$fields){$group='';foreach($fields as $name=>$spec){$control=self::control($name,$spec,$values[$name]??'',$errors[$name]??'');
            if(!empty($spec['lookup'])){$control=\html_writer::div($control.self::lookup($company,$spec['lookup'],$name,$object),'bms-dvu-reference');}$group.=$control;}
            $body.=self::panel(self::tr($title),\html_writer::div($group,'bms-crm-field-grid bms-dvu-grid'));
        }
        if(!in_array($action,['report','editreport','create'],true)){
            $body.=\html_writer::div(\html_writer::empty_tag('input',['type'=>'checkbox','id'=>'dvu_confirmed','name'=>'confirmed','value'=>1,'required'=>'required']).
                \html_writer::label(s(self::tr('confirm_checkbox')),'dvu_confirmed'),'bms-dvu-confirm');
        }
        $body.=\html_writer::div(\html_writer::tag('button',s(self::tr($action==='editreport'?'save':$action)),['class'=>'btn btn-primary','type'=>'submit']).self::link(self::url($company,$row?['deviation'=>$object]:[]),self::tr('cancel_form')),'bms-dvu-actions');
        return $out.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company)->out(false),'class'=>'bms-dvu-form']).self::limits();
    }
    public static function detail(string $company,array $m,string $tab): string {
        $r=$m['selected'];$manual=$r->entrytype==='manual';$title=$r->title?:($m['basis']?($m['basis']['function']['name'].' / '.$m['basis']['object']['name']):self::tr($manual?'manual':'quantitative'));
        $actions='';if($m['actions']['editreport']){$actions.=self::link(self::url($company,['view'=>'edit','deviation'=>$r->uuid]),self::tr('editreport'),true);}
        $menu='';foreach($m['actions'] as $op=>$allowed){if($op==='editreport'||!$allowed){continue;}$menu.=self::link(self::url($company,['view'=>'action','deviation'=>$r->uuid,'operation'=>$op]),self::tr($op));}
        if($menu!==''){$actions.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('actions'))).\html_writer::div($menu,'bms-dvu-menu-items'),['class'=>'bms-dvu-menu']);}
        $out=self::link(self::url($company),self::tr('list'));
        $out.=bms_workspace_ux_service::record_header(s($title),s(self::tr($manual?'manual':'quantitative')),s($r->code?:self::tr('legacy_code')),self::badge($r->status).self::note(self::tr('revision',$r->revision)),$actions);
        $out.=\html_writer::div(\html_writer::tag('strong',s(self::tr('next'))).self::note(self::tr('next_'.(in_array($r->status,deviation_service::STATES,true)?$r->status:'reported'))),'bms-dvu-next');
        $nav='';foreach(['overview','analysis','tasks','checks','history'] as $t){$nav.=\html_writer::link(self::url($company,['deviation'=>$r->uuid,'tab'=>$t]),s(self::tr($t)),['class'=>'bms-dvu-tab'.($tab===$t?' is-active':''),'aria-current'=>$tab===$t?'page':null]);}
        $out.=\html_writer::tag('nav',$nav,['class'=>'bms-dvu-tabs','aria-label'=>self::tr('overview')]);
        if($tab==='overview'){
            if($r->status==='reported'){$out.=self::note(self::tr('not_confirmed'));}
            $fields=self::field(self::tr('observedon'),$r->observedon).self::field(self::tr('author'),$m['authorname']);
            if($manual){$fields.=self::field(self::tr('description'),$r->description).self::field(self::tr('location'),$r->location);}
            else{$fields.=self::field(self::tr('actual'),$r->actual);if($m['basis']){$b=$m['basis'];$fields.=self::field(self::tr('norm'),$b['object']['name'].' / '.$b['data']['periodstart'].' / '.$b['stat']['unit']);}}
            $fields.=self::field(self::tr('evidence'),$r->evidence);$out.=self::panel(self::tr('main'),\html_writer::tag('dl',$fields,['class'=>'bms-dvu-fields']));
            $basis=$manual?self::field(self::tr('requirement'),$r->requirement?:self::tr('manual_no_requirement')).self::field(self::tr('criterion'),$r->criterion):'';
            if(!$manual&&$m['basis']){$b=$m['basis'];$bounds=deviation_service::evaluate((string)$r->actual,$b['data'],$b['stat']['direction']);
                $basis=self::field(self::tr('target'),$b['data']['amount']).self::field(self::tr('lower'),$bounds['lower']??self::tr('unbounded')).self::field(self::tr('upper'),$bounds['upper']??self::tr('unbounded'));}
            $out.=self::panel(self::tr('basis'),\html_writer::tag('dl',$basis,['class'=>'bms-dvu-fields']).($m['document']?self::link(new \moodle_url('/local/kbacompany/documents.php',['uuid'=>$company,'document'=>$m['document']->uuid,'rev'=>$m['document']->revision]),$m['document']->code.' · '.$m['document']->title.' · '.$m['document']->revision):($r->basisref?self::note(self::tr('reference_unavailable')):'')));
            $out.=self::panel(self::tr('responsibility'),\html_writer::tag('dl',self::field(self::tr('ownerpositionuuid'),$m['ownername']?:self::tr('unassigned')).self::field(self::tr('duedate'),$r->duedate),['class'=>'bms-dvu-fields']));
        }else if($tab==='analysis'){$out.=self::panel(self::tr('cause'),self::note($r->cause?:self::tr('not_set')).self::note(self::tr('cause_help')));}
        else if($tab==='tasks'){
            $out.=self::note(self::tr('task_help'));if($m['can_manage']){$out.=self::link(new \moodle_url('/local/kbacompany/tasks.php',['uuid'=>$company,'view'=>'create']),self::tr('new_task'));}
            foreach(['links','legacy_links'] as $key){$rows=[];foreach($m[$key]??[] as $l){$url=new \moodle_url('/local/kbacompany/'.($l->taskengine==='bms'?'tasks.php':'execution.php'),['uuid'=>$company,($l->taskengine==='bms'?'task':'work')=>$l->taskuuid]);
                    $status=get_string(($l->taskengine==='bms'?'taskcenter_status_':'wf_status_').$l->status,'local_kbacompany');$rows[]=[\html_writer::link($url,s($l->title)),s($status),s($l->product),s($l->duedate),s(self::tr($l->active?'active_link':'inactive_link'))];}
                if($key==='legacy_links'&&!$rows){continue;}$out.=self::panel(self::tr($key==='links'?'tasks':'legacy_tasks'),$rows?self::table(array_map([self::class,'tr'],['task','status','product','duedate','event']),$rows,self::tr('tasks')):self::note(self::tr('empty_tasks')));
            }
            $out.=self::pager($company,$m,['deviation'=>$r->uuid,'tab'=>'tasks','legacypage'=>$m['legacy_page']??0]);
            if(!empty($m['legacy_total'])){$out.=self::note(self::tr('legacy_tasks')).self::pager($company,['total'=>$m['legacy_total'],'page'=>$m['legacy_page'],'pagesize'=>25],['deviation'=>$r->uuid,'tab'=>'tasks','page'=>$m['page']],'legacypage');}
        }else if($tab==='checks'){
            $rows=[];foreach($m['checks'] as $c){$rows[]=[s($c->observedon),s($manual?self::tr($c->inrange?'outcome_pass':'outcome_fail'):(string)$c->actual.' · '.self::tr($c->inrange?'outcome_pass':'outcome_fail')),nl2br(s($c->evidence))];}
            $out.=self::panel(self::tr('checks'),self::note(self::tr('check_hint')).($rows?self::table(array_map([self::class,'tr'],['observedon','outcome','evidence']),$rows,self::tr('checks')):self::note(self::tr('empty_checks')))).self::pager($company,$m,['deviation'=>$r->uuid,'tab'=>'checks']);
        }else{
            $rows=[];foreach($m['history'] as $e){$op=substr($e['action'],3);$known=in_array($op,['report','editreport','confirm','reject','analyze','link','unlink','check','close','cancel','create'],true);$rows[]=[s(userdate($e['timecreated'])),s($e['actorname']??self::tr('author_unavailable')),s($known?self::tr($op):self::tr('event')),nl2br(s($e['reason']))];}
            $out.=self::panel(self::tr('history'),self::note(self::tr('history_help')).($rows?self::table(array_map([self::class,'tr'],['time','actor','event','reason']),$rows,self::tr('history')):self::note(self::tr('empty_history')))).self::pager($company,$m,['deviation'=>$r->uuid,'tab'=>'history']);
        }
        $out.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('technical'))).self::note('UUID: '.$r->uuid),['class'=>'bms-dvu-details']);
        return $out.self::limits();
    }
}
