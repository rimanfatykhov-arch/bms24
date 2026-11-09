<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Presentation-only Position directory profile of BMS-UX-CRUD-001. */
class position_ui_service {
    public static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }
    public static function url(string $company, array $params = [], string $page = 'positions'): \moodle_url {
        return new \moodle_url('/local/kbacompany/' . $page . '.php', ['uuid' => $company] + $params);
    }
    public static function hidden(array $values): string {
        return bms_workspace_ux_service::form_hidden($values);
    }
    private static function link($url, string $label, bool $primary = false): string {
        return bms_workspace_ux_service::record_link($url, $label, $primary);
    }
    private static function note(string $value): string {
        return bms_workspace_ux_service::record_note($value);
    }
    private static function panel(string $title, string $body): string {
        return bms_workspace_ux_service::record_panel($title, $body);
    }
    private static function fields(array $fields): string {
        return bms_workspace_ux_service::record_fields($fields);
    }
    private static function menu(string $title, array $items): string {
        return bms_workspace_ux_service::record_menu($title, $items);
    }
    private static function badge(bool $active): string {
        return \html_writer::span(s(self::tr($active ? 'pdu_active' : 'pdu_inactive')), 'bms-pdu-badge ' . ($active ? 'bms-pdu-active' : 'bms-pdu-muted'));
    }
    private static function date(int $time): string { return $time ? userdate($time, '%d.%m.%Y') : self::tr('pdu_not_set'); }
    private static function table(array $heads, array $rows): string {
        return bms_workspace_ux_service::record_table($heads, $rows);
    }
    private static function pager(string $company, array $model, array $params): string {
        if ($model['total'] <= $model['pagesize']) { return ''; }
        $page = $model['page']; $out = '';
        if ($page > 0) { $out .= self::link(self::url($company, $params + ['page' => $page - 1]), self::tr('pdu_previous')); }
        $out .= self::note(self::tr('pdu_page', (object)['page' => $page + 1, 'pages' => (int)ceil($model['total'] / $model['pagesize'])]));
        if (($page + 1) * $model['pagesize'] < $model['total']) { $out .= self::link(self::url($company, $params + ['page' => $page + 1]), self::tr('pdu_next')); }
        return \html_writer::tag('nav', $out, ['class' => 'bms-pdu-actions bms-pdu-pager', 'aria-label' => self::tr('pdu_pagination')]);
    }
    public static function control(string $name, string $label, string $value, array $spec = [], string $error = ''): string {
        return bms_workspace_ux_service::form_control($name, $label, $value, $spec, $error);
    }
    private static function errors(array $errors): string {
        return bms_workspace_ux_service::form_errors($errors);
    }
    public static function listing(string $company, array $model, array $units): string {
        $buttons = $model['can_manage'] ? self::link(self::url($company, ['view' => 'create'] + ($model['filters']['node'] !== '' ? ['node' => $model['filters']['node']] : [])), self::tr('createposition'), true) : '';
        $buttons .= self::menu(self::tr('pdu_tools'), [
            [self::url($company, [], 'staffing_plan'), self::tr('staffingplan')],
            [self::url($company, [], 'staffing_placement'), self::tr('staffingplacement')],
            [self::url($company, [], 'organization_hub'), self::tr('pdu_organization')],
        ]);
        $out = bms_workspace_ux_service::record_header(s(self::tr('positiondirectory')), s(self::tr('pdu_intro')), s($model['company']['name']), '', $buttons);
        $f = $model['filters']; $tabs = '';
        if ($model['can_manage']) {
            foreach (['active' => 'pdu_active_plural', 'inactive' => 'pdu_inactive_plural', 'all' => 'pdu_all'] as $key => $label) {
                $tabs .= \html_writer::link(self::url($company, array_merge($f, ['status' => $key])), s(self::tr($label)),
                    ['class' => 'bms-pdu-tab' . ($f['status'] === $key ? ' is-active' : ''), 'aria-current' => $f['status'] === $key ? 'page' : null]);
            }
            $out .= \html_writer::tag('nav', $tabs, ['class' => 'bms-pdu-tabs', 'aria-label' => self::tr('pdu_views')]);
        }
        $form = self::hidden(['uuid' => $company, 'status' => $f['status']]);
        $form .= self::control('q', self::tr('pdu_search'), $f['q'], ['type' => 'search', 'maxlength' => 200]);
        $form .= self::control('node', self::tr('orgunitname'), $f['node'], ['options' => ['' => self::tr('pdu_all_units')] + $units['options']]);
        $form .= self::control('manager', self::tr('pdu_kind'), $f['manager'], ['options' => ['' => self::tr('pdu_any_kind'), '1' => self::tr('managerposition'), '0' => self::tr('pdu_specialist')]]);
        $form .= \html_writer::div(\html_writer::tag('button', s(self::tr('pdu_apply')), ['type' => 'submit', 'class' => 'btn btn-primary']) .
            self::link(self::url($company), self::tr('pdu_reset')), 'bms-pdu-actions');
        $out .= \html_writer::tag('form', $form, ['method' => 'get', 'action' => self::url($company)->out(false), 'class' => 'bms-pdu-filters']);
        if ($units['limited']) { $out .= self::note(self::tr('pdu_filter_limited')); }
        $out .= self::note(self::tr('pdu_count', (string)$model['total']));
        if (!$model['positions']) {
            $out .= self::panel(self::tr('pdu_empty'), self::note(self::tr('pdu_empty_help')) .
                ($model['can_manage'] ? self::link(self::url($company, ['view' => 'create']), self::tr('createposition'), true) : ''));
        } else {
            $rows = [];
            foreach ($model['positions'] as $p) {
                $rows[] = [s($p['code']), \html_writer::link(self::url($company, ['position' => $p['uuid']]), s($p['name']), ['class' => 'bms-pdu-record-link']),
                    s(trim($p['nodecode'] . ' — ' . $p['nodename'], ' —')) . (!$p['nodeactive'] ? self::note(self::tr('pdu_unit_unavailable')) : ''),
                    s(self::tr($p['ismanager'] ? 'managerposition' : 'pdu_specialist')), self::badge((bool)$p['active']), s(self::date($p['timemodified']))];
            }
            $out .= self::table([self::tr('positioncode'), self::tr('positionname'), self::tr('orgunitname'), self::tr('pdu_kind'), self::tr('pdu_status'), self::tr('pdu_modified')], $rows);
            $out .= self::pager($company, $model, $f);
        }
        return $out . \html_writer::tag('details', \html_writer::tag('summary', s(self::tr('pdu_boundaries'))) . self::note(self::tr('pdu_boundaries_help')), ['class' => 'bms-pdu-help']);
    }
    public static function detail(string $company, array $model, string $tab, array $extra): string {
        $p = $model['position']; $params = ['position' => $p['uuid']];
        $out = \html_writer::link(self::url($company), '← ' . s(self::tr('positiondirectory')), ['class' => 'bms-pdu-back']);
        $buttons = $model['editable'] ? self::link(self::url($company, $params + ['view' => 'edit']), self::tr('pdu_edit'), true) : '';
        $actions = [];
        if ($p['active']) {
            $actions[] = [self::url($company, $params, 'position_competencies'), self::tr('positioncompetency_open')];
            $actions[] = [self::url($company, $params, 'position_os'), self::tr('positionos_title')];
        }
        $actions[] = [self::url($company, [], 'staffing_plan'), self::tr('staffingplan')];
        $actions[] = [self::url($company, [], 'staffing_placement'), self::tr('staffingplacement')];
        if ($model['can_manage']) {
            $actions[] = [self::url($company, $params + ['view' => $p['active'] ? 'delete' : 'restore']),
                self::tr($p['active'] ? 'pdl_delete' : 'pdl_restore'), $p['active'] ? 'bms-pdu-destructive' : ''];
        }
        $buttons .= self::menu(self::tr('pdu_actions'), $actions);
        $subtitle = trim($p['nodecode'] . ' — ' . $p['nodename'], ' —');
        $out .= bms_workspace_ux_service::record_header(s($p['name']), s($subtitle), s($p['code']), self::badge((bool)$p['active']), $buttons);
        $next = !$p['active'] ? 'pdl_next_inactive' : (!$p['nodeactive'] ? 'pdu_next_unit' : ($p['purpose'] === '' ? 'pdu_next_purpose' : 'pdu_next_relations'));
        $out .= \html_writer::div(\html_writer::tag('strong', s(self::tr('pdu_next_step'))) . self::note(self::tr($next)), 'bms-pdu-notice');
        $tabs = '';
        foreach ([
            'overview' => 'pdu_overview',
            'ckp' => 'pdu_ckp',
            'functions' => 'pdu_functions',
            'indicators' => 'pdu_indicators',
            'competencies' => 'pdu_competencies',
            'authorities' => 'pdu_authorities',
            'processes' => 'pdu_processes',
            'learning' => 'pdu_learning',
            'staffing' => 'pdu_staffing',
            'history' => 'pdu_history',
        ] as $key => $label) {
            $tabs .= \html_writer::link(self::url($company, $params + ['tab' => $key]), s(self::tr($label)),
                ['class' => 'bms-pdu-tab' . ($tab === $key ? ' is-active' : ''), 'aria-current' => $tab === $key ? 'page' : null]);
        }
        $out .= \html_writer::tag('nav', $tabs, ['class' => 'bms-pdu-tabs bms-position2-tabs', 'aria-label' => self::tr('pdu_record_tabs')]);
        if ($tab === 'overview') {
            $out .= self::panel(self::tr('pdu_main'), self::fields([
                self::tr('positionname') => $p['name'], self::tr('positioncode') => $p['code'],
                self::tr('pdu_kind') => self::tr($p['ismanager'] ? 'managerposition' : 'pdu_specialist'),
                self::tr('pdu_status') => self::tr($p['active'] ? 'pdu_active' : 'pdu_inactive')]));
            $out .= self::panel(self::tr('positionpurpose'), \html_writer::div(s($p['purpose'] !== '' ? $p['purpose'] : self::tr('pdu_not_set')), 'bms-pdu-prose'));
            $out .= self::panel(self::tr('pdu_organization'), self::fields([
                self::tr('orgunitname') => $subtitle, self::tr('pdu_branch') => $p['branchname']]) . self::note(self::tr('pdu_definition_help')));
            if (!empty($extra['summary'])) {
                $summary = $extra['summary'];
                $out .= self::panel(self::tr('pdu_profile_completeness'), self::fields([
                    self::tr('pdu_ckp') => (string)$summary['ckp'],
                    self::tr('pdu_functions') => (string)$summary['functions'],
                    self::tr('pdu_indicators') => (string)$summary['metrics'],
                    self::tr('pdu_competencies') => (string)$summary['competencies'],
                    self::tr('pdu_processes') => (string)$summary['processes'],
                    self::tr('pdu_staffing') => (string)$summary['staffing'],
                ]) . self::note(self::tr('pdu_profile_completeness_help')));
            }
        } else if ($tab === 'ckp') {
            $out .= self::note(self::tr('pdu_ckp_help'));
            $rows = [];
            foreach (($extra['rows'] ?? []) as $row) {
                $rows[] = [s((string)$row['code']), s((string)$row['name']),
                    s((string)$row['acceptance'] !== '' ? (string)$row['acceptance'] : self::tr('pdu_not_set'))];
            }
            $out .= $rows ? self::table([self::tr('pdu_code'), self::tr('pdu_ckp_name'), self::tr('pdu_ckp_acceptance')], $rows) :
                self::panel(self::tr('pdu_ckp_empty'), self::note(self::tr('pdu_ckp_empty_help')));
            $out .= self::link(self::url($company, [], 'products'), self::tr('pdu_open_products'), !empty($extra['can_manage']));
        } else if ($tab === 'functions') {
            $out .= self::note(self::tr('pdu_functions_help')) . self::note(self::tr('pdu_count', (string)$extra['total']));
            $rows = [];
            foreach ($extra['rows'] as $r) {
                $rows[] = [s($r['code']), \html_writer::link(self::url($company, ['function' => $r['uuid']], 'functions'), s($r['name'])),
                    s(self::tr('pdu_state_' . $r['status'])), (string)(int)$r['revision'],
                    s($r['productname'] ?: ($r['producttext'] ?: self::tr('pdu_not_set')))];
            }
            $out .= $rows ? self::table([self::tr('pdu_code'), self::tr('pdu_function'), self::tr('pdu_status'), self::tr('pdu_revision'), self::tr('pdu_product')], $rows) :
                self::panel(self::tr('pdu_functions_empty'), self::note(self::tr('pdu_functions_empty_help')));
            $out .= self::pager($company, $extra, $params + ['tab' => $tab]);
            if ($p['active']) { $out .= self::link(self::url($company, $params, 'position_os'), self::tr('positionos_title')); }
        } else if ($tab === 'indicators') {
            $out .= self::note(self::tr('positionindicator_help'));
            if (empty($extra['rows'])) {
                $out .= self::panel(self::tr('positionindicator_empty'), self::note(self::tr('positionindicator_empty_help')));
            } else {
                $rows = [];
                foreach ($extra['rows'] as $row) {
                    $m = $row['metric']; $b = $row['binding']; $n = $row['norm'];
                    $norm = self::tr('pdu_not_set');
                    if ($n) {
                        $norm = (string)$n['normkind'] === 'range' ? (string)$n['minval'] . ' — ' . (string)$n['maxval'] : (string)$n['targetvalue'];
                    }
                    $battle = $row['appears_in_weekly_battle_plan'] ? self::tr('positionindicator_battle_yes') : self::tr('positionindicator_battle_no');
                    $actions = '';
                    if ($extra['can_manage'] && empty($row['compatibility'])) {
                        $edit = self::hidden(['uuid' => $company, 'position' => $p['uuid'], 'view' => 'detail', 'tab' => 'indicators',
                            'indicatoraction' => 'save', 'binduuid' => (string)$b['uuid'], 'statuuid' => (string)$m['uuid'], 'sesskey' => sesskey()]);
                        $edit .= self::control('normkind', self::tr('positionindicator_normkind'), $n ? (string)$n['normkind'] : 'target', ['options' => [
                            'target' => self::tr('positionindicator_norm_target'), 'range' => self::tr('positionindicator_norm_range')], 'suffix' => 'edit-' . (string)$b['uuid']]);
                        $edit .= self::control('targetvalue', self::tr('positionindicator_target'), $n ? (string)($n['targetvalue'] ?? '') : '', ['maxlength' => 32, 'suffix' => 'edit-' . (string)$b['uuid']]);
                        $edit .= self::control('minval', self::tr('positionindicator_min'), $n ? (string)($n['minval'] ?? '') : '', ['maxlength' => 32, 'suffix' => 'edit-' . (string)$b['uuid']]);
                        $edit .= self::control('maxval', self::tr('positionindicator_max'), $n ? (string)($n['maxval'] ?? '') : '', ['maxlength' => 32, 'suffix' => 'edit-' . (string)$b['uuid']]);
                        $edit .= self::control('effectivefrom', self::tr('positionindicator_effectivefrom'), $n ? (string)$n['effectivefrom'] : date('Y-m-d'), ['type' => 'date', 'required' => true, 'suffix' => 'edit-' . (string)$b['uuid']]);
                        $edit .= self::control('weight', self::tr('positionindicator_weight'), (string)($b['weight'] ?? '1'), ['maxlength' => 16, 'suffix' => 'edit-' . (string)$b['uuid']]);
                        $edit .= self::control('sortorder', self::tr('pdu_sort'), (string)($b['sortorder'] ?? 0), ['type' => 'number', 'min' => 0, 'max' => 999999, 'suffix' => 'edit-' . (string)$b['uuid']]);
                        $criticalid = 'positionindicator-critical-' . preg_replace('/[^a-zA-Z0-9]/', '', (string)$b['uuid']);
                        $primaryid = 'positionindicator-primary-' . preg_replace('/[^a-zA-Z0-9]/', '', (string)$b['uuid']);
                        $edit .= \html_writer::div(\html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'critical', 'value' => 1,
                            'id' => $criticalid, 'checked' => !empty($b['critical']) ? 'checked' : null]) .
                            \html_writer::label(s(self::tr('positionindicator_critical')), $criticalid), 'mb-2');
                        $edit .= \html_writer::div(\html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'isprimary', 'value' => 1,
                            'id' => $primaryid, 'checked' => !empty($b['isprimary']) ? 'checked' : null]) .
                            \html_writer::label(s(self::tr('positionindicator_primary')), $primaryid), 'mb-2');
                        $edit .= \html_writer::tag('button', s(self::tr('positionindicator_save')), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']);
                        $editform = \html_writer::tag('form', $edit, ['method' => 'post', 'action' => self::url($company, $params + ['tab' => 'indicators'])->out(false)]);
                        $actions .= \html_writer::tag('details', \html_writer::tag('summary', s(self::tr('positionindicator_edit'))) . $editform, ['class' => 'mb-2']);
                        $form = self::hidden(['uuid' => $company, 'position' => $p['uuid'], 'view' => 'detail', 'tab' => 'indicators',
                            'indicatoraction' => 'archive', 'binduuid' => (string)$b['uuid'], 'sesskey' => sesskey()]);
                        $form .= \html_writer::tag('button', s(self::tr('positionindicator_remove')), ['type' => 'submit', 'class' => 'btn btn-sm btn-outline-danger']);
                        $actions .= \html_writer::tag('form', $form, ['method' => 'post', 'action' => self::url($company, $params + ['tab' => 'indicators'])->out(false)]);
                    }
                    $rows[] = [
                        s((string)$m['code'] . ' — ' . (string)$m['name']),
                        s((string)$m['unit']),
                        s(self::tr('positionindicator_period_' . (string)$m['periodtype'])),
                        s($norm),
                        s((string)($b['weight'] ?? '1')),
                        s(get_string(!empty($b['critical']) ? 'yes' : 'no')),
                        s($battle),
                        $actions,
                    ];
                }
                $out .= self::table([self::tr('positionindicator_indicator'), self::tr('positionindicator_unit'),
                    self::tr('positionindicator_period'), self::tr('positionindicator_norm'), self::tr('positionindicator_weight'),
                    self::tr('positionindicator_critical'), self::tr('positionindicator_battleplan'), get_string('actions')], $rows);
            }
            if ($extra['can_manage']) {
                $options = ['' => self::tr('positionindicator_choose')];
                foreach ($extra['available'] as $metric) {
                    $options[(string)$metric['uuid']] = (string)$metric['code'] . ' — ' . (string)$metric['name'] . ' [' .
                        self::tr('positionindicator_period_' . (string)$metric['periodtype']) . ']';
                }
                if (count($options) > 1) {
                    $form = self::hidden(['uuid' => $company, 'position' => $p['uuid'], 'view' => 'detail', 'tab' => 'indicators',
                        'indicatoraction' => 'save', 'sesskey' => sesskey()]);
                    $form .= self::control('statuuid', self::tr('positionindicator_indicator'), '', ['required' => true, 'options' => $options]);
                    $form .= self::control('normkind', self::tr('positionindicator_normkind'), 'target', ['options' => [
                        'target' => self::tr('positionindicator_norm_target'), 'range' => self::tr('positionindicator_norm_range')]]);
                    $form .= self::control('targetvalue', self::tr('positionindicator_target'), '', ['maxlength' => 32]);
                    $form .= self::control('minval', self::tr('positionindicator_min'), '', ['maxlength' => 32]);
                    $form .= self::control('maxval', self::tr('positionindicator_max'), '', ['maxlength' => 32]);
                    $form .= self::control('effectivefrom', self::tr('positionindicator_effectivefrom'), date('Y-m-d'), ['type' => 'date', 'required' => true]);
                    $form .= self::control('weight', self::tr('positionindicator_weight'), '1', ['maxlength' => 16]);
                    $form .= self::control('sortorder', self::tr('pdu_sort'), '0', ['type' => 'number', 'min' => 0, 'max' => 999999]);
                    $checks = \html_writer::div(
                        \html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'critical', 'value' => 1, 'id' => 'positionindicator-critical']) .
                        \html_writer::label(s(self::tr('positionindicator_critical')), 'positionindicator-critical'), 'mb-2');
                    $checks .= \html_writer::div(
                        \html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'isprimary', 'value' => 1, 'id' => 'positionindicator-primary']) .
                        \html_writer::label(s(self::tr('positionindicator_primary')), 'positionindicator-primary'), 'mb-2');
                    $form .= $checks;
                    $form .= self::note(self::tr('positionindicator_norm_help'));
                    $form .= \html_writer::tag('button', s(self::tr('positionindicator_add')), ['type' => 'submit', 'class' => 'btn btn-primary']);
                    $out .= self::panel(self::tr('positionindicator_add'), \html_writer::tag('form', $form, [
                        'method' => 'post', 'action' => self::url($company, $params + ['tab' => 'indicators'])->out(false), 'class' => 'bms-pdu-form']));
                } else {
                    $out .= self::note(self::tr('positionindicator_all_assigned'));
                }
            }
            $out .= self::note(self::tr('positionindicator_battle_rule'));
            $out .= self::link(self::url($company, [], 'indicators'), self::tr('positionindicator_open_registry'));
        } else if ($tab === 'competencies') {
            $profile = $extra['profile'] ?? null;
            $out .= self::note(self::tr('pdu_competencies_help'));
            $out .= self::panel(self::tr('pdu_competency_profile'), self::fields([
                self::tr('pdu_status') => $profile ? self::tr('pdu_competency_status_' . (string)$profile['status']) : self::tr('pdu_competency_not_created'),
                self::tr('pdu_competency_requirements') => (string)($extra['summary']['requirements'] ?? 0),
                self::tr('pdu_competency_required') => (string)($extra['summary']['required'] ?? 0),
                self::tr('pdu_competency_high') => (string)($extra['summary']['high_priority'] ?? 0),
            ]));
            $rows = [];
            foreach (($extra['requirements'] ?? []) as $row) {
                $rows[] = [s((string)$row['code']), s((string)$row['name']),
                    s($row['target_level'] === null ? self::tr('pdu_not_set') : (string)$row['target_level']),
                    s(self::tr('positioncompetency_priority_' . (string)$row['priority'])),
                    s(get_string(!empty($row['required']) ? 'yes' : 'no'))];
            }
            if ($rows) {
                $out .= self::table([self::tr('pdu_code'), self::tr('pdu_competency'), self::tr('positioncompetency_target_level'),
                    get_string('priority'), self::tr('positioncompetency_required')], $rows);
            } else {
                $out .= self::panel(self::tr('pdu_competency_empty'), self::note(self::tr('pdu_competency_empty_help')));
            }
            if ($p['active']) { $out .= self::link(self::url($company, $params, 'position_competencies'), self::tr('pdu_manage_competencies'), true); }
        } else if ($tab === 'authorities') {
            $out .= self::note(self::tr('pdu_authorities_help'));
            $rows = [];
            foreach (($extra['rows'] ?? []) as $row) {
                $direction = ($row['target_position_uuid'] ?? '') === $p['uuid'] ? self::tr('pdu_authority_incoming') : self::tr('pdu_authority_outgoing');
                $rows[] = [s($direction), s(trim((string)$row['function_code'] . ' — ' . (string)$row['function_name'], ' —')),
                    s(self::tr('functiontransfer_type_' . (string)$row['transfer_type'])),
                    s(self::tr('functiontransfer_status_' . (string)$row['effective_status'])),
                    s((string)$row['authority_scope'] !== '' ? (string)$row['authority_scope'] : self::tr('pdu_not_set')),
                    s((int)$row['starts_at'] ? self::date((int)$row['starts_at']) : self::tr('pdu_not_set')),
                    s((int)$row['ends_at'] ? self::date((int)$row['ends_at']) : self::tr('pdu_open_period'))];
            }
            $out .= $rows ? self::table([self::tr('pdu_authority_direction'), self::tr('pdu_function'), self::tr('pdu_authority_type'),
                self::tr('pdu_status'), self::tr('pdu_authority_scope'), self::tr('pdu_from'), self::tr('pdu_to')], $rows) :
                self::panel(self::tr('pdu_authorities_empty'), self::note(self::tr('pdu_authorities_empty_help')));
            $out .= self::link(self::url($company, [], 'function_transfers'), self::tr('pdu_open_authorities'), !empty($extra['can_manage']));
        } else if ($tab === 'processes') {
            $out .= self::note(self::tr('pdu_processes_help'));
            $rows = [];
            foreach (($extra['rows'] ?? []) as $row) {
                $name = (string)$row['name'] !== '' ? (string)$row['name'] : self::tr('pdu_process_unnamed');
                $rows[] = [
                    \html_writer::link(self::url($company, ['process' => (string)$row['uuid']], 'processes'), s($name)),
                    s((string)count($row['functions'])),
                    s(implode('; ', array_slice($row['functions'], 0, 5))) . (count($row['functions']) > 5 ? self::note(self::tr('pdu_more_functions', (string)(count($row['functions']) - 5))) : ''),
                ];
            }
            $out .= $rows ? self::table([self::tr('pdu_process'), self::tr('pdu_process_function_count'), self::tr('pdu_functions')], $rows) :
                self::panel(self::tr('pdu_processes_empty'), self::note(self::tr('pdu_processes_empty_help')));
            $out .= self::link(self::url($company, [], 'processes'), self::tr('pdu_open_processes'));
        } else if ($tab === 'learning') {
            $profile = $extra['profile'] ?? null;
            $out .= self::note(self::tr('pdu_learning_help'));
            $out .= self::panel(self::tr('pdu_learning_basis'), self::fields([
                self::tr('pdu_competency_requirements') => (string)($extra['summary']['requirements'] ?? 0),
                self::tr('pdu_competency_required') => (string)($extra['summary']['required'] ?? 0),
                self::tr('pdu_status') => $profile ? self::tr('pdu_competency_status_' . (string)$profile['status']) : self::tr('pdu_competency_not_created'),
            ]) . self::note(self::tr('pdu_learning_boundary')));
            $required = [];
            foreach (($extra['requirements'] ?? []) as $row) {
                if (empty($row['required'])) { continue; }
                $required[] = s((string)$row['code'] . ' — ' . (string)$row['name']) .
                    ($row['target_level'] === null ? '' : ' · ' . s(self::tr('pdu_target_level_short', (string)$row['target_level'])));
            }
            if ($required) { $out .= self::panel(self::tr('pdu_learning_requirements'), implode('<br>', $required)); }
            $out .= \html_writer::div(
                self::link(self::url($company, $params, 'position_competencies'), self::tr('pdu_manage_competencies'), true) .
                self::link(self::url($company, [], 'learning'), self::tr('pdu_open_learning')),
                'bms-pdu-actions');
        } else if ($tab === 'staffing') {
            $out .= self::note(self::tr('pdu_staffing_help'));
            $rows = [];
            foreach ($extra['rows'] as $r) {
                $rows[] = [\html_writer::link(self::url($company, ['plan' => $r['planuuid']], 'staffing_plan'), s($r['name'])),
                    s(format_float((float)$r['plannedfte'], 2)), s(self::date((int)$r['effectivefrom'])),
                    s((int)$r['effectiveto'] ? self::date((int)$r['effectiveto']) : self::tr('pdu_open_period')),
                    self::link(self::url($company, ['plan' => $r['planuuid']], 'staffing_placement'), self::tr('pdu_open_placement'))];
            }
            $out .= $rows ? self::table([self::tr('staffingplan'), self::tr('pdu_fte'), self::tr('pdu_from'), self::tr('pdu_to'), self::tr('pdu_placement')], $rows) :
                self::panel(self::tr('pdu_staffing_empty'), self::note(self::tr('pdu_staffing_empty_help')));
            $out .= self::pager($company, $extra, $params + ['tab' => $tab]);
            $out .= self::link(self::url($company, [], 'staffing_plan'), self::tr('staffingplan'));
        } else {
            $out .= self::note(self::tr('pdu_history_help'));
            if (!$extra['available']) { $out .= self::panel(self::tr('pdu_history_unavailable'), self::note(self::tr('pdu_history_unavailable_help'))); }
            else if (!$extra['rows']) { $out .= self::panel(self::tr('pdu_history_empty'), self::note(self::tr('pdu_history_empty_help'))); }
            else {
                $rows = [];
                foreach ($extra['rows'] as $r) {
                    $labels = []; foreach ($r['fields'] as $key) { if (isset(self::field_labels()[$key])) { $labels[] = self::tr(self::field_labels()[$key]); } }
                    $rows[] = [s(userdate($r['time'], '%d.%m.%Y %H:%M')), s($r['actorname']),
                        s(self::tr(['created' => 'pdu_created', 'edited' => 'pdu_edited', 'deactivated' => 'pdl_event_deleted', 'restored' => 'pdl_event_restored'][$r['operation']] ?? 'pdu_edited')),
                        s(implode(', ', $labels)) . (in_array($r['reason'] ?? '', ['duplicate', 'created_in_error', 'no_longer_needed', 'restore'], true) ? self::note(self::tr('pdl_reason_' . $r['reason'])) : '')];
                }
                $out .= self::table([self::tr('pdu_when'), self::tr('pdu_actor'), self::tr('pdu_action'), self::tr('pdu_changed_fields')], $rows);
            }
        }
        $out .= \html_writer::tag('details', \html_writer::tag('summary', s(self::tr('pdu_advanced'))) . self::fields([
            'UUID' => $p['uuid'], self::tr('pdu_created_on') => self::date($p['timecreated']),
            self::tr('pdu_modified') => self::date($p['timemodified']), self::tr('pdu_sort') => (string)$p['sortorder']]), ['class' => 'bms-pdu-help']);
        return $out;
    }
    /** Explicit reversible removal/recovery; GET only renders consequences and blockers. */
    public static function lifecycle_form(string $company, array $model, array $proof, array $errors,
            string $reason = '', bool $confirmed = false): string {
        $p = $model['position']; $operation = $model['operation']; $deleting = $operation === 'delete';
        $back = self::url($company, ['position' => $p['uuid']]);
        $out = \html_writer::link($back, '← ' . s(self::tr('pdu_back_record')), ['class' => 'bms-pdu-back']);
        $out .= bms_workspace_ux_service::record_header(s(self::tr($deleting ? 'pdl_delete' : 'pdl_restore')),
            s(self::tr($deleting ? 'pdl_delete_help' : 'pdl_restore_help')), s($model['company']['name']));
        $out .= self::errors($errors);
        $out .= self::panel(self::tr('pdl_target'), self::fields([
            self::tr('positioncode') => $p['code'], self::tr('positionname') => $p['name'],
            self::tr('orgunitname') => trim($p['nodecode'] . ' — ' . $p['nodename'], ' —'),
            self::tr('pdu_status') => self::tr($p['active'] ? 'pdu_active' : 'pdu_inactive')]));
        if (!$model['state_matches']) {
            return $out . self::panel(self::tr('pdl_unavailable'), self::note(self::tr('pdu_stale'))) . self::link($back, self::tr('pdu_back_record'));
        }
        if ($model['blockers']) {
            $items = '';
            foreach ($model['blockers'] as $category) { $items .= \html_writer::tag('li', s(self::tr('pdl_usage_' . $category))); }
            $out .= self::panel(self::tr('pdl_blocked'), self::note(self::tr($deleting ? 'pdl_blocked_help' : 'pdl_restore_blocked')) .
                \html_writer::tag('ul', $items) . self::note(self::tr('pdl_no_cascade')));
            return $out . self::link($back, self::tr('pdu_back_record'));
        }
        $out .= self::panel(self::tr('pdl_consequences'), self::note(self::tr($deleting ? 'pdl_consequences_delete' : 'pdl_consequences_restore')));
        $form = self::hidden(['uuid' => $company, 'position' => $p['uuid'], 'view' => $operation,
            'action' => $operation, 'sesskey' => sesskey()] + $proof);
        if ($deleting) {
            $options = ['' => self::tr('pdl_select_reason')];
            foreach (['duplicate', 'created_in_error', 'no_longer_needed'] as $key) { $options[$key] = self::tr('pdl_reason_' . $key); }
            $form .= self::control('reason', self::tr('pdl_reason'), $reason, ['required' => true, 'options' => $options]);
        } else { $form .= self::hidden(['reason' => 'restore']); }
        $checkbox = \html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'pdl_confirmed', 'name' => 'confirmed',
            'value' => 1, 'required' => 'required', 'checked' => $confirmed ? 'checked' : null]);
        $form .= \html_writer::div($checkbox . \html_writer::label(s(self::tr($deleting ? 'pdl_confirm_delete' : 'pdl_confirm_restore')), 'pdl_confirmed'), 'bms-pdu-confirm');
        $form .= \html_writer::div(\html_writer::tag('button', s(self::tr($deleting ? 'pdl_delete' : 'pdl_restore')),
            ['type' => 'submit', 'class' => 'btn ' . ($deleting ? 'btn-danger' : 'btn-primary')]) .
            self::link($back, self::tr('pdu_cancel')), 'bms-pdu-actions bms-pdu-footer');
        return $out . \html_writer::tag('form', $form, ['method' => 'post',
            'action' => self::url($company, ['view' => $operation, 'position' => $p['uuid']])->out(false), 'class' => 'bms-pdu-form']);
    }

    public static function field_labels(): array {
        return ['name' => 'positionname', 'purpose' => 'positionpurpose', 'orgnodeuuid' => 'orgunitname',
            'ismanager' => 'pdu_kind', 'sortorder' => 'pdu_sort', 'active' => 'pdu_status'];
    }
    public static function form_schema(): array {
        return [
            ['label' => 'pdu_main', 'fields' => ['name' => ['required' => true, 'maxlength' => 255],
                'purpose' => ['type' => 'textarea', 'maxlength' => 20000, 'help' => self::tr('pdu_purpose_help')]]],
            ['label' => 'pdu_organization', 'fields' => ['ismanager' => ['options' => ['0' => self::tr('pdu_specialist'), '1' => self::tr('managerposition')],
                'help' => self::tr('pdu_manager_help')]]],
        ];
    }
    public static function form(string $company, bool $creating, array $values, array $errors, array $proof, array $units, ?array $model): string {
        $title = self::tr($creating ? 'createposition' : 'pdu_edit_title');
        $params = $creating ? [] : ['position' => $model['position']['uuid']];
        $out = \html_writer::link(self::url($company, $params), '← ' . s(self::tr($creating ? 'positiondirectory' : 'pdu_back_record')), ['class' => 'bms-pdu-back']);
        $out .= bms_workspace_ux_service::record_header(s($title), s(self::tr('pdu_form_intro')));
        $out .= self::errors($errors);
        $out .= self::note(self::tr('positioncode') . ': ' . ($creating ? self::tr('pdu_auto_code') : $model['position']['code']));
        $form = self::hidden(['uuid' => $company, 'view' => $creating ? 'create' : 'edit', 'sesskey' => sesskey()] + $params + $proof);
        foreach (self::form_schema() as $group) {
            $body = '';
            if ($group['label'] === 'pdu_organization') {
                if ($creating) {
                    $body .= self::control('unitq', self::tr('pdu_find_unit'), $units['query'], ['type' => 'search', 'maxlength' => 100]);
                    $body .= \html_writer::tag('button', s(self::tr('pdu_find')), ['type' => 'submit', 'name' => 'action', 'value' => 'unitsearch', 'formnovalidate' => 'formnovalidate', 'class' => 'btn btn-outline-secondary']);
                    $body .= self::control('orgnodeuuid', self::tr('orgunitname'), (string)($values['orgnodeuuid'] ?? ''), ['required' => true,
                        'options' => ['' => self::tr('pdu_select_unit')] + $units['options']], $errors['orgnodeuuid'] ?? '');
                    $body .= self::note(self::tr($units['total'] ? ($units['limited'] ? 'pdu_units_limited' : 'pdu_units_help') : 'pdu_units_empty'));
                    if (!$units['total'] && $units['query'] === '') {
                        $url = new \moodle_url('/local/kbacompany/designs.php', ['uuid' => $company, 'firstunit' => 1]);
                        $body .= \html_writer::link($url, s(self::tr('pdu_firstunit')), ['target' => '_blank', 'rel' => 'noopener']);
                        $body .= self::note(self::tr('pdu_refreshunits'));
                    }
                } else {
                    $p = $model['position'];
                    $body .= self::fields([self::tr('orgunitname') => trim($p['nodecode'] . ' — ' . $p['nodename'], ' —')]);
                    $body .= self::note(self::tr('pdu_unit_readonly'));
                }
            }
            foreach ($group['fields'] as $key => $spec) {
                $body .= self::control($key, self::tr(self::field_labels()[$key]), (string)($values[$key] ?? ''), $spec, $errors[$key] ?? '');
                if ($key === 'name' || $key === 'purpose') {
                    $body .= bms_ai_workspace_service::field_button($company, 'position', $key, 'pdu_' . $key);
                }
            }
            $form .= self::panel(self::tr($group['label']), $body);
        }
        $form .= \html_writer::tag('details', \html_writer::tag('summary', s(self::tr('pdu_advanced'))) .
            self::control('sortorder', self::tr('pdu_sort'), (string)($values['sortorder'] ?? '0'), ['type' => 'number', 'min' => 0, 'max' => 999999], $errors['sortorder'] ?? ''),
            ['class' => 'bms-pdu-help', 'open' => isset($errors['sortorder']) ? 'open' : null]);
        $form .= \html_writer::div(\html_writer::tag('button', s(self::tr('pdu_save')), ['type' => 'submit', 'name' => 'action', 'value' => 'save', 'class' => 'btn btn-primary']) .
            self::link(self::url($company, $params), self::tr('pdu_cancel')), 'bms-pdu-actions bms-pdu-footer');
        return $out . \html_writer::tag('form', $form, ['method' => 'post', 'action' => self::url($company, $params + ['view' => $creating ? 'create' : 'edit'])->out(false), 'class' => 'bms-pdu-form']);
    }
}
