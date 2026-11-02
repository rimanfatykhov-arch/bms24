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
        $items=['battle_plan_week_coordination.php'=>'bpu_tool_weekcoord','battle_plan_overview.php'=>'bpu_tool_overview','battle_plan_coordination.php'=>'bpu_tool_coordination'];
        if ($manager) { $items+=['battle_plan_coverage.php'=>'bpu_tool_coverage','battle_plan_readiness.php'=>'bpu_tool_readiness',
            'battle_plan_acceptance.php'=>'bpu_tool_acceptance','battle_plan_settings.php'=>'bpu_tool_settings']; }
        $html=''; foreach ($items as $route=>$key) {
            $params=['uuid'=>$company]; if ($week!=='' && $route!=='battle_plan_settings.php') { $params['week']=$week; }
            $html.=\html_writer::link(new \moodle_url('/local/kbacompany/'.$route,$params),s(self::tr($key)));
        }
        return \html_writer::tag('details',\html_writer::tag('summary',self::tr('bpu_tools'),['class'=>'btn btn-outline-secondary']).
            \html_writer::div($html,'bms-bpu-menu-items'),['class'=>'bms-bpu-menu']);
    }
    /** Common local navigation shared by plan details, editing, task entry and confirmations. */
    public static function record_navigation(string $company, array $data, string $view, string $tab,
            array $queuefields = [], ?\moodle_url $queueurl = null): string {
        $projection = battle_plan_navigation_service::project($data, $view, $tab);
        $p = $data['selected'];
        $employee = (string)($data['employees'][$p['user_id']] ?? self::tr('bpu_unavailable'));
        $period = battle_plan_service::period_label($p['period_start'], $p['period_end']);
        $listurl = self::url($company, ['view'=>'list', 'week'=>$projection['week']]);
        $links = \html_writer::link($listurl, s(self::tr('bpu_back_list')),
            ['class'=>'bms-bpu-object-return']);
        if ($projection['canmanage']) {
            $overview = new \moodle_url('/local/kbacompany/battle_plan_overview.php',
                ['uuid'=>$company, 'week'=>$projection['week']]);
            $links .= \html_writer::link($overview, s(self::tr('bpu_tool_overview')),
                ['class'=>'bms-bpu-object-return']);
        }
        if ($queueurl !== null && !empty($queuefields['fromqueue'])) {
            // The controller constructs $queueurl from typed, whitelisted queue filters.
            $links .= \html_writer::link($queueurl, s(self::tr('bpu_approval_back_queue')),
                ['class'=>'bms-bpu-object-return bms-bpu-object-return-queue']);
        }
        $body = \html_writer::div($links, 'bms-bpu-object-return-links');
        $body .= \html_writer::div(
            \html_writer::tag('strong', s(self::tr('battleplan_plan') . ' · ' . $period)) .
            \html_writer::span(s($employee), 'bms-bpu-object-employee') .
            self::badge($projection['status']), 'bms-bpu-object-identity');
        $tabs = '';
        foreach ($projection['tabs'] as $item) {
            // Carry approved queue context across read-only tab navigation.
            $params = array_merge($item['params'], $queuefields);
            $tabs .= \html_writer::link(self::url($company, $params),
                s(self::tr('bpu_tab_' . $item['key'])),
                ['class'=>'bms-bpu-tab' . ($item['selected'] ? ' is-active' : ''),
                    'aria-current'=>$item['selected'] ? 'page' : null]);
        }
        $body .= \html_writer::tag('nav', $tabs, [
            'class'=>'bms-bpu-tabs bms-bpu-object-tabs',
            'aria-label'=>self::tr('bpu_record_tabs')]);
        if ($projection['modes']) {
            $modes = '';
            foreach ($projection['modes'] as $mode) {
                $modes .= \html_writer::link(self::url($company, array_merge($mode['params'], $queuefields)),
                    s(self::tr('bpu_nav_' . $mode['key'])),
                    ['class'=>'btn btn-sm ' . ($mode['selected'] ? 'btn-primary' : 'btn-outline-secondary'),
                        'aria-current'=>$mode['selected'] ? 'page' : null]);
            }
            $body .= \html_writer::div($modes, 'bms-bpu-object-mode-links');
        }
        if ($projection['mode'] !== '') {
            $body .= \html_writer::div(s(self::tr('bpu_nav_mode_' . $projection['mode'])),
                'bms-bpu-object-mode-label');
        }
        return \html_writer::tag('section', $body, [
            'class'=>'bms-bpu-object-nav', 'aria-label'=>self::tr('bpu_nav_context')]);
    }

    public static function listing(string $company, array $model, array $filters): string {
        $actions=self::link(self::url($company,['view'=>'create','week'=>$filters['week']]),self::tr('bpu_create'),true).self::tools($company,$model['can_manage'],$filters['week']);
        $html=bms_workspace_ux_service::record_header(self::tr('bpu_list_title'),self::tr('bpu_list_intro'),'','',$actions);
        $views=[]; foreach (['all','my','review','watching'] as $scope) {
            $views[$scope]=['label'=>self::tr('bpu_scope_'.$scope),'url'=>self::url($company,array_merge($filters,['scope'=>$scope,'page'=>0]))];
        }
        $html.=bms_workspace_ux_service::action_bar('',bms_workspace_ux_service::view_switcher($views,$filters['scope']));
        if (!empty($model['manager_dashboard'])) {
            $dash=$model['manager_dashboard'];
            $coverage=$dash['coverage']['summary'];
            $readiness=$dash['readiness']['summary'];
            $overview=$dash['overview']['summary'];
            $week=(string)$dash['week'];
            // Each summary card is now an action: managers can move from the count to the concrete people/plans.
            $stats=[
                ['label'=>'battleplan_coverage_missing_plans','value'=>(int)$coverage['missing_plans'],
                    'url'=>new \moodle_url('/local/kbacompany/battle_plan_coverage.php',
                        ['uuid'=>$company,'week'=>$week,'gap'=>'missing_plan'])],
                ['label'=>'battleplan_readiness_drafts','value'=>(int)$readiness['draft'],
                    'url'=>self::url($company,['status'=>'draft','week'=>$week])],
                ['label'=>'battleplan_readiness_submitted_count','value'=>(int)$readiness['submitted'],
                    'url'=>self::url($company,['status'=>'submitted','week'=>$week])],
                ['label'=>'battleplan_readiness_active_count','value'=>(int)$readiness['active'],
                    'url'=>self::url($company,['status'=>'active','week'=>$week])],
                ['label'=>'battleplan_overview_overdue_tasks','value'=>(int)$overview['overdue_tasks'],
                    'url'=>new \moodle_url('/local/kbacompany/battle_plan_overview.php',
                        ['uuid'=>$company,'week'=>$week,'attention'=>'overdue'])],
                ['label'=>'battleplan_overview_needs_decision','value'=>(int)$overview['needs_decision'],
                    'url'=>new \moodle_url('/local/kbacompany/battle_plan_overview.php',
                        ['uuid'=>$company,'week'=>$week,'attention'=>'needs_decision'])],
            ];
            $cards=''; foreach ($stats as $stat) {
                $content=\html_writer::tag('strong',(string)$stat['value']).
                    \html_writer::tag('span',self::tr($stat['label']));
                $cards.=\html_writer::link($stat['url'],$content,['class'=>'bms-bpu-stat bms-bpu-stat-link']);
            }

            // Compact drill-down: surface the concrete employees that require action without creating a second read model.
            $attentionrows=[]; $seen=[];
            foreach ($dash['coverage']['rows'] as $row) {
                if ((string)$row['type']!=='missing_plan') { continue; }
                $key='missing:'.(int)$row['employee_id'].':'.(string)$row['position']['uuid'];
                if (isset($seen[$key])) { continue; } $seen[$key]=true;
                $attentionrows[]=['employee'=>(string)$row['employee_name'],'position'=>(string)$row['position']['name'],
                    'reason'=>self::tr('battleplan_coverage_missing_plans'),'url'=>(string)$row['action_url']];
            }
            foreach ($dash['overview']['rows'] as $row) {
                if (!in_array((string)$row['attention'],['needs_decision','overdue'],true)) { continue; }
                $key='plan:'.(string)$row['plan_uuid']; if (isset($seen[$key])) { continue; } $seen[$key]=true;
                $reason=(string)$row['attention']==='needs_decision' ?
                    self::tr('battleplan_overview_needs_decision') : self::tr('battleplan_overview_overdue_tasks');
                $attentionrows[]=['employee'=>(string)$row['employee_name'],'position'=>(string)$row['position']['name'],
                    'reason'=>$reason,'url'=>(string)$row['url']];
            }
            foreach ($dash['readiness']['rows'] as $row) {
                if (!in_array((string)$row['status'],['draft','submitted'],true)) { continue; }
                $key='plan:'.(string)$row['uuid']; if (isset($seen[$key])) { continue; } $seen[$key]=true;
                $reason=self::tr((string)$row['status']==='draft' ? 'battleplan_readiness_drafts' :
                    'battleplan_readiness_submitted_count');
                $attentionrows[]=['employee'=>(string)$row['employee_name'],'position'=>(string)$row['position']['name'],
                    'reason'=>$reason,'url'=>(string)$row['url']];
            }
            $drilldown='';
            foreach (array_slice($attentionrows,0,8) as $row) {
                $who=\html_writer::tag('strong',s($row['employee'])).
                    \html_writer::tag('span',s($row['position']),['class'=>'bms-bpu-manager-meta']);
                $drilldown.=\html_writer::link($row['url'],
                    \html_writer::div($who,'bms-bpu-manager-person').
                    \html_writer::tag('span',s($row['reason']),['class'=>'bms-bpu-attention-reason']),
                    ['class'=>'bms-bpu-attention-row']);
            }
            if ($drilldown!=='') { $drilldown=\html_writer::div($drilldown,'bms-bpu-attention-list'); }

            $actions=\html_writer::link(new \moodle_url('/local/kbacompany/battle_plan_overview.php',
                    ['uuid'=>$company,'week'=>$week]),self::tr('bpu_tool_overview'),['class'=>'btn btn-outline-secondary']).
                \html_writer::link(new \moodle_url('/local/kbacompany/battle_plan_readiness.php',
                    ['uuid'=>$company,'week'=>$week]),self::tr('bpu_tool_readiness'),['class'=>'btn btn-outline-secondary']).
                \html_writer::link(new \moodle_url('/local/kbacompany/battle_plan_coverage.php',
                    ['uuid'=>$company,'week'=>$week]),self::tr('bpu_tool_coverage'),['class'=>'btn btn-outline-secondary']);
            $html.=self::panel(self::tr('battleplan_overview_title'),
                self::note(self::tr('battleplan_overview_intro')).
                \html_writer::div($cards,'bms-bpu-stats').$drilldown,
                \html_writer::div($actions,'bms-bpu-actions'));
        }
        $states=[''=>self::tr('bpu_all_states')]; foreach (battle_plan_service::STATES as $s) { $states[$s]=self::tr('battleplan_status_'.$s); }
        $form=self::hidden(['uuid'=>$company,'view'=>'list','scope'=>$filters['scope']]);
        $form.=self::control('q',['label'=>'bpu_search','type'=>'search','maxlength'=>200],$filters['q']).
            self::control('status',['label'=>'battleplan_status','options'=>$states],$filters['status']);
        $employeeoptions=$model['employee_options']??[];
        if (count($employeeoptions)>1 || !empty($filters['user'])) {
            $form.=self::control('user',['label'=>'battleplan_employee','options'=>[0=>get_string('all')]+$employeeoptions],$filters['user']);
        }
        $form.=self::control('week',['label'=>'battleplan_week','type'=>'date','help'=>'bpu_filter_week_help'],$filters['week']);
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('bpu_apply'),['type'=>'submit','class'=>'btn btn-primary']).
            self::link(self::url($company,['scope'=>$filters['scope']]),self::tr('bpu_reset')),'bms-bpu-actions');
        $html.=\html_writer::tag('form',$form,['method'=>'get','action'=>self::url($company)->out(false),'class'=>'bms-bpu-filters']);
        // Quick status navigation uses the canonical server-side Battle Plan filters.
        // Keep employee/week/search/scope filters, reset page and avoid introducing a second status model.
        $quickstatuses = ['' => self::tr('bpu_all_states')];
        foreach (battle_plan_service::STATES as $state) {
            $quickstatuses[$state] = self::tr('battleplan_status_'.$state);
        }
        $quicklinks = '';
        foreach ($quickstatuses as $state => $label) {
            $selected = (string)$filters['status'] === (string)$state;
            $params = $filters;
            $params['status'] = $state;
            $params['page'] = 0;
            $count = (int)($model['status_counts'][$state === '' ? 'all' : $state] ?? 0);
            $chiplabel = s($label) . \html_writer::tag('span', (string)$count, ['class'=>'bms-bpu-status-count', 'aria-hidden'=>'true']);
            $quicklinks .= \html_writer::link(self::url($company, $params), $chiplabel, [
                'class' => 'bms-bpu-status-chip'.($selected ? ' is-active' : ''),
                'aria-current' => $selected ? 'true' : 'false',
            ]);
        }
        $html .= \html_writer::tag('nav', $quicklinks, [
            'class' => 'bms-bpu-status-quickfilter',
            'aria-label' => self::tr('bpu_filter_status_quick'),
        ]);
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
        $contextual=!empty($model['contextual_create']);
        if ($contextual) {
            $userid=(int)$model['target_user'];
            $positionuuid=(string)$model['position_uuid'];
            $week=(string)($values['periodstart']??gmdate('Y-m-d'));
            $employee=(string)($model['employees'][$userid]??self::tr('bpu_unavailable'));
            $position=(string)($model['positions'][$positionuuid]??self::tr('bpu_not_set'));
            $weekend=(new \DateTimeImmutable($week,new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
            $summary=\html_writer::start_div('bms-bpu-context-summary');
            foreach ([
                self::tr('battleplan_employee')=>$employee,
                self::tr('battleplan_position')=>$position,
                self::tr('battleplan_week')=>battle_plan_service::period_label($week,$weekend),
            ] as $label=>$value) {
                $summary.=\html_writer::div(\html_writer::tag('strong',s($label)).\html_writer::div(s($value),'bms-bpu-context-value'),'bms-bpu-context-item');
            }
            $summary.=\html_writer::end_div();
            $change=self::link(self::url($company,['view'=>'create','user'=>$userid,'position'=>$positionuuid,'week'=>$week]),self::tr('bpu_change_context'));
            $html.=self::panel(self::tr('bpu_context'),$summary.\html_writer::div($change,'bms-bpu-actions mt-2'));
        } else {
            $form=self::hidden(['uuid'=>$company,'view'=>'create']);
            $people=$model['can_manage'] ? [0=>self::tr('bpu_choose_person')]+$model['employees'] :
                [$model['target_user']=>$model['employees'][$model['target_user']]??self::tr('bpu_unavailable')];
            $contextfields=bms24_ui_kit_service::fieldgrid([
                ['id'=>'bpu_user','name'=>'user','label'=>self::tr('battleplan_employee'),'type'=>'select',
                    'options'=>$people,'value'=>(string)$model['target_user'],'required'=>true],
                ['id'=>'bpu_position','name'=>'position','label'=>self::tr('battleplan_position'),'type'=>'select',
                    'options'=>[''=>self::tr('bpu_choose_position')]+$model['positions'],'value'=>(string)$model['position_uuid']],
                ['id'=>'bpu_context_week','name'=>'week','label'=>self::tr('battleplan_week'),'type'=>'date',
                    'required'=>true,'help'=>self::tr('bpu_week_help'),
                    'value'=>(string)($values['periodstart']??gmdate('Y-m-d'))],
            ]);
            $contextactions=\html_writer::div(
                \html_writer::tag('button',self::tr('bpu_update_context'),['type'=>'submit','class'=>'btn btn-primary']) .
                self::link(self::url($company),self::tr('bpu_cancel')),
                'bms24-filter-actions'
            );
            $form.=$contextfields.$contextactions;
            $contextform=\html_writer::tag('form',$form,['method'=>'get','class'=>'bms24-filter-panel']);
            $html.=self::panel(self::tr('bpu_context'),$contextform.\html_writer::div(self::tr('bpu_context_help'),'bms24-filter-note mt-2'));
        }
        if (!$model['position_uuid']) {
            if ($model['target_user'] && !$model['positions']) { $html.=self::note(self::tr('battleplan_noposition')); }
            return $html.self::link(self::url($company),self::tr('bpu_cancel'));
        }
        $continuity=self::continuity_notice($model,$values);
        if ($continuity!=='') { $html.=$continuity; }
        $fields=[];
        if (!$contextual) {
            $fields[]=['id'=>'bpu_periodstart','name'=>'periodstart','label'=>self::tr('battleplan_week'),'type'=>'date',
                'required'=>true,'help'=>self::tr('bpu_week_help'),'value'=>(string)($values['periodstart']??gmdate('Y-m-d')),
                'error'=>(string)($errors['periodstart']??'')];
        }
        $fields[]=['id'=>'bpu_reviewerid','name'=>'reviewerid','label'=>self::tr('battleplan_reviewer'),'type'=>'select',
                'options'=>[0=>self::tr('battleplan_selectreviewer')]+$model['employees'],'required'=>true,
                'help'=>self::tr('battleplan_selfreview_hint'),'value'=>(string)($values['reviewerid']??0),
                'error'=>(string)($errors['reviewerid']??'')];
        $fields[]=['id'=>'bpu_observerid','name'=>'observerid','label'=>self::tr('battleplan_observer'),'type'=>'select',
                'options'=>[0=>self::tr('battleplan_noobserver')]+$model['employees'],
                'help'=>self::tr('battleplan_observer_hint'),'value'=>(string)($values['observerid']??0),
                'error'=>(string)($errors['observerid']??'')];
        $fields=bms24_ui_kit_service::fieldgrid($fields);
        $hidden=['uuid'=>$company,'action'=>'createplan','view'=>'create','sesskey'=>sesskey(),
            'userid'=>$model['target_user'],'positionuuid'=>$model['position_uuid']];
        if ($contextual) { $hidden['contextual']=1; }
        if ($contextual) { $hidden['periodstart']=(string)($values['periodstart']??gmdate('Y-m-d')); }
        if (!empty($model['continuity']['source_uuid'])) {
            $hidden['fromplan']=(string)$model['continuity']['source_uuid'];
        }
        $form=self::hidden($hidden);
        $form.=self::panel(self::tr('bpu_plan_settings'),$fields);
        $form.=self::design_controls($model,$values,$errors);
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('bpu_save_draft'),['type'=>'submit','class'=>'btn btn-primary']).self::link(self::url($company),self::tr('bpu_cancel')),'bms-bpu-actions');
        return $html.\html_writer::tag('form',$form,['method'=>'post','enctype'=>'multipart/form-data','class'=>'bms-bpu-form']);
    }
    /** Explain next-week continuity without implying that weekly work/results were copied. */
    private static function continuity_notice(array $model, array $values): string {
        $c=(array)($model['continuity']??[]);
        $start=(string)($c['source_period_start']??'');
        $end=(string)($c['source_period_end']??'');
        if ($start==='' || $end==='') { return ''; }

        $parts=[];
        $reviewer=(int)($c['reviewerid']??0);
        if ($reviewer>0 && isset($model['employees'][$reviewer])) {
            $parts[]=self::tr('battleplan_reviewer').': '.$model['employees'][$reviewer];
        }
        $observer=(int)($c['observerid']??0);
        if ($observer>0 && isset($model['employees'][$observer])) {
            $parts[]=self::tr('battleplan_observer').': '.$model['employees'][$observer];
        }
        foreach (['plantemplateuuid'=>['template_options','battleplan_template'], 'normuuid'=>['norm_options','battleplan_normset']] as $field=>$spec) {
            $uuid=(string)($c[$field]??'');
            if ($uuid==='') { continue; }
            foreach ((array)($model[$spec[0]]??[]) as $row) {
                if ((string)($row['uuid']??'')===$uuid) {
                    $parts[]=self::tr($spec[1]).': '.(string)($row['title']??'');
                    break;
                }
            }
        }

        $source=battle_plan_service::period_label($start,$end);
        $body=\html_writer::tag('strong',self::tr('bpu_continuity_title'));
        $body.=self::note(self::tr('bpu_continuity_source',$source));
        if ($parts) { $body.=self::note(self::tr('bpu_continuity_inherited',implode(' · ',$parts))); }
        else { $body.=self::note(self::tr('bpu_continuity_none')); }
        $body.=self::note(self::tr('bpu_continuity_notcopied'));
        $body.=self::note(self::tr('bpu_continuity_review'));
        return \html_writer::div($body,'alert alert-info bms-bpu-continuity');
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
    /** Latest reviewer correction request, shown prominently while the plan is back in draft. */
    private static function correction_feedback(string $company, array $data): string {
        $p=$data['selected']??null; $history=(array)($data['review_history']??[]);
        if (!$p || (string)$p['status']!=='draft' || !$history) { return ''; }
        $latest=end($history);
        if (!$latest || (string)($latest['decision']??'')!=='changes') { return ''; }

        $time=(int)($latest['time_created']??0); $actor=(int)($latest['actor_id']??0);
        $comment=(string)($latest['comment']??''); $targets=[];
        foreach (array_reverse($history) as $row) {
            if ((string)($row['decision']??'')!=='changes' || (int)($row['time_created']??0)!==$time ||
                    (int)($row['actor_id']??0)!==$actor || (string)($row['comment']??'')!==$comment) { continue; }
            $label=trim((string)($row['target_label']??''));
            $itemuuid=(string)($row['item_uuid']??'');
            if ($label!=='' && $itemuuid!=='') { $targets[$itemuuid]=$label; }
        }

        $body=\html_writer::tag('strong',self::tr('battleplan_review_changes'));
        $meta=trim((string)($latest['actor_name']??''));
        if ($time>0) { $meta.=($meta!==''?' · ':'').userdate($time); }
        if ($meta!=='') { $body.=self::note($meta); }
        if ($comment!=='') { $body.=\html_writer::div(nl2br(s($comment)),'bms-bpu-content'); }
        if ($targets) {
            $items=''; foreach ($targets as $itemuuid=>$label) {
                $url=self::url($company,['plan'=>$p['uuid'],'tab'=>'tasks']);
                $url->set_anchor('bpu-review-item-'.preg_replace('/[^a-zA-Z0-9_-]/','',$itemuuid));
                $items.=\html_writer::tag('li',\html_writer::link($url,s($label)));
            }
            $body.=\html_writer::tag('div',\html_writer::tag('strong',self::tr('battleplan_review_fix')).
                \html_writer::tag('ul',$items,['class'=>'mb-0']),['class'=>'mt-2']);
        }

        // Per-target acknowledgement is separate from canonical plan readiness and review status.
        $payload=json_decode((string)($p['state_payload']??''),true) ?: [];
        $events=(array)($payload['review_correction_events']??[]);
        $batch=$time.':'.$actor.':'.$comment;
        $targets = $targets ?: [''=>self::tr('battleplan_review_plan_target')];
        $states=[];
        foreach ($events as $event) {
            if (($event['batch']??'')===$batch) { $states[(string)($event['target']??'')]=$event; }
        }
        // Summarise the latest acknowledgement for each current review target only.
        // This is advisory; canonical submit/review rules remain authoritative.
        $counts=['pending'=>0,'claimed'=>0,'accepted'=>0];
        foreach ($targets as $targetuuid=>$label) {
            $action=(string)($states[$targetuuid]['action']??'');
            $bucket=$action==='accepted'?'accepted':($action==='claimed'?'claimed':'pending');
            ++$counts[$bucket];
        }
        $total=count($targets);
        $summary=\html_writer::tag('strong',self::tr('bpu_fix_summary'));
        foreach (['pending','claimed','accepted'] as $key) {
            $summary.=\html_writer::tag('span',s(self::tr('bpu_fix_count_'.$key,$counts[$key])),
                ['class'=>'badge badge-light mr-2']);
        }
        $summary.=self::note($counts['accepted']===$total ?
            self::tr('bpu_fix_all_accepted') : self::tr('bpu_fix_not_all_accepted'));
        $summary.=self::note(self::tr('bpu_fix_advisory'));
        $body.=\html_writer::div($summary,'bms-bpu-correction-summary border rounded p-3 mt-3');
        $controls='';
        foreach ($targets as $targetuuid=>$label) {
            $event=$states[$targetuuid]??[];
            $status=(string)($event['action']??'');
            $statuslabel=$status==='accepted'?self::tr('bpu_fix_accepted'):
                ($status==='claimed'?self::tr('bpu_fix_claimed'):self::tr('bpu_fix_pending'));
            $content=\html_writer::tag('strong',s($label)).' · '.s($statuslabel);
            if (!empty($event['note'])) { $content.=self::note((string)$event['note']); }
            $canclaim=!empty($data['selected_can_edit']) && $status!=='claimed' && $status!=='accepted';
            $canreview=!empty($data['can_manage']) || (int)($p['reviewer_id']??0)===(int)($data['actor']??0);
            if ($canclaim || ($canreview && $status==='claimed')) {
                $form=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'correctionaction',
                    'tab'=>'review','sesskey'=>sesskey(),'expected'=>$p['edit_token'],
                    'correctiontarget'=>$targetuuid]);
                $form.=\html_writer::tag('label',s(self::tr('bpu_fix_note')),
                    ['for'=>'fix-note-'.md5($targetuuid)]);
                $form.=\html_writer::tag('textarea','',['name'=>'correctionnote','id'=>'fix-note-'.md5($targetuuid),
                    'maxlength'=>1500,'rows'=>2,'class'=>'form-control mb-2']);
                if ($canclaim) {
                    $form.=\html_writer::tag('button',s(self::tr('bpu_fix_claim_action')),
                        ['type'=>'submit','name'=>'correctionactiontype','value'=>'claimed','class'=>'btn btn-outline-primary btn-sm']);
                }
                if ($canreview && $status==='claimed') {
                    $form.=\html_writer::tag('button',s(self::tr('bpu_fix_accept_action')),
                        ['type'=>'submit','name'=>'correctionactiontype','value'=>'accepted','class'=>'btn btn-success btn-sm mr-2']);
                    $form.=\html_writer::tag('button',s(self::tr('bpu_fix_rework_action')),
                        ['type'=>'submit','name'=>'correctionactiontype','value'=>'rework','class'=>'btn btn-outline-danger btn-sm']);
                }
                $content.=\html_writer::tag('form',$form,['method'=>'post','class'=>'mt-2']);
            }
            $controls.=\html_writer::div($content,'border rounded p-2 mb-2');
        }
        $body.=\html_writer::div(\html_writer::tag('strong',self::tr('bpu_fix_title')).$controls,'mt-3');
        $actions='';
        if (!empty($data['selected_can_edit'])) {
            $actions.=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'edit']),self::tr('bpu_edit'),true);
            $actions.=self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'tasks']),self::tr('bpu_tab_tasks'));
        }
        $actions.=self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'review']),self::tr('battleplan_review_history'));
        if ($actions!=='') { $body.=\html_writer::div($actions,'bms-bpu-actions mt-2'); }
        return \html_writer::div($body,'alert alert-warning bms-bpu-correction-feedback');
    }

    /** Single-plan readiness projection. Reuses the canonical week-readiness lifecycle contract. */
    private static function readiness(array $data): array {
        $p=$data['selected'];
        $reviewerid=(int)($p['reviewer_id']??0);
        return battle_plan_week_readiness_service::classify(
            (string)$p['status'],
            (int)$p['user_id'],
            $reviewerid,
            $reviewerid>0 && isset($data['employees'][$reviewerid]),
            count((array)($data['items']??[]))
        );
    }

    /** Canonical close-readiness projection for one active plan.
     *
     * Closing is blocked only by unfinished items that are not explicitly carried to the next
     * draft plan, matching battle_plan_service::transition(..., 'close'). Outcome and metric
     * signals stay advisory and never become new lifecycle blockers here.
     */
    private static function close_readiness(array $data): array {
        $p=$data['selected'];
        if ((string)$p['status']!=='active') {
            return ['ready'=>false,'remaining'=>0,'advisories'=>[]];
        }
        $week=$data['week_workspace']??['outcome'=>'','rollover'=>[]];
        $rollover=(array)($week['rollover']??[]);
        $carried=array_flip(array_map('strval',(array)($rollover['itemuuids']??[])));
        $remaining=0;
        foreach ((array)($data['rollover_items']??[]) as $item) {
            if (!isset($carried[(string)$item['item_uuid']])) { $remaining++; }
        }
        $advisories=[];
        if (trim((string)($week['outcome']??''))==='') { $advisories[]='outcome'; }
        $metriccounts=(array)($data['metric_status_counts']??[]);
        if ((int)($metriccounts['critical']??0)>0 || (int)($metriccounts['nodata']??0)>0) {
            $advisories[]='metrics';
        }
        return ['ready'=>$remaining===0,'remaining'=>$remaining,'advisories'=>$advisories];
    }

    /** Readiness UX for closing an active week. Canonical task blockers plus non-blocking advisories. */
    private static function close_readiness_panel(string $company, array $data): string {
        $p=$data['selected'];
        if ((string)$p['status']!=='active') { return ''; }
        $state=self::close_readiness($data);
        $closetext=$state['ready']?self::tr('bpu_close_ready'):self::tr('bpu_close_blocked',(int)$state['remaining']);
        $body=\html_writer::tag('strong',s($closetext));
        if (in_array('outcome',$state['advisories'],true)) { $body.=self::note(self::tr('bpu_week_outcome_help')); }
        if (in_array('metrics',$state['advisories'],true)) { $body.=self::note(self::tr('bpu_period_metrics_help')); }
        $actions='';
        if ($state['ready'] && !empty($data['selected_can_edit'])) {
            $actions=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'close']),self::tr('battleplan_close'),true);
        }
        if ($actions!=='') { $body.=\html_writer::div($actions,'bms-bpu-actions mt-2'); }
        return \html_writer::div($body,($state['ready']?'alert alert-success':'alert alert-warning').' bms-bpu-readiness-panel');
    }

    /** Readiness UX for submit/re-submit. Does not create a second lifecycle or permission rule. */
    private static function readiness_panel(string $company, array $data): string {
        $p=$data['selected'];
        if (!in_array((string)$p['status'],['draft','submitted'],true)) { return ''; }
        $state=self::readiness($data);
        $readiness=(string)$state['readiness'];
        $title=self::tr('battleplan_readiness_'.$readiness);
        $body=\html_writer::tag('strong',s($title));

        // Advisory correction checklist: task edits cannot prove that a reviewer remark was resolved.
        // Keep this separate from canonical server-side submit readiness and do not auto-mark corrections done.
        if ((string)$p['status']==='draft') {
            $history=(array)($data['review_history']??[]);
            if ($history) {
                $latest=end($history);
                if ($latest && (string)($latest['decision']??'')==='changes') {
                    $time=(int)($latest['time_created']??0);
                    $reviewactor=(int)($latest['actor_id']??0);
                    $comment=(string)($latest['comment']??'');
                    $targets=[];
                    foreach (array_reverse($history) as $row) {
                        if ((string)($row['decision']??'')!=='changes' ||
                                (int)($row['time_created']??0)!==$time ||
                                (int)($row['actor_id']??0)!==$reviewactor ||
                                (string)($row['comment']??'')!==$comment) { continue; }
                        $itemuuid=(string)($row['item_uuid']??'');
                        if ($itemuuid!=='' && trim((string)($row['target_label']??''))!=='') {
                            $targets[$itemuuid]=(string)$row['target_label'];
                        }
                    }
                    $checklist=\html_writer::tag('strong',self::tr('bpu_correction_check_title'));
                    $checklist.=self::note(self::tr('bpu_correction_check_help'));
                    if ($targets) {
                        $links='';
                        foreach ($targets as $itemuuid=>$label) {
                            $url=self::url($company,['plan'=>$p['uuid'],'tab'=>'tasks']);
                            $url->set_anchor('bpu-review-item-'.preg_replace('/[^a-zA-Z0-9_-]/','',$itemuuid));
                            $links.=\html_writer::tag('li',\html_writer::link($url,s($label)).
                                ' · '.s(self::tr('bpu_correction_check_pending')));
                        }
                        $checklist.=\html_writer::tag('ul',$links,['class'=>'mb-0 mt-2']);
                    } else {
                        $checklist.=self::note(self::tr('bpu_correction_check_whole'));
                    }
                    $body.=\html_writer::div($checklist,'bms-bpu-correction-check mt-2');
                }
            }
        }
        if (!empty($state['blockers'])) {
            $items='';
            foreach ($state['blockers'] as $blocker) {
                $items.=\html_writer::tag('li',s(self::tr('battleplan_readiness_blocker_'.$blocker)));
            }
            $body.=\html_writer::tag('ul',$items,['class'=>'mb-0 mt-2']);
        } else {
            $body.=self::note(self::tr('battleplan_readiness_rule'));
        }

        $actions='';
        if (!empty($data['selected_can_edit']) && (string)$p['status']==='draft' && empty($state['blockers'])) {
            $op=(string)($state['action']??'');
            if (in_array($op,['submit','selfapprove'],true)) {
                $actions.=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>$op]),
                    self::tr('battleplan_'.$op),true);
            }
        }
        if ($actions!=='') { $body.=\html_writer::div($actions,'bms-bpu-actions mt-2'); }
        $class=$readiness==='blocked'?'alert alert-warning':'alert alert-success';
        return \html_writer::div($body,$class.' bms-bpu-readiness-panel');
    }

    public static function edit_form(string $company, array $data, array $values, array $errors): string {
        $p=$data['selected'];
        $html=bms_workspace_ux_service::record_header(self::tr('bpu_edit'),
            s(battle_plan_service::period_label($p['period_start'],$p['period_end'])),'',self::badge($p['status']));
        $html.=self::note(self::tr('bpu_edit_full_help'));
        $html.=self::correction_feedback($company,$data);
        $html.=self::readiness_panel($company,$data);

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
            $readiness=self::readiness($data);
            if (empty($readiness['blockers'])) {
                $op=(string)($readiness['action']??'');
                if (in_array($op,['submit','selfapprove'],true)) {
                    $lifeactions.=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>$op]),
                        self::tr('battleplan_'.$op),true);
                }
            }
        } else if ($data['selected_can_edit'] && $p['status']==='active') {
            $closestate=self::close_readiness($data);
            if (!empty($closestate['ready'])) {
                $lifeactions.=self::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'close']),self::tr('battleplan_close'),true);
            }
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
    public static function record_header(string $company, array $data, string $tab, array $queuefields = []): string {
        $p=$data['selected']; $employee=$data['employees'][$p['user_id']]??self::tr('bpu_unavailable');
        $actions='';
        if ($data['selected_can_edit'] && $p['status']!=='closed') { $actions=self::link(self::url($company,array_merge(['plan'=>$p['uuid'],'view'=>'edit'], $queuefields)),self::tr('bpu_edit'),true); }
        $menu='';
        if ($data['selected_can_edit'] && $p['status']==='draft') {
            $menu.=\html_writer::link(self::url($company,array_merge(['plan'=>$p['uuid'],'view'=>'fill'], $queuefields)),self::tr('bpu_fill'));
            $readiness=self::readiness($data);
            if (empty($readiness['blockers'])) {
                $op=(string)($readiness['action']??'');
                if (in_array($op,['submit','selfapprove'],true)) {
                    $menu.=\html_writer::link(self::url($company,array_merge(['plan'=>$p['uuid'],'view'=>'action','op'=>$op], $queuefields)),
                        self::tr('battleplan_'.$op));
                }
            }
        }
        if ($data['selected_can_edit'] && $p['status']==='active') {
            $closestate=self::close_readiness($data);
            if (!empty($closestate['ready'])) {
                $menu.=\html_writer::link(self::url($company,['plan'=>$p['uuid'],'view'=>'action','op'=>'close']),self::tr('battleplan_close'));
            }
        }
        if ($p['status']==='submitted' && ($data['can_manage'] || $p['reviewer_id']===$data['actor'])) {
            $menu.=\html_writer::link(self::url($company,array_merge(['plan'=>$p['uuid'],'tab'=>'review'], $queuefields)),self::tr('bpu_review'));
        }
        $menu.=\html_writer::link(new \moodle_url('/local/kbacompany/battle_plan_export.php',['uuid'=>$company,'plan'=>$p['uuid']]),self::tr('battleplan_export_word'));
        $menu.=\html_writer::link(new \moodle_url('/local/kbacompany/battle_plan_coordination.php',['uuid'=>$company,'week'=>$p['period_start']]),self::tr('bpu_tool_coordination'));
        $actions.=\html_writer::tag('details',\html_writer::tag('summary',self::tr('bpu_actions'),['class'=>'btn btn-outline-secondary']).\html_writer::div($menu,'bms-bpu-menu-items'),['class'=>'bms-bpu-menu']);
        $html=bms_workspace_ux_service::record_header(
            s(self::tr('battleplan_plan').' · '.battle_plan_service::period_label($p['period_start'],$p['period_end'])),s($employee),'',self::badge($p['status']),$actions);
        $fields=self::field(self::tr('battleplan_position'),$data['positions'][$p['position_uuid']]??'').
            self::field(self::tr('battleplan_reviewer'),$data['employees'][$p['reviewer_id']]??self::tr('bpu_unavailable')).
            self::field(self::tr('battleplan_observer'),$p['observer_id']?($data['employees'][$p['observer_id']]??self::tr('bpu_unavailable')):self::tr('battleplan_noobserver'));
        $html.=\html_writer::tag('dl',$fields,['class'=>'bms-crm-detail-grid bms-bpu-people']);
        $html.=self::correction_feedback($company,$data);
        $html.=self::readiness_panel($company,$data);

        // Make subordinate-plan context explicit for a manager without changing any permission rule.
        // selected_can_edit is calculated by battle_plan_service from the canonical company/department ACL.
        if ((int)$p['user_id'] !== (int)$data['actor'] && !empty($data['selected_can_edit'])) {
            $position=$data['positions'][$p['position_uuid']]??self::tr('bpu_unavailable');
            $contextbody=\html_writer::div(
                \html_writer::tag('strong',s($employee)).
                \html_writer::tag('span',s($position),['class'=>'bms-bpu-manager-meta']).
                \html_writer::tag('span',s(battle_plan_service::period_label($p['period_start'],$p['period_end'])),['class'=>'bms-bpu-manager-meta']),
                'bms-bpu-manager-person');
            $contextactions=self::link(self::url($company,['view'=>'list','user'=>$p['user_id'],'week'=>$p['period_start']]),self::tr('bpu_list_title'));
            if ($p['status']!=='closed') {
                $contextactions.=self::link(self::url($company,array_merge(['plan'=>$p['uuid'],'view'=>'edit'], $queuefields)),self::tr('bpu_edit'),true);
            }
            $html.=\html_writer::div($contextbody.\html_writer::div($contextactions,'bms-bpu-manager-actions'),
                'bms-bpu-manager-context');
        }

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

        return $html;
    }
    public static function overview(string $company, array $data): string {
        $p=$data['selected']; $summary=$data['summary']; $next=self::tr('bpu_next_'.$p['status']);
        if (!$data['selected_can_edit'] && $p['status']==='draft') { $next=self::tr('bpu_next_draft_reader'); }
        $html=\html_writer::div(\html_writer::tag('strong',self::tr('bpu_next_step')).self::note($next),'bms-crm-trace');
        if ($data['reference_count']>count($data['items'])) { $html.=\html_writer::div(self::tr('bpu_missing_items'),'alert alert-warning'); }
        // Make existing canonical rollover workflow discoverable at the start of a new week.
        $openrollover = (array)($data['rollover_items'] ?? []);
        $priorrollover = (array)(($data['week_workspace']['rollover'] ?? [])['itemuuids'] ?? []);
        $carrieduuids = array_flip(array_map('strval', $priorrollover));
        $pendingrollover = 0;
        foreach ($openrollover as $rollitem) {
            if (!isset($carrieduuids[(string)($rollitem['item_uuid'] ?? '')])) { ++$pendingrollover; }
        }
        if ($p['status'] === 'active' && $pendingrollover > 0) {
            $callout = \html_writer::tag('strong', self::tr('bpu_rollover_alert_title', $pendingrollover));
            $callout .= \html_writer::tag('p', s(self::tr('bpu_rollover_alert_help')), ['class'=>'mb-2']);
            $callout .= \html_writer::link('#bms-bpu-rollover', self::tr('bpu_rollover_alert_action'),
                ['class'=>'btn btn-outline-primary btn-sm']);
            $html .= \html_writer::div($callout, 'alert alert-warning bms-bpu-rollover-prompt');
        }

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

        // Management-cycle result: keep productivity (task execution) and effectiveness (metric attainment)
        // as two separate dimensions. Do not average them into a synthetic employee score.
        $tasktotal=(int)$summary['tasks'];
        $taskdone=(int)$summary['done'];
        $productivity=$tasktotal>0 ? (int)round(($taskdone/$tasktotal)*100) : null;
        $metricok=(int)($metriccounts['ok']??0);
        $metricevaluated=$metricok+(int)($metriccounts['risk']??0)+(int)($metriccounts['critical']??0);
        $metrictotal=$metricevaluated+(int)($metriccounts['nodata']??0);
        $effectiveness=$metricevaluated>0 ? (int)round(($metricok/$metricevaluated)*100) : null;
        $datacoverage=$metrictotal>0 ? (int)round(($metricevaluated/$metrictotal)*100) : null;
        $previous=$data['previous_week_comparison']??null;
        $previousproductivity=null;
        $previouseffectiveness=null;
        if ($previous) {
            $prevtasks=(int)($previous['tasks']['tasks']??0);
            $prevdone=(int)($previous['tasks']['done']??0);
            if ($prevtasks>0) { $previousproductivity=(int)round(($prevdone/$prevtasks)*100); }
            $prevmetrics=(array)($previous['metric_status_counts']??[]);
            $prevok=(int)($prevmetrics['ok']??0);
            $prevevaluated=$prevok+(int)($prevmetrics['risk']??0)+(int)($prevmetrics['critical']??0);
            if ($prevevaluated>0) { $previouseffectiveness=(int)round(($prevok/$prevevaluated)*100); }
        }
        $cyclecards='';
        $cycleitems=[
            [
                'label'=>self::tr('bpu_cycle_productivity'),
                'value'=>$productivity===null ? '—' : $productivity.'%',
                'detail'=>self::tr('bpu_cycle_productivity_detail',(object)['done'=>$taskdone,'total'=>$tasktotal]),
                'percent'=>$productivity,
                'previous'=>$previousproductivity,
            ],
            [
                'label'=>self::tr('bpu_cycle_effectiveness'),
                'value'=>$effectiveness===null ? '—' : $effectiveness.'%',
                'detail'=>self::tr('bpu_cycle_effectiveness_detail',(object)['ok'=>$metricok,'evaluated'=>$metricevaluated]),
                'percent'=>$effectiveness,
                'previous'=>$previouseffectiveness,
            ],
            [
                'label'=>self::tr('bpu_cycle_data_coverage'),
                'value'=>$datacoverage===null ? '—' : $datacoverage.'%',
                'detail'=>self::tr('bpu_cycle_data_coverage_detail',(object)['evaluated'=>$metricevaluated,'total'=>$metrictotal]),
                'percent'=>$datacoverage,
                'previous'=>null,
            ],
        ];
        foreach ($cycleitems as $item) {
            $delta='';
            if ($item['percent']!==null && $item['previous']!==null) {
                $change=(int)$item['percent']-(int)$item['previous'];
                if ($change>0) { $delta=self::tr('bpu_cycle_delta_up',$change); }
                else if ($change<0) { $delta=self::tr('bpu_cycle_delta_down',abs($change)); }
                else { $delta=self::tr('bpu_cycle_delta_same'); }
            }
            $progress='';
            if ($item['percent']!==null) {
                $width=max(0,min(100,(int)$item['percent']));
                $progress=\html_writer::div(
                    \html_writer::div('', 'bms-bpu-metric-progress-fill', ['style'=>'width:'.$width.'%']),
                    'bms-bpu-metric-progress-track',
                    ['role'=>'progressbar','aria-valuemin'=>'0','aria-valuemax'=>'100','aria-valuenow'=>(string)$width]
                );
            }
            $body=\html_writer::tag('span',s($item['label']),['class'=>'bms-bpu-metric-progress-label']).
                \html_writer::tag('strong',s($item['value']),['class'=>'bms-bpu-metric-progress-value']).
                self::note($item['detail']).$progress;
            if ($delta!=='') { $body.=\html_writer::tag('small',s($delta),['class'=>'text-muted d-block mt-2']); }
            $cyclecards.=\html_writer::div($body,'bms-bpu-cycle-card');
        }
        $cyclebody=\html_writer::div($cyclecards,'bms-bpu-cycle-grid').
            self::note(self::tr('bpu_cycle_method_note'));
        if ($metrictotal>0 && $metricevaluated===0) {
            $cyclebody.=\html_writer::div(self::tr('bpu_cycle_no_metric_data'),'alert alert-info mt-2');
        }
        $html.=self::panel(self::tr('bpu_cycle_title'),$cyclebody);

        $history=(array)($data['performance_history']??[]);
        if (count($history)>1) {
            $rows='';
            foreach ($history as $index=>$week) {
                $iscurrent=$index===count($history)-1;
                $label=battle_plan_service::period_label((string)$week['period_start'],(string)$week['period_end']);
                $metrics=[
                    ['key'=>'productivity','label'=>self::tr('bpu_cycle_productivity')],
                    ['key'=>'effectiveness','label'=>self::tr('bpu_cycle_effectiveness')],
                    ['key'=>'coverage','label'=>self::tr('bpu_cycle_data_coverage')],
                ];
                $bars='';
                foreach ($metrics as $metric) {
                    $value=$week[$metric['key']];
                    $display=$value===null ? '—' : ((int)$value).'%';
                    $width=$value===null ? 0 : max(0,min(100,(int)$value));
                    $bars.=\html_writer::div(
                        \html_writer::tag('span',s($metric['label']),['class'=>'bms-bpu-trend-label']).
                        \html_writer::div(\html_writer::div('', 'bms-bpu-trend-fill', ['style'=>'width:'.$width.'%']),
                            'bms-bpu-trend-track',['aria-hidden'=>'true']).
                        \html_writer::tag('strong',s($display),['class'=>'bms-bpu-trend-value']),
                        'bms-bpu-trend-metric');
                }
                $head=\html_writer::tag('strong',s($label));
                if ($iscurrent) {
                    $head.=' '.\html_writer::tag('span',s(self::tr('bpu_cycle_trend_current')),['class'=>'badge badge-success']);
                }
                $rows.=\html_writer::div(
                    \html_writer::div($head,'bms-bpu-trend-week').$bars,
                    'bms-bpu-trend-row'.($iscurrent?' is-current':''));
            }
            $trendbody=\html_writer::div($rows,'bms-bpu-trend').
                self::note(self::tr('bpu_cycle_trend_note',(object)['weeks'=>count($history)]));
            $html.=self::panel(self::tr('bpu_cycle_trend_title'),$trendbody);
        }

        $previous=$data['previous_week_comparison']??null;
        if ($previous) {
            $currentopen=max(0,(int)$summary['tasks']-(int)$summary['done']);
            $periods=[
                [
                    'label'=>self::tr('bpu_week_compare_previous'),
                    'period'=>battle_plan_service::period_label((string)$previous['period_start'],(string)$previous['period_end']),
                    'status'=>self::tr('battleplan_status_'.(string)$previous['status']),
                    'tasks'=>(int)$previous['tasks']['tasks'],
                    'done'=>(int)$previous['tasks']['done'],
                    'open'=>(int)$previous['tasks']['open'],
                    'metrics'=>(array)$previous['metric_status_counts'],
                    'url'=>self::url($company,['plan'=>(string)$previous['uuid']]),
                ],
                [
                    'label'=>self::tr('bpu_week_compare_current'),
                    'period'=>battle_plan_service::period_label((string)$p['period_start'],(string)$p['period_end']),
                    'status'=>self::tr('battleplan_status_'.(string)$p['status']),
                    'tasks'=>(int)$summary['tasks'],
                    'done'=>(int)$summary['done'],
                    'open'=>$currentopen,
                    'metrics'=>(array)$metriccounts,
                    'url'=>self::url($company,['plan'=>(string)$p['uuid']]),
                ],
            ];
            $comparebody='';
            foreach ($periods as $period) {
                $stats=[
                    'bpu_tasks_count'=>(string)$period['tasks'],
                    'bpu_done_count'=>(string)$period['done'],
                    'bpu_week_compare_open'=>(string)$period['open'],
                    'metric_status_risk'=>(string)($period['metrics']['risk']??0),
                    'metric_status_critical'=>(string)($period['metrics']['critical']??0),
                    'metric_status_nodata'=>(string)($period['metrics']['nodata']??0),
                ];
                $cards='';
                foreach ($stats as $label=>$value) {
                    $cards.=\html_writer::div(\html_writer::tag('strong',s($value)).\html_writer::tag('span',self::tr($label)),'bms-bpu-stat');
                }
                $head=\html_writer::tag('strong',s($period['label'])) .
                    \html_writer::tag('span',s($period['period']).' · '.s($period['status']),['class'=>'d-block text-muted']);
                $comparebody.=\html_writer::div($head.\html_writer::div($cards,'bms-bpu-stats').
                    \html_writer::div(self::link($period['url'],self::tr('bpu_week_compare_open_plan')), 'bms-bpu-actions mt-2'),
                    'bms-bpu-week-compare-item');
            }
            $prevopen=(int)$previous['tasks']['open'];
            $prevattention=(int)($previous['metric_status_counts']['risk']??0)+(int)($previous['metric_status_counts']['critical']??0);
            $currentattention=(int)($metriccounts['risk']??0)+(int)($metriccounts['critical']??0);
            $prevnodata=(int)($previous['metric_status_counts']['nodata']??0);
            $currentnodata=(int)($metriccounts['nodata']??0);

            $insightitems=[];
            $opendelta=$currentopen-$prevopen;
            if ($opendelta<0) {
                $insightitems[]=self::tr('bpu_week_insight_open_better',abs($opendelta));
            } else if ($opendelta>0) {
                $insightitems[]=self::tr('bpu_week_insight_open_worse',$opendelta);
            } else {
                $insightitems[]=self::tr('bpu_week_insight_open_same');
            }
            $attentiondelta=$currentattention-$prevattention;
            if ($attentiondelta<0) {
                $insightitems[]=self::tr('bpu_week_insight_metric_better',abs($attentiondelta));
            } else if ($attentiondelta>0) {
                $insightitems[]=self::tr('bpu_week_insight_metric_worse',$attentiondelta);
            } else {
                $insightitems[]=self::tr('bpu_week_insight_metric_same');
            }
            $nodatadelta=$currentnodata-$prevnodata;
            if ($nodatadelta<0) {
                $insightitems[]=self::tr('bpu_week_insight_nodata_better',abs($nodatadelta));
            } else if ($nodatadelta>0) {
                $insightitems[]=self::tr('bpu_week_insight_nodata_worse',$nodatadelta);
            } else {
                $insightitems[]=self::tr('bpu_week_insight_nodata_same');
            }
            $insightlis='';
            foreach ($insightitems as $item) {
                $insightlis.=\html_writer::tag('li',s($item));
            }
            $attentionnow=self::tr('bpu_week_attention_summary',(object)[
                'open'=>$currentopen,
                'risk'=>(int)($metriccounts['risk']??0),
                'critical'=>(int)($metriccounts['critical']??0),
                'nodata'=>$currentnodata,
            ]);
            $insights=\html_writer::tag('div',
                \html_writer::tag('h4',self::tr('bpu_week_insights_title')).
                \html_writer::tag('ul',$insightlis,['class'=>'mb-2']).
                \html_writer::tag('h4',self::tr('bpu_week_attention_title')).
                self::note($attentionnow),
                ['class'=>'bms-bpu-week-insights mt-3']);

            $html.=self::panel(self::tr('bpu_week_compare_title'),
                \html_writer::div($comparebody,'bms-bpu-grid').$insights.self::note(self::tr('bpu_week_compare_help')));
        }

        // Turn the existing week signals into a compact action list without creating a parallel
        // decision entity. Canonical tasks, indicator projections and the next Battle Plan remain
        // the only sources of truth.
        $currentopen=max(0,(int)$summary['tasks']-(int)$summary['done']);
        $attentionmetrics=(int)($metriccounts['risk']??0)+(int)($metriccounts['critical']??0);
        $nodatametrics=(int)($metriccounts['nodata']??0);
        $actions='';
        if ($currentopen>0) {
            $actions.=\html_writer::tag('li',
                \html_writer::tag('strong',self::tr('bpu_next_actions_open_tasks',$currentopen)).' '.
                self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'tasks']),self::tr('bpu_next_actions_open_tasks_link')));
        }
        if ($attentionmetrics>0) {
            $actions.=\html_writer::tag('li',
                \html_writer::tag('strong',self::tr('bpu_next_actions_attention_metrics',$attentionmetrics)).' '.
                self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'indicators']),self::tr('bpu_next_actions_metrics_link')));
        }
        if ($nodatametrics>0) {
            $actions.=\html_writer::tag('li',
                \html_writer::tag('strong',self::tr('bpu_next_actions_nodata_metrics',$nodatametrics)).' '.
                self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'indicators']),self::tr('bpu_next_actions_metrics_link')));
        }
        if (!empty($data['selected_can_edit']) && ($currentopen>0 || $attentionmetrics>0 || $nodatametrics>0)) {
            $taskparams=[
                'uuid'=>$company,
                'view'=>'create',
                'assigneeid'=>(int)$p['user_id'],
                'startdate'=>(string)$p['period_start'],
                'duedate'=>(string)$p['period_end'],
                'battleplanuuid'=>(string)$p['uuid'],
            ];
            if (!empty($p['reviewer_id'])) { $taskparams['reviewerid']=(int)$p['reviewer_id']; }
            if (!empty($p['observer_id'])) { $taskparams['observerid']=(int)$p['observer_id']; }
            $taskcreate=new \moodle_url('/local/kbacompany/tasks.php',$taskparams);
            $actions.=\html_writer::tag('li',
                \html_writer::tag('strong',self::tr('bpu_next_actions_create_task_prompt')).' '.
                self::link($taskcreate,self::tr('bpu_next_actions_create_task')));
        }
        $nextaction=$data['next_plan']??null;
        if ($nextaction) {
            $actions.=\html_writer::tag('li',
                \html_writer::tag('strong',self::tr('bpu_next_actions_next_plan_exists')).' '.
                self::link(self::url($company,['plan'=>$nextaction['uuid']]),self::tr('bpu_open_next_plan')));
        } else if (!empty($data['selected_can_edit']) && in_array((string)$p['status'],['active','closed'],true)) {
            $nextstartaction=(new \DateTimeImmutable((string)$p['period_start'],new \DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
            $createaction=self::url($company,[
                'view'=>'create','user'=>(int)$p['user_id'],'position'=>(string)$p['position_uuid'],
                'week'=>$nextstartaction,'fromplan'=>(string)$p['uuid'],
            ]);
            $actions.=\html_writer::tag('li',
                \html_writer::tag('strong',self::tr('bpu_next_actions_next_plan_missing')).' '.
                self::link($createaction,self::tr('bpu_create_next_plan')));
        }
        if ($actions==='') {
            $actions=\html_writer::tag('li',self::tr('bpu_next_actions_clear'));
        }
        $actionsbody=\html_writer::tag('ol',$actions,['class'=>'mb-2 bms-bpu-next-actions']).
            self::note(self::tr('bpu_next_actions_help'));
        $html.=self::panel(self::tr('bpu_next_actions_title'),$actionsbody);

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

        // Next-period navigation is independent from carry-over. A user can prepare/open the
        // following week even when there are no unfinished tasks to roll forward.
        $next=$data['next_plan']??null;
        $nextstart=(new \DateTimeImmutable((string)$p['period_start'],new \DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
        $nextend=(new \DateTimeImmutable($nextstart,new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
        $nextbody=self::field(self::tr('battleplan_week'),battle_plan_service::period_label($nextstart,$nextend));
        if ($next) {
            $nextbody.=self::field(self::tr('battleplan_status'),self::tr('battleplan_status_'.(string)$next['status']));
            $nextbody.=\html_writer::div(self::link(self::url($company,['plan'=>$next['uuid']]),self::tr('bpu_open_next_plan'),true),'bms-bpu-actions mt-2');
        } else if (!empty($data['selected_can_edit']) && in_array((string)$p['status'],['active','closed'],true)) {
            $createurl=self::url($company,[
                'view'=>'create','user'=>(int)$p['user_id'],'position'=>(string)$p['position_uuid'],'week'=>$nextstart,
                'fromplan'=>(string)$p['uuid'],
            ]);
            $nextbody.=self::note(self::tr('bpu_next_plan_missing'));
            $nextbody.=\html_writer::div(self::link($createurl,self::tr('bpu_create_next_plan'),true),'bms-bpu-actions mt-2');
        } else {
            $nextbody.=self::note(self::tr('bpu_next_plan_unavailable'));
        }
        $html.=self::panel(self::tr('bpu_next_plan_title'),$nextbody);

        $openitems=$data['rollover_items']??[];
        $rollover=$week['rollover']??[];
        $carried=array_flip(array_map('strval',(array)($rollover['itemuuids']??[])));
        $rollbody='';
        $target=$data['rollover_target']??null;
        $targetisdraft=!$target || (string)($target['status']??'')==='draft';
        $carriedcount=0;
        foreach ($openitems as $item) { if (isset($carried[$item['item_uuid']])) { $carriedcount++; } }
        $remainingcount=max(0,count($openitems)-$carriedcount);
        if ($target) {
            $rollbody.=\html_writer::div(self::tr('bpu_rollover_target',battle_plan_service::period_label($target['period_start'],$target['period_end'])).' '.
                \html_writer::link(self::url($company,['plan'=>$target['uuid']]),self::tr('bpu_open_next_plan')),'alert alert-success');
        }
        if ($openitems) {
            $readiness=$remainingcount>0?self::tr('bpu_close_blocked',$remainingcount):self::tr('bpu_close_ready');
            $rollbody.=\html_writer::div($readiness,'bms-bpu-rollover-readiness '.($remainingcount>0?'is-blocked':'is-ready'));
        }
        if ($p['status']==='active' && $data['selected_can_edit'] && $openitems && $targetisdraft && $remainingcount>0) {
            $form=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'rolloveropen','view'=>'detail','tab'=>'overview','sesskey'=>sesskey(),'expected'=>$p['edit_token']]);
            $rows='';
            foreach ($openitems as $item) {
                $id='roll_'.preg_replace('/[^a-z0-9_]/i','_',$item['item_uuid']);
                $checked=isset($carried[$item['item_uuid']]);
                $attrs=['type'=>'checkbox','name'=>'rolloveritems[]','value'=>$item['item_uuid'],'id'=>$id];
                if ($checked) { $attrs['checked']='checked'; $attrs['disabled']='disabled'; }
                $label=s($item['title']);
                $meta=self::tr('taskcenter_status_'.$item['status']);
                if ($item['due_date']!=='') { $meta.=' · '.self::tr('taskcenter_duedate').': '.$item['due_date']; }
                $rowclass='bms-bpu-rollover-item'.($checked?' is-carried':'');
                $rows.=\html_writer::tag('label',\html_writer::empty_tag('input',$attrs).\html_writer::tag('span',$label.\html_writer::tag('small',s($meta),['class'=>'d-block text-muted'])),['class'=>$rowclass,'for'=>$id]);
            }
            $form.=$rows.self::note(self::tr('bpu_rollover_help')).\html_writer::tag('button',self::tr('bpu_rollover_selected'),['type'=>'submit','class'=>'btn btn-primary']);
            $rollbody.=\html_writer::tag('form',$form,['method'=>'post','class'=>'bms-bpu-form']);
        } else if (!$openitems) {
            $rollbody.=self::note(self::tr('bpu_rollover_none'));
        } else if (!$targetisdraft && $remainingcount>0) {
            $rollbody.=\html_writer::div(self::tr('battleplan_rolloverstate'),'alert alert-warning');
        } else if (!$data['selected_can_edit']) {
            $rollbody.=self::note(self::tr('bpu_rollover_readonly'));
        }
        $html.=\html_writer::div(self::panel(self::tr('bpu_rollover_title'),$rollbody), '', ['id'=>'bms-bpu-rollover']);
        $html.=self::close_readiness_panel($company,$data);

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
                // Stable anchor to the exact item flagged in the latest review.
                $name=\html_writer::span('', '', ['id'=>'bpu-review-item-'.preg_replace('/[^a-zA-Z0-9_-]/','',(string)$item['uuid'])]).$name;
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
    /** Reviewer overview of corrections from the latest returned review batch. Read-only. */
    private static function review_correction_decision_panel(string $company, array $data): string {
        $history=(array)($data['review_history']??[]);
        $plan=$data['selected']??null;
        if (!$plan || !$history) { return ''; }
        // Find the latest changes request, even after its plan was re-submitted.
        $last=null;
        foreach ($history as $row) {
            if ((string)($row['decision']??'')==='changes') { $last=$row; }
        }
        if (!$last) { return ''; }
        $time=(int)($last['time_created']??0);
        $actor=(int)($last['actor_id']??0);
        $comment=(string)($last['comment']??'');
        $targets=[];
        foreach ($history as $row) {
            if ((string)($row['decision']??'')!=='changes' || (int)($row['time_created']??0)!==$time ||
                    (int)($row['actor_id']??0)!==$actor || (string)($row['comment']??'')!==$comment) { continue; }
            $key=(string)($row['item_uuid']??'');
            $targets[$key]=trim((string)($row['target_label']??'')) ?: self::tr('battleplan_review_plan_target');
        }
        if (!$targets) { $targets=[''=>self::tr('battleplan_review_plan_target')]; }
        $payload=json_decode((string)($plan['state_payload']??''),true);
        $events=(array)(is_array($payload)?($payload['review_correction_events']??[]):[]);
        $batch=$time.':'.$actor.':'.$comment;
        $states=[];
        foreach ($events as $event) {
            if (!is_array($event) || (string)($event['batch']??'')!==$batch) { continue; }
            $key=(string)($event['target']??'');
            if (array_key_exists($key,$targets)) { $states[$key]=(string)($event['action']??''); }
        }
        $counts=['pending'=>0,'claimed'=>0,'accepted'=>0];
        $rows='';
        foreach ($targets as $key=>$label) {
            $state=$states[$key]??'';
            $bucket=$state==='accepted'?'accepted':($state==='claimed'?'claimed':'pending');
            ++$counts[$bucket];
            $tasklink=s($label);
            if ($key!=='') {
                $url=self::url($company,['plan'=>$plan['uuid'],'tab'=>'tasks']);
                $url->set_anchor('bpu-review-item-'.preg_replace('/[^a-zA-Z0-9_-]/','',$key));
                $tasklink=\html_writer::link($url,s($label));
            }
            $rows.=\html_writer::tag('tr',\html_writer::tag('td',$tasklink).
                \html_writer::tag('td',s(self::tr('bpu_fix_'.$bucket))));
        }
        $summary=\html_writer::tag('strong',s(self::tr('bpu_reviewer_correction_title')));
        foreach (['pending','claimed','accepted'] as $bucket) {
            $summary.=' '.\html_writer::tag('span',s(self::tr('bpu_fix_count_'.$bucket,$counts[$bucket])),
                ['class'=>'badge badge-light mr-2']);
        }
        if ($comment!=='') { $summary.=\html_writer::div(nl2br(s($comment)),'mt-2'); }
        $table=\html_writer::tag('table',\html_writer::tag('thead',\html_writer::tag('tr',
            \html_writer::tag('th',s(self::tr('taskcenter_task'))).
            \html_writer::tag('th',s(self::tr('bpu_reviewer_correction_state'))))).
            \html_writer::tag('tbody',$rows),['class'=>'table table-sm mt-2']);
        return \html_writer::div($summary.$table,'bms-bpu-review-correction-panel border rounded p-3 mb-3');
    }

    /** Read-only approval checklist. Never introduce new lifecycle blockers. */
    public static function review_checklist_projection(array $data): array {
        $plan=(array)($data['selected']??[]);
        $items=(array)($data['items']??[]);
        $employees=(array)($data['employees']??[]);
        $reviewer=(int)($plan['reviewer_id']??0);
        $invalidproduct=0; $unassigned=0; $noduedate=0;
        foreach ($items as $entry) {
            $task=(array)($entry['task']??[]);
            if (trim((string)($task['product']??''))==='') { ++$invalidproduct; }
            if ((int)($task['assignee_id']??0)<=0) { ++$unassigned; }
            if (trim((string)($task['due_date']??''))==='') { ++$noduedate; }
        }
        $metrics=(array)($data['position_indicators']??[]);
        $metriccounts=(array)($data['metric_status_counts']??[]);
        $missingmetrics=(int)($metriccounts['nodata']??0);
        // The canonical readiness rules apply only to the existing lifecycle; do not hard-block
        // approvals on task content, due dates, missing metric facts or correction acknowledgements.
        $canonical=battle_plan_week_readiness_service::classify(
            (string)($plan['status']??''), (int)($plan['user_id']??0), $reviewer,
            $reviewer>0 && isset($employees[$reviewer]), count($items));
        return [
            'readiness'=>$canonical['readiness'], 'blockers'=>(array)$canonical['blockers'],
            'taskcount'=>count($items), 'missingproducts'=>$invalidproduct,
            'unassigned'=>$unassigned, 'noduedate'=>$noduedate,
            'metrics'=>count($metrics), 'missingmetricfacts'=>$missingmetrics,
            'reviewerok'=>$reviewer>0 && isset($employees[$reviewer]),
        ];
    }

    /** Summary shown above a plan-review decision; the existing review form stays authoritative. */
    private static function review_readiness_panel(string $company, array $data): string {
        $plan=$data['selected']??null;
        if (!$plan || (string)($plan['status']??'')!=='submitted') { return ''; }
        $check=self::review_checklist_projection($data);
        $counts=[
            ['bpu_approval_check_reviewer',$check['reviewerok']?self::tr('bpu_approval_check_ok'):self::tr('bpu_approval_check_missing'),!$check['reviewerok']],
            ['bpu_approval_check_tasks',self::tr('bpu_approval_check_taskcount',$check['taskcount']),$check['taskcount']===0],
            ['bpu_approval_check_product',self::tr('bpu_approval_check_missingcount',$check['missingproducts']),$check['missingproducts']>0],
            ['bpu_approval_check_assignee',self::tr('bpu_approval_check_missingcount',$check['unassigned']),$check['unassigned']>0],
            ['bpu_approval_check_due',self::tr('bpu_approval_check_missingcount',$check['noduedate']),$check['noduedate']>0],
            ['bpu_approval_check_metrics',self::tr('bpu_approval_check_metricstatus',(object)[
                'total'=>$check['metrics'],'missing'=>$check['missingmetricfacts']]),$check['missingmetricfacts']>0],
        ];
        $rows='';
        foreach ($counts as $entry) {
            [$key,$result,$needsattention]=$entry;
            $statusclass=$needsattention?'text-warning':'text-success';
            $rows.=\html_writer::tag('tr',
                \html_writer::tag('td',s(self::tr($key))).
                \html_writer::tag('td',\html_writer::tag('span',s($result),['class'=>$statusclass])));
        }
        $table=\html_writer::tag('table',
            \html_writer::tag('thead',\html_writer::tag('tr',
                \html_writer::tag('th',s(self::tr('bpu_approval_check_column'))).
                \html_writer::tag('th',s(self::tr('bpu_approval_check_result'))))).
            \html_writer::tag('tbody',$rows),['class'=>'table table-sm mb-2']);
        $links=self::link(self::url($company,['plan'=>$plan['uuid'],'tab'=>'tasks']),self::tr('bpu_tab_tasks'));
        $links.=' '.self::link(self::url($company,['plan'=>$plan['uuid'],'tab'=>'indicators']),self::tr('bpu_tab_indicators'));
        $blocking=!empty($check['blockers']);
        $message=$blocking?self::tr('bpu_approval_check_blocked'):self::tr('bpu_approval_check_ready');
        $content=\html_writer::tag('h3',s(self::tr('bpu_approval_check_title')),['class'=>'h5']).
            \html_writer::tag('p',s($message),['class'=>'mb-2']).
            $table.self::note(self::tr('bpu_approval_check_advisory')).
            \html_writer::div($links,'bms-bpu-actions');
        return \html_writer::div($content,'bms-bpu-approval-check border rounded p-3 mb-3');
    }

    public static function review(string $company, array $data, array $values=[], array $errors=[], array $queuefields=[]): string {
        $p=$data['selected']; $allowed=$p['status']==='submitted' && ($data['can_manage'] || $p['reviewer_id']===$data['actor']);
        $html=self::note(self::tr('bpu_review_help')).self::note(self::tr('bpu_review_decision_guide'));
        $html.=self::review_readiness_panel($company,$data);
        $html.=self::review_correction_decision_panel($company,$data);
        if (!$allowed) { return $html.self::note(self::tr('bpu_review_readonly')).self::review_cycle_history($data['review_cycle_history'] ?? []).self::review_history($data['review_history']); }
        $rows=[]; foreach ($data['items'] as $item) {
            $t=$item['task']; $id='review_'.$item['uuid'];
            $attrs=['type'=>'checkbox','name'=>'reviewitems[]','value'=>$item['uuid'],'id'=>$id];
            if (in_array($item['uuid'],(array)($values['reviewitems']??[]),true)) { $attrs['checked']='checked'; }
            $box=\html_writer::empty_tag('input',$attrs).\html_writer::label(self::tr('battleplan_review_fix').': '.$t['title'],$id,false,['class'=>'sr-only']);
            $rows[]=[$box,s($t['title']),s($t['product']),s($t['plan_minutes'].' / '.$t['fact_minutes']),s(self::tr('taskcenter_status_'.$t['status']))];
        }
        $form=self::hidden(array_merge(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>'reviewplan',
            'view'=>'detail','tab'=>'review','sesskey'=>sesskey(),
            'expected'=>$values['expected']??$p['edit_token']], $queuefields));
        $form.=self::table(array_map([self::class,'tr'],['battleplan_review_fix','taskcenter_task','taskcenter_product','bpu_time_minutes','taskcenter_status']),$rows,self::tr('bpu_review'));
        $form.=self::note(self::tr('bpu_review_targets_hint'));
        $form.=self::control('reviewcomment',['label'=>'battleplan_review_comment','type'=>'textarea','maxlength'=>4000,'help'=>'battleplan_review_comment_hint'],$values['reviewcomment']??'',$errors['reviewcomment']??'');
        $form.=self::note(self::tr('bpu_review_comment_required'));
        $form.=self::confirmation($values['confirmed']??false);
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('battleplan_approve'),['type'=>'submit','name'=>'decision','value'=>'approve','class'=>'btn btn-primary']).
            \html_writer::tag('button',self::tr('battleplan_review_changes'),['type'=>'submit','name'=>'decision','value'=>'changes','class'=>'btn btn-outline-danger']),'bms-bpu-actions');
        return $html.\html_writer::tag('form',$form,['method'=>'post','enctype'=>'multipart/form-data','class'=>'bms-bpu-form']).self::review_cycle_history($data['review_cycle_history'] ?? []).self::review_history($data['review_history']);
    }
    /** Explicit journal of review transitions; excludes speculative pre-release cycles. */
    private static function review_cycle_history(array $history): string {
        $rows = (array)($history['rows'] ?? []);
        if (!$rows) {
            return self::panel(self::tr('bpu_reviewlog_title'), self::note(self::tr('bpu_reviewlog_no_history')));
        }
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable bms24-review-cycle-table';
        $table->head = [self::tr('bpu_reviewlog_number'), self::tr('bpu_reviewlog_event'),
            self::tr('bpu_reviewlog_when'), self::tr('bpu_reviewlog_actor'), self::tr('bpu_reviewlog_duration')];
        foreach (array_reverse($rows) as $event) {
            $elapsed = $event['duration_seconds'];
            $duration = $elapsed === null ? '—' : (int)floor($elapsed / 3600) . ' ' . self::tr('bpu_timing_hours') .
                ' ' . (int)floor(($elapsed % 3600) / 60) . ' ' . self::tr('bpu_timing_minutes');
            $table->data[] = [(int)$event['cycle'], s(self::tr('bpu_reviewlog_' . $event['type'])),
                userdate((int)$event['time_created'], '%d.%m.%Y %H:%M'),
                s($event['actor_name'] ?: '—'), s($duration)];
        }
        $summary = self::note(self::tr('bpu_reviewlog_summary', (object)[
            'cycles'=>(int)$history['cycles'], 'timed'=>(int)$history['timed']]));
        if ($history['average_seconds'] !== null) {
            $average = (int)$history['average_seconds'];
            $summary .= self::note(self::tr('bpu_reviewlog_average') . ': ' .
                (int)floor($average / 3600) . ' ' . self::tr('bpu_timing_hours') . ' ' .
                (int)floor(($average % 3600) / 60) . ' ' . self::tr('bpu_timing_minutes'));
        }
        $summary .= self::note(self::tr('bpu_reviewlog_help'));
        return self::panel(self::tr('bpu_reviewlog_title'), $summary . \html_writer::table($table));
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
        return self::panel(self::tr('bpu_state_milestones'),\html_writer::tag('dl',$fields,['class'=>'bms-crm-detail-grid']).self::note(self::tr('bpu_history_help'))).self::review_cycle_history($data['review_cycle_history'] ?? []).self::review_history($data['review_history']);
    }
    public static function confirmation(bool $checked=false): string {
        $attrs=['type'=>'checkbox','name'=>'confirmed','value'=>1,'required'=>'required']; if ($checked) { $attrs['checked']='checked'; }
        return \html_writer::tag('label',\html_writer::empty_tag('input',$attrs).' '.self::tr('bpu_confirm'),['class'=>'bms-bpu-confirm']);
    }
    public static function action(string $company, array $data, string $op, string $itemuuid='', array $values=[]): string {
        $p=$data['selected']; $title=self::tr('battleplan_'.$op); $itemlabel='';
        if ($op==='remove') { foreach ($data['items'] as $item) { if ($item['uuid']===$itemuuid) { $itemlabel=$item['task']['title']; } } }
        $html=bms_workspace_ux_service::record_header($title,s(battle_plan_service::period_label($p['period_start'],$p['period_end'])),'',self::badge($p['status']));
        if ($op==='close') {
            $closestate=self::close_readiness($data);
            if (empty($closestate['ready'])) {
                $body=self::note(self::tr('bpu_close_blocked',(int)$closestate['remaining'])).
                    \html_writer::div(self::link(self::url($company,['plan'=>$p['uuid'],'tab'=>'overview']),self::tr('bpu_cancel')),'bms-bpu-actions');
                return $html.self::panel(self::tr('bpu_action_confirmation'),$body);
            }
        }
        $form=self::hidden(['uuid'=>$company,'plan'=>$p['uuid'],'action'=>$op,'view'=>'action','sesskey'=>sesskey(),
            'expected'=>$values['expected']??$p['edit_token'],'itemuuid'=>$itemuuid]);
        $form.=self::note(self::tr('bpu_confirm_'.$op)); if ($itemlabel!=='') { $form.=\html_writer::tag('p',s($itemlabel),['class'=>'font-weight-bold']); }
        if (in_array($op,['submit','selfapprove'],true)) {
            $fix=battle_plan_service::outstanding_review_corrections($data);
            if ($fix['outstanding']>0) {
                $form.=\html_writer::div(s(self::tr('bpu_resubmit_warning',$fix['outstanding'])),
                    'alert alert-warning bms-bpu-resubmit-warning',['role'=>'alert']);
                $checked=!empty($values['correctionsack']);
                $attrs=['type'=>'checkbox','name'=>'correctionsack','value'=>1,'required'=>'required'];
                if ($checked) { $attrs['checked']='checked'; }
                $form.=\html_writer::tag('label',\html_writer::empty_tag('input',$attrs).
                    ' '.s(self::tr('bpu_resubmit_acknowledge')),['class'=>'bms-bpu-confirm']);
            }
        }
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
