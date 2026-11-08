<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** BMS CRUD presentation of canonical tasks. No persistence or new task ownership. */
class task_ui_service {
    public static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }

    /** Task Center's presentation-only boundary; no changes to actions, ACL or models. */
    private static function owned(): bool {
        return bms24_shell_service::is_enabled();
    }

    /** Native BMS24 headings/tabs in opt-in owned mode. Legacy markup is unchanged. */
    private static function heading(string $title, string $subtitle = '', string $statushtml = '',
            string $actionshtml = ''): string {
        if (!self::owned()) {
            return bms_workspace_ux_service::record_header(s($title), s($subtitle), '', $statushtml, $actionshtml);
        }
        return bms24_shell_service::fragment('task_header', [
            'title' => $title, 'subtitle' => $subtitle,
            // These slots are produced by this service's escaped, typed helpers only.
            'statushtml' => $statushtml, 'actionshtml' => $actionshtml,
        ]);
    }

    /** Task detail header only: subtitle HTML is built from escaped service helpers. */
    private static function detail_heading(string $title, string $subtitlehtml, string $statushtml = '',
            string $actionshtml = ''): string {
        if (!self::owned()) {
            return bms_workspace_ux_service::record_header(s($title), $subtitlehtml, '',
                $statushtml, $actionshtml);
        }
        return bms24_shell_service::fragment('task_header', [
            'title' => $title, 'subtitle' => '', 'subtitlehtml' => $subtitlehtml,
            // These slots are produced by this service's escaped, typed helpers only.
            'statushtml' => $statushtml, 'actionshtml' => $actionshtml,
        ]);
    }

    private static function tabs(array $items, string $active, string $label): string {
        if (!self::owned()) {
            return bms_workspace_ux_service::view_switcher($items, $active);
        }
        $tabs = [];
        foreach ($items as $key => $item) {
            if (!($item['url'] instanceof \moodle_url)) {
                throw new \coding_exception('Task Center tab URL must be moodle_url');
            }
            $tabs[] = [
                'label' => (string)$item['label'], 'url' => $item['url']->out(false),
                'active' => (string)$key === $active,
            ];
        }
        return bms24_shell_service::fragment('task_tabs', ['label' => $label, 'items' => $tabs]);
    }

    private static function toolbar(string $left, string $right): string {
        if (!self::owned()) {
            return bms_workspace_ux_service::action_bar($left, $right);
        }
        return bms24_shell_service::fragment('task_toolbar', [
            'lefthtml' => $left, 'righthtml' => $right,
        ]);
    }

    public static function url(string $company, array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/tasks.php', ['uuid'=>$company] + $params);
    }
    public static function link($url, string $label, bool $primary = false): string {
        if (self::owned()) {
            if (!($url instanceof \moodle_url)) {
                throw new \coding_exception('Task Center link URL must be moodle_url');
            }
            return bms24_ui_kit_service::button($url, $label, $primary ? 'primary' : 'secondary');
        }
        return \html_writer::link($url, s($label), ['class'=>'btn '.($primary?'btn-primary':'btn-outline-secondary')]);
    }
    public static function hidden(array $values): string {
        $html=''; foreach ($values as $name=>$value) { $html.=\html_writer::empty_tag('input', ['type'=>'hidden','name'=>$name,'value'=>$value]); } return $html;
    }
    public static function note(string $text): string {
        return \html_writer::tag('p', s($text), ['class'=>self::owned()?'bms24-task-note':'bms-tui-note']);
    }
    public static function badge(string $status): string {
        if (!in_array($status, task_service::STATUSES, true)) { return s(self::tr('tui_not_set')); }
        if (self::owned()) {
            return bms24_ui_kit_service::status(self::tr('taskcenter_status_'.$status), $status);
        }
        return \html_writer::tag('span',s(self::tr('taskcenter_status_'.$status)),['class'=>'bms-tui-badge bms-tui-state-'.$status]);
    }
    public static function panel(string $title, string $body, string $actions = ''): string {
        if (self::owned()) {
            return bms24_shell_service::fragment('task_panel', [
                'title'=>$title, 'actionshtml'=>$actions, 'bodyhtml'=>$body,
            ]);
        }
        return \html_writer::tag('section', \html_writer::div(\html_writer::tag('h2',s($title)).$actions,'bms-tui-panel-head').$body,
            ['class'=>'bms-crm-section bms-tui-panel']);
    }
    public static function table(array $headers, array $rows, string $label): string {
        $table=new \html_table(); $table->head=$headers; $table->data=$rows;
        $table->attributes['class']=self::owned()?'bms24-task-table':'table bms-tui-table';
        return \html_writer::div(\html_writer::table($table),
            self::owned()?'bms24-task-table-wrap':'bms-tui-table-wrap',
            ['role'=>'region','aria-label'=>$label,'tabindex'=>'0']);
    }
    public static function control(string $name, array $spec, $value = '', string $error = ''): string {
        $id='tui_'.preg_replace('/[^a-z0-9_]/i','_',$name); $type=$spec['type']??'text';
        $controlclass=self::owned()?($type==='textarea'?'bms24-ui-textarea':(isset($spec['options'])?'bms24-ui-select':'bms24-ui-input')):'form-control';
        $attrs=['id'=>$id,'name'=>$name,'class'=>$controlclass.($error!==''?' is-invalid':'')];
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
        return \html_writer::div($html,(self::owned()?'bms24-task-control':'bms-tui-control').(!empty($spec['wide'])?' bms-tui-wide':''));
    }
    private static function battle_plan_control(array $plans, string $value = '', string $error = ''): string {
        $id = 'tui_battleplanuuid';
        $attrs = ['id'=>$id, 'name'=>'battleplanuuid',
            'class'=>(self::owned()?'bms24-ui-select':'form-control').($error!==''?' is-invalid':'')];
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
        return \html_writer::div($html, self::owned()?'bms24-task-control':'bms-tui-control');
    }

    private static function field(string $label, string $value, bool $html = false): string {
        return \html_writer::tag('dl',\html_writer::tag('dt',s(self::tr($label))).\html_writer::tag('dd',$html?$value:s($value!==''?$value:self::tr('tui_not_set'))),['class'=>self::owned()?'bms24-task-field':'bms-tui-field']);
    }
    public static function menu(array $items, string $label = 'tui_actions'): string {
        if(!$items){return '';}
        $html=''; foreach($items as $item){$html.=\html_writer::link($item['url'],s($item['label']));}
        if (self::owned()) {
            return \html_writer::tag('details',
                \html_writer::tag('summary',s(self::tr($label)),['class'=>'bms24-ui-btn']).
                \html_writer::div($html,'bms24-task-menu-items'),['class'=>'bms24-task-menu']);
        }
        return \html_writer::tag('details',\html_writer::tag('summary',s(self::tr($label)),['class'=>'btn btn-outline-secondary']).
            \html_writer::div($html,'bms-tui-menu-items'),['class'=>'bms-tui-menu']);
    }
    public static function tools(string $company, bool $manager): string {
        $items=[['url'=>self::url($company,['view'=>'sources']),'label'=>self::tr('tui_source_tasks')],
            ['url'=>new \moodle_url('/local/kbacompany/recurring_task_library.php',['uuid'=>$company]),'label'=>self::tr('recurringtask_library_title')],
            ['url'=>new \moodle_url('/local/kbacompany/battle_plan.php',['uuid'=>$company]),'label'=>self::tr('battleplan_title')],
            ['url'=>new \moodle_url('/local/kbacompany/communication_center.php',['uuid'=>$company]),'label'=>self::tr('communicationcenter_title')]];
        if($manager){$items[]=['url'=>new \moodle_url('/local/kbacompany/task_templates.php',['uuid'=>$company]),'label'=>self::tr('bms_bundle_title')];}
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

    /** Reusable read-only signal badges; derived from the canonical task, never from text parsing. */
    private static function attention_html(array $task): string {
        $html = '';
        foreach (task_service::attention_signals($task) as $signal) {
            $html .= \html_writer::tag('span', s(self::tr('bms_task1852_signal_'.$signal)),
                ['class'=>'bms-task1852-signal bms-task1852-signal--'.$signal]);
        }
        return $html === '' ? '' : \html_writer::div($html, 'bms-task1852-signals');
    }

    /** Safe and compact audit label; raw log details are only rendered in the history tab. */
    private static function activity_html(array $activity): string {
        if (!$activity) { return ''; }
        $key = 'taskcenter_action_'.(string)($activity['action'] ?? '');
        $event = get_string_manager()->string_exists($key, 'local_kbacompany')
            ? self::tr($key) : self::tr('tui_history_event');
        $actor = trim((string)($activity['actor_name'] ?? ''));
        $description = $actor !== '' ? $actor.' · '.$event : $event;
        $time = (int)($activity['time_created'] ?? 0);
        if ($time > 0) { $description .= ' · '.userdate($time, '%d.%m %H:%M'); }
        return \html_writer::div(s($description), 'bms-task1852-activity');
    }

    /** Compact, read-only Battle Plan membership shown in Task Center list/kanban views. */
    private static function list_plan_context(string $company, array $plans): string {
        if (!$plans) { return ''; }
        $links = '';
        foreach (array_slice($plans, 0, 2) as $plan) {
            $statuskey = 'battleplan_status_'.(string)$plan['status'];
            $label = self::tr('taskcenter_battleplan_heading').' · '.(string)$plan['period_label'];
            $body = \html_writer::tag('span', s($label), ['class'=>'bms24-task-list-plan-label']).
                \html_writer::tag('span', s(self::tr($statuskey)), ['class'=>'bms24-task-list-plan-status']);
            $links .= \html_writer::link(new \moodle_url('/local/kbacompany/battle_plan.php',
                ['uuid'=>$company,'plan'=>(string)$plan['uuid']]), $body, ['class'=>'bms24-task-list-plan']);
        }
        if (count($plans) > 2) {
            $links .= \html_writer::tag('span', '+'.(count($plans)-2), ['class'=>'bms24-task-list-plan-more']);
        }
        return \html_writer::div($links, 'bms24-task-list-plans');
    }
    /** The first column is mandatory; all other list columns are user-configurable. */
    private static function table_preferences(string $company, array $model, array $filters): string {
        $prefs = $model['list_preferences'] ?? [
            'columns' => task_list_preferences_service::DEFAULT_COLUMNS,
            'sort' => 'duedate', 'dir' => 'asc',
        ];
        $options = '';
        foreach (task_list_preferences_service::COLUMNS as $key => $stringkey) {
            $checked = in_array($key, $prefs['columns'], true);
            // HTML boolean attributes are true by their mere presence, even when
            // html_writer serialises PHP false as an empty string.
            $attributes = [
                'type' => 'checkbox', 'name' => 'columns['.$key.']', 'value' => 1,
            ];
            if ($checked) { $attributes['checked'] = 'checked'; }
            if ($key === 'title') { $attributes['disabled'] = 'disabled'; }
            $label = \html_writer::empty_tag('input', $attributes).' '.s(self::tr($stringkey));
            $options .= \html_writer::tag('label', $label, ['class' => 'bms24-task-column-option']);
        }
        $sorts = [];
        foreach (task_list_preferences_service::SORTABLE as $key) {
            $sorts[$key] = self::tr(task_list_preferences_service::COLUMNS[$key]);
        }
        $sortcontrol = \html_writer::div(
            \html_writer::tag('label', s(self::tr('bms_task1841_default_sort')),
                ['for' => 'bms_task1841_defaultsort']).
            \html_writer::select($sorts, 'defaultsort', $prefs['sort'], false,
                ['id' => 'bms_task1841_defaultsort', 'class' => 'bms24-ui-select']),
            'bms24-task-sort-field');
        $dircontrol = \html_writer::div(
            \html_writer::tag('label', s(self::tr('bms_task1841_direction')),
                ['for' => 'bms_task1841_defaultdir']).
            \html_writer::select(['asc' => self::tr('bms_task1841_asc'),
                    'desc' => self::tr('bms_task1841_desc')],
                'defaultdir', $prefs['dir'], false,
                ['id' => 'bms_task1841_defaultdir', 'class' => 'bms24-ui-select']),
            'bms24-task-sort-field');
        $hidden = ['uuid' => $company, 'sesskey' => sesskey()];
        foreach (['scope','q','status','stream','sourcekind','battleplan','signal','assignee','project'] as $key) {
            if (isset($filters[$key])) { $hidden[$key] = $filters[$key]; }
        }
        $body = self::hidden($hidden).
            \html_writer::tag('fieldset',
                \html_writer::tag('legend', s(self::tr('bms_task1841_columns'))).
                \html_writer::div($options, 'bms24-task-column-options'),
                ['class' => 'bms24-task-column-fieldset']).
            \html_writer::div($sortcontrol.$dircontrol, 'bms24-task-sort-preferences').
            \html_writer::div(
                \html_writer::tag('button', s(self::tr('bms_task1841_save')),
                    ['type' => 'submit', 'class' => 'bms24-ui-btn bms24-ui-btn--primary']).
                \html_writer::tag('button', s(self::tr('bms_task1847_reset_columns')),
                    ['type'=>'submit', 'name'=>'resetcolumns', 'value'=>1,
                        'class'=>'bms24-ui-btn']), 'bms24-task-preferences-actions');
        $summary = \html_writer::tag('span', '&#9881;', ['class'=>'bms24-task-preferences-icon', 'aria-hidden'=>'true']).
            \html_writer::tag('span', s(self::tr('bms_task1841_configure')), ['class'=>'sr-only']);
        return \html_writer::tag('details',
            \html_writer::tag('summary', $summary, [
                'class'=>'bms24-task-preferences-toggle',
                'title'=>self::tr('bms_task1841_configure'),
                'aria-label'=>self::tr('bms_task1841_configure')]).
            \html_writer::tag('form', $body, ['method'=>'post',
                'action' => (new \moodle_url('/local/kbacompany/task_list_preferences.php'))->out(false),
                'class' => 'bms24-task-preferences-form']),
            ['class' => 'bms24-task-preferences']);
    }

    /** Per-user Kanban layout editor. Only changes the visual grouping of existing statuses. */
    private static function kanban_preferences(string $company, array $model, array $filters): string {
        $prefs = $model['kanban_preferences'] ?? task_kanban_preferences_service::defaults();
        $columns = $prefs['columns'];
        $mapping = $prefs['mapping'];
        $items = '';
        $positions = [];
        for ($i = 1; $i <= count(task_kanban_preferences_service::STATUSES); $i++) {
            $positions[$i] = (string)$i;
        }
        $targets = [];
        foreach (task_kanban_preferences_service::STATUSES as $status) {
            $targets[$status] = self::tr('taskcenter_status_'.$status);
        }
        foreach ($columns as $id=>$column) {
            $canonical = self::tr('taskcenter_status_'.$id);
            $label = \html_writer::tag('label', s($canonical), ['for'=>'bms1846_label_'.$id]);
            $name = \html_writer::empty_tag('input', [
                'type'=>'text', 'name'=>'labels['.$id.']',
                'id'=>'bms1846_label_'.$id, 'value'=>$column['label'], 'maxlength'=>60,
                'class'=>'bms24-ui-input', 'placeholder'=>$canonical,
            ]);
            $visibleattrs = ['type'=>'checkbox', 'name'=>'visible['.$id.']', 'value'=>1];
            if ($column['visible']) { $visibleattrs['checked'] = 'checked'; }
            $visible = \html_writer::tag('label',
                \html_writer::empty_tag('input', $visibleattrs).
                ' '.s(self::tr('bms_task1846_visible')),
                ['class'=>'bms-task1846-visible']);
            $order = \html_writer::select($positions, 'orders['.$id.']',
                $column['order'], false, ['class'=>'bms24-ui-select',
                    'aria-label'=>self::tr('bms_task1846_position').': '.$canonical]);
            $limit = \html_writer::empty_tag('input', [
                'type'=>'number', 'name'=>'limits['.$id.']',
                'min'=>0, 'max'=>task_kanban_preferences_service::MAX_WIP_LIMIT, 'step'=>1,
                'value'=>(int)($prefs['limits'][$id] ?? 0), 'class'=>'bms24-ui-input bms-task1850-wip-input',
                'title'=>self::tr('bms_task1850_wip_hint'),
                'aria-label'=>self::tr('bms_task1850_wip').': '.$canonical,
            ]);
            $items .= \html_writer::tag('div', $label.$name.$order.$visible.$limit,
                ['class'=>'bms-task1846-editor-row','data-kanban-column'=>$id]);
        }
        $statusoptions = '';
        foreach (task_kanban_preferences_service::STATUSES as $status) {
            $statusoptions .= \html_writer::tag('label',
                s(self::tr('taskcenter_status_'.$status)).
                \html_writer::select($targets, 'mapping['.$status.']', $mapping[$status], false,
                    ['class'=>'bms24-ui-select']),
                ['class'=>'bms-task1846-map-option']);
        }
        $hidden = ['uuid'=>$company, 'sesskey'=>sesskey(), 'scope'=>$filters['scope']];
        foreach (['q','status','stream','sourcekind','battleplan','signal','assignee','project'] as $key) {
            if (isset($filters[$key])) { $hidden[$key] = $filters[$key]; }
        }
        $groupoptions = [
            'none'=>self::tr('bms_task1850_group_none'),
            'assignee'=>self::tr('bms_task1850_group_assignee'),
            'project'=>self::tr('bms_task1850_group_project'),
        ];
        $groupcontrol = \html_writer::tag('label', s(self::tr('bms_task1850_group')),
            ['for'=>'bms_task1850_groupby']).
            \html_writer::select($groupoptions, 'groupby', $prefs['groupby'] ?? 'none', false,
                ['id'=>'bms_task1850_groupby', 'class'=>'bms24-ui-select']);
        $body = self::hidden($hidden).
            \html_writer::div($groupcontrol, 'bms-task1850-group-control').
            \html_writer::tag('h3', s(self::tr('bms_task1846_columns')), ['class'=>'bms-task1846-editor-title']).
            self::note(self::tr('bms_task1850_wip_hint')).
            \html_writer::div($items,'bms-task1846-editor-list').
            \html_writer::tag('h3', s(self::tr('bms_task1846_mapping')), ['class'=>'bms-task1846-editor-title']).
            \html_writer::div($statusoptions,'bms-task1846-mapping').
            self::note(self::tr('bms_task1846_help')).
            \html_writer::tag('div',
                \html_writer::tag('button', s(self::tr('bms_task1846_save')),
                    ['type'=>'submit', 'class'=>'bms24-ui-btn bms24-ui-btn--primary']).
                \html_writer::tag('button', s(self::tr('bms_task1846_reset')),
                    ['type'=>'submit', 'name'=>'reset', 'value'=>1,
                        'class'=>'bms24-ui-btn']),
                ['class'=>'bms-task1846-editor-actions']);
        $summary = \html_writer::tag('span', '&#9881;', ['aria-hidden'=>'true']).
            \html_writer::tag('span', s(self::tr('bms_task1846_configure')), ['class'=>'sr-only']);
        return \html_writer::tag('details',
            \html_writer::tag('summary', $summary, [
                'class'=>'bms-task1846-toggle', 'title'=>self::tr('bms_task1846_configure'),
                'aria-label'=>self::tr('bms_task1846_configure')]).
            \html_writer::tag('form', $body, [
                'method'=>'post', 'action'=>(new \moodle_url(
                    '/local/kbacompany/task_kanban_preferences.php'))->out(false),
                'class'=>'bms-task1846-editor']),
            ['class'=>'bms-task1846-settings']);
    }

    /** Render a page-local group, retaining the ACL-filtered rows from the current page. */
    private static function kanban_group_key(array $task, string $groupby): array {
        if ($groupby === 'assignee') {
            $userid = (int)($task['effective_assignee_id'] ?? $task['assignee_id'] ?? 0);
            $name = trim((string)($task['effective_assignee_name'] ?? $task['assignee_name'] ?? ''));
            return ['user_'.$userid, $name !== '' ? $name : self::tr('bms_task1850_unassigned')];
        }
        if ($groupby === 'project') {
            $uuid = (string)($task['context_uuid'] ?? '');
            if (($task['context_kind'] ?? '') !== 'project' || $uuid === '') {
                return ['no_project', self::tr('bms_task1850_no_project')];
            }
            $label = trim((string)($task['context_label'] ?? ''));
            return ['project_'.$uuid, $label !== '' ? $label : self::tr('bms_task1850_project')];
        }
        return ['none', ''];
    }

    /** Keep list controls viewport-width: horizontal scrolling belongs to the table only. */
    private static function list_workspace(string $toolbarhtml, string $listcontent): string {
        return \html_writer::div(
            \html_writer::div($toolbarhtml, 'bms-task1849-topbar').$listcontent,
            'bms-task1849-shell bms-task1848-shell', ['data-bms-task1848-shell'=>'1']);
    }

    /** Owned kanban gets its own horizontal viewport with side arrows. */
    private static function kanban_workspace(string $boardhtml): string {
        $viewport = \html_writer::div($boardhtml,
            'bms-task1851-board-scroll', [
                'role'=>'region',
                'tabindex'=>'0',
                'aria-label'=>self::tr('tui_view_kanban'),
                'data-bms-task1848-scroller'=>'1',
            ]);
        return \html_writer::div(
            $viewport.self::table_scroll_button('left').self::table_scroll_button('right'),
            'bms-task1851-board-stage bms-task1848-shell',
            ['data-bms-task1848-shell'=>'1']);
    }

    /** Scroll buttons are siblings of the table viewport, not its scrolling content. */
    private static function table_scroll_button(string $direction): string {
        if (!in_array($direction, ['left','right'], true)) {
            throw new \coding_exception('Invalid horizontal scroll direction');
        }
        $label = self::tr('bms_task1848_scroll_'.$direction);
        $symbol = $direction === 'left' ? '&#10094;' : '&#10095;';
        $button = \html_writer::tag('button',
            \html_writer::tag('span', $symbol, ['aria-hidden'=>'true']), [
                'type'=>'button',
                'class'=>'bms-task1848-scrollbtn bms-task1848-scrollbtn--'.$direction,
                'data-bms-task1848-scroll'=>$direction,
                'title'=>$label,
                'aria-label'=>$label,
                'hidden'=>'hidden',
            ]);
        return \html_writer::div($button, 'bms-task1849-scrollrail bms-task1849-scrollrail--'.$direction);
    }

    /** Real TH/TD markup supports sorted-header accessibility and narrow screens. */
    private static function configurable_list_table(string $company, array $model, array $filters,
            array $taskrows): string {
        $columns = $model['list_preferences']['columns'] ?? task_list_preferences_service::DEFAULT_COLUMNS;
        $sort = task_list_preferences_service::sort((string)($filters['sort'] ?? 'duedate'));
        $direction = task_list_preferences_service::direction((string)($filters['dir'] ?? 'asc'));
        $headers = \html_writer::tag('th',
            \html_writer::tag('span', s(self::tr('bms_task1842_select')), ['class'=>'sr-only']),
            ['scope'=>'col','class'=>'bms-task1842-select-head']);
        foreach ($columns as $key) {
            $label = self::tr(task_list_preferences_service::COLUMNS[$key]);
            $attrs = ['scope' => 'col'];
            if (in_array($key, task_list_preferences_service::SORTABLE, true)) {
                $active = $key === $sort;
                $nextdir = $active && $direction === 'asc' ? 'desc' : 'asc';
                $params = array_merge($filters, [
                    'display'=>'list', 'sort'=>$key, 'dir'=>$nextdir, 'page'=>0]);
                $attrs['aria-sort'] = $active ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none';
                $indicator = $active ? ($direction === 'asc' ? ' ↑' : ' ↓') : '';
                $labelhtml = \html_writer::link(self::url($company, $params),
                    s($label).s($indicator), ['class'=>'bms24-task-sort-link',
                    'title'=>self::tr('bms_task1841_sort_by', $label)]);
            } else { $labelhtml = s($label); }
            $headers .= \html_writer::tag('th', $labelhtml, $attrs);
        }
        $rows = '';
        foreach ($taskrows as $index => $taskrow) {
            $task = $model['tasks'][$index];
            $checkbox = \html_writer::empty_tag('input', [
                'type'=>'checkbox','name'=>'selected['.(string)$task['uuid'].']',
                'value'=>(int)($task['revision'] ?? 0),'class'=>'bms-task1842-row-select',
                'aria-label'=>self::tr('bms_task1842_select').': '.(string)$task['title'],
            ]);
            $cells = \html_writer::tag('td', $checkbox, ['class'=>'bms-task1842-select-cell']);
            foreach ($columns as $key) {
                $cells .= \html_writer::tag('td', $taskrow[$key] ?? '', ['data-column'=>$key]);
            }
            $rows .= \html_writer::tag('tr', $cells);
        }
        // Fixed first column: with auto table layout, a spare column can grow
        // to 100+ px even if the checkbox is only 16 px wide.
        $columnspec = \html_writer::empty_tag('col', ['class'=>'bms-task1847-select-col']);
        foreach ($columns as $key) {
            $columnspec .= \html_writer::empty_tag('col', [
                'class'=>'bms-task1847-col bms-task1847-col--'.$key,
            ]);
        }
        $tablewidth = max(420, 350 + (count($columns) - 1) * 140);
        $table = \html_writer::tag('table',
            \html_writer::tag('colgroup', $columnspec).
            \html_writer::tag('thead', \html_writer::tag('tr', $headers)).
            \html_writer::tag('tbody', $rows), [
                'class'=>'bms24-task-table bms24-task-table--1841 bms-task1847-compact-table',
                'style'=>'min-width:'.$tablewidth.'px',
            ]);
        $hidden = ['uuid'=>$company, 'sesskey'=>sesskey(),
            'requestkey'=>bin2hex(random_bytes(16)), 'scope'=>$filters['scope']];
        foreach (['q','status','stream','sourcekind','battleplan','signal','project','sort','dir'] as $name) {
            if (isset($filters[$name])) { $hidden[$name] = $filters[$name]; }
        }
        $hidden['filterassignee'] = (int)($filters['assignee'] ?? 0);
        $ops = [
            'start'=>self::tr('bms_task1842_start'),
            'accept'=>self::tr('bms_task1842_accept'),
            'duedate'=>self::tr('bms_task1842_due'),
            'assignee'=>self::tr('bms_task1842_assign'),
        ];
        $operation = \html_writer::select($ops,'operation','start',false,[
            'id'=>'bms_task1842_operation','class'=>'bms24-ui-select']);
        $duedate = \html_writer::empty_tag('input',[
            'type'=>'date','name'=>'duedate','id'=>'bms_task1842_duedate',
            'class'=>'bms24-ui-input', 'aria-label'=>self::tr('bms_task1842_due')]);
        $people = [''=>self::tr('bms_task1842_choose_employee')];
        foreach (($model['employees'] ?? []) as $id=>$name) { $people[(int)$id] = (string)$name; }
        $assignee = \html_writer::select($people,'assigneeid','',false,[
            'id'=>'bms_task1842_assignee','class'=>'bms24-ui-select',
            'aria-label'=>self::tr('taskcenter_assignee')]);
        $confirmation = \html_writer::tag('label',
            \html_writer::empty_tag('input', ['type'=>'checkbox','name'=>'confirmed',
                'value'=>1,'required'=>'required']).' '.s(self::tr('bms_task1842_confirm')),
            ['class'=>'bms-task1842-confirm']);
        $buttons = \html_writer::tag('button',s(self::tr('bms_task1842_apply')),[
            'type'=>'submit','class'=>'bms24-ui-btn bms24-ui-btn--primary']);
        $toolbar = \html_writer::div(
            // Hotfix 1844: keep select-all visually compact; native tooltip and
            // aria-label preserve discoverability for pointer/keyboard users.
            \html_writer::tag('label',
                \html_writer::empty_tag('input', [
                    'type'=>'checkbox','data-bms-task1842-select-all'=>'1',
                    'title'=>self::tr('bms_task1842_select_all'),
                    'aria-label'=>self::tr('bms_task1842_select_all'),
                ]),
                ['class'=>'bms-task1842-select-all',
                 'title'=>self::tr('bms_task1842_select_all')]).
            \html_writer::tag('span','0 / '.count($model['tasks']),[
                'class'=>'bms-task1842-selection-count','aria-live'=>'polite',
                'data-bms-task1842-count'=>'1']).$operation.$duedate.$assignee.$confirmation.$buttons,
            'bms-task1842-toolbar');
        // Only the data table scrolls horizontally. Toolbar and view settings remain
        // full-width, accessible and independent of the selected column count.
        $tableviewport = \html_writer::div($table,
            'bms24-task-table-wrap bms-task1849-table-scroll', [
                'role'=>'region','aria-label'=>self::tr('taskcenter_title'),
                'tabindex'=>'0', 'data-bms-task1848-scroller'=>'1',
            ]);
        $stage = \html_writer::div($tableviewport.
            self::table_scroll_button('left').self::table_scroll_button('right'),
            'bms-task1849-table-stage');
        $form = self::hidden($hidden).$toolbar.$stage;
        return \html_writer::tag('form',$form,[
            'method'=>'post',
            'action'=>(new \moodle_url('/local/kbacompany/task_batch_actions.php'))->out(false),
            'class'=>'bms-task1842-batch-form', 'data-bms-task1842-batch'=>'1']);
    }

    /** Date buckets are scoped in SQL, not inferred from an incomplete page. */
    private static function deadlines_navigation(string $company, array $model, array $filters): string {
        $counts = $model['deadline_summary'] ?? [];
        $selected = (string)($filters['duegroup'] ?? '');
        $chips = '';
        $options = [''=>'all','overdue'=>'overdue','today'=>'today','week'=>'week',
            'later'=>'later','undated'=>'undated','closed'=>'closed'];
        foreach ($options as $bucket=>$suffix) {
            $params = $filters;
            unset($params['deadlineview']);
            $params['display'] = 'deadlines';
            $params['duegroup'] = $bucket;
            $params['page'] = 0;
            $text = self::tr('bms_task1843_'.$suffix).' · '.(int)($counts[$bucket === '' ? 'total' : $bucket] ?? 0);
            $chips .= \html_writer::link(self::url($company, $params), s($text), [
                'class'=>'bms-task1843-chip'.($bucket === $selected ? ' is-active' : ''),
                'aria-current'=>$bucket === $selected ? 'page' : null,
            ]);
        }
        $description = \html_writer::tag('p', s(self::tr('bms_task1843_help')),
            ['class'=>'bms-task1843-help']);
        return \html_writer::tag('nav', $chips, ['class'=>'bms-task1843-nav',
            'aria-label'=>self::tr('bms_task1843_title')]).$description;
    }

    /** HTML-only presentation of the canonical, ACL-filtered page of tasks. */
    private static function deadlines_view(string $company, array $model, array $filters): string {
        $dates = task_service::reporting_dates();
        $groups = [];
        foreach ($model['tasks'] as $task) {
            $bucket = task_service::deadline_bucket((string)$task['due_date'], (string)$task['status'], $dates);
            $groups[$bucket][] = $task;
        }
        $html = '';
        foreach (['overdue','today','week','later','undated','closed'] as $bucket) {
            if (empty($groups[$bucket])) { continue; }
            $cards = '';
            foreach ($groups[$bucket] as $task) {
                $priority = in_array($task['priority'], task_service::PRIORITIES, true) ? $task['priority'] : 'normal';
                $taskurl = self::url($company, ['task'=>$task['uuid'], 'scope'=>$filters['scope']]);
                $title = \html_writer::link($taskurl, s((string)$task['title']), ['class'=>'bms-task1843-task-link']);
                // As in the standard list, preserve participation roles in the all-roles view.
                $rolekeys = task_service::role_keys($task, (int)($model['actor'] ?? 0));
                if (!$rolekeys && ($filters['scope'] ?? '') === 'roles') { $rolekeys = ['plan']; }
                $rolebadges = '';
                foreach ($rolekeys as $rolekey) {
                    $rolebadges .= \html_writer::tag('span', s(self::tr('taskcenter_role_'.$rolekey)),
                        ['class'=>'bms24-task-role bms24-task-role--'.$rolekey]);
                }
                $details = self::badge((string)$task['status']).
                    \html_writer::tag('span', s(self::tr('taskcenter_priority_'.$priority)),
                        ['class'=>'bms-task1843-priority bms-task1843-priority--'.$priority]);
                $due = (string)$task['due_date'];
                if ($due !== '') {
                    $details .= \html_writer::tag('span', s(self::tr('taskcenter_duedate').': '.$due),
                        ['class'=>'bms-task1843-date']);
                }
                $responsible = self::employee_link($company, (int)$task['effective_assignee_id'],
                    (string)$task['effective_assignee_name']);
                $meta = \html_writer::tag('span', s(self::tr('taskcenter_assignee').': '),
                    ['class'=>'bms-task1843-meta-label']).$responsible;
                $product = \core_text::substr((string)($task['product'] ?? ''), 0, 130);
                $cards .= \html_writer::tag('article',
                    \html_writer::tag('div', $title, ['class'=>'bms-task1843-task-title']).
                    ($rolebadges !== '' ? \html_writer::div($rolebadges, 'bms24-task-role-list') : '').
                    ($product !== '' ? \html_writer::tag('p', s($product), ['class'=>'bms-task1843-product']) : '').
                    \html_writer::div($details, 'bms-task1843-details').
                    \html_writer::div($meta, 'bms-task1843-meta'),
                    ['class'=>'bms-task1843-card bms-task1843-card--'.$bucket]);
            }
            $id = 'bms-task1843-group-'.$bucket;
            $heading = self::tr('bms_task1843_'.$bucket).' · '.count($groups[$bucket]);
            $html .= \html_writer::tag('section',
                \html_writer::tag('h3', s($heading), ['id'=>$id, 'class'=>'bms-task1843-heading']).
                \html_writer::div($cards, 'bms-task1843-grid'),
                ['class'=>'bms-task1843-group bms-task1843-group--'.$bucket,
                    'aria-labelledby'=>$id]);
        }
        return \html_writer::div($html, 'bms-task1843-workspace');
    }

    public static function listing(string $company, array $model, array $filters, string $display): string {
        $actions=self::link(self::url($company,['view'=>'create']),self::tr('taskcenter_create'),true).self::tools($company,$model['can_manage']);
        $html=self::heading(self::tr('taskcenter_title'),self::owned()?'':self::tr('tui_intro'),'', $actions);
        // The role-union is the default; overdue is now a quick status filter, not a competing role.
        $scopes=self::owned()?['roles','my','created','review','watching','department','all']:
            ['roles','my','created','review','watching','overdue','department','all'];
        $tabs=[];
        foreach($scopes as $scope){
            if($scope==='all'&&!$model['can_manage']){continue;}
            if($scope==='department'&&empty($model['scope_counts']['department_allowed'])){continue;}
            $label=self::tr('taskcenter_scope_'.$scope);
            if(isset($model['scope_counts'][$scope])){
                $label.=' · '.(int)$model['scope_counts'][$scope];
            }
            $tabs[$scope]=['label'=>$label,'url'=>self::url($company,array_merge($filters,
                ['scope'=>$scope,'display'=>$display,'page'=>0]))];
        }
        $html.=self::toolbar('',self::tabs($tabs,$filters['scope'],self::tr('taskcenter_title')));
        $states=[''=>self::tr('tui_all_states')];foreach(task_service::STATUSES as $v){if($v!=='deleted'){$states[$v]=self::tr('taskcenter_status_'.$v);}}
        $streams=[''=>self::tr('tui_all_streams')];foreach(task_service::STREAMS as $v){$streams[$v]=self::tr('taskcenter_stream_'.$v);}
        $sources=[''=>self::tr('tui_all_sources')];foreach(task_service::SOURCE_KINDS as $v){$sources[$v]=self::tr('taskcenter_source_'.$v);}
        $planoptions=[''=>self::tr('tui_all_battle_plans')]+($model['filter_plans']??[]);
        if (self::owned()) {
            // Progressive enhancement: normal GET search works with JavaScript disabled.
            $quickhidden = $filters;
            unset($quickhidden['q'], $quickhidden['deadlineview']);
            $quickhidden['uuid']=$company;
            $quickhidden['display']=$display;
            $quickhidden['page']=0;
            $quicksearch=self::hidden($quickhidden).
                \html_writer::tag('label',s(self::tr('bms_task1840_search')),
                    ['for'=>'bms_task1840_q','class'=>'sr-only']).
                \html_writer::empty_tag('input',[
                    'type'=>'search','id'=>'bms_task1840_q','name'=>'q','value'=>(string)$filters['q'],
                    'maxlength'=>200,'class'=>'bms24-ui-input','autocomplete'=>'off',
                    'placeholder'=>self::tr('bms_task1840_search')]).
                \html_writer::tag('button',s(self::tr('bms_task1840_search_button')),
                    ['class'=>'bms24-ui-btn bms24-ui-btn--primary','type'=>'submit']);
            $html.=\html_writer::tag('form',$quicksearch,[
                'class'=>'bms24-task-quicksearch','method'=>'get','action'=>self::url($company)->out(false),
                'role'=>'search','data-bms-task-fast-search'=>'1']);
            // Status chips use the existing permission-scoped signal predicate.
            $quickchips='';
            $quickfilters=['open'=>'tui_count_open','review'=>'tui_count_review',
                'overdue'=>'tui_count_overdue','soon'=>'bms_exec_soon','done'=>'tui_count_done'];
            foreach($quickfilters as $signal=>$label){
                $params=$filters;
                $params['display']=in_array($display, ['analytics','manager'], true)?'list':$display;
                $params['page']=0;
                $params['signal']=($filters['signal']??'')===$signal?'':$signal;
                $active=($filters['signal']??'')===$signal;
                $quickchips.=\html_writer::link(self::url($company,$params),s(self::tr($label)),[
                    'class'=>'bms24-task-quickchip'.($active?' is-active':''),
                    'aria-pressed'=>$active?'true':'false',
                ]);
            }
            $html.=\html_writer::tag('nav',$quickchips,['class'=>'bms24-task-quickfilters',
                'aria-label'=>self::tr('bms_task1840_quick')]);
            $filterfields=[
                ['id'=>'tui_status','name'=>'status','label'=>self::tr('taskcenter_status'),'type'=>'select','value'=>$filters['status'],'options'=>$states],
                ['id'=>'tui_stream','name'=>'stream','label'=>self::tr('taskcenter_stream'),'type'=>'select','value'=>$filters['stream'],'options'=>$streams],
                ['id'=>'tui_sourcekind','name'=>'sourcekind','label'=>self::tr('taskcenter_source'),'type'=>'select','value'=>$filters['sourcekind'],'options'=>$sources],
                ['id'=>'tui_battleplan','name'=>'battleplan','label'=>self::tr('tui_battle_plan_filter'),'type'=>'select','value'=>$filters['battleplan']??'','options'=>$planoptions],
            ];
            // The two GET forms never have conflicting visible q inputs.
            $advanced=bms24_ui_kit_service::filterbar(self::url($company), self::tr('taskcenter_title'),
                $filterfields,['uuid'=>$company,'scope'=>$filters['scope'],'display'=>$display,
                    'signal'=>$filters['signal']??'','q'=>$filters['q']??'',
                    'assignee'=>$filters['assignee']??0,'project'=>$filters['project']??'',
                    'duegroup'=>$filters['duegroup']??''],
                self::url($company,['scope'=>$filters['scope'],'display'=>$display]),
                self::tr('tui_apply'),self::tr('tui_reset'));
            $advancedactive=(!empty($filters['status'])||!empty($filters['stream'])||
                !empty($filters['sourcekind'])||!empty($filters['battleplan']));
            $html.=\html_writer::tag('details',
                \html_writer::tag('summary',s(self::tr('bms_task1840_more_filters'))).
                $advanced,['class'=>'bms24-task-advanced', 'open'=>$advancedactive?'open':null]);
        } else {
            $form=self::hidden(['uuid'=>$company,'scope'=>$filters['scope'],'display'=>$display,'signal'=>$filters['signal']??'',
                'assignee'=>$filters['assignee']??0, 'project'=>$filters['project']??'',
                'duegroup'=>$filters['duegroup']??'']).
                self::control('q',['label'=>'tui_search','type'=>'search','maxlength'=>200],$filters['q']).
                self::control('status',['label'=>'taskcenter_status','options'=>$states],$filters['status']).
                self::control('stream',['label'=>'taskcenter_stream','options'=>$streams],$filters['stream']).
                self::control('sourcekind',['label'=>'taskcenter_source','options'=>$sources],$filters['sourcekind']).
                self::control('battleplan',['label'=>'tui_battle_plan_filter','options'=>$planoptions],$filters['battleplan']??'').
                \html_writer::div(\html_writer::tag('button',self::tr('tui_apply'),['type'=>'submit','class'=>'btn btn-primary']).
                    self::link(self::url($company,['scope'=>$filters['scope']]),self::tr('tui_reset')),'bms-tui-actions');
            $html.=\html_writer::tag('form',$form,['method'=>'get','action'=>self::url($company)->out(false),'class'=>'bms-tui-filters']);
        }
        $views=[];
        foreach (['list','kanban','deadlines','analytics'] as $mode) {
            $params = $filters;
            unset($params['deadlineview']);
            if ($mode !== 'deadlines') { unset($params['duegroup']); }
            $params['display'] = $mode;
            $params['page'] = 0;
            $views[$mode] = ['label'=>self::tr($mode === 'deadlines' ? 'bms_task1843_title' : 'tui_view_'.$mode),
                'url'=>self::url($company,$params)];
        }
        if (!empty($model['can_manage'])) {
            $params = $filters;
            $params['scope'] = 'all';
            $params['display'] = 'manager';
            $params['signal'] = '';
            $params['page'] = 0;
            $views['manager'] = ['label'=>self::tr('bms_task1853_manager'),
                'url'=>self::url($company, $params)];
        }
        $found=self::note(self::tr('tui_found',$model['total']));
        $signal=(string)($filters['signal']??'');
        if (in_array($signal,['open','done','overdue','review','returned','soon','undated','subtaskoverdue'],true)) {
            $signallabel=self::tr(in_array($signal,['open','done','overdue','review'],true) ? 'tui_count_'.$signal : 'bms_exec_'.$signal);
            $clear=$filters; unset($clear['signal']); $clear['display']=$display; $clear['page']=0;
            $found.=' '.\html_writer::tag('span',s($signallabel),['class'=>'bms24-task-signal-chip']).
                self::link(self::url($company,$clear),self::tr('tui_reset'));
        }
        if (!empty($filters['assignee']) || !empty($filters['project'])) {
            $clear=$filters; unset($clear['assignee'], $clear['project']); $clear['page']=0;
            $found.=' '.\html_writer::tag('span',s(self::tr('bms_exec_narrowed')),['class'=>'bms24-task-signal-chip']).
                self::link(self::url($company,$clear),self::tr('tui_reset'));
        }
                $viewtoolbar = self::tabs($views, $display, self::tr('tui_view_list'));
        if (self::owned() && in_array($display, ['list','kanban'], true)) {
            $settings = $display === 'list' ? self::table_preferences($company, $model, $filters)
                : self::kanban_preferences($company, $model, $filters);
            $viewtoolbar = \html_writer::div($settings.$viewtoolbar, 'bms24-task-viewtools');
        }
        $listtoolbar = self::toolbar($found, $viewtoolbar);
        if (!($display === 'list' && self::owned())) {
            $html .= $listtoolbar;
        }
        if (isset($model['batch_result'])) {
            $r = $model['batch_result'];
            $message = self::tr('bms_task1842_result', (object)[
                'updated'=>(int)($r['updated']??0), 'skipped'=>(int)($r['skipped']??0),
                'total'=>(int)($r['total']??0),
            ]);
            $html .= \html_writer::div(s($message), 'alert alert-info', ['role'=>'status']);
        }
        if ($display === 'deadlines') {
            $html .= self::deadlines_navigation($company, $model, $filters);
        }
        if (!$model['total'] && !in_array($display, ['analytics', 'manager'], true)) {
            // A zero-result search must not remove the list's view tabs and gear.
            // build1849 moved this toolbar into list_workspace(), so the early
            // return previously stranded users without the usual controls.
            $emptyactions = self::link(self::url($company, ['view'=>'create']),
                self::tr('taskcenter_create'), true);
            $activefilters = false;
            foreach (['q', 'status', 'stream', 'sourcekind', 'battleplan', 'signal',
                    'assignee', 'project', 'duegroup'] as $filterkey) {
                if (!empty($filters[$filterkey])) { $activefilters = true; break; }
            }
            if ($activefilters) {
                // Keep the current scope and presentation, but remove every
                // narrowing filter; never allow an external redirect target.
                $emptyactions .= self::link(self::url($company, [
                    'scope'=>(string)($filters['scope'] ?? 'roles'), 'display'=>$display,
                ]), self::tr('bms_task1858_clear_filters'));
            }
            $empty = self::panel(self::tr('tui_empty'),
                self::note(self::tr('tui_empty_help')).
                \html_writer::div($emptyactions, 'bms-task1858-empty-actions'));
            if ($display === 'list' && self::owned()) {
                return $html.self::list_workspace($listtoolbar, $empty);
            }
            return $html.$empty;
        }
        if ($display === 'manager') {
            // Defensive view boundary; the route itself also blocks this display.
            if (empty($model['can_manage'])) { return $html.self::note(self::tr('bms_task1853_restricted')); }
            return $html.self::manager_workspace($company, $model, $filters);
        }
        if ($display === 'analytics') {
            return $html.self::execution_analytics($company, $model, $filters);
        }
        if ($display === 'deadlines') {
            $html .= self::deadlines_view($company, $model, $filters);
            $pagerfilters = $filters;
            unset($pagerfilters['deadlineview']);
            $nav = '';
            if ($model['page'] > 0) {
                $nav .= self::link(self::url($company, array_merge($pagerfilters,
                    ['display'=>'deadlines','page'=>$model['page']-1])), self::tr('tui_previous'));
            }
            $nav .= self::note(self::tr('tui_page', (object)[
                'page'=>$model['page']+1, 'pages'=>(int)ceil($model['total']/$model['pagesize'])]));
            if (($model['page']+1)*$model['pagesize'] < $model['total']) {
                $nav .= self::link(self::url($company, array_merge($pagerfilters,
                    ['display'=>'deadlines','page'=>$model['page']+1])), self::tr('tui_next'));
            }
            return $html.\html_writer::tag('nav', $nav,
                ['class'=>'bms-tui-actions','aria-label'=>self::tr('tui_pages')]);
        }
        $today = task_service::reporting_dates()['today'];
        $rows=[];$columns=[];foreach(task_service::STATUSES as $state){if($state!=='deleted'){$columns[$state]=[];}}
        foreach($model['tasks'] as $task){
            $title=\html_writer::link(self::url($company,['task'=>$task['uuid'],'scope'=>$filters['scope']]),s($task['title']));
            $roleshtml='';
            if (self::owned()) {
                $rolekeys=task_service::role_keys($task, (int)($model['actor']??0));
                if (!$rolekeys && $filters['scope']==='roles') { $rolekeys=['plan']; }
                foreach ($rolekeys as $rolekey) {
                    $roleshtml.=\html_writer::tag('span',s(self::tr('taskcenter_role_'.$rolekey)),
                        ['class'=>'bms24-task-role bms24-task-role--'.$rolekey]);
                }
                if ($roleshtml!=='') { $roleshtml=\html_writer::div($roleshtml,'bms24-task-role-list'); }
            }
            if (self::owned() && $display === 'list') {
                $actorid = (int)($model['actor'] ?? 0);
                $ismanager = (bool)($model['can_manage'] ?? false);
                // RC30/1856: use the SAME authoritative permission projection as
                // the task card and Kanban. In particular, department-scoped
                // approval grants can permit accept/return even when the actor is
                // not the task's named reviewer. No action is performed via GET.
                // The four shortcut operations depend on department ACLs only for
                // approval of a review task. Avoid expensive department grant and
                // organization lookups for every unrelated row on large lists.
                $needsdepartmentapproval = $task['status'] === 'review' && !$ismanager &&
                    (int)$task['reviewer_id'] !== $actorid;
                $allowedactions = task_service::record_actions($task, $actorid, $ismanager,
                    $needsdepartmentapproval ? $company : '');
                $quick = '';
                foreach (['start', 'submit', 'accept', 'return'] as $quickaction) {
                    if (empty($allowedactions[$quickaction])) { continue; }
                    $quick .= \html_writer::link(self::url($company,[
                        'task'=>$task['uuid'], 'view'=>'action','operation'=>$quickaction,
                        'scope'=>$filters['scope']]),
                        s(self::tr('bms_task1842_'.$quickaction)), ['class'=>'bms-task1842-quick-link']);
                }
                if ($quick !== '') { $title .= \html_writer::div($quick,'bms-task1842-quick-actions'); }
            }
            $plancontext=self::list_plan_context($company,$model['plan_memberships'][$task['uuid']]??[]);
            $due=s($task['due_date']?:self::tr('tui_no_date'));
            if($task['due_date']!==''&&$task['due_date']<$today&&!in_array($task['status'],['done','cancelled'],true)){$due.=' '.\html_writer::tag('span',self::tr('taskcenter_overdue'),['class'=>'bms-tui-overdue']);}
            $responsible=self::employee_link($company, (int)$task['assignee_id'], (string)$task['assignee_name']);
            if ((int)$task['effective_assignee_id'] !== (int)$task['assignee_id']) {
                $responsible=self::employee_link($company, (int)$task['effective_assignee_id'],
                    (string)$task['effective_assignee_name']).self::note(self::tr('coverage_task_original').': '.
                    (string)$task['assignee_name']);
            }
            if (self::owned()) {
                // Values are escaped or generated by typed HTML helpers before they are stored.
                $activity = $model['recent_activity'][$task['uuid']] ?? [];
                $row = [
                    'title' => $title.$roleshtml.self::attention_html($task).
                        self::activity_html($activity).
                        self::note(\core_text::substr($task['product'],0,100)).$plancontext,
                    'assignee' => $responsible,
                    'creator' => self::employee_link($company, (int)$task['creator_id'], (string)$task['creator_name']),
                    'reviewer' => self::employee_link($company, (int)$task['reviewer_id'], (string)$task['reviewer_name']),
                    'observer' => self::employee_link($company, (int)$task['observer_id'], (string)$task['observer_name']),
                    'status' => self::badge($task['status']),
                    'duedate' => $due,
                    'priority' => s(self::tr('taskcenter_priority_'.$task['priority'])),
                    'sourcekind' => s(self::tr('taskcenter_source_'.$task['source_kind'])),
                    'stream' => s(self::tr('taskcenter_stream_'.$task['stream'])),
                    'updated' => !empty($task['time_modified']) ? s(userdate((int)$task['time_modified'], '%d.%m.%Y %H:%M')) : '',
                    'created' => !empty($task['time_created']) ? s(userdate((int)$task['time_created'], '%d.%m.%Y %H:%M')) : '',
                ];
                $rows[] = $row;
            } else {
                $rows[] = [$title.$roleshtml.self::note(\core_text::substr($task['product'],0,100)).$plancontext,
                    $responsible, self::badge($task['status']), $due,
                    s(self::tr('taskcenter_priority_'.$task['priority'])),
                    s(self::tr('taskcenter_source_'.$task['source_kind']))];
            }
            $columns[$task['status']][]=\html_writer::div($title.$roleshtml.\html_writer::div($responsible,'bms-tui-note').$plancontext.$due,'bms-tui-board-card');
        }
        if ($display === 'kanban') {
            $board = '';
            if (self::owned()) {
                $prefs = $model['kanban_preferences'] ?? task_kanban_preferences_service::defaults();
                $groups = []; $groupstatuses = [];
                foreach ($prefs['columns'] as $id => $column) {
                    if ($column['visible']) { $groups[$id] = []; $groupstatuses[$id] = []; }
                }
                $fallback = array_key_first($groups);
                $actorid = (int)($model['actor'] ?? 0);
                $manager = (bool)($model['can_manage'] ?? false);
                // Grouping is limited to this page; full-column counts are queried separately.
                foreach ($model['tasks'] as $task) {
                    $status = (string)$task['status'];
                    if (!in_array($status, task_kanban_preferences_service::STATUSES, true)) { continue; }
                    $target = $prefs['mapping'][$status] ?? $status;
                    if (!isset($groups[$target])) { $target = $fallback; }
                    if ($target === null) { continue; }
                    $taskurl = self::url($company, ['task'=>$task['uuid'], 'scope'=>$filters['scope']]);
                    $title = \html_writer::link($taskurl, s((string)$task['title']));
                    $rolekeys = task_service::role_keys($task, $actorid);
                    if (!$rolekeys && $filters['scope'] === 'roles') { $rolekeys = ['plan']; }
                    $rolehtml = '';
                    foreach ($rolekeys as $rolekey) {
                        $rolehtml .= \html_writer::tag('span', s(self::tr('taskcenter_role_'.$rolekey)),
                            ['class'=>'bms24-task-role bms24-task-role--'.$rolekey]);
                    }
                    $responsible = self::employee_link($company,
                        (int)($task['effective_assignee_id'] ?? $task['assignee_id']),
                        (string)($task['effective_assignee_name'] ?? $task['assignee_name']));
                    $plancontext = self::list_plan_context($company,
                        $model['plan_memberships'][$task['uuid']] ?? []);
                    $due = (string)($task['due_date'] ?? '');
                    $duehtml = $due !== '' ? \html_writer::tag('span', s($due),
                        ['class'=>'bms-task1850-due']) : '';
                    $actions = task_service::record_actions($task, $actorid, $manager, $company);
                    $routes = [];
                    foreach ($groups as $columnid => $unused) {
                        $destination = task_kanban_preferences_service::target_status($columnid, $prefs);
                        $operation = task_kanban_preferences_service::drop_operation(
                            $status, $destination, $actions, (int)($task['reviewer_id'] ?? 0) > 0);
                        if ($operation !== '') {
                            $routes[$columnid] = self::url($company, [
                                'task'=>$task['uuid'], 'view'=>'action', 'operation'=>$operation,
                                'scope'=>$filters['scope'],
                            ])->out(false);
                        }
                    }
                    $groupkey = self::kanban_group_key($task, (string)($prefs['groupby'] ?? 'none'));
                    $actionlinks = '';
                    foreach ($routes as $destinationid => $url) {
                        $columnname = trim((string)($prefs['columns'][$destinationid]['label'] ?? ''));
                        if ($columnname === '') { $columnname = self::tr('taskcenter_status_'.$destinationid); }
                        $actionlinks .= \html_writer::link($url, s($columnname),
                            ['class'=>'bms-task1850-action-link']);
                    }
                    $actionshtml = $actionlinks !== '' ? \html_writer::tag('details',
                        \html_writer::tag('summary', s(self::tr('bms_task1850_move'))).
                        \html_writer::div($actionlinks, 'bms-task1850-action-links'),
                        ['class'=>'bms-task1850-actions']) : '';
                    $body = \html_writer::div($title, 'bms-tui-board-card-title').
                        ($rolehtml !== '' ? \html_writer::div($rolehtml, 'bms24-task-role-list') : '').
                        self::attention_html($task).
                        self::activity_html($model['recent_activity'][$task['uuid']] ?? []).
                        \html_writer::div($responsible, 'bms-tui-note').$duehtml.$plancontext.$actionshtml;
                    $attrs = ['class'=>'bms-tui-board-card bms-task1850-card',
                        'data-kanban-task'=>$task['uuid'],
                        'data-kanban-routes'=>json_encode($routes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'draggable'=>!empty($routes) ? 'true' : 'false'];
                    if ($routes) { $attrs['title'] = self::tr('bms_task1850_drag_hint'); }
                    $card = \html_writer::tag('article', $body, $attrs);
                    if (!isset($groups[$target][$groupkey[0]])) {
                        $groups[$target][$groupkey[0]] = ['label'=>$groupkey[1], 'cards'=>[]];
                    }
                    $groups[$target][$groupkey[0]]['cards'][] = $card;
                    $groupstatuses[$target][$status] = true;
                }
                $totalcounts = $model['kanban_status_counts'] ?? [];
                foreach ($groups as $id => $subgroups) {
                    $column = $prefs['columns'][$id];
                    $name = trim((string)$column['label']);
                    if ($name === '') { $name = self::tr('taskcenter_status_'.$id); }
                    $statuses = [];
                    $count = 0;
                    foreach (task_kanban_preferences_service::STATUSES as $status) {
                        if (($prefs['mapping'][$status] ?? '') === $id) {
                            $statuses[] = $status;
                            $count += (int)($totalcounts[$status] ?? 0);
                        }
                    }
                    $badgehtml = '';
                    foreach ($statuses as $status) { $badgehtml .= self::badge($status); }
                    $limit = (int)($prefs['limits'][$id] ?? 0);
                    $overlimit = $limit > 0 && $count > $limit;
                    $title = \html_writer::tag('span', s($name)).
                        \html_writer::tag('span', (string)$count, ['class'=>'bms-task1846-count']);
                    $wip = $limit > 0 ? \html_writer::tag('span',
                        s(self::tr('bms_task1850_wip').': '.$count.' / '.$limit),
                        ['class'=>'bms-task1850-wip'.($overlimit ? ' is-over' : '')]) : '';
                    $content = '';
                    foreach ($subgroups as $group) {
                        $cards = implode('', $group['cards']);
                        if (($prefs['groupby'] ?? 'none') === 'none') { $content .= $cards; }
                        else {
                            $content .= \html_writer::tag('div',
                                \html_writer::tag('h3', s((string)$group['label']).' · '.count($group['cards'])).$cards,
                                ['class'=>'bms-task1850-group']);
                        }
                    }
                    if ($content === '') {
                        $content = \html_writer::div(s(self::tr('bms_task1850_empty')), 'bms-task1850-empty');
                    }
                    $board .= \html_writer::tag('section',
                        \html_writer::tag('header',
                            \html_writer::tag('h2', $title).
                            \html_writer::div($badgehtml, 'bms-task1846-statuses').$wip,
                            ['class'=>'bms-task1846-header']).
                        \html_writer::div($content, 'bms-task1850-dropzone',
                            ['data-kanban-target'=>$id]),
                        ['class'=>'bms-tui-board-column bms-task1846-column'.($overlimit?' bms-task1850-overlimit':''),
                            'data-kanban-column'=>$id, 'aria-label'=>$name]);
                }
            } else {
                foreach ($columns as $state=>$cards) {
                    $board .= \html_writer::tag('section',
                        \html_writer::tag('h2', self::badge($state).' '.count($cards)).implode('',$cards),
                        ['class'=>'bms-tui-board-column']);
                }
            }
            $boardhtml = \html_writer::div($board,
                'bms-tui-board'.(self::owned()?' bms-task1846-board bms-task1850-board':''));
            $html .= self::note(self::tr('tui_board_page')).
                (self::owned() ? self::note(self::tr('bms_task1850_drag_hint')) : '').
                (self::owned() ? self::kanban_workspace($boardhtml)
                    : \html_writer::div($board,'bms-tui-board',
                        ['role'=>'region', 'tabindex'=>'0', 'aria-label'=>self::tr('tui_view_kanban')]));
        }else{
            if (self::owned()) {
                $html .= self::list_workspace($listtoolbar,
                    self::configurable_list_table($company, $model, $filters, $rows));
            } else {
                $headers=['taskcenter_task','taskcenter_assignee','taskcenter_status','taskcenter_duedate',
                    'taskcenter_priority','taskcenter_source'];
                $html .= self::table(array_map([self::class,'tr'],$headers),$rows,self::tr('taskcenter_title'));
            }
        }
        $nav='';if($model['page']>0){$nav.=self::link(self::url($company,array_merge($filters,['display'=>$display,'page'=>$model['page']-1])),self::tr('tui_previous'));}
        $nav.=self::note(self::tr('tui_page',(object)['page'=>$model['page']+1,'pages'=>(int)ceil($model['total']/$model['pagesize'])]));
        if(($model['page']+1)*$model['pagesize']<$model['total']){$nav.=self::link(self::url($company,array_merge($filters,['display'=>$display,'page'=>$model['page']+1])),self::tr('tui_next'));}
        return $html.\html_writer::tag('nav',$nav,['class'=>'bms-tui-actions','aria-label'=>self::tr('tui_pages')]);
    }
    /** Executive read-only task workspace. Shares ACL-filtered existing analytics, never a new task query. */
    private static function manager_workspace(string $company, array $model, array $filters): string {
        $report = $model['execution_report'] ?? ['summary'=>[], 'assignees'=>[], 'projects'=>[], 'risks'=>[]];
        $counts = $model['counts'] ?? [];
        $summary = $report['summary'] ?? [];
        $base = $filters;
        $base['scope'] = 'all';
        $base['display'] = 'list';
        $base['page'] = 0;
        $base['signal'] = '';
        $kpis = [
            ['tui_count_open', (int)($counts['open'] ?? 0), 'open'],
            ['tui_count_overdue', (int)($counts['overdue'] ?? 0), 'overdue'],
            ['tui_count_review', (int)($counts['review'] ?? 0), 'review'],
            ['bms_exec_returned', (int)($summary['returned'] ?? 0), 'returned'],
            ['bms_exec_soon', (int)($summary['soon'] ?? 0), 'soon'],
            ['bms_exec_undated', (int)($summary['undated'] ?? 0), 'undated'],
        ];
        $cards = '';
        foreach ($kpis as [$labelkey, $value, $signal]) {
            $args = $base;
            $args['signal'] = $signal;
            $cards .= \html_writer::link(self::url($company, $args),
                \html_writer::tag('strong', (string)$value).
                \html_writer::tag('span', s(self::tr($labelkey))),
                ['class'=>'bms-task1853-metric',
                    'aria-label'=>self::tr($labelkey).': '.$value]);
        }
        $out = \html_writer::tag('div',
            \html_writer::tag('h2', s(self::tr('bms_task1853_title'))).
            \html_writer::tag('p', s(self::tr('bms_task1853_intro'))).
            \html_writer::div($cards, 'bms-task1853-metrics'),
            ['class'=>'bms-task1853-head']);
        $total = (int)($counts['total'] ?? 0);
        $done = (int)($counts['done'] ?? 0);
        $eligible = max(0, $total - (int)($counts['cancelled'] ?? 0));
        $progress = $eligible > 0 ? (int)round(100 * $done / $eligible) : 0;
        $out .= \html_writer::div(
            \html_writer::tag('span', s(self::tr('bms_task1853_progress')).': '.$progress.'%').
            \html_writer::tag('progress', (string)$progress.'%',
                ['value'=>$progress,'max'=>100,'aria-label'=>self::tr('bms_task1853_progress')]),
            'bms-task1853-progress');
        if ($total === 0) { $out .= self::note(self::tr('bms_exec_empty')); }
        $rows = [];
        foreach (($report['assignees'] ?? []) as $employee) {
            $id = (int)$employee['userid'];
            $args = $base; $args['assignee'] = $id;
            $name = \html_writer::link(self::url($company, $args), s((string)$employee['name']));
            $measure = static function(string $signal, int $number) use ($company, $args): string {
                $target = $args;
                $target['signal'] = $signal;
                return \html_writer::link(self::url($company, $target), (string)$number);
            };
            $rows[] = [$name,
                (string)(int)$employee['active'],
                $measure('overdue', (int)$employee['overdue']),
                $measure('review', (int)$employee['review']),
                $measure('returned', (int)$employee['returned']),
                (string)(int)$employee['done']];
        }
        $team = $rows ? self::table([
            self::tr('taskcenter_assignee'), self::tr('tui_count_open'),
            self::tr('tui_count_overdue'), self::tr('tui_count_review'),
            self::tr('bms_exec_returned'), self::tr('tui_count_done')],
            $rows, self::tr('bms_task1853_team')) : self::note(self::tr('bms_exec_empty'));
        $team .= self::note(self::tr('bms_task1853_team_limit'));
        $out .= self::panel(self::tr('bms_task1853_team'), $team);
        $riskitems = '';
        foreach (($report['risks'] ?? []) as $task) {
            $link = \html_writer::link(self::url($company, [
                'task'=>(string)$task['uuid'], 'scope'=>'all']), s((string)$task['title']));
            $context = \html_writer::tag('span', s((string)$task['assignee'])).
                self::badge((string)$task['status']);
            if ((string)$task['duedate'] !== '') {
                $context .= \html_writer::tag('span', s((string)$task['duedate']));
            }
            $riskitems .= \html_writer::tag('li',
                \html_writer::div($link, 'bms-task1853-risk-title').
                \html_writer::div($context, 'bms-task1853-risk-meta'),
                ['class'=>'bms-task1853-risk']);
        }
        $riskbody = $riskitems !== '' ? \html_writer::tag('ol', $riskitems, ['class'=>'bms-task1853-risk-list'])
            : self::note(self::tr('bms_exec_risks_empty'));
        $out .= self::panel(self::tr('bms_exec_risks'), $riskbody);
        $projects = '';
        foreach (($report['projects'] ?? []) as $project) {
            $args = $base;
            $args['project'] = (string)$project['uuid'];
            $projects .= \html_writer::tag('li',
                \html_writer::link(self::url($company, $args), s((string)$project['name'])).
                \html_writer::tag('span', s(self::tr('tui_count_open').': '.(int)$project['active'])).
                \html_writer::tag('span', s(self::tr('tui_count_overdue').': '.(int)$project['overdue'])),
                ['class'=>'bms-task1853-project']);
        }
        $out .= self::panel(self::tr('bms_exec_projects'),
            $projects !== '' ? \html_writer::tag('ul', $projects, ['class'=>'bms-task1853-project-list'])
                : self::note(self::tr('bms_exec_project_empty')));
        $out .= self::note(self::tr('bms_task1853_limits'));
        return \html_writer::div($out, 'bms-task1853-workspace');
    }

    /** Scoped task execution dashboard. All report values already passed task_service ACL. */
    private static function execution_analytics(string $company, array $model, array $filters): string {
        $report = $model['execution_report'] ?? ['summary'=>[], 'assignees'=>[], 'projects'=>[], 'risks'=>[]];
        $counts = $model['counts'];
        $summary = $report['summary'];
        $base = $filters;
        $base['display'] = 'list';
        $base['page'] = 0;
        $base['signal'] = '';
        $cards = '';
        $metrics = [
            ['total','tui_count_total',''],
            ['open','tui_count_open','open'],
            ['review','tui_count_review','review'],
            ['overdue','tui_count_overdue','overdue'],
            ['done','tui_count_done','done'],
            ['returned','bms_exec_returned','returned'],
            ['soon','bms_exec_soon','soon'],
            ['undated','bms_exec_undated','undated'],
            ['subtaskoverdue','bms_exec_subtaskoverdue','subtaskoverdue'],
        ];
        foreach ($metrics as [$field,$label,$signal]) {
            $value = (int)($counts[$field] ?? $summary[$field] ?? 0);
            $args = $base;
            if ($signal !== '') { $args['signal'] = $signal; }
            $card = \html_writer::tag('strong',(string)$value).
                \html_writer::tag('span',s(self::tr($label)));
            $cards .= \html_writer::link(self::url($company,$args), $card,
                ['class'=>'bms-exec-card', 'aria-label'=>self::tr($label).': '.$value]);
        }
        $out = \html_writer::div($cards,'bms-exec-summary');
        $out .= self::note(self::tr('bms_exec_scope'));
        if (!(int)($counts['total'] ?? 0)) {
            $out .= self::note(self::tr('bms_exec_empty'));
        }
        if (!empty($filters['battleplan']) && !empty($model['filter_plan_label'])) {
            $out .= self::panel(self::tr('tui_analytics_battle_plan_title'),
                \html_writer::div(s((string)$model['filter_plan_label']), 'bms-exec-context'));
        }
        $done = (int)($counts['done'] ?? 0);
        // Cancelled work is neither an achieved result nor an outstanding assignment.
        // Keep it in the total KPI, but exclude it from the completion-rate denominator.
        $eligible = max(0, (int)($counts['total'] ?? 0) - (int)($counts['cancelled'] ?? 0));
        $pct = $eligible > 0 ? (int)round(100 * $done / $eligible) : 0;
        $out .= \html_writer::div(
            \html_writer::div(
                \html_writer::tag('strong',$pct.'%').
                \html_writer::tag('span',s(self::tr('bms_exec_done_progress'))),
                'bms-exec-progress-label').
            \html_writer::div(\html_writer::div('', 'bms-exec-progress-fill',
                ['style'=>'width:'.$pct.'%']), 'bms-exec-progress-track'),
            'bms-exec-progress', ['role'=>'group','aria-label'=>self::tr('bms_exec_done_progress')]);
        $rows = [];
        foreach ($report['assignees'] as $item) {
            $args=$base; $args['assignee']=(int)$item['userid'];
            $assignee = \html_writer::link(self::url($company,$args),s((string)$item['name']));
            // Every clickable table figure must use the same narrowing predicate as
            // the card figure itself; preserve scope, plan and other active filters.
            $measure = static function(string $signal, int $value) use ($company, $args): string {
                $urlargs = $args;
                $urlargs['signal'] = $signal;
                return \html_writer::link(self::url($company, $urlargs), (string)$value);
            };
            $rows[] = [$assignee,$measure('open',(int)$item['active']),
                $measure('review',(int)$item['review']),$measure('overdue',(int)$item['overdue']),
                $measure('returned',(int)$item['returned']),$measure('done',(int)$item['done'])];
        }
        $assigneebody = $rows ? self::table([
            self::tr('taskcenter_assignee'),self::tr('tui_count_open'),
            self::tr('tui_count_review'),self::tr('tui_count_overdue'),
            self::tr('bms_exec_returned'),self::tr('tui_count_done')],$rows,self::tr('bms_exec_people'))
            : self::note(self::tr('bms_exec_empty'));
        $assigneebody .= self::note(self::tr('bms_exec_people_note'));
        $out .= self::panel(self::tr('bms_exec_people'),$assigneebody);
        $rows = [];
        foreach ($report['projects'] as $item) {
            $args=$base; $args['project']=(string)$item['uuid'];
            $measure = static function(string $signal, int $value) use ($company, $args): string {
                $urlargs = $args;
                if ($signal !== '') { $urlargs['signal'] = $signal; }
                return \html_writer::link(self::url($company, $urlargs), (string)$value);
            };
            $rows[] = [\html_writer::link(self::url($company,$args),s((string)$item['name'])),
                $measure('',(int)$item['total']), $measure('open',(int)$item['active']),
                $measure('overdue',(int)$item['overdue']),$measure('done',(int)$item['done'])];
        }
        $projectbody = $rows ? self::table([self::tr('bms_exec_project'),self::tr('tui_count_total'),
            self::tr('tui_count_open'),self::tr('tui_count_overdue'),
            self::tr('tui_count_done')],$rows,self::tr('bms_exec_projects'))
            : self::note(self::tr('bms_exec_project_empty'));
        $projectbody .= self::note(self::tr('bms_exec_projects_note'));
        $out .= self::panel(self::tr('bms_exec_projects'),$projectbody);
        $rows = [];
        foreach ($report['risks'] as $item) {
            $tasklink=\html_writer::link(self::url($company,['task'=>$item['uuid'], 'scope'=>$filters['scope']]),
                s((string)$item['title']));
            if ($item['subtask']) { $tasklink .= ' '.\html_writer::tag('span',
                s(self::tr('bms_exec_subtask')),['class'=>'bms-exec-subtask-label']); }
            $rows[] = [$tasklink,s((string)$item['assignee']),self::badge((string)$item['status']),
                s((string)$item['duedate'] !== '' ? (string)$item['duedate'] : self::tr('tui_no_date'))];
        }
        $riskbody=$rows?self::table([self::tr('taskcenter_task'),self::tr('taskcenter_assignee'),
            self::tr('taskcenter_status'),self::tr('taskcenter_duedate')],$rows,self::tr('bms_exec_risks'))
            : self::note(self::tr('bms_exec_risks_empty'));
        $out .= self::panel(self::tr('bms_exec_risks'),$riskbody);
        $out .= self::note(self::tr('bms_exec_limits'));
        return \html_writer::div($out,'bms-exec-dashboard');
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
        $html=self::heading($title,self::tr('tui_form_help'));
        if (!$editing && !empty($model['prefillparent'])) {
            $parent = $model['prefillparent'];
            $html .= self::panel(self::tr('bms_subtask_parent'),
                \html_writer::link(self::url($company,['task'=>$parent['uuid']]),s($parent['title'])) .
                self::note(self::tr('bms_subtask_parent_hint')));
        }

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
        $form.=\html_writer::div(\html_writer::tag('button',self::tr('taskcenter_save_button'),['class'=>'btn btn-primary','type'=>'submit']).self::link($cancel,get_string('cancel')),
            self::owned()?'bms-tui-actions bms-tui-footer bms24-task-form-footer':'bms-tui-actions bms-tui-footer');
        $formattrs=['method'=>'post','enctype'=>'multipart/form-data',
            'action'=>self::url($company)->out(false),
            'class'=>self::owned()?'bms-tui-edit bms24-task-edit':'bms-tui-edit'];
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
    /** Unified checklist component for overview and checklist tab (no separate data ownership). */
    private static function checklist_panel(string $company, array $model, array $actions, string $tab = 'overview'): string {
        $task = $model['selected'];
        $checks = $model['checklist'] ?? [];
        $total = count($checks);
        $completed = count(array_filter($checks, static function(array $check): bool { return !empty($check['done']); }));
        $percent = $total ? (int)round(100 * $completed / $total) : 0;
        $progresslabel = self::tr('bmscheck_progress', (object)['done' => $completed, 'total' => $total, 'percent' => $percent]);
        $progress = \html_writer::div(
            \html_writer::tag('span', s($progresslabel), ['class' => 'bms-task-check-progress-label']) .
            \html_writer::tag('span', s($percent . '%'), ['class' => 'bms-task-check-progress-percent']),
            'bms-task-check-progress-head');
        $progress .= \html_writer::div(
            \html_writer::div('', 'bms-task-check-progress-fill', ['style' => 'width:' . $percent . '%']),
            'bms-task-check-progress-track', ['role' => 'progressbar', 'aria-valuenow' => $percent,
                'aria-valuemin' => 0, 'aria-valuemax' => 100, 'aria-label' => self::tr('taskcenter_checklist')]);
        $editable = !empty($actions['checkadd']) && !in_array($task['status'], ['done', 'cancelled', 'deleted'], true);
        $rows = '';
        foreach ($checks as $i => $check) {
            $checkuuid = (string)$check['uuid'];
            $idprefix = 'bmscheck_' . preg_replace('/[^a-zA-Z0-9]/', '', $checkuuid);
            $label = ($check['done'] ? '☑' : '☐') . ' ' . $check['text'];
            $name = \html_writer::div(s($check['text']), 'bms-task-check-text' . ($check['done'] ? ' is-done' : ''));
            if (!empty($check['assignee_name'])) {
                $name .= \html_writer::div(s($check['assignee_name']), 'bms-task-check-assignee');
            }
            $status = !empty($check['can_toggle']) && !in_array($task['status'], ['done', 'cancelled', 'deleted'], true)
                ? self::post_form($company, $task, 'checkset', '',
                    self::tr($check['done'] ? 'bmscheck_unmark' : 'bmscheck_mark'), $tab,
                    ['checkuuid' => $checkuuid, 'done' => $check['done'] ? 0 : 1])
                : \html_writer::tag('span', s($check['done'] ? '☑' : '☐'), ['class' => 'bms-task-check-static']);
            $controls = '';
            if ($editable) {
                $rowform = \html_writer::label(s(self::tr('tui_check_item')), $idprefix . '_text', false) .
                    \html_writer::tag('textarea', s($check['text']),
                        ['name' => 'itemtext', 'id' => $idprefix . '_text', 'maxlength' => 1000, 'rows' => 2,
                            'class' => 'bms24-ui-textarea', 'required' => 'required']);
                $rowform .= \html_writer::label(s(self::tr('taskcenter_assignee')), $idprefix . '_assignee', false) .
                    \html_writer::select([0 => self::tr('taskcenter_noassignee')] + ($model['employees'] ?? []),
                        'checkassigneeid', (int)$check['assignee_id'], false,
                        ['id' => $idprefix . '_assignee', 'class' => 'bms24-ui-select']);
                $controls .= \html_writer::tag('details',
                    \html_writer::tag('summary', s(self::tr('bmscheck_edit'))) .
                    self::post_form($company, $task, 'checkedit', $rowform, self::tr('bmscheck_save'), $tab,
                        ['checkuuid' => $checkuuid]),
                    ['class' => 'bms-task-check-edit']);
                if ($i > 0) {
                    $controls .= self::post_form($company, $task, 'checkmove', '',
                        self::tr('bmscheck_moveup'), $tab, ['checkuuid' => $checkuuid, 'direction' => 'up']);
                }
                if ($i < $total - 1) {
                    $controls .= self::post_form($company, $task, 'checkmove', '',
                        self::tr('bmscheck_movedown'), $tab, ['checkuuid' => $checkuuid, 'direction' => 'down']);
                }
                $confirmid = $idprefix . '_confirm';
                $confirmbody = \html_writer::div(
                    \html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'confirmed', 'value' => 1,
                        'required' => 'required', 'id' => $confirmid]) .
                    \html_writer::label(s(self::tr('bmscheck_confirmdelete')), $confirmid, false),
                    'bms-task-check-confirm');
                $controls .= \html_writer::tag('details',
                    \html_writer::tag('summary', s(self::tr('bmscheck_delete'))) .
                    self::post_form($company, $task, 'checkdelete', $confirmbody,
                        self::tr('bmscheck_delete'), $tab, ['checkuuid' => $checkuuid]),
                    ['class' => 'bms-task-check-delete']);
            }
            $rows .= \html_writer::div(
                \html_writer::div($status, 'bms-task-check-toggle') .
                \html_writer::div($name, 'bms-task-check-detail') .
                ($controls !== '' ? \html_writer::div($controls, 'bms-task-check-actions') : ''),
                'bms-task-check-row' . ($check['done'] ? ' is-complete' : ''));
        }
        if (!$rows) { $rows = self::note(self::tr('tui_check_empty')); }
        $add = '';
        if ($editable) {
            $newbody = self::control('itemtext', ['label' => 'tui_check_item', 'required' => true, 'maxlength' => 1000], '') .
                self::control('checkassigneeid', ['label' => 'taskcenter_assignee',
                    'options' => [0 => self::tr('taskcenter_noassignee')] + ($model['employees'] ?? [])], 0);
            $add = \html_writer::tag('details',
                \html_writer::tag('summary', s(self::tr('taskcenter_addcheck'))) .
                self::post_form($company, $task, 'checkadd', $newbody, self::tr('taskcenter_addcheck'), $tab),
                ['class' => 'bms-task-check-add']);
        }
        $body = \html_writer::div($progress . \html_writer::div($rows, 'bms-task-check-items') . $add,
            'bms-task-checklist');
        return self::panel(self::tr('taskcenter_checklist'), $body);
    }

    /** One weekly read-only planning overview for the complete visible subtask set. */
    private static function subtask_week_panel(string $company, array $model): string {
        $week = $model['subtask_week'] ?? [];
        if (!$week || empty($week['visible_count'])) { return ''; }
        $parent = $model['selected'];
        $start = (string)$week['weekstart'];
        $end = (string)$week['weekend'];
        $weekdate = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        $previous = $weekdate->modify('-7 days')->format('Y-m-d');
        $next = $weekdate->modify('+7 days')->format('Y-m-d');
        $subpage = (int)($model['subtasks']['page'] ?? 0);
        $base = ['task'=>$parent['uuid'], 'tab'=>'overview', 'subpage'=>$subpage];
        $nav = \html_writer::link(self::url($company, $base + ['subweek'=>$previous]),
            s(self::tr('bms_subtask_week_previous'))) .
            \html_writer::tag('strong', s(battle_plan_service::period_label($start, $end))) .
            \html_writer::link(self::url($company, $base + ['subweek'=>$next]),
                s(self::tr('bms_subtask_week_next')));
        $nav = \html_writer::div($nav, 'bms-task-subtask-week-navigation');
        $stats = \html_writer::div(
            \html_writer::tag('strong', (string)(int)$week['planned_count']) .
                \html_writer::tag('span', s(self::tr('bms_subtask_week_planned'))),
            'bms-task-subtask-week-stat');
        $stats .= \html_writer::div(
            \html_writer::tag('strong', (string)(int)$week['unmatched_count']) .
                \html_writer::tag('span', s($week['all_plans_visible'] ?
                    self::tr('bms_subtask_week_unplanned') : self::tr('bms_subtask_week_not_visible'))),
            'bms-task-subtask-week-stat');
        $stats .= \html_writer::div(
            \html_writer::tag('strong', (string)(int)$week['done_count']) .
                \html_writer::tag('span', s(self::tr('bms_subtask_week_done'))),
            'bms-task-subtask-week-stat');
        $stats = \html_writer::div($stats, 'bms-task-subtask-week-stats');
        $notice = '';
        if (!empty($week['limited'])) {
            $notice .= self::note(self::tr('bms_subtask_week_partial'));
        }
        if (empty($week['all_plans_visible'])) {
            $notice .= self::note(self::tr('bms_subtask_week_scope'));
        }
        $workload = '';
        foreach ($week['workload'] ?? [] as $person) {
            $workload .= \html_writer::tag('li',
                s($person['assignee_name']) . ' — ' . s(self::tr('bms_subtask_week_person', (object)[
                    'tasks' => (int)$person['tasks'], 'minutes' => (int)$person['planned_minutes'],
                ])));
        }
        if ($workload !== '') {
            $workload = \html_writer::tag('details',
                \html_writer::tag('summary', s(self::tr('bms_subtask_week_workload'))) .
                \html_writer::tag('ul', $workload), ['class'=>'bms-task-subtask-week-workload']);
            if (!empty($week['workload_limited'])) {
                $workload .= self::note(self::tr('bms_subtask_week_more_people'));
            }
        }
        return \html_writer::tag('section',
            \html_writer::tag('h3', s(self::tr('bms_subtask_week_heading'))) .
            $nav . $stats . $notice . $workload,
            ['class'=>'bms-task-subtask-week', 'aria-label'=>self::tr('bms_subtask_week_heading')]);
    }

    /** Hierarchy is presentation of canonical task UUIDs, never a copied child record. */
    public static function subtasks_panel(string $company, array $model): string {
        $parent = $model['selected'];
        $page = $model['subtasks'] ?? ['items' => [], 'total' => 0, 'page' => 0, 'pagesize' => 20];
        $total = (int)$page['total'];
        $actions = '';
        if (!empty($model['can_add_subtasks'])) {
            $actions = self::link(self::url($company, ['view'=>'create', 'parent'=>$parent['uuid']]),
                self::tr('bms_subtask_create'), true);
            $actions .= ' ' . self::link(self::url($company, ['view'=>'linksubtask', 'task'=>$parent['uuid']]),
                self::tr('bms_subtask_link'));
        }
        $body = self::note(self::tr('bms_subtask_intro'));
        $progress = $page['progress'] ?? null;
        if ($progress && $progress['total'] > 0) {
            $done = (int)$progress['done'];
            $total = (int)$progress['total'];
            $cancelled = (int)$progress['cancelled'];
            $active = $total - $cancelled;
            $summary = \html_writer::tag('strong', s(self::tr('bms_subtask_progress_title')));
            if ($progress['limited']) {
                $summary .= self::note(self::tr('bms_subtask_partial_visibility'));
            } else {
                $percent = (int)($progress['percent'] ?? 0);
                $summary .= \html_writer::tag('span', s(self::tr('bms_subtask_progress', (object)[
                    'done'=>$done, 'total'=>$active, 'percent'=>$percent])),
                    ['class'=>'bms-task-subtask-progress-label']);
                $bar = \html_writer::div('', 'bms-task-subtask-progress-fill',
                    ['style'=>'width:'.$percent.'%']);
                $summary .= \html_writer::div($bar, 'bms-task-subtask-progress-track', [
                    'role'=>'progressbar','aria-valuenow'=>$percent, 'aria-valuemin'=>0,
                    'aria-valuemax'=>100, 'aria-label'=>self::tr('bms_subtask_progress_title')]);
            }
            $stats = [];
            if ($progress['review']) { $stats[] = self::tr('bms_subtask_review_count', (int)$progress['review']); }
            if ($progress['overdue']) { $stats[] = self::tr('bms_subtask_overdue_count', (int)$progress['overdue']); }
            if ($cancelled) { $stats[] = self::tr('bms_subtask_cancelled_count', $cancelled); }
            if ($stats) { $summary .= \html_writer::div(s(implode(' · ', $stats)), 'bms-task-subtask-progress-stats'); }
            $body .= \html_writer::div($summary, 'bms-task-subtask-progress');
        }
        $body .= self::subtask_week_panel($company, $model);
        if (!$page['items']) { $body .= self::note(self::tr('bms_subtask_empty')); }
        $today = task_service::reporting_dates()['today'];
        foreach ($page['items'] as $item) {
            $title = \html_writer::link(self::url($company, ['view'=>'detail','task'=>$item['uuid']]),
                s($item['title']), ['class'=>'bms-task-subtask-title']);
            $description = \html_writer::tag('span', s($item['assignee_name'] ?: self::tr('tui_unavailable')),
                ['class'=>'bms-task-subtask-assignee']);
            $due = (string)$item['due_date'];
            if ($due !== '') {
                $description .= \html_writer::tag('span', s($due), ['class'=>'bms-task-subtask-due']);
                if ($due < $today && !in_array($item['status'], ['done', 'cancelled'], true)) {
                    $description .= \html_writer::tag('span',
                        s(self::tr('bms_subtask_overdue_label')), ['class'=>'bms-task-subtask-overdue']);
                }
            }
            $planlinks = '';
            $week = $model['subtask_week'] ?? [];
            if (!empty($week)) {
                $membership = $model['subtask_plan_memberships'][(string)$item['uuid']] ?? [];
                $matched = 0;
                foreach ($membership as $plan) {
                    if ((string)$plan['period_start'] > (string)$week['weekend'] ||
                            (string)$plan['period_end'] < (string)$week['weekstart']) {
                        continue;
                    }
                    $matched++;
                    $planlinks .= \html_writer::link(new \moodle_url('/local/kbacompany/battle_plan.php',
                        ['uuid'=>$company, 'plan'=>$plan['uuid']]),
                        s(self::tr('bms_subtask_week_open_plan')));
                }
                if (!$matched && !in_array((string)$item['status'], ['done', 'cancelled'], true)) {
                    // This is navigation to the existing server-validated add-to-plan
                    // action. No new POST endpoint and no automatic copying of tasks.
                    $planlinks .= \html_writer::link(self::url($company,
                        ['task'=>$item['uuid'], 'view'=>'action', 'operation'=>'addbattleplan']),
                        s(self::tr('taskcenter_add_to_battleplan')));
                }
            }
            if ($planlinks !== '') {
                $planlinks = \html_writer::div($planlinks, 'bms-task-subtask-plan-links');
            }
            $body .= \html_writer::div(
                \html_writer::div($title . \html_writer::div($description, 'bms-task-subtask-meta'),
                    'bms-task-subtask-info') . self::badge($item['status']) . $planlinks,
                'bms-task-subtask-row');
        }
        $prev = (int)$page['page'] > 0 ? self::link(self::url($company,
            ['task'=>$parent['uuid'],'tab'=>'overview','subpage'=>(int)$page['page']-1,
             'subweek'=>(string)($model['subtask_week']['weekstart'] ?? '')]),
            self::tr('tui_previous')) : '';
        $next = ((int)$page['page']+1)*(int)$page['pagesize'] < $total ? self::link(self::url($company,
            ['task'=>$parent['uuid'],'tab'=>'overview','subpage'=>(int)$page['page']+1,
             'subweek'=>(string)($model['subtask_week']['weekstart'] ?? '')]),
            self::tr('tui_next')) : '';
        $body .= \html_writer::div($prev . $next, 'bms-tui-actions');
        return self::panel(self::tr('bms_subtask_heading', $total), $body, $actions);
    }

    /** Progressive-enhancement picker: live suggestions, with a working GET/select fallback. */
    public static function link_subtask_form(string $company, array $model, array $candidates,
            string $requestkey, string $search): string {
        $parent = $model['selected'];
        $back = self::link(self::url($company, ['task'=>$parent['uuid']]), self::tr('tui_back_record'));
        $searchform = self::hidden(['uuid'=>$company,'task'=>$parent['uuid'],'view'=>'linksubtask']);
        $searchform .= \html_writer::tag('label', s(self::tr('bms_subtask_search')),
            ['for'=>'bms_subtask_search']);
        $searchform .= \html_writer::empty_tag('input', [
            'type'=>'search', 'name'=>'subsearch', 'id'=>'bms_subtask_search', 'class'=>'form-control',
            'value'=>$search, 'maxlength'=>160, 'placeholder'=>self::tr('bms_subtask_search_placeholder'),
            'autocomplete'=>'off', 'role'=>'combobox', 'aria-autocomplete'=>'list',
            'aria-controls'=>'bms_subtask_suggestions', 'aria-expanded'=>'false',
            'aria-describedby'=>'bms_subtask_search_status',
        ]);
        $searchform .= \html_writer::div('', 'bms-task-subtask-suggestions', [
            'id'=>'bms_subtask_suggestions', 'role'=>'listbox',
            'aria-label'=>self::tr('bms_subtask_search'), 'hidden'=>'hidden',
        ]);
        $searchform .= \html_writer::div(self::tr('bms_subtask_search_help'),
            'bms-task-subtask-search-status', [
                'id'=>'bms_subtask_search_status', 'role'=>'status', 'aria-live'=>'polite',
            ]);
        $searchform .= \html_writer::div(\html_writer::tag('button', s(self::tr('tui_find_relation')),
            ['type'=>'submit','class'=>'btn btn-outline-secondary']), 'bms-task-subtask-manual-search');
        $searchurl = self::url($company, [
            'task'=>$parent['uuid'], 'view'=>'searchsubtasks',
        ]);
        $searchattrs = [
            'method'=>'get', 'action'=>self::url($company)->out(false),
            'class'=>'bms-task-subtask-search-form',
            'data-subtask-search-url'=>$searchurl->out(false),
            'data-lookup-min'=>self::tr('bms_subtask_search_help'),
            'data-lookup-loading'=>self::tr('bms_subtask_search_loading'),
            'data-lookup-empty'=>self::tr('bms_subtask_search_empty'),
            'data-lookup-error'=>self::tr('bms_subtask_search_error'),
            'data-lookup-more'=>self::tr('bms_subtask_search_more'),
            'data-lookup-selected'=>self::tr('bms_subtask_search_selected'),
            'data-lookup-due'=>self::tr('bms_subtask_search_due'),
        ];
        $html = self::heading(self::tr('bms_subtask_link'), (string)$parent['title']);
        $html .= self::panel(self::tr('bms_subtask_search'),
            \html_writer::tag('form',$searchform,$searchattrs));
        $options = [''=>self::tr('bms_subtask_choose')];
        foreach ($candidates as $item) {
            $options[$item['uuid'].'|'.$item['revision']] = $item['title'];
        }
        $form = self::hidden(['uuid'=>$company,'task'=>$parent['uuid'],
            'view'=>'linksubtask','action'=>'linksubtask','sesskey'=>sesskey(),'requestkey'=>$requestkey]);
        $form .= \html_writer::div(self::control('childchoice', [
            'label'=>'bms_subtask_choose', 'options'=>$options, 'required'=>true,
        ]), 'bms-task-subtask-manual-choice');
        $form .= \html_writer::div('', 'bms-task-subtask-selected', [
            'data-subtask-selected'=>'', 'hidden'=>'hidden',
        ]);
        $form .= self::note(self::tr('bms_subtask_link_hint'));
        $form .= \html_writer::tag('button', s(self::tr('bms_subtask_link')),
            ['type'=>'submit', 'class'=>'btn btn-primary', 'data-subtask-submit'=>'']);
        $html .= self::panel(self::tr('bms_subtask_link'),
            \html_writer::tag('form', $form, [
                'method'=>'post', 'action'=>self::url($company)->out(false),
                'class'=>'bms-task-subtask-link-form',
            ]));
        if (!$candidates && $search !== '') {
            $html .= self::note(self::tr('bms_subtask_no_candidates'));
        }
        return $html . $back;
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
        if (!empty($model['can_manage'])) {
            $primary .= self::link(new \moodle_url('/local/kbacompany/task_templates.php',
                ['uuid'=>$company,'task'=>$id]), self::tr('bms_bundle_capture'));
        }
        $buttons=$primary.(!empty($actions['save'])?self::link(self::url($company,['task'=>$id,'view'=>'edit']),self::tr('taskcenter_edit')):'').
            self::menu($menu).self::link(self::url($company),self::tr('tui_back_list'));
        $assigneehtml = s(self::tr('taskcenter_assignee')).': '.
            self::employee_link($company, (int)$task['assignee_id'], (string)$task['assignee_name']);
        $html=self::detail_heading((string)$task['title'],$assigneehtml,self::badge($task['status']),$buttons);
        if (!empty($model['parent'])) {
            $parentlink = \html_writer::link(self::url($company, ['task'=>$model['parent']['uuid']]),
                s((string)$model['parent']['title']));
            $html .= \html_writer::div(s(self::tr('bms_subtask_parent')) . ': ' . $parentlink .
                ' <span aria-hidden="true">›</span> ' . s((string)$task['title']),
                'bms-task-subtask-breadcrumb');
        }
        $nav=[];foreach(['overview','checklist','result','files','discussion','history'] as $t){$nav[$t]=['label'=>self::tr('tui_tab_'.$t),'url'=>self::url($company,['task'=>$id,'tab'=>$t])];}
        $html.=self::toolbar('',self::tabs($nav,$tab,self::tr('taskcenter_title')));
        $linkedplans=$model['plans']['included']??[];
        if($linkedplans){
            $planlinks='';
            foreach($linkedplans as $plan){
                $statuskey='battleplan_status_'.(string)$plan['status'];
                $planbody=\html_writer::tag('strong',s(self::tr('taskcenter_battleplan_heading')),['class'=>'bms24-task-plan-kicker']).
                    \html_writer::tag('span',s((string)$plan['label']),['class'=>'bms24-task-plan-label']).
                    \html_writer::tag('span',s(self::tr($statuskey)),['class'=>'bms24-task-plan-status']);
                $planlinks.=\html_writer::link(new \moodle_url('/local/kbacompany/battle_plan.php',
                    ['uuid'=>$company,'plan'=>(string)$plan['uuid']]),$planbody,['class'=>'bms24-task-plan-link']);
            }
            $html.=\html_writer::div(
                \html_writer::div($planlinks,'bms24-task-plan-links').
                \html_writer::div(s(self::tr('taskcenter_battleplan_hint')),'bms24-task-plan-note'),
                'bms24-task-plan-context');
        }
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
            // Task Card 3.0 — the accepted result is the focal point of the overview.
            // Only the presentation changes: product, description and task data are unchanged.
            $resulttext = trim((string)$task['product']);
            $resultvalue = $resulttext !== '' ? $resulttext : self::tr('tui_not_set');
            $outcome = \html_writer::tag('section',
                \html_writer::tag('h2', s(self::tr('taskcenter_product')), ['class'=>'bms-task3-outcome-label']) .
                \html_writer::div(s($resultvalue), 'bms-task3-outcome-value'),
                ['class'=>'bms-task3-outcome', 'id'=>'bms-task3-outcome']);
            // Real section anchors, not pseudo-tabs: no extra navigation state to synchronize.
            $quicknav = '';
            foreach (['bms-task3-subtasks'=>'bms_subtask_progress_title',
                    'bms-task3-checklist'=>'tui_tab_checklist',
                    'bms-task3-discussion'=>'tui_tab_discussion'] as $anchor=>$textkey) {
                $quicknav .= \html_writer::link('#'.$anchor, s(self::tr($textkey)),
                    ['class'=>'bms-task3-jump-link']);
            }
            $quicknav = \html_writer::tag('nav',$quicknav,
                ['class'=>'bms-task3-jump-nav','aria-label'=>self::tr('taskcenter_title')]);
            if (self::owned()) {
                $main = self::panel(self::tr('taskcenter_description'),
                    \html_writer::div(s((string)($task['description'] !== '' ? $task['description'] : self::tr('tui_not_set'))),
                        'bms-task3-description'));
            } else {
                $main = self::panel(self::tr('tui_group_content'),
                    self::field('taskcenter_product',$task['product']) .
                    self::field('taskcenter_description',$task['description']));
            }
            $visibleprogress = $model['subtasks']['progress'] ?? [];
            if ((!empty($actions['submit']) || !empty($actions['accept'])) &&
                    (!empty($visibleprogress['pending']) || !empty($visibleprogress['limited']))) {
                $main .= \html_writer::div(s(self::tr('bms_subtask_completion_warning')),
                    'alert alert-warning bms-task-subtask-completion-warning', ['role'=>'status']);
            }
            $main .= \html_writer::div(self::subtasks_panel($company,$model), 'bms-task3-section',
                ['id'=>'bms-task3-subtasks']);
            $main .= \html_writer::div(self::checklist_panel($company,$model,$actions,'overview'),
                'bms-task3-section', ['id'=>'bms-task3-checklist']);
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
            // The full communication thread remains canonical. This is its task-scoped preview,
            // not a second discussion source; ACL and permissions come from $model['discussion'].
            $messages .= self::link(self::url($company,['task'=>$id,'tab'=>'discussion']),
                self::tr('tui_tab_discussion'));
            if (empty($discussion['restricted'])) {
                $discussionparams=['uuid'=>$company,'tab'=>'discussions'];
                if (!empty($discussion['thread_uuid'])) {
                    $discussionparams['thread']=$discussion['thread_uuid'];
                } else {
                    $discussionparams+=['objectkind'=>'task','objectuuid'=>(string)$task['uuid']];
                }
                $messages .= self::link(new \moodle_url('/local/kbacompany/communication_center.php',
                    $discussionparams),self::tr('taskcenter_discussion_open'));
            }
            $aside = self::panel(self::tr('taskcenter_discussion'), $messages);
            if (self::owned()) {
                $recent = '';
                foreach (array_slice($model['history'] ?? [], 0, 3) as $entry) {
                    $recent .= self::activity_html($entry);
                }
                if ($recent === '') { $recent = self::note(self::tr('bms_task1852_no_activity')); }
                $recent .= self::link(self::url($company, ['task'=>$id,'tab'=>'history']),
                    self::tr('bms_task1852_full_history'));
                $aside .= self::panel(self::tr('bms_task1852_recent'), $recent);
                $outcome .= self::attention_html($task);
            }
            if (self::owned()) {
                $html .= \html_writer::div($outcome .
                    \html_writer::div($summary, 'bms-task2-summary-wrap') . $quicknav,
                    'bms-task3-intro');
            } else {
                // The legacy/non-owned presentation keeps its previous summary placement.
                $html .= \html_writer::div($summary, 'bms-task2-summary-wrap');
            }
            $html .= \html_writer::div(
                \html_writer::div($main,'bms-task2-main') .
                \html_writer::div($aside,'bms-task2-aside', ['id'=>'bms-task3-discussion']),
                'bms-task2-layout bms-task3-layout');
        }else if($tab==='checklist'){
            $html.=self::checklist_panel($company,$model,$actions,'checklist');
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
        $html=self::heading($label,(string)$task['title'],self::badge($task['status']));
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
