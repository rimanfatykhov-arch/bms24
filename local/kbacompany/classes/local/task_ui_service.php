<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS CRUD presentation of canonical tasks. No persistence or new task ownership. */
class task_ui_service {
    public static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }
    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/tasks.php', ['uuid'=>$company] + $params);
    }
    public static function link($url, string $label, bool $primary = false): string {
        return \html_writer::link($url, s($label), ['class'=>'btn '.($primary?'btn-primary':'btn-outline-secondary')]);
    }
    public static function hidden(array $values): string {
        $html=''; foreach ($values as $name=>$value) { $html.=\html_writer::empty_tag('input', ['type'=>'hidden','name'=>$name,'value'=>$value]); } return $html;
    }
    public static function note(string $text): string { return \html_writer::tag('p', s($text), ['class'=>'bms-tui-note']); }
    public static function badge(string $status): string {
        if (!in_array($status, task_service::STATUSES, true)) { return s(self::tr('tui_not_set')); }
        return \html_writer::tag('span',s(self::tr('taskcenter_status_'.$status)),['class'=>'bms-tui-badge bms-tui-state-'.$status]);
    }
    public static function panel(string $title, string $body, string $actions = ''): string {
        return \html_writer::tag('section', \html_writer::div(\html_writer::tag('h2',s($title)).$actions,'bms-tui-panel-head').$body,
            ['class'=>'bms-crm-section bms-tui-panel']);
    }
    public static function table(array $headers, array $rows, string $label): string {
        $table=new \html_table(); $table->head=$headers; $table->data=$rows; $table->attributes['class']='table bms-tui-table';
        return \html_writer::div(\html_writer::table($table),'bms-tui-table-wrap',['role'=>'region','aria-label'=>$label,'tabindex'=>'0']);
    }
    public static function control(string $name, array $spec, $value = '', string $error = ''): string {
        $id='tui_'.preg_replace('/[^a-z0-9_]/i','_',$name); $type=$spec['type']??'text';
        $attrs=['id'=>$id,'name'=>$name,'class'=>'form-control'.($error!==''?' is-invalid':'')];
        foreach (['maxlength','min','max','step','accept'] as $key) { if(isset($spec[$key])) { $attrs[$key]=$spec[$key]; } }
        if (!empty($spec['required'])) { $attrs['required']='required'; }
        $descs=[]; if(!empty($spec['help'])){$descs[]=$id.'_help';}
        if($error!==''){$attrs['aria-invalid']='true';$descs[]=$id.'_error';}
        if($descs){$attrs['aria-describedby']=implode(' ',$descs);}
        if(isset($spec['options'])){$input=\html_writer::select(array_map('s',$spec['options']),$name,$value,false,$attrs);}
        else if($type==='textarea'){$input=\html_writer::tag('textarea',s((string)$value),$attrs+['rows'=>$spec['rows']??3]);}
        else if($type==='file'){$input=\html_writer::empty_tag('input',$attrs+['type'=>'file']);}
        else{$input=\html_writer::empty_tag('input',$attrs+['type'=>$type,'value'=>$value]);}
        $label=self::tr($spec['label']).(!empty($spec['required'])?' *':'');
        $html=\html_writer::label(s($label),$id).$input;
        if(!empty($spec['help'])){$html.=\html_writer::tag('small',s(self::tr($spec['help'])),['id'=>$id.'_help','class'=>'bms-tui-note']);}
        if($error!==''){$html.=\html_writer::tag('div',s($error),['id'=>$id.'_error','class'=>'invalid-feedback d-block']);}
        return \html_writer::div($html,'bms-tui-control'.(!empty($spec['wide'])?' bms-tui-wide':''));
    }
    private static function battle_plan_control(array $plans, string $value = '', string $error = ''): string {
        $id = 'tui_battleplanuuid';
        $attrs = ['id'=>$id, 'name'=>'battleplanuuid', 'class'=>'form-control'.($error!==''?' is-invalid':'')];
        $descs = [$id.'_help'];
        if ($error !== '') { $attrs['aria-invalid']='true'; $descs[]=$id.'_error'; }
        $attrs['aria-describedby'] = implode(' ', $descs);
        $options = \html_writer::tag('option', s(self::tr('taskcenter_create_no_battleplan')), ['value'=>'']);
        $groups = [];
        foreach ($plans as $plan) {
            $ownerid = (int)($plan['user_id'] ?? 0);
            if (!isset($groups[$ownerid])) {
                $groups[$ownerid] = [
                    'label'=>(string)($plan['employee_name'] ?? $plan['label'] ?? $ownerid),
                    'items'=>[],
                ];
            }
            $period = (string)($plan['period_label'] ?? (($plan['period_start'] ?? '').' — '.($plan['period_end'] ?? '')));
            $position = trim((string)($plan['position_name'] ?? ''));
            $text = ($position !== '' ? $position.' · ' : '').$period;
            $optionattrs = ['value'=>(string)$plan['uuid']];
            if ((string)$plan['uuid'] === $value) { $optionattrs['selected']='selected'; }
            $groups[$ownerid]['items'][] = \html_writer::tag('option', s($text), $optionattrs);
        }
        foreach ($groups as $group) {
            $options .= \html_writer::tag('optgroup', implode('', $group['items']), ['label'=>(string)$group['label']]);
        }
        $html = \html_writer::label(s(self::tr('taskcenter_create_battleplan')), $id).\html_writer::tag('select', $options, $attrs);
        $html .= \html_writer::tag('small', s(self::tr('taskcenter_create_battleplan_hint')), ['id'=>$id.'_help','class'=>'bms-tui-note']);
        if ($error !== '') { $html .= \html_writer::tag('div', s($error), ['id'=>$id.'_error','class'=>'invalid-feedback d-block']); }
        return \html_writer::div($html, 'bms-tui-control');
    }

    private static function field(string $label, string $value, bool $html = false): string {
        return \html_writer::tag('dl',\html_writer::tag('dt',s(self::tr($label))).\html_writer::tag('dd',$html?$value:s($value!==''?$value:self::tr('tui_not_set'))),['class'=>'bms-tui-field']);
    }
    public static function menu(array $items, string $label = 'tui_actions'): string {
        if(!$items){return '';}
        $html=''; foreach($items as $item){$html.=\html_writer::link($item['url'],s($item['label']));}
        return \html_writer::tag('details',\html_writer::tag('summary',s(self::tr($label)),['class'=>'btn btn-outline-secondary']).
            \html_writer::div($html,'bms-tui-menu-items'),['class'=>'bms-tui-menu']);
    }
    public static function tools(string $company, bool $manager): string {
        $items=[['url'=>self::url($company,['view'=>'sources']),'label'=>self::tr('tui_source_tasks')],
            ['url'=>new \moodle_url('/local/kbacompany/recurring_task_library.php',['uuid'=>$company]),'label'=>self::tr('recurringtask_library_title')],
            ['url'=>new \moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$company]),'label'=>self::tr('battleplan_title')],
            ['url'=>new \moodle_url('/local/kbacompany/communication_center.php',['uuid'=>$company]),'label'=>self::tr('communicationcenter_title')]];
        if($manager){$items[]=['url'=>self::url($company,['view'=>'sourceconfig']),'label'=>self::tr('tui_source_settings')];}
        return self::menu($items,'tui_tools');
    }
    /** Link a task participant to the existing, ACL-guarded employee card. */
    private static function employee_link(string $company, int $userid, string $name): string {
        $name = trim($name);
        if ($userid <= 0 || $name === '') {
            return s($name !== '' ? $name : self::tr('tui_unavailable'));
        }
        return \html_writer::link(new \moodle_url('/local/kbacompany/employee_view.php',
            ['uuid'=>$company, 'userid'=>$userid]), s($name), ['class'=>'bms-tui-employee-link']);
    }
    public static function listing(string $company, array $model, array $filters, string $display): string {
        $actions=self::link(self::url($company,['view'=>'create']),self::tr('taskcenter_create'),true).self::tools($company,$model['can_manage']);
        $html=bms_workspace_ux_service::record_header(self::tr('taskcenter_title'),self::tr('tui_intro'),'','',$actions);
        $tabs=[];foreach(['my','created','review','watching','overdue','all'] as $scope){
            if($scope==='all'&&!$model['can_manage']){continue;}
            $tabs[$scope]=['label'=>self::tr('taskcenter_scope_'.$scope),'url'=>self::url($company,array_merge($filters,['scope'=>$scope,'display'=>$display]))];
        }
        $html.=bms_workspace_ux_service::action_bar('',bms_workspace_ux_service::view_switcher($tabs,$filters['scope']));
        $states=[''=>self::tr('tui_all_states')];foreach(task_service::STATUSES as $v){if($v!=='deleted'){$states[$v]=self::tr('taskcenter_status_'.$v);}}
        $streams=[''=>self::tr('tui_all_streams')];foreach(task_service::STREAMS as $v){$streams[$v]=self::tr('taskcenter_stream_'.$v);}
        $sources=[''=>self::tr('tui_all_sources')];foreach(task_service::SOURCE_KINDS as $v){$sources[$v]=self::tr('taskcenter_source_'.$v);}
        $form=self::hidden(['uuid'=>$company,'scope'=>$filters['scope'],'display'=>$display]).
            self::control('q',['label'=>'tui_search','type'=>'search','maxlength'=>200],$filters['q']).
            self::control('status',['label'=>'taskcenter_status','options'=>$states],$filters['status']).
            self::control('stream',['label'=>'taskcenter_stream','options'=>$streams],$filters['stream']).
            self::control('sourcekind',['label'=>'taskcenter_source','options'=>$sources],$filters['sourcekind']).
            \html_writer::div(\html_writer::tag('button',self::tr('tui_apply'),['type'=>'submit','class'=>'btn btn-primary']).
                self::link(self::url($company,['scope'=>$filters['scope']]),self::tr('tui_reset')),'bms-tui-actions');
        $html.=\html_writer::tag('form',$form,['method'=>'get','action'=>self::url($company)->out(false),'class'=>'bms-tui-filters']);
        $views=[];foreach(['list','kanban','analytics'] as $mode){$views[$mode]=['label'=>self::tr('tui_view_'.$mode),'url'=>self::url($company,$filters+['display'=>$mode])];}
        $html.=bms_workspace_ux_service::action_bar(self::note(self::tr('tui_found',$model['total'])),bms_workspace_ux_service::view_switcher($views,$display));
        if(!$model['total']){return $html.self::panel(self::tr('tui_empty'),self::note(self::tr('tui_empty_help')).self::link(self::url($company,['view'=>'create']),self::tr('taskcenter_create'),true));}
        if($display==='analytics'){
            $cards='';foreach(['total','open','review','overdue','done'] as $k){$cards.=\html_writer::div(\html_writer::tag('strong',(string)$model['counts'][$k]).s(self::tr('tui_count_'.$k)),'bms-tui-stat');}
            return $html.\html_writer::div($cards,'bms-tui-stats').self::note(self::tr('tui_counts_help'));
        }
        $rows=[];$columns=[];foreach(task_service::STATUSES as $state){if($state!=='deleted'){$columns[$state]=[];}}
        foreach($model['tasks'] as $task){
            $title=\html_writer::link(self::url($company,['task'=>$task['uuid'],'scope'=>$filters['scope']]),s($task['title']));
            $due=s($task['due_date']?:self::tr('tui_no_date'));
            if($task['due_date']!==''&&$task['due_date']<gmdate('Y-m-d')&&!in_array($task['status'],['done','cancelled'],true)){$due.=' '.\html_writer::tag('span',self::tr('taskcenter_overdue'),['class'=>'bms-tui-overdue']);}
            $responsible=self::employee_link($company, (int)$task['assignee_id'], (string)$task['assignee_name']);
            if ((int)$task['effective_assignee_id'] !== (int)$task['assignee_id']) {
                $responsible=self::employee_link($company, (int)$task['effective_assignee_id'],
                    (string)$task['effective_assignee_name']).self::note(self::tr('coverage_task_original').': '.
                    (string)$task['assignee_name']);
            }
            $rows[]=[$title.self::note(\core_text::substr($task['product'],0,100)),$responsible,
                self::badge($task['status']),$due,s(self::tr('taskcenter_priority_'.$task['priority'])),s(self::tr('taskcenter_source_'.$task['source_kind']))];
            $columns[$task['status']][]=\html_writer::div($title.\html_writer::div($responsible,'bms-tui-note').$due,'bms-tui-board-card');
        }
        if($display==='kanban'){
            $board='';foreach($columns as $state=>$cards){$board.=\html_writer::tag('section',\html_writer::tag('h2',self::badge($state).' '.count($cards)).implode('',$cards),['class'=>'bms-tui-board-column']);}
            $html.=self::note(self::tr('tui_board_page')).\html_writer::div($board,'bms-tui-board',['role'=>'region','tabindex'=>'0','aria-label'=>self::tr('tui_view_kanban')]);
        }else{$html.=self::table(array_map([self::class,'tr'],['taskcenter_task','taskcenter_assignee','taskcenter_status','taskcenter_duedate','taskcenter_priority','taskcenter_source']),$rows,self::tr('taskcenter_title'));}
        $nav='';if($model['page']>0){$nav.=self::link(self::url($company,$filters+['display'=>$display,'page'=>$model['page']-1]),self::tr('tui_previous'));}
        $nav.=self::note(self::tr('tui_page',(object)['page'=>$model['page']+1,'pages'=>(int)ceil($model['total']/$model['pagesize'])]));
        if(($model['page']+1)*$model['pagesize']<$model['total']){$nav.=self::link(self::url($company,$filters+['display'=>$display,'page'=>$model['page']+1]),self::tr('tui_next'));}
        return $html.\html_writer::tag('nav',$nav,['class'=>'bms-tui-actions','aria-label'=>self::tr('tui_pages')]);
    }
    public static function form_values(?array $task, int $actor, array $employees): array {
        if(!$task){return ['title'=>'','description'=>'','product'=>'','assigneeid'=>isset($employees[$actor])?$actor:0,'reviewerid'=>0,'observerid'=>0,
            'priority'=>'normal','stream'=>'operate','startdate'=>'','starttime'=>'','duedate'=>'','duetime'=>'','planminutes'=>0,'relationref'=>'','battleplanuuid'=>'','revision'=>0];}
        $out=[];foreach(['title','description','product','priority','stream','revision'] as $k){$out[$k]=$task[$k];}
        foreach(['assigneeid'=>'assignee_id','reviewerid'=>'reviewer_id','observerid'=>'observer_id','startdate'=>'start_date','starttime'=>'start_time','duedate'=>'due_date','duetime'=>'due_time','planminutes'=>'plan_minutes'] as $k=>$v){$out[$k]=$task[$v];}
        $out['relationref']=$task['parent_uuid']!==''?'task|'.$task['parent_uuid'].'|0':($task['context_uuid']!==''?$task['context_kind'].'|'.$task['context_uuid'].'|'.$task['context_revision']:'');
        $out['battleplanuuid']='';
        $out['dutypositionuuid']=(string)($task['duty_position_uuid'] ?? '');
        return $out;
    }
    public static function edit_form(string $company, array $model, array $values, array $errors, string $requestkey): string {
        $task=$model['selected'];$editing=$task!==null;
        $title=self::tr($editing?'taskcenter_edit':'taskcenter_create');
        $html=bms_workspace_ux_service::record_header($title,self::tr('tui_form_help'));
        $people=[0=>self::tr('tui_choose_person')]+$model['employees'];$optional=[0=>self::tr('tui_not_set')]+$model['employees'];
        $canclassify = task_service::can_manage($company, (int)$GLOBALS['USER']->id);
        $dutyoptions = [];
        if ($canclassify) {
            $dutyoptions=task_service::duty_position_options($company, (int)$GLOBALS['USER']->id,
                (int)($values['assigneeid'] ?? 0));
            $currentposition=(string)($values['dutypositionuuid'] ?? '');
            if ($currentposition!=='' && !array_key_exists($currentposition,$dutyoptions)) {
                $dutyoptions[$currentposition]=self::tr('coverage_task_position_stale');
            }
        }
        $streams=[];foreach(task_service::STREAMS as $v){$streams[$v]=self::tr('taskcenter_stream_'.$v);}
        $priorities=[];foreach(task_service::PRIORITIES as $v){$priorities[$v]=self::tr('taskcenter_priority_'.$v);}
        $relations=[''=>self::tr('taskcenter_relation_none')];
        foreach($model['parent_candidates'] as $id=>$label){if(!$editing||$id!==$task['uuid']){$relations['task|'.$id.'|0']=self::tr('taskcenter_relation_task').': '.$label;}}
        foreach($model['management_contexts'] as $kind=>$items){foreach($items as $item){$relations[$kind.'|'.$item['uuid'].'|'.$item['revision']]=self::tr('adminscale_kind_'.$kind).': '.$item['code'].' — '.$item['name'];}}
        if($values['relationref']!==''&&!isset($relations[$values['relationref']])){$relations[$values['relationref']]=$editing?($task['context_label']?:self::tr('tui_relation_kept')):self::tr('tui_unavailable');}
        $groups=[
            'tui_group_content'=>[
                'title'=>['label'=>'taskcenter_task','required'=>true,'maxlength'=>255,'wide'=>true],
                'product'=>['label'=>'taskcenter_product','type'=>'textarea','required'=>true,'maxlength'=>12000,'help'=>'tui_product_help','wide'=>true],
                'description'=>['label'=>'taskcenter_description','type'=>'textarea','maxlength'=>12000,'wide'=>true]],
            'taskcenter_form_participants'=>[
                'assigneeid'=>['label'=>'taskcenter_assignee','options'=>$people,'required'=>true],
                'reviewerid'=>['label'=>'taskcenter_reviewer','options'=>$optional,'help'=>'tui_reviewer_help'],
                'observerid'=>['label'=>'taskcenter_observer','options'=>$optional,'help'=>'taskcenter_observer_hint']],
            'taskcenter_form_schedule'=>[
                'startdate'=>['label'=>'taskcenter_startdate','type'=>'date'],
                'starttime'=>['label'=>'taskcenter_starttime','type'=>'time'],
                'duedate'=>['label'=>'taskcenter_duedate','type'=>'date'],
                'duetime'=>['label'=>'taskcenter_duetime','type'=>'time'],
                'planminutes'=>['label'=>'taskcenter_planminutes','type'=>'number','min'=>0,'max'=>525600,'step'=>1],
                'priority'=>['label'=>'taskcenter_priority','options'=>$priorities],
                'stream'=>['label'=>'taskcenter_stream','options'=>$streams]]];
        if ($canclassify) {
            $groups['taskcenter_form_participants']['dutypositionuuid']=[
                'label'=>'coverage_task_responsibility', 'options'=>$dutyoptions,
                'help'=>'coverage_task_scope_hint', 'wide'=>true,
            ];
        }
        $form=self::hidden(['uuid'=>$company,'view'=>$editing?'edit':'create','task'=>$task['uuid']??'',
            'action'=>$editing?'save':'create','revision'=>$values['revision'],'sesskey'=>sesskey(),'requestkey'=>$requestkey]);
        foreach($groups as $heading=>$fields){$body='';foreach($fields as $name=>$spec){$control=self::control($name,$spec,$values[$name]??'',$errors[$name]??'');if(in_array($name,['title','product','description'],true)){$control=\local_kbacompany\local\bms_ai_workspace_service::field_control($control,$company,'task',$name,'tui_'.$name);}$body.=$control;}
            if ($heading === 'taskcenter_form_participants' && $canclassify) {
                $checked=!empty($values['dutyconfirmed'])?'checked':null;
                $required=!empty($values['dutypositionuuid']) && (!$editing ||
                    (string)$values['dutypositionuuid']!==(string)($task['duty_position_uuid']??'') ||
                    (int)$values['assigneeid']!==(int)$task['assignee_id']);
                $body.=\html_writer::div(\html_writer::label(
                    \html_writer::empty_tag('input',['type'=>'checkbox','name'=>'dutyconfirmed','value'=>1,
                        'checked'=>$checked,'required'=>$required?'required':null,'data-duty-confirm'=>'1']).
                    ' '.s(self::tr('coverage_task_explicit_confirm')), 'tui_dutyconfirm'),
                    'bms-tui-control bms-tui-wide');
                if (!empty($errors['dutyconfirmed'])) {
                    $body.=\html_writer::div(s($errors['dutyconfirmed']),'invalid-feedback d-block');
                }
                $body.=\html_writer::tag('p', s(self::tr('coverage_task_no_primary')),
                    ['class'=>'bms-tui-note','data-duty-no-positions'=>'1',
                     'hidden'=>count($dutyoptions)>1?'hidden':null]);
                $body.=\html_writer::tag('p','',['class'=>'bms-tui-note','data-duty-status'=>'1','role'=>'status','aria-live'=>'polite']);
            }
            $form.=self::panel(self::tr($heading),\html_writer::div($body,'bms-tui-grid'));}
        $materials='';
        if(!$editing){$materials.=self::battle_plan_control($model['creation_plans']??[],$values['battleplanuuid'],$errors['battleplanuuid']??'');}
        $materials.=self::control('attachment',['label'=>'taskcenter_attachment_optional','type'=>'file','help'=>'tui_upload_help'],'',$errors['attachment']??'');
        $form.=self::panel(self::tr('taskcenter_form_battleplan_materials'),\html_writer::div($materials,'bms-tui-grid'));
        $lookup=self::control('relationsearch',['label'=>'tui_relation_search','type'=>'search','maxlength'=>200]);
        $lookup.=\html_writer::tag('button',self::tr('tui_find_relation'),['type'=>'button','class'=>'btn btn-outline-primary','data-relation-button'=>'1']);
        $lookup.=\html_writer::tag('span','',['data-relation-status'=>'1','role'=>'status','aria-live'=>'polite']);
        $extra=\html_writer::div($lookup,'bms-tui-relation-search',['data-relation-url'=>self::url($company,['view'=>'relations','task'=>$task['uuid']??''])->out(false),
            'data-error'=>self::tr('tui_relation_error'),'data-found'=>self::tr('tui_relation_found'),'hidden'=>'hidden']);
        $extra.=self::control('relationref',['label'=>'taskcenter_relation','options'=>$relations,'help'=>'tui_relation_help'],$values['relationref'],$errors['relationref']??'');
        $form.=\html_writer::tag('details',\html_writer::tag('summary',self::tr('tui_advanced')).\html_writer::div($extra,'bms-tui-grid'),['class'=>'bms-tui-panel bms-tui-advanced','open'=>!empty($errors['relationref'])?'open':null]);
        $cancel=self::url($company,$editing?['task'=>$task['uuid']]:[]);
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('taskcenter_save_button'),['class'=>'btn btn-primary','type'=>'submit']).self::link($cancel,get_string('cancel')),'bms-tui-actions bms-tui-footer');
        $formattrs=['method'=>'post','enctype'=>'multipart/form-data',
            'action'=>self::url($company)->out(false),'class'=>'bms-tui-edit'];
        if ($canclassify) {
            $formattrs['data-duty-positions-url']=self::url($company,['view'=>'dutypositions'])->out(false);
            $formattrs['data-duty-options-error']=self::tr('coverage_task_options_error');
            $formattrs['data-duty-original']=$editing?(string)$task['duty_position_uuid']:'';
            $formattrs['data-assignee-original']=$editing?(string)$task['assignee_id']:'';
        }
        return $html.\html_writer::tag('form',$form,$formattrs);
    }
    public static function post_form(string $company, array $task, string $action, string $body, string $label, string $tab = 'overview', array $extra = []): string {
        $hidden=self::hidden(['uuid'=>$company,'task'=>$task['uuid'],'view'=>'detail','tab'=>$tab,'revision'=>$task['revision'],
            'action'=>$action,'sesskey'=>sesskey(),'requestkey'=>bin2hex(random_bytes(16))]+$extra);
        return \html_writer::tag('form',$hidden.$body.\html_writer::tag('button',s($label),['type'=>'submit','class'=>'btn btn-outline-primary']),
            ['method'=>'post','enctype'=>'multipart/form-data','action'=>self::url($company)->out(false),'class'=>'bms-tui-inline-form']);
    }
    public static function detail(string $company, array $model, string $tab, array $actions, array $values = []): string {
        $task=$model['selected'];$id=$task['uuid'];$menu=[];
        foreach(['start','submit','accept','return','cancel','observer','delete'] as $a){if(!empty($actions[$a])){$menu[]=['url'=>self::url($company,['task'=>$id,'view'=>'action','operation'=>$a]),'label'=>self::action_label($a,$task)];}}
        if(!empty($model['plans']['eligible'])){$menu[]=['url'=>self::url($company,['task'=>$id,'view'=>'action','operation'=>'addbattleplan']),'label'=>self::tr('taskcenter_add_to_battleplan')];}
        // Duty responsibility has a dedicated control beside the value, not a buried dropdown action.
        // Primary actions are visible; lifecycle transitions keep the canonical confirmation/POST route.
        $primary='';
        foreach(['start','submit','accept','return'] as $operation){
            if(!empty($actions[$operation])){
                $primary.=self::link(self::url($company,['task'=>$id,'view'=>'action','operation'=>$operation]),self::action_label($operation,$task),true);
            }
        }
        $buttons=$primary.(!empty($actions['save'])?self::link(self::url($company,['task'=>$id,'view'=>'edit']),self::tr('taskcenter_edit')):'').
            self::menu($menu).self::link(self::url($company),self::tr('tui_back_list'));
        $html=bms_workspace_ux_service::record_header(s($task['title']),s(self::tr('taskcenter_assignee').': '.($task['assignee_name']?:self::tr('tui_unavailable'))),'',self::badge($task['status']),$buttons);
        $nav=[];foreach(['overview','checklist','result','files','discussion','history'] as $t){$nav[$t]=['label'=>self::tr('tui_tab_'.$t),'url'=>self::url($company,['task'=>$id,'tab'=>$t])];}
        $html.=bms_workspace_ux_service::action_bar('',bms_workspace_ux_service::view_switcher($nav,$tab));
        if($tab==='overview'){
            $dutylabel = !empty($task['duty_position_uuid'])
                ? self::tr('coverage_task_position_duty') : self::tr('coverage_task_personal');
            if (!empty($actions['classify_duty'])) {
                $dutylabel = s($dutylabel).' '.self::link(self::url($company,
                    ['task'=>$id,'view'=>'action','operation'=>'classifyduty']), self::tr('coverage_task_edit_type'));
            } else {
                $dutylabel = s($dutylabel);
            }
            $summary=self::field('taskcenter_assignee',self::employee_link($company, (int)$task['assignee_id'], (string)$task['assignee_name']), true).
                self::field('coverage_task_responsibility',$dutylabel,true) .
                ((int)$task['effective_assignee_id'] !== (int)$task['assignee_id'] ?
                    self::field('coverage_task_current_executor',self::employee_link($company,
                        (int)$task['effective_assignee_id'],(string)$task['effective_assignee_name']),true) : '').
                self::field('taskcenter_duedate',trim((string)$task['due_date'].' '.(string)$task['due_time'])).
                self::field('taskcenter_priority',self::tr('taskcenter_priority_'.$task['priority'])).
                self::field('taskcenter_creator',$task['creator_name']);
            $summary=\html_writer::div($summary,'bms-task2-summary');
            $main=self::panel(self::tr('tui_group_content'),self::field('taskcenter_product',$task['product']).self::field('taskcenter_description',$task['description']));
            $details='';
            foreach(['reviewer_name'=>'taskcenter_reviewer','observer_name'=>'taskcenter_observer',
                'start_date'=>'taskcenter_startdate','start_time'=>'taskcenter_starttime','due_time'=>'taskcenter_duetime'] as $k=>$label){$details.=self::field($label,$task[$k]);}
            $details.=self::field('taskcenter_planminutes',(string)$task['plan_minutes']).self::field('taskcenter_factminutes',(string)$task['fact_minutes']).
                self::field('taskcenter_stream',self::tr('taskcenter_stream_'.$task['stream']));
            $main.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('tui_group_work'))).
                \html_writer::div($details,'bms-tui-grid'),['class'=>'bms-tui-panel bms-task2-details']);
            $relations=self::field('taskcenter_source',self::tr('taskcenter_source_'.$task['source_kind']));
            if($task['context_uuid']!==''){$relations.=self::field('taskcenter_relation',\html_writer::link(new \moodle_url('/local/kbacompany/administrative_scale.php',
                ['uuid'=>$company,'viewkind'=>$task['context_kind'],'detailuuid'=>$task['context_uuid']]),s($task['context_label']?:self::tr('tui_relation_kept'))).self::note(self::tr('tui_source_revision',$task['context_revision'])),true);}
            if(!empty($model['parent'])){$relations.=self::field('taskcenter_relation',\html_writer::link(self::url($company,['task'=>$model['parent']['uuid']]),s($model['parent']['title'])),true);}
            $plans='';foreach($model['plans']['included']??[] as $plan){$plans.=\html_writer::tag('p',\html_writer::link(new \moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$company,'plan'=>$plan['uuid']]),s($plan['label'])));}
            $relations.=self::field('battleplan_title',$plans?:self::note(self::tr('tui_plan_empty')),true);
            $main.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('taskcenter_form_relations'))).
                $relations.self::note(self::tr('tui_no_copies')),['class'=>'bms-tui-panel bms-task2-details']);
            $discussion=$model['discussion'];$messages='';
            if(!empty($discussion['restricted'])){$messages=self::note(self::tr('taskcenter_discussion_restricted'));}
            else{foreach(($discussion['messages']??[]) as $msg){if($msg['status']!=='active'){continue;}
                $messages.=\html_writer::tag('article',\html_writer::tag('strong',s($msg['author_name'])).
                self::note(userdate($msg['time_created'])).\html_writer::div(format_text($msg['body'],FORMAT_PLAIN),'bms-tui-prose'),['class'=>'bms-tui-message']);}}
            if(!$messages){$messages=self::note(self::tr('taskcenter_discussion_empty'));}
            if(!empty($discussion['can_post'])){$messages.=self::post_form($company,$task,'discussionpost',
                self::control('message',['label'=>'tui_message','type'=>'textarea','required'=>true,'maxlength'=>12000],$values['message']??''),
                self::tr('taskcenter_discussion_send'),'overview');}
            $messages.=self::link(self::url($company,['task'=>$id,'tab'=>'discussion']),self::tr('tui_tab_discussion'));
            $aside=self::panel(self::tr('taskcenter_discussion'),$messages);
            $html.=\html_writer::div($summary,'bms-task2-summary-wrap');
            $html.=\html_writer::div(\html_writer::div($main,'bms-task2-main').\html_writer::div($aside,'bms-task2-aside'),'bms-task2-layout');
        }else if($tab==='checklist'){
            $body='';foreach($model['checklist'] as $check){
                $label=($check['done']?'☑ ':'☐ ').$check['text'];
                if(!empty($check['can_toggle'])&&!in_array($task['status'],['done','cancelled'],true)){$body.=self::post_form($company,$task,'checkset','',$label,'checklist',['checkuuid'=>$check['uuid'],'done'=>$check['done']?0:1]);}
                else{$body.=\html_writer::tag('p',s($label));}
                if($check['assignee_name']!==''){$body.=self::note($check['assignee_name']);}
            }
            if(!$body){$body=self::note(self::tr('tui_check_empty'));}
            if(!empty($actions['checkadd'])){$body.=self::post_form($company,$task,'checkadd',self::control('itemtext',['label'=>'tui_check_item','required'=>true,'maxlength'=>1000],$values['itemtext']??'').
                self::control('checkassigneeid',['label'=>'taskcenter_assignee','options'=>[0=>self::tr('taskcenter_noassignee')]+$model['employees']],$values['checkassigneeid']??0),self::tr('taskcenter_addcheck'),'checklist');}
            $html.=self::panel(self::tr('taskcenter_checklist').' ('.count($model['checklist']).')',$body);
        }else if($tab==='result'){
            $html.=self::panel(self::tr('taskcenter_result'),self::field('taskcenter_result',$task['result']).self::field('taskcenter_factminutes',(string)$task['fact_minutes']).
                self::note(self::tr('tui_result_help')).self::file_list($company,$task,$model['result_attachments'],true));
            if(!empty($actions['submit'])){$html.=self::link(self::url($company,['task'=>$id,'view'=>'action','operation'=>'submit']),self::action_label('submit',$task),true);}
            if(!empty($actions['resultattachmentadd'])){$html.=self::panel(self::tr('taskcenter_resultattachments'),self::post_form($company,$task,'resultattachmentadd',
                self::control('resultattachment',['label'=>'taskcenter_resultattachments','type'=>'file','required'=>true]),self::tr('taskcenter_attachresultfile'),'result'));}
        }else if($tab==='files'){
            $body=self::file_list($company,$task,$model['attachments'],false);
            if(!empty($actions['attachmentadd'])){$body.=self::post_form($company,$task,'attachmentadd',self::control('attachment',['label'=>'taskcenter_attachments','type'=>'file','required'=>true]),self::tr('tui_upload'),'files');}
            $html.=self::panel(self::tr('taskcenter_attachments').' ('.count($model['attachments']).')',$body);
        }else if($tab==='discussion'){
            $d=$model['discussion'];$body='';
            if(!empty($d['restricted'])){$body=self::note(self::tr('taskcenter_discussion_restricted'));}
            else{foreach($d['messages'] as $msg){if($msg['status']!=='active'){continue;}$body.=\html_writer::tag('article',\html_writer::tag('strong',s($msg['author_name'])).
                self::note(userdate($msg['time_created'])).\html_writer::div(format_text($msg['body'],FORMAT_PLAIN),'bms-tui-prose'),['class'=>'bms-tui-message']);}}
            if(!$body){$body=self::note(self::tr('taskcenter_discussion_empty'));}
            if(!empty($d['can_post'])){$body.=self::post_form($company,$task,'discussionpost',self::control('message',['label'=>'tui_message','type'=>'textarea','required'=>true,'maxlength'=>12000],$values['message']??''),self::tr('taskcenter_discussion_send'),'discussion');}
            if(empty($d['restricted'])){
                $discussionparams=['uuid'=>$company,'tab'=>'discussions'];
                if(!empty($d['thread_uuid'])){$discussionparams['thread']=$d['thread_uuid'];}
                else{$discussionparams+=['objectkind'=>'task','objectuuid'=>(string)$task['uuid']];}
                $body.=self::link(new \moodle_url('/local/kbacompany/communication_center.php',$discussionparams),self::tr('taskcenter_discussion_open'));
            }
            $html.=self::panel(self::tr('taskcenter_discussion'),$body);
        }else{
            $body=self::note(self::tr('tui_history_help'));foreach($model['history'] as $entry){$key='taskcenter_action_'.$entry['action'];
                $label=get_string_manager()->string_exists($key,'local_kbacompany')?self::tr($key):self::tr('tui_history_event');
                $body.=\html_writer::tag('article',\html_writer::tag('strong',s($label)).self::note(($entry['actor_name']?:self::tr('tui_unavailable')).' · '.userdate($entry['time_created'])).
                    ($entry['new_status']!==''?self::badge($entry['new_status']):'').
                    (!empty($entry['details']['reason'])?self::field('tui_reason',(string)$entry['details']['reason']):'').
                    (!empty($entry['details']['result'])?self::field('taskcenter_result',(string)$entry['details']['result']):'').
                    (!empty($entry['details']['filename'])?self::note((string)$entry['details']['filename']):''),['class'=>'bms-tui-message']);}
            $html.=self::panel(self::tr('taskcenter_history'),$body);
            $html.=\html_writer::tag('details',\html_writer::tag('summary',self::tr('tui_technical')).self::note('UUID: '.$task['uuid'].' · '.self::tr('tui_revision').': '.$task['revision']),['class'=>'bms-tui-panel']);
        }
        return $html;
    }
    private static function file_list(string $company, array $task, array $files, bool $result): string {
        if(!$files){return self::note(self::tr($result?'taskcenter_noresultattachments':'tui_files_empty'));}
        $rows=[];foreach($files as $file){$buttons='';if(!empty($file['can_delete'])){$buttons=self::link(self::url($company,['task'=>$task['uuid'],'view'=>'action',
            'operation'=>$result?'resultattachmentdelete':'attachmentdelete','filename'=>$file['filename']]),get_string('delete'));}
            $rows[]=[\html_writer::link($file['url'],s($file['filename'])),s(display_size($file['filesize'])),$buttons];}
        return self::table([self::tr('tui_file'),self::tr('tui_size'),self::tr('tui_actions')],$rows,self::tr('taskcenter_attachments'));
    }
    public static function action_label(string $operation, array $task): string {
        if($operation==='submit'){return self::tr($task['reviewer_id']>0?'taskcenter_submit':'tui_complete');}
        $map=['start'=>'taskcenter_start','accept'=>'taskcenter_accept','return'=>'taskcenter_return','cancel'=>'taskcenter_cancel','delete'=>'taskcenter_delete',
            'observer'=>'tui_change_observer','classifyduty'=>'coverage_task_set_position','addbattleplan'=>'taskcenter_add_to_battleplan','attachmentdelete'=>'tui_delete_file','resultattachmentdelete'=>'tui_delete_file'];
        return self::tr($map[$operation]??'tui_actions');
    }
    public static function action_form(string $company, array $model, string $operation, array $values, array $errors, string $requestkey): string {
        $task=$model['selected'];$label=self::action_label($operation,$task);
        $html=bms_workspace_ux_service::record_header(s($label),s($task['title']),'',self::badge($task['status']));
        $form=self::hidden(['uuid'=>$company,'task'=>$task['uuid'],'view'=>'action','operation'=>$operation,'action'=>$operation,
            'revision'=>$values['revision']??$task['revision'],'sesskey'=>sesskey(),'requestkey'=>$requestkey]);
        if($operation==='submit'){
            $form.=self::control('result',['label'=>'taskcenter_result','type'=>'textarea','maxlength'=>12000,'required'=>true],$values['result']??$task['result'],$errors['result']??'').
                self::control('factminutes',['label'=>'taskcenter_factminutes','type'=>'number','min'=>0,'max'=>525600,'step'=>1],$values['factminutes']??$task['fact_minutes'],$errors['factminutes']??'').
                self::control('resultattachment',['label'=>'taskcenter_resultattachments','type'=>'file'],'',$errors['resultattachment']??'').self::note(self::tr($task['reviewer_id']>0?'tui_submit_help':'tui_complete_help'));
        }else if(in_array($operation,['return','cancel'],true)){$form.=self::control('reason',['label'=>'tui_reason','type'=>'textarea','maxlength'=>4000,'required'=>true],$values['reason']??'',$errors['reason']??'');}
        else if($operation==='observer'){$form.=self::control('observerid',['label'=>'taskcenter_observer','options'=>[0=>self::tr('tui_not_set')]+$model['employees']],$values['observerid']??$task['observer_id'],$errors['observerid']??'');}
        else if($operation==='classifyduty'){
            $options=task_service::duty_position_options($company, (int)$GLOBALS['USER']->id,
                (int)$task['assignee_id']);
            $form.=self::control('dutypositionuuid',['label'=>'coverage_task_position','options'=>$options,
                'help'=>'coverage_task_scope_hint'], $values['dutypositionuuid']??($task['duty_position_uuid']??''));
        }
        else if($operation==='addbattleplan'){$plans=[''=>self::tr('tui_choose_plan')];foreach($model['plans']['eligible']??[] as $p){$plans[$p['uuid']]=$p['label'];}$form.=self::control('plan',['label'=>'battleplan_title','options'=>$plans,'required'=>true],$values['plan']??'',$errors['plan']??'').self::note(self::tr('tui_no_copies'));}
        else if(in_array($operation,['attachmentdelete','resultattachmentdelete'],true)){$form.=self::hidden(['filename'=>$values['filename']??'']).self::note($values['filename']??'').self::note(self::tr('tui_delete_file_help'));}
        else if($operation==='delete'){$form.=self::note(self::tr('taskcenter_delete_note')).self::hidden(['confirmdelete'=>1]);}
        $form.=\html_writer::tag('label',\html_writer::empty_tag('input',['type'=>'checkbox','name'=>'confirmed','value'=>1,'required'=>'required']).' '.s(self::tr('tui_confirm')),['class'=>'bms-tui-confirm']);
        if(!empty($errors['confirmed'])){$form.=self::note($errors['confirmed']);}
        $form.=\html_writer::div(\html_writer::tag('button',s($label),['type'=>'submit','class'=>'btn '.(in_array($operation,['delete','cancel','attachmentdelete','resultattachmentdelete'],true)?'btn-danger':'btn-primary')]).
            self::link(self::url($company,['task'=>$task['uuid']]),get_string('cancel')),'bms-tui-actions');
        return $html.self::panel($label,\html_writer::tag('form',$form,['method'=>'post','enctype'=>'multipart/form-data','action'=>self::url($company)->out(false)]));
    }
}
