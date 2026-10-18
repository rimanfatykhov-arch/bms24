<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS CRUD presentation for the existing weekly plan. No persistence or permission grants. */
class battle_plan_ui_service {
    public static function tr(string $key, $a = null): string { return get_string($key,'local_kbacompany',$a); }
    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$company]+$params);
    }
    public static function link($url, string $label, bool $primary = false): string {
        return \html_writer::link($url,s($label),['class'=>'btn '.($primary?'btn-primary':'btn-outline-secondary')]);
    }
    public static function hidden(array $values): string {
        $html=''; foreach ($values as $name=>$value) { $html.=\html_writer::empty_tag('input',['type'=>'hidden','name'=>$name,'value'=>$value]); } return $html;
    }
    public static function note(string $text): string { return \html_writer::tag('p',s($text),['class'=>'bms-bpu-note']); }
    public static function badge(string $state): string {
        return \html_writer::tag('span',s(self::tr('battleplan_status_'.$state)),['class'=>'bms-bpu-badge bms-bpu-'.$state]);
    }
    public static function panel(string $title, string $body, string $actions=''): string {
        return \html_writer::tag('section',\html_writer::div(\html_writer::tag('h2',s($title),['class'=>'bms-crm-section-title']).$actions,'bms-bpu-panel-head').$body,['class'=>'bms-crm-section bms-bpu-panel']);
    }
    public static function table(array $headers, array $rows, string $label): string {
        $t=new \html_table(); $t->attributes['class']='table bms-bpu-table'; $t->head=$headers; $t->data=$rows;
        return \html_writer::div(\html_writer::table($t),'bms-bpu-table-wrap',['role'=>'region','aria-label'=>$label,'tabindex'=>'0']);
    }
    public static function control(string $name, array $spec, $value='', string $error=''): string {
        $id='bpu_'.preg_replace('/[^a-z0-9_]/i','_',$name).($spec['suffix']??'');
        $attrs=['id'=>$id,'name'=>$name,'class'=>'form-control'.($error!==''?' is-invalid':'')];
        if (!empty($spec['required'])) { $attrs['required']='required'; }
        if (!empty($spec['readonly'])) { $attrs['readonly']='readonly'; }
        foreach (['maxlength','min','max','step'] as $key) { if (isset($spec[$key])) { $attrs[$key]=$spec[$key]; } }
        $desc=[]; if (!empty($spec['help'])) { $desc[]=$id.'_help'; }
        if ($error!=='') { $attrs['aria-invalid']='true'; $desc[]=$id.'_error'; }
        if ($desc) { $attrs['aria-describedby']=implode(' ',$desc); }
        $type=$spec['type']??'text';
        if (isset($spec['options'])) { $input=\html_writer::select(array_map('s',$spec['options']),$name,$value,false,$attrs); }
        else if ($type==='textarea') { $input=\html_writer::tag('textarea',s((string)$value),$attrs+['rows'=>3]); }
        else { $input=\html_writer::empty_tag('input',$attrs+['type'=>$type,'value'=>$value]); }
        $html=\html_writer::label(self::tr($spec['label']).(!empty($spec['required'])?' *':''),$id).$input;
        if (!empty($spec['help'])) { $html.=\html_writer::tag('small',s(self::tr($spec['help'])),['id'=>$id.'_help','class'=>'bms-bpu-note']); }
        if ($error!=='') { $html.=\html_writer::tag('div',s($error),['id'=>$id.'_error','class'=>'invalid-feedback d-block']); }
        return \html_writer::div($html,'bms-bpu-control'.($type==='textarea'?' bms-bpu-wide':''));
    }
    private static function field(string $label, string $value): string {
        return \html_writer::div(\html_writer::tag('dt',s($label)).\html_writer::tag('dd',s($value!==''?$value:self::tr('bpu_not_set'))),'bms-bpu-field');
    }
    public static function tools(string $company, bool $manager, string $week=''): string {
        $items=['battle_plan_overview.php'=>'bpu_tool_overview','battle_plan_coordination.php'=>'bpu_tool_coordination'];
        if ($manager) { $items+=['battle_plan_coverage.php'=>'bpu_tool_coverage','battle_plan_readiness.php'=>'bpu_tool_readiness',
            'battle_plan_acceptance.php'=>'bpu_tool_acceptance','battle_plan_settings.php'=>'bpu_tool_settings']; }
        $html=''; foreach ($items as $route=>$key) {
            $params=['uuid'=>$company]; if ($week!=='' && $route!=='battle_plan_settings.php') { $params['week']=$week; }
            $html.=\html_writer::link(new \moodle_url('/local/kbacompany/'.$route,$params),s(self::tr($key)));
        }
        return \html_writer::tag('details',\html_writer::tag('summary',self::tr('bpu_tools'),['class'=>'btn btn-outline-secondary']).
            \html_writer::div($html,'bms-bpu-menu-items'),['class'=>'bms-bpu-menu']);
    }
    public static function listing(string $company, array $model, array $filters): string {
        $actions=self::link(self::url($company,['view'=>'create']),self::tr('bpu_create'),true).self::tools($company,$model['can_manage'],$filters['week']);
        $html=bms_workspace_ux_service::record_header(self::tr('bpu_list_title'),self::tr('bpu_list_intro'),'','',$actions);
        $views=[]; foreach (['all','my','review','watching'] as $scope) {
            $views[$scope]=['label'=>self::tr('bpu_scope_'.$scope),'url'=>self::url($company,array_merge($filters,['scope'=>$scope,'page'=>0]))];
        }
        $html.=bms_workspace_ux_service::action_bar('',bms_workspace_ux_service::view_switcher($views,$filters['scope']));
        $states=[''=>self::tr('bpu_all_states')]; foreach (battle_plan_service::STATES as $s) { $states[$s]=self::tr('battleplan_status_'.$s); }
        $form=self::hidden(['uuid'=>$company,'view'=>'list','scope'=>$filters['scope']]);
        if ($filters['user']) { $form.=self::hidden(['user'=>$filters['user']]); }
        $form.=self::control('q',['label'=>'bpu_search','type'=>'search','maxlength'=>200],$filters['q']).
            self::control('status',['label'=>'battleplan_status','options'=>$states],$filters['status']).
            self::control('week',['label'=>'battleplan_week','type'=>'date','help'=>'bpu_filter_week_help'],$filters['week']);
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('bpu_apply'),['type'=>'submit','class'=>'btn btn-primary']).
            self::link(self::url($company,['scope'=>$filters['scope']]),self::tr('bpu_reset')),'bms-bpu-actions');
        $html.=\html_writer::tag('form',$form,['method'=>'get','action'=>self::url($company)->out(false),'class'=>'bms-bpu-filters']);
        $html.=self::note(self::tr('bpu_list_scope_note'));
        if (!$model['total']) {
            return $html.\html_writer::div(\html_writer::tag('h2',self::tr('bpu_empty')).self::note(self::tr('bpu_empty_help')),'bms-crm-empty');
        }
        $rows=[]; foreach ($model['rows'] as $row) {
            $rows[]=[\html_writer::link(self::url($company,['plan'=>$row['uuid']]),s(battle_plan_service::period_label($row['period_start'],$row['period_end']))),
                s($row['employee']?:self::tr('bpu_unavailable')).self::note($row['position']?:self::tr('bpu_not_set')),
                s($row['reviewer']?:self::tr('bpu_unavailable')),self::badge($row['status']),s(userdate($row['updated']))];
        }
        $html.=self::note(self::tr('bpu_found',$model['total'])).self::table(array_map([self::class,'tr'],
            ['battleplan_period','battleplan_employee','battleplan_reviewer','battleplan_status','bpu_updated']),$rows,self::tr('bpu_list_title'));
        $pages=''; if ($model['page']>0) { $pages.=self::link(self::url($company,$filters+['page'=>$model['page']-1]),self::tr('bpu_previous')); }
        $pages.=self::note(self::tr('bpu_page',(object)['page'=>$model['page']+1,'pages'=>(int)ceil($model['total']/$model['pagesize'])]));
        if (($model['page']+1)*$model['pagesize']<$model['total']) { $pages.=self::link(self::url($company,$filters+['page'=>$model['page']+1]),self::tr('bpu_next')); }
        return $html.\html_writer::tag('nav',$pages,['class'=>'bms-bpu-actions','aria-label'=>self::tr('bpu_pages')]);
    }
    public static function create_form(string $company, array $model, array $values, array $errors): string {
        $html=bms_workspace_ux_service::record_header(self::tr('bpu_create'),self::tr('bpu_create_help'));
        $form=self::hidden(['uuid'=>$company,'view'=>'create']);
        $people=$model['can_manage'] ? [0=>self::tr('bpu_choose_person')]+$model['employees'] :
            [$model['target_user']=>$model['employees'][$model['target_user']]??self::tr('bpu_unavailable')];
        $form.=self::control('user',['label'=>'battleplan_employee','options'=>$people,'required'=>true],$model['target_user']);
        $form.=self::control('position',['label'=>'battleplan_position','options'=>[''=>self::tr('bpu_choose_position')]+$model['positions']],$model['position_uuid']);
        $form.=\html_writer::tag('button',self::tr('bpu_update_context'),['type'=>'submit','class'=>'btn btn-outline-secondary']);
        $html.=self::panel(self::tr('bpu_context'),\html_writer::tag('form',$form,['method'=>'get','class'=>'bms-bpu-grid']).self::note(self::tr('bpu_context_help')));
        if (!$model['position_uuid']) {
            if ($model['target_user'] && !$model['positions']) { $html.=self::note(self::tr('battleplan_noposition')); }
            return $html.self::link(self::url($company),self::tr('bpu_cancel'));
        }
        $fields=self::control('periodstart',['label'=>'battleplan_week','type'=>'date','required'=>true,'help'=>'bpu_week_help'],$values['periodstart']??gmdate('Y-m-d'),$errors['periodstart']??'');
        $fields.=self::control('reviewerid',['label'=>'battleplan_reviewer','options'=>[0=>self::tr('battleplan_selectreviewer')]+$model['employees'],'required'=>true,'help'=>'battleplan_selfreview_hint'],$values['reviewerid']??0,$errors['reviewerid']??'');
        $fields.=self::control('observerid',['label'=>'battleplan_observer','options'=>[0=>self::tr('battleplan_noobserver')]+$model['employees'],'help'=>'battleplan_observer_hint'],$values['observerid']??0,$errors['observerid']??'');
        $form=self::hidden(['uuid'=>$company,'action'=>'createplan','view'=>'create','sesskey'=>sesskey(),
            'userid'=>$model['target_user'],'positionuuid'=>$model['position_uuid']]);
        $form.=self::panel(self::tr('bpu_plan_settings'),\html_writer::div($fields,'bms-bpu-grid'));
        $form.=self::design_controls($model,$values,$errors);
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('bpu_save_draft'),['type'=>'submit','class'=>'btn btn-primary']).self::link(self::url($company),self::tr('bpu_cancel')),'bms-bpu-actions');
        return $html.\html_writer::tag('form',$form,['method'=>'post','enctype'=>'multipart/form-data','class'=>'bms-bpu-form']);
    }
    private static function design_controls(array $model, array $values, array $errors): string {
        $html=''; foreach (['plantemplateuuid'=>'template_options','normuuid'=>'norm_options'] as $name=>$key) {
            $options=[''=>self::tr($name==='normuuid'?'battleplan_no_norm':'battleplan_no_template')];
            foreach ($model[$key] as $row) { $options[$row['uuid']]=$row['title'].' · '.self::tr('battleplan_setup_revision_short',$row['revision']); }
            if (!empty($values[$name]) && !isset($options[$values[$name]])) { $options[$values[$name]]=self::tr('bpu_pinned_reference'); }
            $html.=self::control($name,['label'=>$name==='normuuid'?'battleplan_normset':'battleplan_template','options'=>$options],$values[$name]??'',$errors[$name]??'');
        }
        return \html_writer::tag('details',\html_writer::tag('summary',self::tr('bpu_design')).self::note(self::tr('bpu_design_help')).\html_writer::div($html,'bms-bpu-grid'),['class'=>'bms-bpu-advanced']);
    }
    public static function edit_form(string $company, array $data, array $values, array $errors): string {
        $p=$data['selected'];
        $html=bms_workspace_ux_service::record_header(self::tr('bpu_edit'),
            s(battle_plan_service::period_label($p['period_start'],$p['period_end'])),'',self::badge($p['status']));
        $html.=self::note(self::tr('bpu_edit_full_help'));

        $form=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'savesettings','view'=>'edit','sesskey'=>sesskey(),
            'expected'=>$values['expected']??$p['edit_token']]);
        $fields='';
        if ($p['status']==='draft') {
            $fields.=self::control('periodstart',['label'=>'battleplan_week','type'=>'date','required'=>true,'help'=>'bpu_period_help'],
                $values['periodstart']??$p['period_start'],$errors['periodstart']??'');
        } else {
            $fields.=self::control('periodlocked',['label'=>'battleplan_week','readonly'=>true],$p['period_start']);
        }

        $ownerlabel=($data['employees'][$p['user_id']]??self::tr('bpu_unavailable')).' · '.($data['positions'][$p['position_uuid']]??self::tr('bpu_unavailable'));
        if ($p['status']==='draft' && $data['can_manage'] && empty($data['items']) && !empty($data['owner_position_options'])) {
            $ownervalue=$values['ownerposition']??($p['user_id'].'::'.$p['position_uuid']);
            $fields.=self::control('ownerposition',['label'=>'bpu_owner_position','options'=>$data['owner_position_options'],
                'required'=>true,'help'=>'bpu_owner_position_help'],$ownervalue,$errors['ownerposition']??'');
        } else {
            $fields.=self::control('ownerpositionlocked',['label'=>'bpu_owner_position','readonly'=>true],$ownerlabel);
            if ($p['status']==='draft' && $data['can_manage'] && !empty($data['items'])) {
                $fields.=self::note(self::tr('bpu_owner_position_locked'));
            }
        }
        $fields.=self::control('reviewerid',['label'=>'battleplan_reviewer','options'=>[0=>self::tr('battleplan_selectreviewer')]+$data['employees'],
            'required'=>true,'help'=>'bpu_reviewer_help'],$values['reviewerid']??$p['reviewer_id'],$errors['reviewerid']??'');
        $fields.=self::control('observerid',['label'=>'battleplan_observer','options'=>[0=>self::tr('battleplan_noobserver')]+$data['employees'],
            'help'=>'battleplan_observer_hint'],$values['observerid']??$p['observer_id'],$errors['observerid']??'');
        $form.=self::panel(self::tr('bpu_plan_settings'),\html_writer::div($fields,'bms-bpu-grid'));
        if ($p['status']==='draft') {
            $form.=self::design_controls($data,$values+['plantemplateuuid'=>$p['template_uuid'],'normuuid'=>$p['norm_uuid']],$errors);
        }
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('bpu_save'),['type'=>'submit','class'=>'btn btn-primary']).
            self::link(self::url($company,['plan'=>$p['uuid']]),self::tr('bpu_cancel')),'bms-bpu-actions');
        $html.=\html_writer::tag('form',$form,['method'=>'post','enctype'=>'multipart/form-data','class'=>'bms-bpu-form']);

        $lifecycle=self::note(self::tr('bpu_lifecycle_help')).self::badge($p['status']);
        $lifeactions='';
        if ($data['selected_can_edit'] && $p['status']==='draft') {
            $lifeactions.=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'submit']),self::tr('battleplan_submit'),true);
            if ($p['user_id']===$p['reviewer_id'] && $p['user_id']===$data['actor']) {
                $lifeactions.=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'selfapprove']),self::tr('battleplan_selfapprove'));
            }
        } else if ($data['selected_can_edit'] && $p['status']==='active') {
            $lifeactions.=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'close']),self::tr('battleplan_close'),true);
        } else if ($p['status']==='submitted' && ($data['can_manage'] || $p['reviewer_id']===$data['actor'])) {
            $lifeactions.=self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'review']),self::tr('bpu_review'),true);
        }
        $html.=self::panel(self::tr('bpu_lifecycle'),$lifecycle,$lifeactions);

        $today=gmdate('Y-m-d'); $overdue=0;
        foreach ($data['items'] as $item) {
            $task=$item['task'];
            if (!empty($task['due_date']) && $task['due_date']<$today && !in_array($task['status'],['done','cancelled'],true)) { $overdue++; }
        }
        $stats='';
        foreach ([
            'bpu_tasks_total'=>count($data['items']),
            'bpu_tasks_done_short'=>$data['summary']['done'],
            'bpu_tasks_overdue'=>$overdue,
            'bpu_plan_minutes_total'=>$data['summary']['plan_minutes'],
        ] as $key=>$value) {
            $stats.=\html_writer::div(\html_writer::tag('strong',s((string)$value)).\html_writer::tag('span',self::tr($key)),'bms-bpu-stat');
        }
        $composition=self::note(self::tr('bpu_edit_composition_help')).\html_writer::div($stats,'bms-bpu-stats');
        $rows=[];
        foreach ($data['items'] as $item) {
            $t=$item['task'];
            $name=!empty($t['source_url'])?\html_writer::link($t['source_url'],s($t['title'])):s($t['title']);
            $actions='';
            if ($p['status']==='draft' && $data['selected_can_edit']) {
                $actions=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'remove','item'=>$item['uuid']]),self::tr('battleplan_remove'));
            }
            $rows[]=[$name,s(self::tr('taskcenter_stream_'.$item['stream'])),s(self::tr('taskcenter_status_'.$t['status'])),
                s($t['due_date']?:'—'),s((string)$t['plan_minutes']),$actions];
        }
        $composition.=$rows?self::table(array_map([self::class,'tr'],['taskcenter_task','taskcenter_stream','taskcenter_status','taskcenter_duedate','taskcenter_planminutes','bpu_actions']),$rows,self::tr('bpu_edit_composition')):
            self::note(self::tr('battleplan_streamempty'));

        if ($p['status']==='draft' && $data['selected_can_edit']) {
            $options=[''=>self::tr('bpu_choose_task')];
            foreach ($data['available_tasks'] as $t) {
                $options[$t['provider'].'::'.$t['external_id']]=($t['provider_label']??$t['provider']).' · '.$t['title'];
            }
            if (count($options)>1) {
                $addform=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'editaddprovider','view'=>'edit','sesskey'=>sesskey()]).
                    self::control('taskref',['label'=>'bpu_add_existing_task','options'=>$options,'required'=>true],$values['taskref']??'',$errors['taskref']??'').
                    \html_writer::tag('button',self::tr('battleplan_add'),['type'=>'submit','class'=>'btn btn-primary']);
                $composition.=\html_writer::tag('form',$addform,['method'=>'post','class'=>'bms-bpu-form bms-bpu-inline-add']);
            }

            $streams=[]; foreach(task_service::STREAMS as $stream) { $streams[$stream]=self::tr('taskcenter_stream_'.$stream); }
            $priorities=[]; foreach(task_service::PRIORITIES as $priority) { $priorities[$priority]=self::tr('taskcenter_priority_'.$priority); }
            $manual=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'editmanual','view'=>'edit','sesskey'=>sesskey(),
                'reviewerid'=>$p['reviewer_id']]);
            $manual.=self::control('title',['label'=>'taskcenter_task','maxlength'=>255,'required'=>true],$values['title']??'',$errors['title']??'').
                self::control('product',['label'=>'taskcenter_product','type'=>'textarea','required'=>true],$values['product']??'',$errors['product']??'').
                self::control('stream',['label'=>'taskcenter_stream','options'=>$streams],$values['stream']??'operate').
                self::control('priority',['label'=>'taskcenter_priority','options'=>$priorities],$values['priority']??'normal').
                self::control('startdate',['label'=>'taskcenter_startdate','type'=>'date'],$values['startdate']??$p['period_start'],$errors['startdate']??'').
                self::control('duedate',['label'=>'taskcenter_duedate','type'=>'date'],$values['duedate']??$p['period_end'],$errors['duedate']??'').
                self::control('planminutes',['label'=>'taskcenter_planminutes','type'=>'number','min'=>0,'step'=>1],$values['planminutes']??0,$errors['planminutes']??'').
                \html_writer::tag('button',self::tr('bpu_create_task'),['type'=>'submit','class'=>'btn btn-primary']);
            $composition.=\html_writer::tag('details',\html_writer::tag('summary',self::tr('bpu_create_task_for_plan')).
                \html_writer::tag('form',\html_writer::div($manual,'bms-bpu-grid'),['method'=>'post','enctype'=>'multipart/form-data','class'=>'bms-bpu-form']),
                ['class'=>'bms-bpu-advanced','open'=>!empty($errors['title'])||!empty($errors['product'])?'open':null]);
        }
        $composition.=\html_writer::div(self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'fill']),self::tr('bpu_manage_composition')),'bms-bpu-actions');
        $html.=self::panel(self::tr('bpu_edit_composition'),$composition);
        return $html;
    }
    public static function record_header(string $company, array $data, string $tab): string {
        $p=$data['selected']; $employee=$data['employees'][$p['user_id']]??self::tr('bpu_unavailable');
        $actions='';
        if ($data['selected_can_edit'] && $p['status']!=='closed') { $actions=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'edit']),self::tr('bpu_edit'),true); }
        $menu='';
        if ($data['selected_can_edit'] && $p['status']==='draft') {
            $menu.=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'view'=>'fill']),self::tr('bpu_fill'));
            foreach (['submit','selfapprove'] as $action) {
                if ($action==='selfapprove' && ($p['user_id']!==$p['reviewer_id'] || $p['user_id']!==$data['actor'])) { continue; }
                $menu.=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>$action]),self::tr('battleplan_'.$action));
            }
        }
        if ($data['selected_can_edit'] && $p['status']==='active') { $menu.=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'close']),self::tr('battleplan_close')); }
        if ($p['status']==='submitted' && ($data['can_manage'] || $p['reviewer_id']===$data['actor'])) {
            $menu.=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'review']),self::tr('bpu_review'));
        }
        $menu.=\html_writer::link(new \moodle_url('/local/kbacompany/battle_plan_export.php',['uuid'=>$company,'plan'=>$p['uuid']]),self::tr('battleplan_export_word'));
        $menu.=\html_writer::link(new \moodle_url('/local/kbacompany/battle_plan_coordination.php',['uuid'=>$company,'week'=>$p['period_start']]),self::tr('bpu_tool_coordination'));
        $actions.=\html_writer::tag('details',\html_writer::tag('summary',self::tr('bpu_actions'),['class'=>'btn btn-outline-secondary']).\html_writer::div($menu,'bms-bpu-menu-items'),['class'=>'bms-bpu-menu']);
        $html=self::link(self::url($company),self::tr('bpu_back_list')).bms_workspace_ux_service::record_header(
            s(self::tr('battleplan_plan').' · '.battle_plan_service::period_label($p['period_start'],$p['period_end'])),s($employee),'',self::badge($p['status']),$actions);
        $fields=self::field(self::tr('battleplan_position'),$data['positions'][$p['position_uuid']]??'').
            self::field(self::tr('battleplan_reviewer'),$data['employees'][$p['reviewer_id']]??self::tr('bpu_unavailable')).
            self::field(self::tr('battleplan_observer'),$p['observer_id']?($data['employees'][$p['observer_id']]??self::tr('bpu_unavailable')):self::tr('battleplan_noobserver'));
        $html.=\html_writer::tag('dl',$fields,['class'=>'bms-crm-detail-grid bms-bpu-people']);
        // No coverage UI is shown to ordinary plan readers. The model is manager-only.
        if (!empty($data['coverage_window'])) {
            $items = '';
            foreach ($data['coverage_window'] as $coverage) {
                $delegate = $data['employees'][$coverage['delegate_id']] ?? self::tr('bpu_unavailable');
                $period = $coverage['start_date'] . ' — ' . $coverage['end_date'];
                $label = $coverage['active_now'] ? self::tr('bpu_coverage_active') : self::tr('bpu_coverage_approved');
                $url = new \moodle_url('/local/kbacompany/position_coverage.php', [
                    'uuid' => $company, 'coverage' => $coverage['uuid']]);
                $items .= \html_writer::tag('li',
                    \html_writer::tag('strong', s($delegate)) . ' · ' . s($period) . ' · ' .
                    \html_writer::tag('span', s($label), ['class' => 'text-muted']) . ' · ' .
                    \html_writer::link($url, self::tr('bpu_coverage_open')));
            }
            $html .= \html_writer::div(
                \html_writer::tag('strong', self::tr('bpu_coverage_title')) .
                \html_writer::tag('ul', $items, ['class' => 'mb-2']) .
                \html_writer::tag('p', self::tr('bpu_coverage_readonly'), ['class' => 'text-muted mb-0']),
                'alert alert-info bms-bpu-coverage-summary');
        }

        $tabs=''; foreach (['overview','tasks','indicators','review','history'] as $key) {
            $attrs=['class'=>'bms-bpu-tab'.($key===$tab?' is-active':'')]; if ($key===$tab) { $attrs['aria-current']='page'; }
            $tabs.=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'tab'=>$key]),self::tr('bpu_tab_'.$key),$attrs);
        }
        return $html.\html_writer::tag('nav',$tabs,['class'=>'bms-bpu-tabs','aria-label'=>self::tr('bpu_record_tabs')]);
    }
    public static function overview(string $company, array $data): string {
        $p=$data['selected']; $summary=$data['summary']; $next=self::tr('bpu_next_'.$p['status']);
        if (!$data['selected_can_edit'] && $p['status']==='draft') { $next=self::tr('bpu_next_draft_reader'); }
        $html=\html_writer::div(\html_writer::tag('strong',self::tr('bpu_next_step')).self::note($next),'bms-crm-trace');
        if ($data['reference_count']>count($data['items'])) { $html.=\html_writer::div(self::tr('bpu_missing_items'),'alert alert-warning'); }

        $week=$data['week_workspace']??['goal'=>'','outcome'=>'','rollover'=>[]];
        $weekbody='';
        if ($data['selected_can_edit'] && $p['status']!=='closed') {
            $form=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'saveweek','view'=>'detail','tab'=>'overview',
                'sesskey'=>sesskey(),'expected'=>$p['edit_token']]);
            $form.=\html_writer::div(
                \local_kbacompany\local\bms_ai_workspace_service::field_control(
                    self::control('weekgoal',['label'=>'bpu_week_goal','type'=>'textarea','maxlength'=>4000,'help'=>'bpu_week_goal_help'],$week['goal']??''),
                    $company,'battleplan','weekgoal','bpu_weekgoal').
                \local_kbacompany\local\bms_ai_workspace_service::field_control(
                    self::control('weekoutcome',['label'=>'bpu_week_outcome','type'=>'textarea','maxlength'=>4000,'help'=>'bpu_week_outcome_help'],$week['outcome']??''),
                    $company,'battleplan','weekoutcome','bpu_weekoutcome'),
                'bms-bpu-grid');
            $form.=\html_writer::div(\html_writer::tag('button',self::tr('bpu_save_week_workspace'),['type'=>'submit','class'=>'btn btn-primary']),'bms-bpu-actions');
            $weekbody.=\html_writer::tag('form',$form,['method'=>'post','class'=>'bms-bpu-form']);
        } else {
            $weekbody.=self::field(self::tr('bpu_week_goal'),(string)($week['goal']??''));
            $weekbody.=self::field(self::tr('bpu_week_outcome'),(string)($week['outcome']??''));
            $weekbody=\html_writer::tag('dl',$weekbody,['class'=>'bms-crm-detail-grid']);
        }
        $html.=self::panel(self::tr('bpu_week_focus'),$weekbody);

        $meetings=$data['week_meetings']??['count'=>0,'minutes'=>0,'items'=>[],'details_visible'=>false];
        $load=$data['load_summary']??['task_minutes'=>0,'meeting_minutes'=>0,'total_minutes'=>0];
        $stats=[
            'bpu_tasks_count'=>(string)$summary['tasks'],
            'bpu_done_count'=>(string)$summary['done'],
            'bpu_task_load_hours'=>format_float(((int)$load['task_minutes'])/60,1),
            'bpu_meeting_load_hours'=>format_float(((int)$load['meeting_minutes'])/60,1),
            'bpu_total_load_hours'=>format_float(((int)$load['total_minutes'])/60,1),
        ];
        $cards=''; foreach ($stats as $label=>$value) {
            $cards.=\html_writer::div(\html_writer::tag('strong',s($value)).\html_writer::tag('span',self::tr($label)),'bms-bpu-stat');
        }
        $html.=self::panel(self::tr('bpu_week_load'),\html_writer::div($cards,'bms-bpu-stats bms-bpu-stats-five').self::note(self::tr('bpu_week_load_help')));

        $cap=$data['capacity_summary']??[];
        if ($cap) {
            $hours = static function($minutes) { return format_float(((int)$minutes) / 60, 1); };
            $values = [
                'bpu_capacity_week_norm' => $hours($cap['owner_capacity_minutes']),
                'bpu_capacity_all_tasks' => $hours($cap['owner_task_minutes']),
                'bpu_capacity_meetings' => $hours($cap['owner_meeting_minutes']),
                'bpu_capacity_remaining' => $hours($cap['owner_remaining_minutes']),
            ];
            $cards='';
            foreach ($values as $key=>$value) {
                $cards.=\html_writer::div(\html_writer::tag('strong',s($value)).\html_writer::tag('span',self::tr($key)),
                    'bms-bpu-stat');
            }
            $body=\html_writer::div($cards,'bms-bpu-stats').
                self::note(self::tr('bpu_capacity_percent',(object)['percent'=>(int)$cap['owner_utilisation'], 'hours'=>((int)$cap['owner_capacity_minutes']/60)])).
                self::note(self::tr('bpu_capacity_disclaimer'));
            if ((int)$cap['owner_remaining_minutes'] < 0) {
                $body.=\html_writer::div(self::tr('bpu_capacity_overload'), 'alert alert-warning');
            }
            if (!empty($data['can_manage'])) {
                $body.=\html_writer::link(new \moodle_url('/local/kbacompany/employee_work_schedule.php',
                    ['uuid'=>$data['company']['uuid'], 'userid'=>$data['target_user']]),
                    self::tr('bpu_capacity_edit_schedule'), ['class'=>'btn btn-outline-secondary mb-2']);
            }
            if (!empty($data['can_manage'])) {
                $teamcards='';
                foreach (['bpu_capacity_team_people'=>(string)$cap['headcount'],
                    'bpu_capacity_team_norm'=>$hours($cap['team_capacity_minutes']),
                    'bpu_capacity_team_tasks'=>$hours($cap['team_task_minutes']),
                    'bpu_capacity_team_remaining'=>$hours($cap['team_remaining_task_minutes'])] as $key=>$value) {
                    $teamcards.=\html_writer::div(\html_writer::tag('strong',s($value)).
                        \html_writer::tag('span',self::tr($key)),'bms-bpu-stat');
                }
                $body.=\html_writer::tag('h4',self::tr('bpu_capacity_team_heading')).
                    \html_writer::div($teamcards,'bms-bpu-stats').self::note(self::tr('bpu_capacity_team_note'));
            }
            $html.=self::panel(self::tr('bpu_capacity_heading'),$body);
        }

        $metriccounts=$data['metric_status_counts']??['ok'=>0,'risk'=>0,'critical'=>0,'nodata'=>0];
        $metriccards='';
        foreach (['ok'=>'metric_status_ok','risk'=>'metric_status_risk','critical'=>'metric_status_critical','nodata'=>'metric_status_nodata'] as $code=>$key) {
            $metriccards.=\html_writer::div(\html_writer::tag('strong',(string)($metriccounts[$code]??0)).\html_writer::tag('span',self::tr($key)),'bms-bpu-stat bms-bpu-metric-'.$code);
        }
        $html.=self::panel(self::tr('bpu_period_metrics'),\html_writer::div($metriccards,'bms-bpu-stats').self::note(self::tr('bpu_period_metrics_help')),
            self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'indicators']),self::tr('bpu_open_metrics')));

        $meetingbody=self::note(self::tr('bpu_meetings_summary',(object)['count'=>(int)$meetings['count'],'hours'=>format_float(((int)$meetings['minutes'])/60,1)]));
        if (!empty($meetings['details_visible']) && !empty($meetings['items'])) {
            foreach ($meetings['items'] as $meeting) {
                $meta=userdate((int)$meeting['starts_at'],get_string('strftimedatetimeshort'));
                if ((string)$meeting['location']!=='') { $meta.=' · '.(string)$meeting['location']; }
                $meetingbody.=\html_writer::tag('article',\html_writer::link($meeting['url'],s($meeting['title'])).self::note($meta),['class'=>'bms-bpu-meeting-item']);
            }
        } else if ((int)$meetings['count']>0 && empty($meetings['details_visible'])) {
            $meetingbody.=self::note(self::tr('bpu_meetings_private_note'));
        }
        $html.=self::panel(self::tr('bpu_week_meetings'),$meetingbody,
            self::link(new \moodle_url('/local/kbacompany/calendar.php',['uuid'=>$company]),self::tr('bpu_open_calendar')));

        $streams=''; foreach (task_service::STREAMS as $stream) {
            $count=count($data['by_stream'][$stream]??[]);
            $streams.=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'tasks'])->out(false).'#stream-'.$stream,
                \html_writer::tag('strong',(string)$count).\html_writer::tag('span',self::tr('taskcenter_stream_'.$stream)),['class'=>'bms-bpu-stat']);
        }
        $html.=self::panel(self::tr('bpu_composition'),\html_writer::div($streams,'bms-bpu-streams'),
            $p['status']==='draft' && $data['selected_can_edit']?self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'fill']),self::tr('bpu_fill')):'');

        $openitems=$data['rollover_items']??[];
        $rollover=$week['rollover']??[];
        $carried=array_flip(array_map('strval',(array)($rollover['itemuuids']??[])));
        $rollbody='';
        if (!empty($data['rollover_target'])) {
            $target=$data['rollover_target'];
            $rollbody.=\html_writer::div(self::tr('bpu_rollover_target',battle_plan_service::period_label($target['period_start'],$target['period_end'])).' '.
                \html_writer::link(self::url($company,['plan'=>$target['uuid']]),self::tr('bpu_open_next_plan')),'alert alert-success');
        }
        if ($p['status']==='active' && $data['selected_can_edit'] && $openitems) {
            $form=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'rolloveropen','view'=>'detail','tab'=>'overview','sesskey'=>sesskey(),'expected'=>$p['edit_token']]);
            $rows='';
            foreach ($openitems as $item) {
                $id='roll_'.preg_replace('/[^a-z0-9_]/i','_',$item['item_uuid']);
                $checked=isset($carried[$item['item_uuid']]);
                $attrs=['type'=>'checkbox','name'=>'rolloveritems[]','value'=>$item['item_uuid'],'id'=>$id];
                if ($checked) { $attrs['checked']='checked'; }
                $label=s($item['title']);
                $meta=self::tr('taskcenter_status_'.$item['status']);
                if ($item['due_date']!=='') { $meta.=' · '.self::tr('taskcenter_duedate').': '.$item['due_date']; }
                $rows.=\html_writer::tag('label',\html_writer::empty_tag('input',$attrs).\html_writer::tag('span',$label.\html_writer::tag('small',s($meta),['class'=>'d-block text-muted'])),['class'=>'bms-bpu-rollover-item','for'=>$id]);
            }
            $form.=$rows.self::note(self::tr('bpu_rollover_help')).\html_writer::tag('button',self::tr('bpu_rollover_selected'),['type'=>'submit','class'=>'btn btn-primary']);
            $rollbody.=\html_writer::tag('form',$form,['method'=>'post','class'=>'bms-bpu-form']);
        } else if (!$openitems) {
            $rollbody.=self::note(self::tr('bpu_rollover_none'));
        } else {
            $rollbody.=self::note(self::tr('bpu_rollover_readonly'));
        }
        if ($p['status']==='active' && $data['selected_can_edit']) {
            $remaining=0; foreach ($openitems as $item) { if (!isset($carried[$item['item_uuid']])) { $remaining++; } }
            $rollbody.=self::note($remaining===0?self::tr('bpu_close_ready'):self::tr('bpu_close_blocked',$remaining));
        }
        $html.=self::panel(self::tr('bpu_rollover_title'),$rollbody);

        $refs=''; foreach (['template','norm'] as $kind) {
            if ($p[$kind.'_uuid']==='') { continue; }
            $row=$kind==='template'?battle_plan_template_service::exact_template($company,$p[$kind.'_uuid'],$p[$kind.'_revision']):battle_plan_template_service::exact_norm($company,$p[$kind.'_uuid'],$p[$kind.'_revision']);
            $refs.=self::field(self::tr($kind==='template'?'battleplan_template':'battleplan_normset'),($row?$row['title']:self::tr('bpu_unavailable')).' · '.self::tr('battleplan_setup_revision_short',$p[$kind.'_revision']));
        }
        if ($refs!=='') { $html.=self::panel(self::tr('bpu_design'),\html_writer::tag('dl',$refs,['class'=>'bms-crm-detail-grid'])); }
        return $html;
    }
    public static function tasks(string $company, array $data): string {
        $p=$data['selected']; $html=self::note(self::tr('bpu_tasks_help'));
        if ($p['status']==='draft' && $data['selected_can_edit']) {
            $html.=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'fill']),self::tr('bpu_fill'),true);
        } else if ($p['status']==='active' && $data['selected_can_edit']) {
            $html.=self::note(self::tr('bpu_active_task_add_help'));
        }
        if ($p['status']==='draft' && $data['selected_can_edit']) {
            $sync = self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],
                'action'=>'syncrecurring','tab'=>'tasks','sesskey'=>sesskey()]);
            $sync .= \html_writer::tag('button',self::tr('recurringtask_sync_week'),
                ['type'=>'submit','class'=>'btn btn-outline-primary']);
            $html .= \html_writer::tag('form',$sync,['method'=>'post','class'=>'my-3']);
            $html .= self::note(self::tr('recurringtask_sync_help'));
        }

        foreach (task_service::STREAMS as $stream) {
            $items=$data['by_stream'][$stream]??[]; $rows=[];
            foreach ($items as $item) {
                $t=$item['task']; $name=!empty($t['source_url'])?\html_writer::link($t['source_url'],s($t['title'])):s($t['title']);
                $source=trim(($t['source_label']??'').(!empty($t['context_label'])?' · '.$t['context_label']:''));
                if ($source!=='') { $name.=self::note($source); }
                // A post-approval addition remains visibly distinct from the approved baseline.
                if ($p['status']==='active' && (int)$p['approved_at'] > 0 &&
                        !empty($item['time_created']) && (int)$item['time_created'] > (int)$p['approved_at']) {
                    $name.=self::note(self::tr('bpu_added_after_approval'));
                }
                $actions=''; if ($p['status']==='draft' && $data['selected_can_edit']) {
                    $actions=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'remove','item'=>$item['uuid']]),self::tr('battleplan_remove'));
                }
                $result=(string)($t['result']??'');
                $rows[]=[$name,s($t['product']),s(self::tr('taskcenter_status_'.$t['status'])),s($t['due_date']?:'—'),
                    s($t['plan_minutes'].' / '.$t['fact_minutes']),$result!==''?s($result):'—',$actions];
            }
            $title=self::tr('taskcenter_stream_'.$stream).' · '.count($items);
            $body=$rows?self::table(array_map([self::class,'tr'],['taskcenter_task','taskcenter_product','taskcenter_status','taskcenter_duedate','bpu_time_minutes','bpu_result','bpu_actions']),$rows,$title):self::note(self::tr('battleplan_streamempty'));
            $buttons='';
            if ($data['selected_can_edit'] && in_array($p['status'], ['draft', 'active'], true)) {
                $buttons=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'view'=>'fill',
                    'flow'=>'new','section'=>$stream]),s(self::tr('bpu_section_add_task')),
                    ['class'=>'btn btn-success btn-sm']);
                if ($stream==='run') {
                    // Creating a recurrence definition is a different CRUD action from a one-off task.
                    $buttons.=\html_writer::link(new \moodle_url('/local/kbacompany/recurring_tasks.php',[
                        'uuid'=>$company,'plan'=>$p['uuid'],'view'=>'create','stream'=>'run']),
                        s(self::tr('bpu_section_create_recurrence')),
                        ['class'=>'btn btn-outline-secondary btn-sm']);
                }
                $buttons=\html_writer::div($buttons,'bms-bpu-stream-head-actions');
            }
            $html.=\html_writer::div(self::panel($title,$body,$buttons),'',['id'=>'stream-'.$stream]);
        }
        return $html;
    }
    public static function review(string $company, array $data, array $values=[], array $errors=[]): string {
        $p=$data['selected']; $allowed=$p['status']==='submitted' && ($data['can_manage'] || $p['reviewer_id']===$data['actor']);
        $html=self::note(self::tr('bpu_review_help'));
        if (!$allowed) { return $html.self::note(self::tr('bpu_review_readonly')).self::review_history($data['review_history']); }
        $rows=[]; foreach ($data['items'] as $item) {
            $t=$item['task']; $id='review_'.$item['uuid'];
            $attrs=['type'=>'checkbox','name'=>'reviewitems[]','value'=>$item['uuid'],'id'=>$id];
            if (in_array($item['uuid'],(array)($values['reviewitems']??[]),true)) { $attrs['checked']='checked'; }
            $box=\html_writer::empty_tag('input',$attrs).\html_writer::label(self::tr('battleplan_review_fix').': '.$t['title'],$id,false,['class'=>'sr-only']);
            $rows[]=[$box,s($t['title']),s($t['product']),s($t['plan_minutes'].' / '.$t['fact_minutes']),s(self::tr('taskcenter_status_'.$t['status']))];
        }
        $form=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'reviewplan','view'=>'detail','tab'=>'review','sesskey'=>sesskey(),'expected'=>$values['expected']??$p['edit_token']]);
        $form.=self::table(array_map([self::class,'tr'],['battleplan_review_fix','taskcenter_task','taskcenter_product','bpu_time_minutes','taskcenter_status']),$rows,self::tr('bpu_review'));
        $form.=self::control('reviewcomment',['label'=>'battleplan_review_comment','type'=>'textarea','maxlength'=>4000,'help'=>'battleplan_review_comment_hint'],$values['reviewcomment']??'',$errors['reviewcomment']??'');
        $form.=self::confirmation($values['confirmed']??false);
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('battleplan_approve'),['type'=>'submit','name'=>'decision','value'=>'approve','class'=>'btn btn-primary']).
            \html_writer::tag('button',self::tr('battleplan_review_changes'),['type'=>'submit','name'=>'decision','value'=>'changes','class'=>'btn btn-outline-danger']),'bms-bpu-actions');
        return $html.\html_writer::tag('form',$form,['method'=>'post','enctype'=>'multipart/form-data','class'=>'bms-bpu-form']).self::review_history($data['review_history']);
    }
    private static function review_history(array $history): string {
        $html=''; foreach ($history as $row) {
            $text=self::tr($row['decision']==='approved'?'battleplan_review_approved':'battleplan_review_changes');
            $title=$row['target_label']?:self::tr('battleplan_review_plan_target');
            $html.=\html_writer::tag('article',\html_writer::tag('strong',s($text.' · '.$title)).self::note($row['actor_name'].' · '.userdate($row['time_created'])).
                \html_writer::div(nl2br(s($row['comment'])),'bms-bpu-content'),['class'=>'bms-bpu-history-item']);
        }
        return self::panel(self::tr('battleplan_review_history'),$html!==''?$html:self::note(self::tr('bpu_no_history')));
    }
    public static function history(array $data): string {
        $p=$data['selected']; $fields=''; foreach (['time_created'=>'bpu_created','submitted_at'=>'bpu_submitted','approved_at'=>'bpu_approved','closed_at'=>'bpu_closed'] as $field=>$label) {
            $fields.=self::field(self::tr($label),$p[$field]?userdate($p[$field]):'');
        }
        return self::panel(self::tr('bpu_state_milestones'),\html_writer::tag('dl',$fields,['class'=>'bms-crm-detail-grid']).self::note(self::tr('bpu_history_help'))).self::review_history($data['review_history']);
    }
    public static function confirmation(bool $checked=false): string {
        $attrs=['type'=>'checkbox','name'=>'confirmed','value'=>1,'required'=>'required']; if ($checked) { $attrs['checked']='checked'; }
        return \html_writer::tag('label',\html_writer::empty_tag('input',$attrs).' '.self::tr('bpu_confirm'),['class'=>'bms-bpu-confirm']);
    }
    public static function action(string $company, array $data, string $op, string $itemuuid='', array $values=[]): string {
        $p=$data['selected']; $title=self::tr('battleplan_'.$op); $itemlabel='';
        if ($op==='remove') { foreach ($data['items'] as $item) { if ($item['uuid']===$itemuuid) { $itemlabel=$item['task']['title']; } } }
        $html=bms_workspace_ux_service::record_header($title,s(battle_plan_service::period_label($p['period_start'],$p['period_end'])),'',self::badge($p['status']));
        $form=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>$op,'view'=>'action','sesskey'=>sesskey(),
            'expected'=>$values['expected']??$p['edit_token'],'itemuuid'=>$itemuuid]);
        $form.=self::note(self::tr('bpu_confirm_'.$op)); if ($itemlabel!=='') { $form.=\html_writer::tag('p',s($itemlabel),['class'=>'font-weight-bold']); }
        $form.=self::confirmation($values['confirmed']??false);
        $form.=\html_writer::div(\html_writer::tag('button',s($title),['type'=>'submit','class'=>'btn '.($op==='remove'?'btn-outline-danger':'btn-primary')]).
            self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>$op==='remove'?'tasks':'overview']),self::tr('bpu_cancel')),'bms-bpu-actions');
        return $html.self::panel(self::tr('bpu_action_confirmation'),\html_writer::tag('form',$form,['method'=>'post','enctype'=>'multipart/form-data','class'=>'bms-bpu-form']));
    }
    public static function fill(string $company, array $data, array $values=[], array $errors=[], string $current='', ?array $template=null, string $flow='new', string $section=''): string {
        $p=$data['selected'];
        // Keep validation errors in the originating form, even after a failed POST.
        if ($template || in_array($current,['manual','editmanual','addtemplate'],true)) { $flow='new'; }
        else if (in_array($current,['addprovider','addtask','addadmin','addwork'],true)) { $flow='existing'; }
        if (!in_array($flow,['new','existing'],true)) { $flow='new'; }
        if (!in_array($section, task_service::STREAMS, true)) { $section=''; }
        if ($p['status']==='active') { $flow='new'; }
        $period=battle_plan_service::period_label($p['period_start'],$p['period_end']);
        $assignee=(string)($data['employees'][(int)$p['user_id']] ?? self::tr('tui_unavailable'));
        $html=bms_workspace_ux_service::record_header(self::tr('bpu_fill'),self::tr('bpu_target_period',$period));
        $html.=\html_writer::div(
            \html_writer::div(
                \html_writer::tag('strong',s(self::tr('bpu_target_plan_label'))).' '.s($period).
                \html_writer::tag('span',s(self::tr('taskcenter_assignee').': '.$assignee),['class'=>'bms-bpu-context-person']),
                'bms-bpu-context-main').
            self::note(self::tr('bpu_target_plan_help')),
            'bms-bpu-context');
        $html.=self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'tasks']),self::tr('bpu_back_plan'));
        if (!$template && $p['status']==='draft') {
            $choices='';
            foreach (['new'=>'bpu_flow_new','existing'=>'bpu_flow_existing'] as $choice=>$label) {
                $choices.=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'view'=>'fill','flow'=>$choice]),
                    s(self::tr($label)),['class'=>'btn '.($flow===$choice?'btn-primary':'btn-outline-secondary'),
                    'aria-current'=>$flow===$choice?'page':null]);
            }
            $html.=\html_writer::tag('nav',$choices,['class'=>'bms-bpu-flow-switch','aria-label'=>self::tr('bpu_flow_choose')]);
        }
        $makeform=static function(string $action,string $body,string $button='battleplan_add') use($company,$p): string {
            $buttons=\html_writer::tag('button',self::tr($button),['type'=>'submit','class'=>'btn btn-primary']).
                self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'tasks']),self::tr('bpu_cancel'));
            return \html_writer::tag('form',self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>$action,'view'=>'fill','sesskey'=>sesskey()]).$body.
                \html_writer::div($buttons,'bms-bpu-crud-actions'),['method'=>'post','enctype'=>'multipart/form-data','class'=>'bms-bpu-form bms-bpu-crud-form']);
        };
        if (!$template && $flow==='existing') {
        // Preserve the active tab after GET search; otherwise the default new-task tab hides results.
        $search=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'view'=>'fill','flow'=>'existing']).self::control('taskq',['label'=>'bpu_task_search','type'=>'search','maxlength'=>200],$data['task_search']??'').
            \html_writer::tag('button',self::tr('bpu_apply'),['type'=>'submit','class'=>'btn btn-outline-secondary']);
        $body=\html_writer::tag('form',$search,['method'=>'get','class'=>'bms-bpu-grid']).self::note(self::tr('bpu_picker_limit'));
        // Accessible radio list: task metadata is visible before selection. The value stays
        // provider::externalid and is validated again by the canonical provider service.
        $picker='';
        $selectedref=$current==='addprovider'?(string)($values['taskref']??''):'';
        foreach ($data['available_tasks'] as $t) {
            $ref=(string)$t['provider'].'::'.(string)$t['external_id'];
            $status=(string)($t['status']??'');
            $statustext=in_array($status,task_service::STATUSES,true)
                ?self::tr('taskcenter_status_'.$status):$status;
            $meta=array_filter([
                (string)($t['provider_label']??$t['provider']),
                (string)($t['assignee_name']??$assignee),
                $statustext,
                !empty($t['due_date'])?self::tr('taskcenter_duedate').': '.(string)$t['due_date']:'',
            ],static function($value) { return trim((string)$value)!==''; });
            $radio=\html_writer::empty_tag('input',['type'=>'radio','name'=>'taskref','value'=>$ref,
                'required'=>'required','checked'=>$ref===$selectedref?'checked':null]);
            $picker.=\html_writer::tag('label',$radio.
                \html_writer::div(\html_writer::tag('strong',s((string)$t['title'])).
                    \html_writer::tag('small',s(implode(' · ',$meta))), 'bms-bpu-picker-text'),
                ['class'=>'bms-bpu-picker-item']);
        }
        $body.=$picker!==''?$makeform('addprovider',\html_writer::div($picker,'bms-bpu-picker'),
            'bpu_add_existing'):self::note(self::tr('bpu_no_candidates'));
        $html.=self::panel(self::tr('battleplan_existingtask'),$body);
        foreach (['addadmin'=>['administrative_tasks','battleplan_fromadmin'],'addwork'=>['position_work','battleplan_fromwork']] as $action=>$spec) {
            $options=[''=>self::tr('bpu_choose_source')]; foreach($data[$spec[0]] as $source) {
                $options[$source['uuid']]=$action==='addadmin'?$source['code'].' · '.$source['name']:$source['action'].' → '.$source['product'];
            }
            $body=count($options)>1?$makeform($action,self::control('sourceuuid',['label'=>$spec[1],'options'=>$options,'required'=>true,'suffix'=>$action],$current===$action?($values['sourceuuid']??''):'')):self::note(self::tr('bpu_no_sources'));
            $html.=\html_writer::tag('details',\html_writer::tag('summary',self::tr($spec[1])).$body,['class'=>'bms-bpu-advanced']);
        }
        $tplbody=self::link(new \moodle_url('/local/kbacompany/recurring_task_library.php',['uuid'=>$company,'plan'=>$p['uuid']]),self::tr('tasktemplate_open_library_for_battleplan'));
        if ($p['template_uuid']!=='') { $tplbody.=$makeform('applyplantemplate',self::note(self::tr('battleplan_apply_template_hint')).self::confirmation(),'battleplan_apply_template'); }
        $html.=self::panel(self::tr('bpu_templates'),$tplbody);
        }
        $action=$template?'addtemplate':'manual'; $v=$current===$action?$values:[];
        if ($p['status']==='active') {
            $html.=\html_writer::div(s(self::tr('bpu_active_add_notice')),
                'alert alert-info bms-bpu-active-create-notice');
        }
        if ($template || $flow==='new') {
            $essential='';
            if (!$template) {
                $titlecontrol=self::control('title',['label'=>'taskcenter_task','maxlength'=>255,'required'=>true],
                    $v['title']??'',$errors['title']??'');
                $essential.=bms_ai_workspace_service::field_control($titlecontrol,$company,'task','title','bpu_title');
            } else {
                $essential.=self::hidden(['template'=>$template['key']]).\html_writer::tag('p',s($template['title']));
            }
            $productcontrol=self::control('product',['label'=>'taskcenter_product','type'=>'textarea',
                'maxlength'=>12000,'required'=>true],$v['product']??'',$errors['product']??'');
            $essential.=bms_ai_workspace_service::field_control($productcontrol,$company,'task','product','bpu_product');
            // A battle plan belongs to exactly one employee. create_manual_task() sets this
            // assignee server-side; never offer another employee who cannot be linked to the plan.
            $essential.=\html_writer::div(
                \html_writer::tag('div',s(self::tr('taskcenter_assignee')),['class'=>'bms-bpu-static-label']).
                \html_writer::tag('div',s($assignee),['class'=>'bms-bpu-static-value']).
                \html_writer::tag('small',s(self::tr('bpu_assignee_locked_help')),['class'=>'bms-bpu-note']),
                'bms-bpu-control');
            if (!$template) {
                $essential.=self::control('duedate',['label'=>'taskcenter_duedate','type'=>'date',
                    'required'=>true],$v['duedate']??$p['period_end'],$errors['duedate']??'');
            }
            $priorities=[]; foreach(task_service::PRIORITIES as $priority) { $priorities[$priority]=self::tr('taskcenter_priority_'.$priority); }
            $streams=[]; foreach(task_service::STREAMS as $stream) { $streams[$stream]=self::tr('taskcenter_stream_'.$stream); }
            $people=[0=>self::tr('tui_not_set')]+($data['employees']??[]);
            $descriptioncontrol=self::control('description',['label'=>'taskcenter_description','type'=>'textarea',
                    'maxlength'=>12000],$v['description']??'');
            $details=bms_ai_workspace_service::field_control($descriptioncontrol,$company,'task','description','bpu_description').
                self::control('priority',['label'=>'taskcenter_priority','options'=>$priorities],$v['priority']??'normal').
                ($section===''?self::control('stream',['label'=>'taskcenter_stream','options'=>$streams],
                    $v['stream']??'operate'):'').
                self::control('reviewerid',['label'=>'taskcenter_reviewer','options'=>$people,'help'=>'tui_reviewer_help'],$v['reviewerid']??$p['reviewer_id']).
                self::control('observerid',['label'=>'taskcenter_observer','options'=>$people,'help'=>'taskcenter_observer_hint'],$v['observerid']??$p['observer_id']).
                self::control('startdate',['label'=>'taskcenter_startdate','type'=>'date'],$v['startdate']??$p['period_start'],$errors['startdate']??'').
                self::control('starttime',['label'=>'taskcenter_starttime','type'=>'time'],$v['starttime']??'',$errors['starttime']??'').
                ($template?self::control('duedate',['label'=>'taskcenter_duedate','type'=>'date'],
                    $v['duedate']??$p['period_end'],$errors['duedate']??''):'').
                self::control('duetime',['label'=>'taskcenter_duetime','type'=>'time'],$v['duetime']??'',$errors['duetime']??'').
                self::control('planminutes',['label'=>'taskcenter_planminutes','type'=>'number','min'=>0,'max'=>525600,'step'=>1],$v['planminutes']??0,$errors['planminutes']??'').
                self::control('attachment',['label'=>'taskcenter_attachment_optional','type'=>'file','help'=>'tui_upload_help'],'',$errors['attachment']??'');
            $advanced=\html_writer::tag('details',
                \html_writer::tag('summary',self::tr('bpu_flow_options')).
                \html_writer::div($details,'bms-bpu-crud-fields'),
                ['class'=>'bms-bpu-advanced','open'=>array_intersect(array_keys($errors),$template?['startdate','duedate','planminutes','attachment']:['startdate','planminutes','attachment'])?'open':null]);
            if ($section!=='') {
                $essential.=\html_writer::div(
                    \html_writer::tag('div',s(self::tr('taskcenter_stream')),['class'=>'bms-bpu-static-label']).
                    \html_writer::tag('div',s(self::tr('taskcenter_stream_'.$section)),['class'=>'bms-bpu-static-value']),
                    'bms-bpu-control');
            }
            $body=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>$action,'view'=>'fill','sesskey'=>sesskey()]);
            if ($section!=='') { $body.=self::hidden(['stream'=>$section,'section'=>$section]); }
            $body.=\html_writer::div($essential,'bms-bpu-crud-fields').$advanced;
            $buttons=\html_writer::tag('button',self::tr($template?'tasktemplate_confirm_add':'bpu_create_task'),
                ['type'=>'submit','class'=>'btn btn-primary']);
            $buttons.=self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'tasks']),self::tr('bpu_cancel'));
            $body.=\html_writer::div($buttons,'bms-bpu-crud-actions');
            $html.=self::panel($template?self::tr('tasktemplate_confirm_heading'):self::tr('bpu_flow_new'),
                \html_writer::tag('form',$body,['method'=>'post','enctype'=>'multipart/form-data',
                    'class'=>'bms-bpu-form bms-bpu-crud-form']));
        }
        return $html;
    }
}
