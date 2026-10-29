<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS-UX-CRUD-001 presentation schema and renderers. No data writes or permission grants. */
class management_cycle_ui_service {
    private static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }
    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/management_cycles.php', ['uuid'=>$company]+$params);
    }
    private static function link($url, string $label, bool $primary = false): string {
        return \html_writer::link($url, s($label), ['class'=>'btn '.($primary ? 'btn-primary' : 'btn-outline-secondary')]);
    }
    private static function note(string $text): string { return \html_writer::tag('p', s($text), ['class'=>'bms-mcycle-note']); }
    private static function field(string $label, string $value): string {
        return \html_writer::div(\html_writer::tag('dt', s($label)).\html_writer::tag('dd', nl2br(s($value !== '' ? $value : self::tr('mcu_not_set')))), 'bms-mcycle-field');
    }
    public static function badge(string $status): string {
        if (!in_array($status, management_cycle_template_service::STATUSES, true)) { return s($status); }
        return \html_writer::tag('span', self::tr('mcycle_status_'.$status), ['class'=>'bms-mcycle-badge bms-mcycle-'.$status]);
    }
    public static function template_label(string $code): string {
        $template = management_cycle_template_service::templates()[$code] ?? null;
        return $template ? self::tr($template['labelkey']) : $code;
    }
    private static function tabs(string $company, string $cycle, string $active): string {
        $html = '';
        foreach (['overview','checkpoints','execution','a3','history'] as $tab) {
            $attrs = ['class'=>'bms-mcycle-tab'.($tab === $active ? ' is-active' : '')];
            if ($tab === $active) { $attrs['aria-current'] = 'page'; }
            $html .= \html_writer::link(self::url($company, ['cycle'=>$cycle,'tab'=>$tab]), self::tr('mcu_tab_'.$tab), $attrs);
        }
        return \html_writer::tag('nav', $html, ['class'=>'bms-mcycle-tabs','aria-label'=>self::tr('mcu_record_nav')]);
    }
    public static function hidden(array $data): string {
        $html = '';
        foreach ($data as $key=>$value) { $html .= \html_writer::empty_tag('input', ['type'=>'hidden','name'=>$key,'value'=>$value]); }
        return $html;
    }
    private static function empty_state(string $title, string $text, string $actions = ''): string {
        return \html_writer::div(\html_writer::tag('h2', s($title)).self::note($text).$actions, 'bms-crm-empty bms-mcycle-empty');
    }
    public static function listing(string $company, array $model, array $filters, array $members, array $cadence = [], bool $cancreate = true): string {
        $html = bms_workspace_ux_service::record_header(self::tr('mcycle_title'), self::tr('mcu_intro'), '', '',
            $cancreate ? self::link(self::url($company, ['view'=>'create']), self::tr('mcu_create'), true) : '');
        if ($cadence) { $html .= self::cadence_overview($company, $cadence); }
        $html .= \html_writer::start_div('bms-mcycle-summary');
        foreach ($model['summary'] as $status=>$count) {
            $html .= \html_writer::link(self::url($company, ['status'=>$status]),
                \html_writer::tag('strong',(string)$count).\html_writer::tag('span', self::tr('mcycle_status_'.$status)), ['class'=>'bms-mcycle-stat']);
        }
        $html .= \html_writer::end_div();
        $html .= self::note(self::tr('mcu_counts_company'));
        $form = self::hidden(['uuid'=>$company]);
        $fields = [
            'q'=>['label'=>'mcu_search','type'=>'search'],
            'status'=>['label'=>'mcycle_status','options'=>[''=>self::tr('mcu_all_status')]+array_combine(management_cycle_template_service::STATUSES,
                array_map(static function($s) { return self::tr('mcycle_status_'.$s); }, management_cycle_template_service::STATUSES))],
            'templatecode'=>['label'=>'mcycle_template','options'=>[''=>self::tr('mcu_all_templates')]+array_map(static function($t) { return self::tr($t['labelkey']); }, management_cycle_template_service::templates())],
            'ownerid'=>['label'=>'mcycle_owner','options'=>[0=>self::tr('mcu_all_owners')]+$members],
            'from'=>['label'=>'mcu_filter_from','type'=>'date'], 'to'=>['label'=>'mcu_filter_to','type'=>'date'],
        ];
        foreach ($fields as $key=>$field) { $form .= self::control($key, $field, $filters[$key] ?? '', ''); }
        $form .= \html_writer::div(\html_writer::tag('button', self::tr('mcu_apply'), ['type'=>'submit','class'=>'btn btn-primary']).
            self::link(self::url($company), self::tr('mcu_reset')), 'bms-mcycle-actions');
        $html .= \html_writer::tag('form', $form, ['method'=>'get','action'=>self::url($company)->out(false), 'class'=>'bms-mcycle-filters']);
        if (!$model['total']) {
            $isfiltered = count(array_filter($filters, static function($v) { return $v !== '' && $v !== 0; })) > 0;
            return $html.self::empty_state(self::tr($isfiltered ? 'mcu_no_matches' : 'mcycle_empty'),
                self::tr($isfiltered ? 'mcu_no_matches_help' : 'mcu_empty_help'));
        }
        $html .= \html_writer::tag('p', self::tr('mcu_found', $model['total']));
        $table = new \html_table(); $table->attributes['class'] = 'table bms-mcycle-table';
        $table->head = array_map(static function($s) { return self::tr($s); },
            ['mcycle_name','mcycle_period','mcycle_owner','mcycle_status','mcycle_checkpoints_title','mcu_next_checkpoint']);
        foreach ($model['rows'] as $row) {
            $table->data[] = [
                \html_writer::link(self::url($company,['cycle'=>$row['uuid']]), s($row['title'])).self::note(self::template_label($row['templatecode'])),
                s($row['periodstart'].' — '.$row['periodend']), s($row['ownername']).self::note(self::tr('mcycle_curator').': '.$row['curatorname']), self::badge($row['status']),
                (int)$row['checkpoint_done'].' / '.(int)$row['checkpoint_total'],
                $row['nextat'] && $row['status'] === 'active' ? s(gmdate('d.m.Y', (int)$row['nextat'])) : '—',
            ];
        }
        $html .= \html_writer::div(\html_writer::table($table), 'bms-mcycle-table-wrap', ['role'=>'region','aria-label'=>self::tr('mcycle_list_title'),'tabindex'=>'0']);
        $pages = [];
        if ($model['page'] > 0) { $pages[] = self::link(self::url($company, $filters+['page'=>$model['page']-1]), self::tr('mcu_previous')); }
        $pages[] = self::note(self::tr('mcu_page', (object)['page'=>$model['page']+1,'pages'=>(int)ceil($model['total']/$model['pagesize'])]));
        if (($model['page']+1)*$model['pagesize'] < $model['total']) { $pages[] = self::link(self::url($company, $filters+['page'=>$model['page']+1]), self::tr('mcu_next')); }
        return $html.\html_writer::tag('nav', implode('', $pages), ['class'=>'bms-mcycle-actions','aria-label'=>self::tr('mcu_pagination')]);
    }

    /** Current Year → Quarter → Month → Week management cadence. */
    public static function cadence_overview(string $company, array $model): string {
        $html = \html_writer::start_tag('section', ['class'=>'bms-mcycle-panel bms-mcycle2-cadence']);
        $html .= \html_writer::tag('h2', self::tr('mcycle2_cadence_title'));
        $html .= self::note(self::tr('mcycle2_cadence_intro'));
        $html .= \html_writer::start_div('bms-mcycle2-cadence-grid');
        foreach ((array)($model['rows'] ?? []) as $row) {
            $body = \html_writer::div(self::template_label((string)$row['templatecode']), 'bms-mcycle2-cadence-type');
            if (!empty($row['exists'])) {
                $body .= \html_writer::tag('strong', s((string)$row['title']));
                $body .= self::note(s((string)$row['periodstart'].' — '.(string)$row['periodend']));
                $body .= self::badge((string)$row['status']);
                $body .= \html_writer::div(self::link((string)$row['url'], self::tr('mcycle2_open_cycle')), 'bms-mcycle-actions');
            } else {
                $body .= self::note(self::tr('mcycle2_missing_cycle'));
                $body .= \html_writer::div(self::link((string)$row['url'], self::tr('mcycle2_create_cycle')), 'bms-mcycle-actions');
            }
            $html .= \html_writer::tag('article', $body, ['class'=>'bms-mcycle2-cadence-card']);
        }
        $html .= \html_writer::end_div();
        return $html . \html_writer::end_tag('section');
    }

    /** Five-function BMS24 management-cycle workspace. */
    private static function management2(array $model): string {
        if (empty($model['enabled'])) { return ''; }
        $summary = (array)($model['summary'] ?? []);
        $html = \html_writer::start_tag('section', ['class'=>'bms-mcycle-panel bms-mcycle2']);
        $html .= \html_writer::tag('h2', self::tr('mcycle2_title'));
        $html .= self::note(self::tr('mcycle2_intro'));
        $html .= \html_writer::div(
            \html_writer::tag('strong', self::tr('mcycle2_summary', (object)[
                'done'=>(int)($summary['done'] ?? 0), 'attention'=>(int)($summary['attention'] ?? 0),
            ])), 'bms-mcycle2-summary');
        $html .= \html_writer::start_div('bms-mcycle2-grid');
        $n = 0;
        foreach ((array)($model['stages'] ?? []) as $stage) {
            $n++;
            $code = (string)$stage['code'];
            $state = (string)$stage['state'];
            $facts = (array)$stage['facts'];
            $body = \html_writer::div((string)$n, 'bms-mcycle2-number');
            $body .= \html_writer::tag('h3', self::tr('mcycle2_stage_'.$code));
            $body .= \html_writer::tag('span', self::tr('mcycle2_state_'.$state), ['class'=>'bms-mcycle2-state bms-mcycle2-state-'.$state]);
            $body .= self::note(self::tr('mcycle2_stage_'.$code.'_help'));
            $facttext = '';
            if ($code === 'planning') {
                $facttext = self::tr('mcycle2_fact_planning', (object)['metrics'=>(int)$facts['indicators'],'plans'=>(int)$facts['metrics_with_plan']]);
            } else if ($code === 'organization') {
                $facttext = self::tr('mcycle2_fact_organization', (object)['plans'=>(int)$facts['battle_plans'],'checkpoints'=>(int)$facts['checkpoints']]);
            } else if ($code === 'leadership') {
                $facttext = self::tr('mcycle2_fact_leadership', (object)['tasks'=>(int)$facts['tasks'],'meetings'=>(int)$facts['meetings'],'decisions'=>(int)$facts['accepted_decisions']]);
            } else if ($code === 'control') {
                $facttext = self::tr('mcycle2_fact_control', (object)['done'=>(int)$facts['checkpoints_done'],'total'=>(int)$facts['checkpoints_total'],'overdue'=>(int)$facts['checkpoints_overdue'],'facts'=>(int)$facts['metrics_with_fact']]);
            } else if ($code === 'correction') {
                $facttext = self::tr('mcycle2_fact_correction', (object)['a3'=>(int)$facts['a3_open'],'tasks'=>(int)$facts['open_tasks'],'next'=>(int)$facts['next_cycles']]);
            }
            if ($facttext !== '') { $body .= \html_writer::div(s($facttext), 'bms-mcycle2-facts'); }
            $body .= \html_writer::div(self::link((string)$stage['url'], self::tr('mcycle2_open_stage')), 'bms-mcycle-actions');
            $html .= \html_writer::tag('article', $body, ['class'=>'bms-mcycle2-stage']);
        }
        $html .= \html_writer::end_div();
        if ((int)($summary['close_attention'] ?? 0) > 0) {
            $html .= \html_writer::div(self::tr('mcycle2_close_attention', (int)$summary['close_attention']), 'alert alert-warning mt-3');
        } else {
            $html .= \html_writer::div(self::tr('mcycle2_close_clear'), 'alert alert-success mt-3');
        }
        $html .= self::note(self::tr('mcycle2_advisory_note'));
        return $html . \html_writer::end_tag('section');
    }

    public static function template_picker(string $company): string {
        $html = bms_workspace_ux_service::record_header(self::tr('mcu_create'), self::tr('mcu_choose_template'));
        $html .= \html_writer::start_div('bms-mcycle-templates');
        foreach (management_cycle_template_service::templates() as $code=>$template) {
            $html .= \html_writer::tag('article', \html_writer::tag('h2', self::template_label($code)).
                self::note(self::tr('mcu_template_help_'.$code)).
                self::note(self::tr('mcu_template_limits', (object)['count'=>count($template['checkpointkeys']),
                    'min'=>$template['indicator_min'],'max'=>$template['indicator_max']])).
                self::link(self::url($company, ['view'=>'create','templatecode'=>$code]), self::tr('mcu_choose')),
                ['class'=>'bms-mcycle-panel']);
        }
        return $html.\html_writer::end_div().self::link(self::url($company), self::tr('mcu_back_list'));
    }

    /** Field schema is domain-owned; the form never changes lifecycle through an editable select. */
    public static function schema(array $options): array {
        $people = [0=>self::tr('mcu_choose_person')]+$options['members'];
        $parents = [''=>self::tr('mcu_no_parent')];
        foreach ($options['parents'] as $row) { $parents[$row->uuid] = $row->title.' · '.$row->periodstart.' — '.$row->periodend; }
        $indicators = [];
        foreach ($options['indicators'] as $row) { $indicators[$row->uuid] = trim($row->code.' · '.$row->name, ' ·'); }
        return [
            'mcu_section_main'=>[
                'title'=>['label'=>'mcycle_name','required'=>true,'maxlength'=>255],
                'priority'=>['label'=>'mcycle_priority','maxlength'=>10000,'help'=>'mcu_priority_help'],
                'outcome'=>['label'=>'mcycle_outcome','type'=>'textarea','maxlength'=>10000,'help'=>'mcu_outcome_help'],
            ],
            'mcu_section_period'=>[
                'periodstart'=>['label'=>'mcycle_periodstart','type'=>'date','required'=>true],
                'periodend'=>['label'=>'mcycle_periodend','type'=>'date','required'=>true],
            ],
            'mcu_section_people'=>[
                'ownerid'=>['label'=>'mcycle_owner','options'=>$people,'required'=>true,'help'=>'mcu_owner_help'],
                'curatorid'=>['label'=>'mcycle_curator','options'=>$people,'required'=>true,'help'=>'mcu_curator_help'],
            ],
            'mcu_section_measure'=>['indicatoruuids'=>['label'=>'mcycle_indicators','type'=>'checkboxes','options'=>$indicators]],
            'mcu_section_more'=>['parentuuid'=>['label'=>'mcycle_parent','options'=>$parents,'help'=>'mcu_parent_help']],
        ];
    }
    private static function control(string $name, array $spec, $value, string $error): string {
        $id = 'mcu_'.$name; $type = $spec['type'] ?? 'text';
        $label = self::tr($spec['label']).(!empty($spec['required']) ? ' *' : '');
        $attrs = ['name'=>$name,'id'=>$id,'class'=>'form-control'.($error !== '' ? ' is-invalid' : '')];
        $described = [];
        if (!empty($spec['help'])) { $described[] = $id.'_help'; }
        if ($error !== '') { $attrs['aria-invalid'] = 'true'; $described[] = $id.'_error'; }
        if ($described) { $attrs['aria-describedby'] = implode(' ', $described); }
        if (!empty($spec['required'])) { $attrs['required'] = 'required'; }
        if (isset($spec['maxlength'])) { $attrs['maxlength'] = $spec['maxlength']; }
        $html = '';
        if ($type === 'checkboxes') {
            foreach ($spec['options'] as $key=>$text) {
                $a = ['type'=>'checkbox','name'=>$name.'[]','value'=>$key];
                if (in_array((string)$key, (array)$value, true)) { $a['checked'] = 'checked'; }
                $html .= \html_writer::tag('label', \html_writer::empty_tag('input',$a).' '.s($text), ['class'=>'bms-mcycle-choice']);
            }
            if ($html === '') { $html = self::note(self::tr('mcu_no_indicators')); }
            $html = \html_writer::tag('fieldset', \html_writer::tag('legend', s($label)).
                \html_writer::div($html,'bms-mcycle-picker'), ['class'=>'bms-mcycle-checks','id'=>$id, 'aria-describedby'=>$described ? implode(' ', $described) : null, 'aria-invalid'=>$error !== '' ? 'true' : null]);
        } else {
            $html = \html_writer::label(s($label), $id);
            if (isset($spec['options'])) { $html .= \html_writer::select($spec['options'], $name, $value, false, $attrs); }
            else if ($type === 'textarea') { $html .= \html_writer::tag('textarea', s((string)$value), $attrs+['rows'=>3]); }
            else { $html .= \html_writer::empty_tag('input', $attrs+['type'=>$type,'value'=>(string)$value]); }
        }
        if (!empty($spec['help'])) { $html .= \html_writer::div(s(self::tr($spec['help'])),'bms-mcycle-note',['id'=>$id.'_help']); }
        if ($error !== '') { $html .= \html_writer::div(s($error), 'invalid-feedback d-block', ['id'=>$id.'_error']); }
        return \html_writer::div($html, 'bms-mcycle-control'.(in_array($type,['textarea','checkboxes'],true) ? ' bms-mcycle-control-wide' : ''));
    }
    public static function form(string $company, array $options, array $values, array $errors, string $cycle = ''): string {
        $edit = $cycle !== '';
        $template = $options['templates'][$values['templatecode']];
        $html = bms_workspace_ux_service::record_header(self::tr($edit ? 'mcu_edit' : 'mcu_create'),
            self::template_label($values['templatecode']));
        $html .= self::note(self::tr('mcu_form_help'));
        if ($errors) { $html .= \html_writer::div(self::tr('mcu_form_errors'), 'alert alert-danger', ['role'=>'alert']); }
        if (isset($errors['_form'])) { $html .= \html_writer::div(s($errors['_form']), 'alert alert-danger'); }
        $form = self::hidden(['uuid'=>$company,'cycle'=>$cycle,'view'=>$edit ? 'edit' : 'create',
            'action'=>$edit ? 'save' : 'create','sesskey'=>sesskey(),'templatecode'=>$values['templatecode'],
            'expected'=>$values['edit_token'] ?? '', 'requestkey'=>$values['requestkey'] ?? '']);
        foreach (self::schema($options) as $section=>$fields) {
            $body = '';
            if ($section === 'mcu_section_people') { $body .= self::note(self::tr('mcu_people_notice')); }
            if ($section === 'mcu_section_period') { $body .= self::note(self::tr('mcu_dates_help')); }
            if ($section === 'mcu_section_measure') {
                $body .= self::note(self::tr('mcu_template_limits', (object)['count'=>count($template['checkpointkeys']),
                    'min'=>$template['indicator_min'],'max'=>$template['indicator_max']]));
            }
            $controls = '';
            foreach ($fields as $key=>$spec) {
                if ($key === 'indicatoruuids') { $spec['required'] = $template['indicator_min'] > 0; }
                $controls .= self::control($key, $spec, $values[$key] ?? '', $errors[$key] ?? '');
            }
            $body .= \html_writer::div($controls, 'bms-mcycle-grid');
            if ($section === 'mcu_section_more') {
                $attrs = ['class'=>'bms-mcycle-panel'];
                if (!empty($values['parentuuid']) || !empty($errors['parentuuid'])) { $attrs['open'] = 'open'; }
                $form .= \html_writer::tag('details', \html_writer::tag('summary',self::tr($section)).$body, $attrs);
            } else { $form .= \html_writer::tag('section', \html_writer::tag('h2',self::tr($section)).$body, ['class'=>'bms-mcycle-panel']); }
        }
        $back = self::url($company, $edit ? ['cycle'=>$cycle] : []);
        $form .= \html_writer::div(\html_writer::tag('button',self::tr($edit ? 'mcu_save' : 'mcu_save_draft'),
            ['type'=>'submit','class'=>'btn btn-primary']).self::link($back,self::tr('mcu_cancel_edit')), 'bms-mcycle-actions');
        return $html.\html_writer::tag('form',$form,['method'=>'post','action'=>self::url($company)->out(false),'class'=>'bms-mcycle-form','novalidate'=>'novalidate']);
    }
    public static function detail(string $company, array $cycle, string $tab, array $extra = []): string {
        $id = $cycle['uuid']; $actions = '';
        if ($cycle['actions']['edit']) { $actions = self::link(self::url($company, ['cycle'=>$id,'view'=>'edit']), self::tr('mcu_edit'), true); }
        $secondary = '';
        foreach (['start','close','cancel','rollover','createchild'] as $action) {
            if ($cycle['actions'][$action]) { $secondary .= self::link(self::url($company, ['cycle'=>$id,'view'=>'action','action'=>$action]), self::tr('mcu_action_'.$action)); }
        }
        if ($secondary !== '') { $actions .= \html_writer::tag('details',\html_writer::tag('summary', self::tr('mcu_actions')).
            \html_writer::div($secondary,'bms-mcycle-actions'),['class'=>'bms-mcycle-actionmenu']); }
        $html = self::link(self::url($company),self::tr('mcu_back_list'));
        $html .= bms_workspace_ux_service::record_header(s($cycle['title']), self::template_label($cycle['templatecode']), '',
            self::badge($cycle['status']).' '.s($cycle['periodstart'].' — '.$cycle['periodend']), $actions);
        $next = 'mcu_next_'.$cycle['status'];
        if ($cycle['status'] === 'active' && $cycle['checkpoint_total'] === $cycle['checkpoint_done']) { $next = 'mcu_next_review'; }
        $html .= \html_writer::div(\html_writer::tag('strong',self::tr('mcu_next_step').': ').s(self::tr($next)), 'bms-mcycle-next');
        $html .= self::tabs($company, $id, $tab);
        if ($tab === 'overview') {
            if (!empty($extra['management2'])) { $html .= self::management2((array)$extra['management2']); }
            $fields = self::field(self::tr('mcycle_priority'),$cycle['priority']).self::field(self::tr('mcycle_outcome'),$cycle['outcome']).
                self::field(self::tr('mcycle_owner'),$cycle['ownername']).self::field(self::tr('mcycle_curator'),$cycle['curatorname']);
            $html .= \html_writer::tag('dl',$fields,['class'=>'bms-mcycle-grid']);
            $html .= self::note(self::tr('mcu_people_notice'));
            $html .= \html_writer::tag('section', \html_writer::tag('h2',self::tr('mcycle_checkpoints_title')).
                self::note(self::tr('mcu_progress', (object)['done'=>$cycle['checkpoint_done'],'total'=>$cycle['checkpoint_total']])).
                self::link(self::url($company,['cycle'=>$id,'tab'=>'checkpoints']),self::tr('mcu_open_checkpoints')),['class'=>'bms-mcycle-panel']);
            $html .= \html_writer::start_tag('section',['class'=>'bms-mcycle-panel']);
            $html .= \html_writer::tag('h2',self::tr('mcycle_indicators').' · '.count($cycle['indicators']));
            if (!$cycle['indicators']) { $html .= self::note(self::tr('mcu_no_bound_indicators')); }
            foreach ($cycle['indicators'] as $indicator) {
                $html .= \html_writer::tag('p', \html_writer::link(new \moodle_url('/local/kbacompany/indicators.php',
                    ['uuid'=>$company,'stat'=>$indicator['uuid']]),s(trim($indicator['code'].' · '.$indicator['name'], ' ·'))));
            }
            $html .= self::note(self::tr('mcu_indicators_notice')).\html_writer::end_tag('section');
            $html .= \html_writer::start_tag('section',['class'=>'bms-mcycle-panel']);
            $html .= \html_writer::tag('h2',self::tr('mcu_links'));
            if ($cycle['parent']) { $html .= \html_writer::tag('p',self::tr('mcycle_parent').': '.\html_writer::link(self::url($company,['cycle'=>$cycle['parent']->uuid]),s($cycle['parent']->title))); }
            else { $html .= self::note(self::tr('mcu_no_parent')); }
            if (!empty($cycle['rollover']['sourcecycleuuid'])) { $html .= self::link(self::url($company,['cycle'=>$cycle['rollover']['sourcecycleuuid']]),self::tr('mcycle_rollover_open_source')); }
            foreach ($cycle['children'] as $child) { $html .= \html_writer::tag('p',\html_writer::link(self::url($company,['cycle'=>$child->uuid]),s($child->title)).' · '.s($child->periodstart.' — '.$child->periodend)); }
            $html .= self::note(self::tr('mcu_children_count', $cycle['children_total'])).\html_writer::end_tag('section');
            if (in_array($cycle['status'],['active','closed'],true)) {
                $html .= self::link(new \moodle_url('/local/kbacompany/management_cycle_report.php',['uuid'=>$company,'cycle'=>$id]),self::tr('mcycle_report_open'));
            }
        } else if ($tab === 'checkpoints') {
            $html .= self::note(self::tr('mcu_checkpoints_help'));
            foreach ($cycle['checkpoints'] as $cp) {
                $body = \html_writer::tag('h2', s($cp->title)).self::note(gmdate('d.m.Y',(int)$cp->plannedat).' · '.self::tr('mcycle_cp_status_'.$cp->status));
                if ($cycle['status'] === 'active' && $cp->status !== 'done' && gmdate('Y-m-d',(int)$cp->plannedat) < gmdate('Y-m-d')) {
                    $body .= \html_writer::tag('strong', self::tr('mcu_overdue'),['class'=>'text-danger']);
                }
                if ($cp->result !== '' && $cp->result !== null) { $body .= \html_writer::tag('p',nl2br(s($cp->result))); }
                $buttons = '';
                if ($cp->canopenmeeting) { $buttons .= self::link(new \moodle_url('/local/kbacompany/meetings.php',['uuid'=>$company,'meeting'=>$cp->meetinguuid]),self::tr('mcycle_meeting_open')); }
                else if ($cp->meetinguuid !== '') { $body .= self::note(self::tr('mcu_meeting_private')); }
                else if ($cycle['actions']['checkpointmeeting'] && $cp->status !== 'done') {
                    $buttons .= self::link(self::url($company,['cycle'=>$id,'view'=>'action','action'=>'checkpointmeeting','checkpoint'=>$cp->uuid]),self::tr('mcycle_meeting_prepare'));
                }
                if ($cycle['actions']['checkpoint'] && $cp->status !== 'done') { $buttons .= self::link(self::url($company,
                    ['cycle'=>$id,'view'=>'action','action'=>'checkpoint','checkpoint'=>$cp->uuid]),self::tr('mcycle_checkpoint_done')); }
                $html .= \html_writer::tag('article',$body.\html_writer::div($buttons,'bms-mcycle-actions'),['class'=>'bms-mcycle-panel']);
            }
        } else if ($tab === 'execution') {
            $html .= self::note(self::tr('mcycle_execution_note'));
            $html .= self::note(self::tr('mcu_execution_scope'));
            if (!$cycle['battleplans']) { $html .= self::empty_state(self::tr('mcycle_no_battleplans'),self::tr('mcu_plans_help')); }
            foreach ($cycle['battleplans'] as $plan) {
                $html .= \html_writer::tag('article',self::link($plan['url'],self::tr('battleplan_title').' · '.$plan['periodstart']).
                    self::note(self::tr('battleplan_status_'.$plan['status'])),['class'=>'bms-mcycle-panel']);
            }
            if (!empty($cycle['rollover']['carrytasks'])) {
                $html .= \html_writer::tag('h2', self::tr('mcu_carried'));
                $html .= self::note(self::tr('mcycle_rollover_from_note'));
                foreach ($cycle['rollover']['carrytasks'] as $task) { $html .= \html_writer::tag('p',\html_writer::link($task['url'],s($task['title'])).' · '.s($task['duedate'])); }
            }
        } else if ($tab === 'a3') {
            $rows = $extra['a3'] ?? [];
            $html .= self::note(self::tr('mcu_a3_help'));
            if (in_array($cycle['status'],['draft','active'],true)) { $html .= self::link(new \moodle_url('/local/kbacompany/management_a3.php',['uuid'=>$company,'cycle'=>$id]),self::tr('a3_new')); }
            if (!$rows) { $html .= self::empty_state(self::tr('a3_list_empty'),self::tr('mcu_a3_empty_help')); }
            foreach ($rows as $row) { $html .= \html_writer::tag('article',self::link(new \moodle_url('/local/kbacompany/management_a3.php',
                ['uuid'=>$company,'a3'=>$row['uuid']]),$row['title']).self::note(self::tr('a3_status_'.$row['status'])),['class'=>'bms-mcycle-panel']); }
        } else if ($tab === 'history') {
            $html .= \html_writer::tag('dl',self::field(self::tr('mcu_created'),userdate($cycle['timecreated']).' · '.$cycle['createdbyname']).
                self::field(self::tr('mcu_modified'),userdate($cycle['timemodified'])),['class'=>'bms-mcycle-grid']);
            $html .= self::note(self::tr('mcu_history_notice'));
            $history = $extra['history'] ?? ['available'=>false,'rows'=>[]];
            if (!$history['available']) { $html .= self::note(self::tr('mcu_history_unavailable')); }
            foreach ($history['rows'] as $event) {
                $key = 'mcu_event_'.$event['operation'];
                $label = get_string_manager()->string_exists($key,'local_kbacompany') ? self::tr($key) : self::tr('mcu_event');
                $labels = ['title'=>'mcycle_name','priority'=>'mcycle_priority','outcome'=>'mcycle_outcome',
                    'periodstart'=>'mcycle_periodstart','periodend'=>'mcycle_periodend','ownerid'=>'mcycle_owner',
                    'curatorid'=>'mcycle_curator','parentuuid'=>'mcycle_parent','indicatoruuids'=>'mcycle_indicators',
                    'status'=>'mcycle_status','checkpoint'=>'mcu_tab_checkpoints'];
                $fields = [];
                foreach ($event['fields'] as $field) { if (isset($labels[$field])) { $fields[] = self::tr($labels[$field]); } }
                $html .= \html_writer::tag('article',\html_writer::tag('strong', s($label)).
                    self::note(userdate($event['time']).' · '.$event['actor']).
                    ($fields ? self::note(implode(', ', $fields)) : ''),['class'=>'bms-mcycle-panel']);
            }
            $html .= \html_writer::tag('details',\html_writer::tag('summary', self::tr('mcu_technical')).
                self::note('UUID: '.$id),['class'=>'bms-mcycle-panel']);
        }
        return $html;
    }

    /** Secondary operations have a separate confirmation page, never inline in the main record. */
    public static function action_form(string $company, array $cycle, string $action, array $values, array $extra = [], string $error = ''): string {
        $html = bms_workspace_ux_service::record_header(self::tr('mcu_action_'.$action), s($cycle['title']), '', self::badge($cycle['status']));
        $html .= self::note(self::tr('mcu_confirm_'.$action));
        if ($error !== '') { $html .= \html_writer::div(s($error),'alert alert-danger',['role'=>'alert']); }
        $form = self::hidden(['uuid'=>$company,'cycle'=>$cycle['uuid'],'action'=>$action,'view'=>'action','sesskey'=>sesskey(),
            'expected'=>$values['expected'] ?? $cycle['edit_token'],'checkpoint'=>$values['checkpoint'] ?? '', 'requestkey'=>$values['requestkey'] ?? '']);
        if (!empty($extra['checkpoint'])) { $form .= \html_writer::tag('h2',s($extra['checkpoint']->title)); }
        if ($action === 'checkpoint') { $form .= self::control('result',['label'=>'mcycle_result','type'=>'textarea','required'=>true,'maxlength'=>5000],$values['result'] ?? '', ''); }
        if ($action === 'close') { $form .= self::note(self::tr('mcu_close_pending',max(0,$cycle['checkpoint_total']-$cycle['checkpoint_done']))); }
        if ($action === 'rollover' || $action === 'createchild') {
            $preview = $extra['preview'];
            $form .= self::control('title',['label'=>'mcycle_name','maxlength'=>255],$values['title'] ?? '', '');
            if ($action === 'rollover') {
                $form .= self::note($preview['next_period_start'].' — '.$preview['next_period_end']);
                foreach ($preview['open_tasks'] as $task) {
                    $attrs = ['type'=>'checkbox','name'=>'carrytasks[]','value'=>$task['uuid']];
                    if (in_array($task['uuid'],(array)($values['carrytasks'] ?? []),true)) { $attrs['checked'] = 'checked'; }
                    $form .= \html_writer::tag('label',\html_writer::empty_tag('input',$attrs).' '.s($task['title']).' · '.s($task['due_date']),['class'=>'bms-mcycle-choice']);
                }
                if (!$preview['open_tasks']) { $form .= self::note(self::tr('mcycle_rollover_no_tasks')); }
            } else {
                $form .= self::note(self::template_label($preview['child_template']));
                foreach (['periodstart','periodend'] as $field) { $form .= self::control($field,['label'=>'mcycle_'.$field,'type'=>'date','required'=>true],$values[$field] ?? '', ''); }
            }
        }
        $form .= \html_writer::tag('label',\html_writer::empty_tag('input',['type'=>'checkbox','name'=>'confirmed','value'=>'1','required'=>'required']).
            ' '.s(self::tr('mcu_confirm_check')), ['class'=>'bms-mcycle-choice']);
        $form .= \html_writer::div(\html_writer::tag('button',self::tr('mcu_confirm_button'),['type'=>'submit','class'=>'btn btn-primary']).
            self::link(self::url($company,['cycle'=>$cycle['uuid']]),self::tr('mcu_cancel_edit')),'bms-mcycle-actions');
        return $html.\html_writer::tag('form',$form,['method'=>'post','action'=>self::url($company)->out(false),'class'=>'bms-mcycle-form bms-mcycle-panel']);
    }
}
