<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS-UX-CRUD-001 document presentation. Domain authorization and writes stay in document_service. */
class document_ui_service {
    public static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }
    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/documents.php', ['uuid'=>$company]+$params);
    }
    private static function note(string $text): string { return \html_writer::tag('p', s($text), ['class'=>'bms-dcu-note']); }
    public static function link($url, string $label, bool $primary = false): string {
        return \html_writer::link($url, s($label), ['class'=>'btn '.($primary ? 'btn-primary' : 'btn-outline-secondary')]);
    }
    public static function hidden(array $values): string {
        $html = '';
        foreach ($values as $key=>$value) { $html .= \html_writer::empty_tag('input', ['type'=>'hidden','name'=>$key,'value'=>$value]); }
        return $html;
    }
    public static function badge(string $status): string {
        if (!in_array($status, document_service::STATES, true)) { return s($status); }
        return \html_writer::tag('span', s(self::tr('document_status_'.$status)), ['class'=>'bms-dcu-badge bms-dcu-'.$status]);
    }
    private static function field(string $label, string $value): string {
        return \html_writer::div(\html_writer::tag('dt', s($label)).\html_writer::tag('dd', nl2br(s($value !== '' ? $value : self::tr('dcu_not_set')))), 'bms-dcu-field');
    }
    public static function type_options(): array {
        $options = [];
        foreach (document_service::TYPE_LEVELS as $type=>$level) { $options[$type] = self::tr('document_type_'.$type); }
        return $options;
    }
    private static function positions(array $options, bool $all = false, string $selected = ''): array {
        $out = [''=>self::tr($all ? 'dcu_all_owners' : 'document_noowner')];
        foreach ($options['positions'] as $position) {
            if ($all || $position->active || $position->uuid === $selected) {
                $out[$position->uuid] = trim($position->code.' · '.$position->name, ' ·').(!$position->active ? ' · '.self::tr('dcu_inactive') : '');
            }
        }
        return $out;
    }
    public static function action_label(string $action): string {
        $map = ['addreviewer'=>'document_addreviewer','submitreview'=>'document_submitreview','returndraft'=>'document_returndraft',
            'approve'=>'document_approve','publish'=>'document_publish','activate'=>'document_activate','newrevision'=>'document_newrevision',
            'cancel'=>'document_cancel','archive'=>'document_archive','requireack'=>'document_requireack','acknowledge'=>'dcu_acknowledge',
            'createrecord'=>'document_recordcreate','reviewapprove'=>'document_reviewapprove','reviewreject'=>'document_reviewreject','createstp'=>'document_controlstp'];
        return self::tr($map[$action] ?? 'dcu_action');
    }
    public static function listing(string $company, array $model, array $filters, array $options): string {
        $actions = $model['can_manage'] ? self::link(self::url($company, ['view'=>'create']), self::tr('dcu_create'), true) : '';
        $html = bms_workspace_ux_service::record_header(self::tr('document_title'), self::tr('dcu_intro'), '', '', $actions);
        $states = [''=>self::tr('dcu_all_states')];
        foreach (document_service::STATES as $state) { $states[$state] = self::tr('document_status_'.$state); }
        $form = self::hidden(['uuid'=>$company]);
        $form .= self::control('q', ['label'=>'dcu_search','type'=>'search','maxlength'=>200], $filters['q'], '');
        $form .= self::control('status', ['label'=>'document_status','options'=>$states], $filters['status'], '');
        $form .= self::control('doctype', ['label'=>'document_type','options'=>[''=>self::tr('dcu_all_types')]+self::type_options()], $filters['doctype'], '');
        $form .= self::control('owner', ['label'=>'document_owner','options'=>self::positions($options, true)], $filters['owner'], '');
        $form .= \html_writer::div(\html_writer::tag('button', self::tr('dcu_apply'), ['type'=>'submit','class'=>'btn btn-primary']).self::link(self::url($company), self::tr('dcu_reset')), 'bms-dcu-actions');
        $html .= \html_writer::tag('form', $form, ['action'=>self::url($company)->out(false),'method'=>'get','class'=>'bms-dcu-filters']);
        $html .= self::note(self::tr($model['can_manage'] ? 'dcu_list_manager' : 'dcu_list_reader'));
        if (!$model['total']) {
            return $html.\html_writer::div(\html_writer::tag('h2', self::tr(array_filter($filters) ? 'dcu_no_matches' : 'document_empty')).
                self::note(self::tr(array_filter($filters) ? 'dcu_no_matches_help' : ($model['can_manage'] ? 'dcu_empty_help' : 'dcu_empty_reader'))), 'bms-crm-empty');
        }
        $html .= \html_writer::tag('p', self::tr('dcu_found', $model['total']));
        $table = new \html_table(); $table->attributes['class'] = 'table bms-crm-list-table';
        $table->head = array_map(static function($key) { return self::tr($key); },
            ['document_code','document_name','document_owner','dcu_selected_revision','document_current','dcu_updated']);
        foreach ($model['rows'] as $row) {
            $effective = (int)$row->effectiverevision;
            $table->data[] = [s($row->code),
                \html_writer::link(self::url($company, ['document'=>$row->uuid]), s($row->title)).self::note(self::tr('document_type_'.$row->doctype)),
                s($row->ownername ?: self::tr('document_noowner')),
                self::note(self::tr('dcu_revision_number', $row->revision)).self::badge($row->status),
                $effective ? \html_writer::link(self::url($company, ['document'=>$row->uuid,'rev'=>$effective]), self::tr('dcu_revision_number',$effective)) : self::tr('dcu_not_effective'),
                s(userdate($row->timemodified))];
        }
        $html .= self::table($table, self::tr('document_masterlist'));
        return $html.self::pager($company, $model, $filters);
    }
    private static function pager(string $company, array $model, array $params): string {
        if ($model['total'] <= $model['pagesize']) { return ''; }
        $html = '';
        if ($model['page'] > 0) { $html .= self::link(self::url($company, $params+['page'=>$model['page']-1]), self::tr('dcu_previous')); }
        $html .= self::note(self::tr('dcu_page', (object)['page'=>$model['page']+1,'pages'=>(int)ceil($model['total']/$model['pagesize'])]));
        if (($model['page']+1)*$model['pagesize'] < $model['total']) { $html .= self::link(self::url($company,$params+['page'=>$model['page']+1]),self::tr('dcu_next')); }
        return \html_writer::tag('nav', $html, ['class'=>'bms-dcu-actions','aria-label'=>self::tr('dcu_pagination')]);
    }
    private static function table(\html_table $table, string $label): string {
        return \html_writer::div(\html_writer::table($table), 'bms-dcu-table-wrap', ['tabindex'=>'0','role'=>'region','aria-label'=>$label]);
    }
    /** Record-specific field schema, shared by create/edit. */
    public static function schema(array $options, array $values, bool $manual): array {
        $main = ['title'=>['label'=>'dcu_name','required'=>true,'maxlength'=>255],
            'ownerpositionuuid'=>['label'=>'document_owner','options'=>self::positions($options, false, $values['ownerpositionuuid'] ?? ''),'help'=>'dcu_owner_help']];
        if ($manual) { $main = ['title'=>$main['title'],'doctype'=>['label'=>'document_type','options'=>self::type_options(),'required'=>true]] + $main; }
        $schema = ['dcu_main'=>$main];
        if ($manual) { $schema['document_content'] = ['content'=>['label'=>'document_content','type'=>'textarea','rows'=>12,'maxlength'=>100000,'help'=>'dcu_content_help']]; }
        $schema['dcu_change'] = ['changereason'=>['label'=>'document_changereason','type'=>'textarea','rows'=>3,'maxlength'=>4000]];
        if ($manual) { $schema['dcu_additional'] = ['doclevel'=>['label'=>'dcu_other_level','options'=>[1=>'1',2=>'2',3=>'3',4=>'4',5=>'5'],'help'=>'dcu_level_help']]; }
        return $schema;
    }
    private static function control(string $name, array $spec, $value, string $error): string {
        $id = 'dcu_'.$name;
        $label = self::tr($spec['label']).(!empty($spec['required']) ? ' *' : '');
        $attrs = ['id'=>$id,'name'=>$name,'class'=>'form-control'.($error !== '' ? ' is-invalid' : '')];
        if (!empty($spec['required'])) { $attrs['required'] = 'required'; }
        if (isset($spec['maxlength'])) { $attrs['maxlength'] = $spec['maxlength']; }
        $descriptions = [];
        if (!empty($spec['help'])) { $descriptions[] = $id.'_help'; }
        if ($error !== '') { $attrs['aria-invalid'] = 'true'; $descriptions[] = $id.'_error'; }
        if ($descriptions) { $attrs['aria-describedby'] = implode(' ', $descriptions); }
        $html = \html_writer::label(s($label), $id);
        if (isset($spec['options'])) { $html .= \html_writer::select($spec['options'],$name,$value,false,$attrs); }
        else if (($spec['type'] ?? '') === 'textarea') { $html .= \html_writer::tag('textarea', s((string)$value), $attrs+['rows'=>$spec['rows'] ?? 4]); }
        else { $html .= \html_writer::empty_tag('input', $attrs+['type'=>$spec['type'] ?? 'text','value'=>$value]); }
        if (!empty($spec['help'])) { $html .= \html_writer::div(s(self::tr($spec['help'])), 'bms-dcu-note', ['id'=>$id.'_help']); }
        if ($error !== '') { $html .= \html_writer::div(s($error), 'invalid-feedback d-block', ['id'=>$id.'_error']); }
        return \html_writer::div($html, 'bms-dcu-control'.(($spec['type'] ?? '') === 'textarea' ? ' bms-dcu-wide' : ''));
    }
    public static function form(string $company, array $options, array $values, array $errors, array $proof, ?array $model = null): string {
        $doc = $model['selected'] ?? null; $manual = !$doc || $doc['source_kind'] === 'manual';
        $html = bms_workspace_ux_service::record_header(self::tr($doc ? 'dcu_edit' : 'dcu_create'), self::tr('dcu_form_help'));
        $html .= self::note(self::tr('dcu_code_help').': '.($doc ? $doc['code'] : self::tr('dcu_auto_code')));
        if ($doc && !$manual) { $html .= \html_writer::div(self::tr('document_stpsnapshotnotice'),'alert alert-info'); }
        if ($errors) { $html .= \html_writer::div(self::tr('dcu_form_errors'), 'alert alert-danger', ['role'=>'alert']); }
        if (!empty($errors['_form'])) { $html .= \html_writer::div(s($errors['_form']), 'alert alert-danger'); }
        $body = self::hidden($proof+['uuid'=>$company,'sesskey'=>sesskey(),'document'=>$doc['uuid'] ?? '',
            'action'=>$doc ? 'save' : 'create','revision'=>$doc['revision'] ?? 0]);
        foreach (self::schema($options,$values,$manual) as $heading=>$fields) {
            $controls = '';
            foreach ($fields as $name=>$spec) { $controls .= self::control($name,$spec,$values[$name] ?? '',$errors[$name] ?? ''); }
            $group = \html_writer::div($controls, 'bms-crm-field-grid');
            if ($heading === 'dcu_additional') {
                $attrs = ['class'=>'bms-crm-section']; if (isset($errors['doctype'])) { $attrs['open'] = 'open'; }
                $body .= \html_writer::tag('details', \html_writer::tag('summary',self::tr($heading)).$group,$attrs);
            } else { $body .= \html_writer::tag('section',\html_writer::tag('h2',self::tr($heading),['class'=>'bms-crm-section-title']).$group,['class'=>'bms-crm-section']); }
            if ($heading === 'dcu_main') { $body .= self::upload_control($model['file'] ?? null, $errors, $values); }
        }
        $body .= \html_writer::div(\html_writer::tag('button',self::tr('dcu_save_draft'),['type'=>'submit','class'=>'btn btn-primary']).
            self::link(self::url($company,$doc ? ['document'=>$doc['uuid'],'rev'=>$doc['revision']] : []),self::tr('dcu_cancel')), 'bms-crm-form-actions');
        return $html.\html_writer::tag('form',$body,['method'=>'post','action'=>self::url($company)->out(false),'class'=>'bms-crm-form','enctype'=>'multipart/form-data','novalidate'=>'novalidate']);
    }
    /** File input is deliberately native and works without JavaScript or an external document editor. */
    private static function upload_control(?array $file, array $errors, array $values): string {
        $body = \html_writer::tag('h2', self::tr('dcf_file'), ['class'=>'bms-crm-section-title']);
        if ($file) { $body .= self::file_card($file); }
        $id = 'dcu_documentfile';
        $error = $errors['documentfile'] ?? '';
        $attrs = ['type'=>'file','name'=>'documentfile','id'=>$id,'class'=>'bms-dcf-input',
            'accept'=>'.'.implode(',.', array_keys(document_upload::TYPES)),
            'aria-describedby'=>$id.'_help'.($error !== '' ? ' '.$id.'_error' : '')];
        if ($error !== '') { $attrs['aria-invalid'] = 'true'; }
        $body .= \html_writer::label(self::tr($file ? 'dcf_replace' : 'dcf_upload'), $id).
            \html_writer::empty_tag('input', $attrs);
        $body .= \html_writer::div(s(self::tr('dcf_upload_help', display_size(document_upload::max_bytes()))),
            'bms-dcu-note', ['id'=>$id.'_help']);
        if ($file) {
            $checked = !empty($values['confirmfile']) ? ['checked'=>'checked'] : [];
            $body .= \html_writer::tag('label', \html_writer::empty_tag('input',
                ['type'=>'checkbox','name'=>'confirmfile','value'=>1]+$checked).' '.s(self::tr('dcf_confirm_replace')),
                ['class'=>'bms-dcf-confirm']);
        }
        if ($error !== '') { $body .= \html_writer::div(s($error), 'invalid-feedback d-block', ['id'=>$id.'_error']); }
        if (!empty($errors['_file_retry'])) { $body .= \html_writer::div(s($errors['_file_retry']), 'alert alert-warning', ['role'=>'alert']); }
        return \html_writer::tag('section', $body, ['class'=>'bms-crm-section bms-dcf-upload']);
    }

    private static function file_card(?array $file, bool $compact = false): string {
        if (!$file) { return self::note(self::tr('dcf_no_file')); }
        $name = s($file['filename']);
        $meta = display_size($file['bytes']).' · '.userdate($file['uploadedat']);
        $download = !empty($file['available']) ? \html_writer::link($file['url'], self::tr('dcf_download'),
            ['class'=>'btn btn-outline-secondary','aria-label'=>self::tr('dcf_download_named', $file['filename'])]) :
            \html_writer::div(self::tr('dcf_missing'), 'text-danger', ['role'=>'status']);
        $body = \html_writer::div(\html_writer::tag('strong',$name).self::note($meta), 'bms-dcf-info').$download;
        return \html_writer::div($body, 'bms-dcf-card'.($compact ? ' bms-dcf-compact' : ''));
    }

    public static function tabs(array $model): array {
        $tabs = ['overview'];
        if ($model['can_manage'] || $model['selected']['status'] === 'review') { $tabs[] = 'reviews'; }
        $tabs[] = 'acknowledgements'; $tabs[] = 'revisions';
        if ($model['selected']['type'] === 'form') { array_splice($tabs,count($tabs)-1,0,['records']); }
        if ($model['can_manage']) { $tabs[] = 'history'; }
        return $tabs;
    }
    public static function detail(string $company, array $model, string $tab, ?array $panel = null): string {
        $doc = $model['selected']; $params = ['document'=>$doc['uuid'],'rev'=>$doc['revision']];
        $actions = $model['can_edit'] ? self::link(self::url($company,$params+['view'=>'edit']),self::tr('dcu_edit'),true) : '';
        if ($model['actions']) {
            $links = '';
            foreach ($model['actions'] as $action) { $links .= \html_writer::link(self::url($company,$params+['view'=>'action','op'=>$action]),s(self::action_label($action))); }
            $actions .= \html_writer::tag('details',\html_writer::tag('summary',self::tr('dcu_actions'),['class'=>'btn btn-outline-secondary']).
                \html_writer::div($links,'bms-crm-action-menu-panel'),['class'=>'bms-crm-action-menu']);
        }
        $html = self::link(self::url($company),self::tr('dcu_back'));
        $meta = self::badge($doc['status']).' '.s(self::tr('dcu_revision_number',$doc['revision'])).
            self::note(self::tr('document_owner').': '.($model['owner_name'] ?: self::tr('document_noowner')));
        $html .= bms_workspace_ux_service::record_header(s($doc['title']),self::tr('document_type_'.$doc['type']),s($doc['code']),$meta,$actions);
        if (!$model['is_latest'] && $model['can_manage']) {
            $html .= \html_writer::div(self::tr('dcu_historical').' '.self::link(self::url($company,['document'=>$doc['uuid']]),self::tr('dcu_open_working')), 'alert alert-info');
        }
        if ($model['effective_revision'] && $model['effective_revision'] !== $doc['revision']) {
            $html .= \html_writer::div(self::tr('dcu_effective_notice').' '.\html_writer::link(self::url($company,['document'=>$doc['uuid'],'rev'=>$model['effective_revision']]),
                self::tr('dcu_revision_number',$model['effective_revision'])), 'bms-crm-trace');
        }
        if ($doc['source_outdated']) { $html .= \html_writer::div(self::tr('document_sourceoutdated'),'alert alert-warning'); }
        $tabs = '';
        foreach (self::tabs($model) as $key) {
            $attrs = ['class'=>'bms-dcu-tab'.($key === $tab ? ' is-active' : '')];
            if ($key === $tab) { $attrs['aria-current'] = 'page'; }
            $tabs .= \html_writer::link(self::url($company,$params+['tab'=>$key]),self::tr('dcu_tab_'.$key),$attrs);
        }
        $html .= \html_writer::tag('nav',$tabs,['class'=>'bms-dcu-tabs','aria-label'=>self::tr('dcu_record_nav')]);
        if ($tab !== 'overview') { return $html.self::subpanel($company,$model,$tab,$panel); }
        $html .= \html_writer::div(\html_writer::tag('strong',self::tr('dcu_next_step')).self::note(self::tr('dcu_next_'.$doc['status'].(!$model['can_manage'] && in_array($doc['status'],['review','effective'],true) ? '_reader' : ''))), 'bms-crm-trace');
        $fields = self::field(self::tr('document_owner'),$model['owner_name'] ?: self::tr('document_noowner')).
            self::field(self::tr('document_level'),(string)$doc['level']).
            self::field(self::tr('document_source'),self::tr('dcu_source_'.$doc['source_kind'])).
            self::field(self::tr('document_effectivefrom'),$doc['effective_from'] ? userdate($doc['effective_from']) : '').
            self::field(self::tr('dcu_updated'),userdate($doc['time_modified'])).
            self::field(self::tr('document_changereason'),(string)$doc['change_reason']);
        $html .= \html_writer::tag('section',\html_writer::tag('h2',self::tr('dcu_main'),['class'=>'bms-crm-section-title']).
            \html_writer::tag('dl',$fields,['class'=>'bms-crm-detail-grid']),['class'=>'bms-crm-section']);
        $html .= \html_writer::tag('section', \html_writer::tag('h2', self::tr('dcf_file'), ['class'=>'bms-crm-section-title']).
            self::file_card($model['file'] ?? null).self::note(self::tr('dcf_version_help')), ['class'=>'bms-crm-section']);
        $content = $doc['source_kind'] === 'stp' ? self::snapshot($doc) : \html_writer::div(nl2br(s((string)$doc['content'])), 'bms-dcu-content');
        if (trim((string)$doc['content']) === '') { $content = self::note(self::tr('dcu_no_content')); }
        $html .= \html_writer::tag('section',\html_writer::tag('h2',self::tr('document_content'),['class'=>'bms-crm-section-title']).$content,['class'=>'bms-crm-section']);
        $technical = self::field('UUID',$doc['uuid']).self::field(self::tr('document_sourcehash'),$doc['source_hash']);
        if ($doc['source_kind'] === 'stp') { $technical .= self::field(self::tr('dcu_source_revision'),(string)$doc['source_revision']).self::field(self::tr('dcu_source_uuid'),$doc['source_uuid']); }
        return $html.\html_writer::tag('details',\html_writer::tag('summary',self::tr('dcu_technical')).\html_writer::tag('dl',$technical,['class'=>'bms-crm-detail-grid']),['class'=>'bms-crm-section']);
    }
    public static function source_preview(array $preview): string {
        return \html_writer::tag('section', \html_writer::tag('h2',self::tr('dcu_source_revision').': '.(int)$preview['revision']).
            self::snapshot(['content'=>$preview['content']]), ['class'=>'bms-crm-section']);
    }

    private static function snapshot(array $doc): string {
        $data = json_decode((string)$doc['content'],true);
        if (!is_array($data)) { return self::note(self::tr('dcu_invalid_snapshot')); }
        $html = self::note(self::tr('document_stpsnapshotnotice'));
        foreach (['purpose'=>'process_purpose','scope'=>'procmodel_scope','trigger'=>'procmodel_trigger'] as $field=>$label) {
            $html .= \html_writer::tag('h3',self::tr($label)).\html_writer::div(nl2br(s((string)($data[$field] ?? ''))),'bms-dcu-content');
        }
        foreach ($data['steps'] ?? [] as $step) {
            $html .= \html_writer::tag('article',\html_writer::tag('h3',s(($step['number'] ?? '').'. '.($step['activity'] ?? ''))).
                self::note((string)($step['action'] ?? '')).self::note(self::tr('process_product').': '.($step['product'] ?? '')),
                ['class'=>'bms-crm-section']);
        }
        // Preserve full immutable source information without presenting DB identifiers as ordinary fields.
        $html .= \html_writer::tag('details',\html_writer::tag('summary',self::tr('dcu_snapshot_full')).
            \html_writer::tag('pre',s(json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))),['class'=>'bms-dcu-snapshot']);
        return $html;
    }
    private static function subpanel(string $company, array $model, string $tab, array $panel): string {
        $doc = $model['selected'];
        $html = \html_writer::tag('h2',self::tr('dcu_tab_'.$tab).' · '.(int)$panel['total'],['class'=>'bms-dcu-panel-title']);
        $html .= self::note(self::tr('dcu_panel_'.$tab));
        if (!$panel['rows']) { return $html.\html_writer::div(self::tr('dcu_panel_empty'),'bms-crm-empty'); }
        $table = new \html_table(); $table->attributes['class'] = 'table';
        if ($tab === 'revisions') {
            $table->head = [self::tr('document_revision'),self::tr('document_status'),self::tr('dcf_file'),self::tr('document_changereason'),self::tr('dcu_updated')];
            $table->attributes['class'] .= ' bms-dcf-versions';
            foreach ($panel['rows'] as $row) {
                $cells = [\html_writer::link(self::url($company,['document'=>$doc['uuid'],'rev'=>$row->revision]),
                    self::tr('dcu_revision_number',$row->revision)), self::badge($row->status),
                    self::file_card($row->file ?? null, true), nl2br(s($row->changereason)), s(userdate($row->timemodified))];
                foreach ($cells as $i => $value) {
                    $cells[$i] = \html_writer::tag('span', $table->head[$i], ['class'=>'bms-dcf-cell-label']).$value;
                }
                $table->data[] = $cells;
            }
        } else if ($tab === 'reviews') {
            $table->head = [self::tr('document_reviewer'),self::tr('document_round'),self::tr('document_stage'),self::tr('document_decision'),self::tr('document_comment')];
            foreach ($panel['rows'] as $row) { $table->data[] = [s(fullname($row)),(int)$row->reviewround,self::tr('dcu_stage_'.$row->stage),self::tr('dcu_decision_'.$row->decision),nl2br(s($row->comment))]; }
        } else if ($tab === 'acknowledgements') {
            $table->head = [self::tr('document_person'),self::tr('document_status'),self::tr('document_date')];
            foreach ($panel['rows'] as $row) { $table->data[] = [s(fullname($row)),self::tr('document_ack_'.$row->status),$row->timeacknowledged ? s(userdate($row->timeacknowledged)) : '—']; }
        } else if ($tab === 'records') {
            $table->head = [self::tr('document_code'),self::tr('document_recordtitle'),self::tr('document_person'),self::tr('document_date')];
            foreach ($panel['rows'] as $row) {
                $table->data[] = [s($row->code),\html_writer::tag('details',\html_writer::tag('summary',s($row->title)).
                    \html_writer::div(nl2br(s($row->payload)),'bms-dcu-content')),s(fullname($row)),s(userdate($row->timecreated))];
            }
        } else if ($tab === 'history') {
            $table->head = [self::tr('document_date'),self::tr('document_person'),self::tr('dcu_action')];
            foreach ($panel['rows'] as $row) { $key = 'dcu_log_'.substr($row->action,4); $label = get_string_manager()->string_exists($key,'local_kbacompany') ? self::tr($key) : self::tr('dcu_log_other');
                $table->data[] = [s(userdate($row->timecreated)),s(fullname($row)),s($label)]; }
        }
        $html .= self::table($table,self::tr('dcu_tab_'.$tab));
        return $html.self::pager($company,$panel,['document'=>$doc['uuid'],'rev'=>$doc['revision'],'tab'=>$tab]);
    }
    public static function action_form(string $company, array $model, string $action, array $options,
            array $values, array $errors, array $proof): string {
        $doc = $model['selected'];
        $html = bms_workspace_ux_service::record_header(self::action_label($action),s($doc['code'].' · '.$doc['title']), '',
            self::badge($doc['status']).' '.self::tr('dcu_revision_number',$doc['revision']));
        $html .= self::note(self::tr('dcu_action_help_'.$action));
        if ($errors) { $html .= \html_writer::div(self::tr('dcu_form_errors'),'alert alert-danger',['role'=>'alert']); }
        if (isset($errors['_form'])) { $html .= \html_writer::div(s($errors['_form']),'alert alert-danger'); }
        $form = self::hidden($proof+['uuid'=>$company,'document'=>$doc['uuid'],'revision'=>$doc['revision'],'action'=>$action,'sesskey'=>sesskey()]);
        $fields = [];
        if (in_array($action,['addreviewer','requireack'],true)) {
            $members = [0=>self::tr('dcu_choose_person')]; foreach ($options['members'] as $member) { $members[$member['userid']] = $member['name']; }
            $fields[$action === 'addreviewer' ? 'reviewerid' : 'userid'] = ['label'=>$action === 'addreviewer' ? 'document_reviewer' : 'document_person','options'=>$members,'required'=>true];
        }
        if (in_array($action,['newrevision','cancel'],true)) { $fields['reason'] = ['label'=>'document_changereason','type'=>'textarea','required'=>true,'maxlength'=>4000]; }
        if (in_array($action,['approve','reviewapprove','reviewreject'],true)) { $fields['comment'] = ['label'=>'document_comment','type'=>'textarea','required'=>$action === 'reviewreject','maxlength'=>4000]; }
        if ($action === 'publish') { $fields['effectivedate'] = ['label'=>'document_effectivefrom','type'=>'date','help'=>'dcu_date_help']; }
        if ($action === 'createrecord') {
            $fields['title'] = ['label'=>'document_recordtitle','required'=>true,'maxlength'=>255];
            $fields['payload'] = ['label'=>'document_recordpayload','type'=>'textarea','required'=>true,'maxlength'=>100000,'rows'=>8];
        }
        if ($action === 'createstp') { $fields['changereason'] = ['label'=>'document_changereason','type'=>'textarea','maxlength'=>4000]; }
        foreach ($fields as $name=>$spec) { $form .= self::control($name,$spec,$values[$name] ?? '',$errors[$name] ?? ''); }
        $form .= \html_writer::tag('label',\html_writer::empty_tag('input',['type'=>'checkbox','name'=>'confirmed','value'=>1,'required'=>'required']).
            ' '.s(self::tr($action === 'acknowledge' ? 'document_acknowledge' : 'dcu_confirm')),['class'=>'bms-dcu-confirm']);
        $form .= \html_writer::div(\html_writer::tag('button',s(self::action_label($action)),['type'=>'submit','class'=>'btn '.(in_array($action,['cancel','archive','reviewreject'],true) ? 'btn-danger' : 'btn-primary')]).
            self::link(self::url($company,$doc['uuid'] !== '' ? ['document'=>$doc['uuid'],'rev'=>$doc['revision']] : []),self::tr('dcu_cancel')),'bms-dcu-actions');
        return $html.\html_writer::tag('form',$form,['action'=>self::url($company)->out(false),'method'=>'post','class'=>'bms-crm-section bms-dcu-action-form','novalidate'=>'novalidate']);
    }
}
