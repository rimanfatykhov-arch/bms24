<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS-UX-CRUD-001 presentation. No writes or permission grants in this class. */
class policy_ui_service {
    public static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }
    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/policies.php', ['uuid'=>$company]+$params);
    }
    public static function hidden(array $values): string {
        $out = '';
        foreach ($values as $name=>$value) { $out .= \html_writer::empty_tag('input', ['type'=>'hidden','name'=>$name,'value'=>$value]); }
        return $out;
    }
    public static function link($url, string $text, bool $primary = false): string {
        return \html_writer::link($url, s($text), ['class'=>'btn '.($primary ? 'btn-primary' : 'btn-outline-secondary')]);
    }
    private static function note(string $text): string { return \html_writer::tag('p', s($text), ['class'=>'bms-pcu-note']); }
    private static function field(string $label, string $value): string {
        return \html_writer::div(\html_writer::tag('dt',s($label)).\html_writer::tag('dd',nl2br(s($value ?: self::tr('pcu_not_set')))), 'bms-pcu-field');
    }
    private static function panel(string $title, string $body): string {
        return \html_writer::tag('section',\html_writer::tag('h2',s($title),['class'=>'bms-crm-section-title']).$body,['class'=>'bms-crm-section bms-pcu-panel']);
    }
    public static function badge(string $status): string {
        $key = in_array($status,policy_service::STATES,true) ? 'policy_status_'.$status : 'pcu_not_set';
        return \html_writer::tag('span',s(self::tr($key)),['class'=>'bms-pcu-badge bms-pcu-'.(in_array($status,policy_service::STATES,true)?$status:'unknown')]);
    }
    public static function action_label(string $operation): string {
        return self::tr(['publish'=>'policy_publish','newversion'=>'pcu_newversion','withdraw'=>'policy_withdraw'][$operation] ?? 'pcu_actions');
    }
    private static function label_for(array $labels, string $type, string $uuid): string {
        return $type === 'company' ? self::tr('policy_scope_company') : ($labels[$type.'|'.$uuid] ?? self::tr('pcu_reference_unavailable'));
    }
    private static function table(array $headers, array $rows, string $label): string {
        $table = new \html_table(); $table->attributes['class'] = 'table bms-crm-list-table bms-pcu-table'; $table->head = array_map('s',$headers);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i=>$cell) { $cells[] = \html_writer::tag('span',s($headers[$i]),['class'=>'bms-pcu-cell-label','aria-hidden'=>'true']).$cell; }
            $table->data[] = $cells;
        }
        return \html_writer::div(\html_writer::table($table),'bms-pcu-table-wrap',['tabindex'=>'0','role'=>'region','aria-label'=>$label]);
    }
    private static function pager(string $company, int $total, int $page, int $pagesize, array $params): string {
        if ($total <= $pagesize) { return ''; }
        $out = $page > 0 ? self::link(self::url($company,$params+['page'=>$page-1]),self::tr('pcu_previous')) : '';
        $out .= self::note(self::tr('pcu_page',(object)['page'=>$page+1,'pages'=>(int)ceil($total/$pagesize)]));
        if (($page+1)*$pagesize < $total) { $out .= self::link(self::url($company,$params+['page'=>$page+1]),self::tr('pcu_next')); }
        return \html_writer::tag('nav',$out,['class'=>'bms-pcu-actions','aria-label'=>self::tr('pcu_pagination')]);
    }
    public static function listing(string $company, array $model): string {
        $actions = $model['can_manage'] ? self::link(self::url($company,['view'=>'create']),self::tr('pcu_create'),true) : '';
        $out = bms_workspace_ux_service::record_header(s(self::tr('policy_title')),s(self::tr('pcu_intro')),'','',$actions);
        $states = [''=>self::tr('pcu_all_statuses')];
        foreach (policy_service::STATES as $state) { if ($model['can_manage'] || $state === 'published') { $states[$state] = self::tr('policy_status_'.$state); } }
        $f = $model['filters'];
        $form = self::hidden(['uuid'=>$company]);
        $form .= self::control('q',['label'=>'pcu_search','type'=>'search','maxlength'=>200],$f['q']);
        $form .= self::control('kind',['label'=>'policy_kind','options'=>[''=>self::tr('pcu_all_kinds'),'policy'=>self::tr('policy_kind_policy'),'directive'=>self::tr('policy_kind_directive')]],$f['kind']);
        $form .= self::control('status',['label'=>'policy_status','options'=>$states],$f['status']);
        $form .= \html_writer::div(\html_writer::tag('button',self::tr('pcu_apply'),['type'=>'submit','class'=>'btn btn-primary']).self::link(self::url($company),self::tr('pcu_reset')),'bms-pcu-actions');
        $out .= \html_writer::tag('form',$form,['method'=>'get','action'=>self::url($company)->out(false),'class'=>'bms-pcu-filters']);
        $out .= self::note(self::tr($model['can_manage']?'pcu_list_manager':'pcu_list_reader'));
        if (!$model['total']) {
            return $out.self::panel(self::tr('pcu_empty'), self::note(self::tr($f['q'] || $f['kind'] || $f['status'] ? 'pcu_empty_filtered' : ($model['can_manage']?'pcu_empty_manager':'pcu_empty_reader'))));
        }
        $out .= self::note(self::tr('pcu_found',$model['total'])); $rows = [];
        foreach ($model['rows'] as $row) {
            $rows[] = [s($row->code),\html_writer::link(self::url($company,['object'=>$row->uuid]),s($row->title)).self::note(self::tr('policy_kind_'.$row->kind)),
                s(self::label_for($model['labels'],$row->ownertype,$row->owneruuid)),
                s(self::label_for($model['labels'],$row->scopetype,$row->scopeuuid)),
                self::badge($row->status).self::note(self::tr('pcu_revision',$row->revision)),s(userdate($row->timecreated))];
        }
        $out .= self::table(array_map([self::class,'tr'],['policy_code','policy_name','policy_owner','policy_scope','policy_status','pcu_updated']),$rows,self::tr('policy_title'));
        return $out.self::pager($company,$model['total'],$model['page'],$model['pagesize'],$f);
    }
    /** Typed relation choices; explicitly retain an authorized old reference when it is inactive. */
    private static function options(array $rows, string $empty, array $values, array $labels, string $field): array {
        $options = [''=>$empty];
        if ($field === 'scoperef') { $options = ['company|'=>self::tr('policy_scope_company')]; }
        foreach ($rows as $row) { $options[$row['value']] = $row['label']; }
        $selected = (string)($values[$field] ?? '');
        if ($selected !== '' && !isset($options[$selected])) { $options[$selected] = $labels[$selected] ?? self::tr('pcu_reference_unavailable'); }
        return $options;
    }
    public static function schema(array $options, array $values, bool $editing): array {
        $main = ['title'=>['label'=>'policy_name','required'=>true,'maxlength'=>255]];
        if (!$editing) { $main['kind'] = ['label'=>'policy_kind','required'=>true,'options'=>['policy'=>self::tr('policy_kind_policy'),'directive'=>self::tr('policy_kind_directive')],'help'=>'pcu_kind_help']; }
        $main['purpose'] = ['label'=>'policy_purpose','type'=>'textarea','required'=>true,'maxlength'=>4000,'rows'=>4];
        return ['pcu_main'=>$main,
            'pcu_responsibility'=>[
                'ownerref'=>['label'=>'policy_owner','required'=>true,'options'=>self::options($options['owner'],self::tr('pcu_choose_owner'),$values,$options['labels'],'ownerref'),'lookup'=>'owner','help'=>'pcu_owner_help'],
                'scoperef'=>['label'=>'policy_scope','required'=>true,'options'=>self::options($options['scope'],'',$values,$options['labels'],'scoperef'),'lookup'=>'scope','help'=>'pcu_scope_help']],
            'pcu_publication'=>[
                'effectivefrom'=>['label'=>'policy_effectivefrom','type'=>'date','help'=>'pcu_date_help'],
                'effectiveto'=>['label'=>'policy_effectiveto','type'=>'date','help'=>'pcu_end_help'],
                'documenturl'=>['label'=>'pcu_document_url','type'=>'url','maxlength'=>2000,'help'=>'pcu_document_help','wide'=>true]],
            'pcu_basis_group'=>[
                'basis'=>['label'=>'policy_basis','type'=>'textarea','rows'=>3,'required'=>true,'maxlength'=>4000],
                'reason'=>['label'=>'pcu_change_reason','type'=>'textarea','rows'=>2,'maxlength'=>4000]]];
    }
    private static function control(string $name, array $spec, $value = '', string $error = ''): string {
        $id = 'pcu_'.$name; $desc = [];
        $attrs = ['id'=>$id,'name'=>$name,'class'=>'form-control'.($error !== ''?' is-invalid':'')];
        if (!empty($spec['required'])) { $attrs['required']='required'; }
        if (isset($spec['maxlength'])) { $attrs['maxlength']=$spec['maxlength']; }
        if (!empty($spec['help'])) { $desc[]=$id.'_help'; }
        if ($error !== '') { $desc[]=$id.'_error'; $attrs['aria-invalid']='true'; }
        if ($desc) { $attrs['aria-describedby']=implode(' ',$desc); }
        $out = \html_writer::label(s(self::tr($spec['label']).(!empty($spec['required'])?' *':'')),$id);
        if (isset($spec['options'])) { $out .= \html_writer::select($spec['options'],$name,$value,false,$attrs); }
        else if (($spec['type']??'')==='textarea') { $out .= \html_writer::tag('textarea',s((string)$value),$attrs+['rows'=>$spec['rows']??4]); }
        else { $out .= \html_writer::empty_tag('input',$attrs+['type'=>$spec['type']??'text','value'=>$value]); }
        if (!empty($spec['help'])) { $out .= \html_writer::div(s(self::tr($spec['help'])),'bms-pcu-note',['id'=>$id.'_help']); }
        if ($error !== '') { $out .= \html_writer::div(s($error),'invalid-feedback d-block',['id'=>$id.'_error']); }
        return \html_writer::div($out,'bms-pcu-control'.(($spec['type']??'')==='textarea'||!empty($spec['wide'])?' bms-pcu-wide':''));
    }
    public static function form(string $company, array $options, array $values, array $errors, array $proof, ?array $model = null): string {
        $row = $model['selected'] ?? null; $editing = $row !== null; $object = $editing ? $row->uuid : '';
        $out = self::link(self::url($company,$editing?['object'=>$object]:[]),self::tr($editing?'pcu_back_record':'pcu_back_list'));
        $out .= bms_workspace_ux_service::record_header(s(self::tr($editing?'pcu_edit':'pcu_create')),s(self::tr('pcu_save_help')));
        $out .= self::note(self::tr('policy_code').': '.($row?$row->code:self::tr('pcu_auto_code')));
        $body = self::hidden(['uuid'=>$company,'object'=>$object,'view'=>$editing?'edit':'create','action'=>'save','revision'=>$row->revision??0,'sesskey'=>sesskey(),'requestkey'=>$proof['requestkey']]);
        if ($editing) { $body .= self::hidden(['kind'=>$row->kind]); }
        foreach (self::schema($options,$values,$editing) as $title=>$fields) {
            $group = '';
            foreach ($fields as $name=>$spec) {
                $control = self::control($name,$spec,$values[$name]??'',$errors[$name]??'');
                if (!empty($spec['lookup'])) {
                    $target=$spec['lookup'];
                    $search = \html_writer::label(s(self::tr('pcu_search_reference')),'pcu_search_'.$target).
                        \html_writer::empty_tag('input',['id'=>'pcu_search_'.$target,'class'=>'form-control','type'=>'search','maxlength'=>200]).
                        \html_writer::tag('button',s(self::tr('pcu_find')),['type'=>'button','class'=>'btn btn-outline-secondary','data-policy-find'=>'1']).
                        \html_writer::tag('span','',['role'=>'status','aria-live'=>'polite','data-policy-result'=>'1']);
                    $control .= \html_writer::div($search,'bms-pcu-lookup',['data-policy-url'=>self::url($company,['view'=>'lookup','target'=>$target,'object'=>$object])->out(false),
                        'data-policy-select'=>'pcu_'.$name,'data-policy-error'=>self::tr('pcu_lookup_error'),'data-policy-found'=>self::tr('pcu_lookup_found')]);
                    $control = \html_writer::div($control,'bms-pcu-reference');
                }
                $group .= $control;
            }
            $body .= self::panel(self::tr($title),\html_writer::div($group,'bms-crm-field-grid bms-pcu-grid'));
        }
        $body .= self::note(self::tr('pcu_append_help'));
        $body .= \html_writer::div(\html_writer::tag('button',s(self::tr('pcu_save_draft')),['class'=>'btn btn-primary','type'=>'submit']).
            self::link(self::url($company,$editing?['object'=>$object]:[]),self::tr('pcu_cancel')),'bms-pcu-actions');
        $out .= \html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company)->out(false),'class'=>'bms-pcu-form']);
        return $out;
    }
    public static function errors(array $errors): string {
        if (!$errors) { return ''; }
        $out = \html_writer::tag('strong',s(self::tr('pcu_check_form')));
        foreach ($errors as $key=>$message) {
            $out .= \html_writer::tag('p',substr($key,0,1)==='_'?s($message):\html_writer::link('#pcu_'.$key,s($message)));
        }
        return \html_writer::div($out,'alert alert-danger',['role'=>'alert']);
    }
    public static function detail(string $company, array $model, string $tab): string {
        $row=$model['selected']; $current=$model['current']; $actions=$model['actions']; $p=['object'=>$row->uuid];
        $out=self::link(self::url($company),self::tr('pcu_back_list'));
        $buttons=$actions['edit']?self::link(self::url($company,$p+['view'=>'edit']),self::tr('pcu_edit'),true):'';
        $secondary='';
        foreach (['publish','newversion','withdraw'] as $operation) {
            if ($actions[$operation]) { $secondary.=\html_writer::link(self::url($company,$p+['view'=>'action','operation'=>$operation]),s(self::action_label($operation))); }
        }
        if ($secondary) { $buttons.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pcu_actions')),['class'=>'btn btn-outline-secondary']).
            \html_writer::div($secondary,'bms-crm-action-menu-panel bms-pcu-menu-items'),['class'=>'bms-crm-action-menu bms-pcu-menu']); }
        $meta=self::badge($row->status).' '.s(self::tr('pcu_revision',$row->revision));
        $out.=bms_workspace_ux_service::record_header(s($row->title),s(self::tr('policy_kind_'.$row->kind)),s($row->code),$meta,$buttons);
        if ((int)$row->revision !== (int)$current->revision) {
            $out.=\html_writer::div(s(self::tr('pcu_historical')).' '.self::link(self::url($company,$p),self::tr('pcu_open_current')),'bms-pcu-notice');
        } else {
            $next=$row->status==='draft' ? ($model['can_manage']?'pcu_next_draft':'pcu_next_reader') : ($row->status==='published'?'pcu_next_published':'pcu_next_withdrawn');
            $out.=\html_writer::div(\html_writer::tag('strong',s(self::tr('pcu_next_step'))).self::note(self::tr($next)),'bms-pcu-notice');
        }
        if ($model['can_manage'] && $current->status==='draft' && $model['published']) {
            $out.=self::note(self::tr('pcu_draft_does_not_replace')).self::link(self::url($company,$p+['rev'=>$model['published']->revision]),self::tr('pcu_open_published'));
        }
        $tabs='';
        foreach (['overview'=>'pcu_overview','links'=>'pcu_links','revisions'=>'pcu_revisions','history'=>'pcu_history'] as $id=>$key) {
            $attrs=['class'=>'bms-pcu-tab'.($tab===$id?' is-active':'')]; if($tab===$id){$attrs['aria-current']='page';}
            $tabs.=\html_writer::link(self::url($company,$p+['rev'=>$row->revision,'tab'=>$id]),s(self::tr($key)),$attrs);
        }
        $out.=\html_writer::tag('nav',$tabs,['class'=>'bms-pcu-tabs','aria-label'=>self::tr('pcu_record_sections')]);
        if ($tab==='overview') {
            $body=self::field(self::tr('policy_purpose'),$row->purpose).self::field(self::tr('policy_basis'),$row->basis);
            $out.=self::panel(self::tr('pcu_content'),\html_writer::tag('dl',$body,['class'=>'bms-pcu-grid']));
            $today=(new \DateTimeImmutable('now',\core_date::get_user_timezone_object()))->format('Y-m-d');
            $period=self::tr('pcu_period_'.policy_service::period_state($row,$today));
            $body=self::field(self::tr('policy_owner'),self::label_for($model['labels'],$row->ownertype,$row->owneruuid)).
                self::field(self::tr('policy_scope'),self::label_for($model['labels'],$row->scopetype,$row->scopeuuid)).
                self::field(self::tr('policy_effectivefrom'),$row->effectivefrom).self::field(self::tr('policy_effectiveto'),$row->effectiveto?:self::tr('policy_indefinite'));
            $out.=self::panel(self::tr('pcu_responsibility'),\html_writer::tag('dl',$body,['class'=>'bms-pcu-grid']).self::note($period).self::note(self::tr('pcu_scope_help')));
            $document=self::note(self::tr('pcu_no_document'));
            if ($row->documenturl !== '' && policy_service::safe_url($row->documenturl)) {
                $document=\html_writer::link($row->documenturl,s(self::tr('pcu_open_document')),['class'=>'btn btn-outline-primary','target'=>'_blank','rel'=>'noopener noreferrer']).self::note(self::tr('pcu_external_note'));
            }
            $out.=self::panel(self::tr('pcu_document'),$document);
        } else if ($tab==='links') {
            $out.=self::note(self::tr('pcu_links_help')); $rows=[];
            foreach($model['links'] as $link){
                $rows[]=[s($link->code),\html_writer::link(new \moodle_url('/local/kbacompany/administrative_scale.php',['uuid'=>$company,'detailuuid'=>$link->uuid,'viewkind'=>$link->kind]),s($link->title)),
                    s(self::tr('adminscale_status_'.$link->status)),s(self::tr('pcu_revision',$link->sourcerevision))];
            }
            $out.=$rows?self::table(array_map([self::class,'tr'],['policy_code','policy_name','policy_status','pcu_source_revision']),$rows,self::tr('pcu_links')):self::panel(self::tr('pcu_links'),self::note(self::tr('pcu_no_links')));
            $out.=self::pager($company,$model['linktotal'],$model['page'],$model['pagesize'],$p+['rev'=>$row->revision,'tab'=>$tab]);
        } else {
            $out.=self::note(self::tr('pcu_history_help')); $rows=[]; $timeline='';
            foreach($model['history'] as $item){
                $actor=trim((string)$item->firstname.' '.(string)$item->lastname) ?: self::tr('pcu_unknown_actor');
                $name=self::tr('pcu_revision',$item->revision);
                $url=self::url($company,$p+['rev'=>$item->revision]);
                $rows[]=[\html_writer::link($url,s($name)),self::badge($item->status),s($actor),s(userdate($item->timecreated)),nl2br(s($item->reason?:self::tr('pcu_no_reason')))];
                $timeline.=\html_writer::tag('article',\html_writer::link($url,s($name)).' '.self::badge($item->status).
                    self::note($actor.' · '.userdate($item->timecreated)).\html_writer::tag('p',nl2br(s($item->reason?:self::tr('pcu_no_reason')))),['class'=>'bms-pcu-history-item']);
            }
            $out.=$tab==='revisions'?self::table(array_map([self::class,'tr'],['policy_revision','policy_status','pcu_author','policy_recorddate','pcu_change_reason']),$rows,self::tr('pcu_revisions')):$timeline;
            $out.=self::pager($company,$model['total'],$model['page'],$model['pagesize'],$p+['rev'=>$row->revision,'tab'=>$tab]);
        }
        if($model['can_manage']){
            $out.=\html_writer::tag('details',\html_writer::tag('summary',s(self::tr('pcu_technical'))).
                \html_writer::tag('p',s('UUID: '.$row->uuid)).\html_writer::tag('p',s(self::tr('pcu_append_help'))),['class'=>'bms-pcu-advanced']);
        }
        return $out;
    }
    public static function action(string $company, array $model, string $operation, array $values, array $proof, array $errors = []): string {
        $row=$model['selected']; $params=['object'=>$row->uuid];
        $out=self::link(self::url($company,$params),self::tr('pcu_back_record'));
        $out.=bms_workspace_ux_service::record_header(s(self::action_label($operation)),s($row->code.' · '.$row->title));
        $body=self::hidden(['uuid'=>$company,'object'=>$row->uuid,'view'=>'action','action'=>$operation,'operation'=>$operation,'revision'=>$row->revision,'sesskey'=>sesskey(),'requestkey'=>$proof['requestkey']]);
        $body.=self::note(self::tr('pcu_confirm_'.$operation));
        if ($operation==='publish') { $body.=self::note(self::tr('policy_publishhelp')); }
        $body.=self::control('reason',['label'=>'pcu_action_reason','required'=>true,'type'=>'textarea','rows'=>4,'maxlength'=>4000],$values['reason']??'', $errors['reason']??'');
        $body.=\html_writer::div(\html_writer::empty_tag('input',['id'=>'pcu_confirm','type'=>'checkbox','name'=>'confirmed','value'=>1,'required'=>'required']).
            \html_writer::label(s(self::tr('pcu_confirmation')),'pcu_confirm'),'bms-pcu-confirm');
        $body.=\html_writer::div(\html_writer::tag('button',s(self::action_label($operation)),['type'=>'submit','class'=>'btn '.($operation==='withdraw'?'btn-outline-danger':'btn-primary')]).
            self::link(self::url($company,$params),self::tr('pcu_cancel')),'bms-pcu-actions');
        return $out.\html_writer::tag('form',self::panel(self::tr('pcu_action_review'),$body),['method'=>'post','action'=>self::url($company)->out(false),'class'=>'bms-pcu-action-form']);
    }
}
