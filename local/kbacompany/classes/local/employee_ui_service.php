<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Presentation schema for BMS Employee CRUD. */
class employee_ui_service {
    public const CONTRACT_VERSION = '1.1.0-rc15';

    /** Field groups for Create/Edit views. */
    public static function form_schema(string $mode): array {
        if (!in_array($mode, ['create', 'edit'], true)) {
            throw new \invalid_parameter_exception('Unknown employee form mode');
        }
        return [
            [
                'key' => 'identity',
                'fields' => ['firstname', 'lastname'],
            ],
            [
                'key' => 'contacts',
                'fields' => ['email', 'phone'],
            ],
            [
                'key' => 'organization',
                'fields' => $mode === 'edit' ? ['position', 'department'] : [],
                'readonly' => true,
            ],
        ];
    }

    /** Human field label key. */
    public static function label_key(string $field): string {
        $map = [
            'firstname' => 'firstname',
            'lastname' => 'lastname',
            'email' => 'email',
            'phone' => 'phone',
            'position' => 'positionname',
            'department' => 'bms_employee_department',
        ];
        return $map[$field] ?? $field;
    }

    /** Required editable fields. */
    public static function required(string $field): bool {
        return in_array($field, ['firstname', 'lastname', 'email'], true);
    }

    /** Input type for editable fields. */
    public static function input_type(string $field): string {
        return $field === 'email' ? 'email' : 'text';
    }

    /** Section label key. */
    public static function section_label_key(string $section): string {
        $map = [
            'identity' => 'bms_employee_section_identity',
            'contacts' => 'bms_employee_section_contacts',
            'organization' => 'bms_employee_section_organization',
            'membership' => 'bms_employee_section_membership',
            'integration' => 'bms_employee_section_integration',
            'advanced' => 'bms_employee_section_advanced',
        ];
        return $map[$section] ?? 'bms_employee_details';
    }

    /** Compact status class for list/detail badges. */
    public static function status_badge_class(bool $active): string {
        return $active ? 'badge badge-success' : 'badge badge-secondary';
    }

    /** Compact sync badge class. */
    public static function sync_badge_class(string $status): string {
        if ($status === 'error') { return 'badge badge-danger'; }
        if (in_array($status, ['pending', 'waiting_company_mapping'], true)) { return 'badge badge-warning'; }
        if (in_array($status, ['synced', 'done'], true)) { return 'badge badge-success'; }
        return 'badge badge-secondary';
    }

    public static function tr(string $key, $a = null): string { return get_string($key, 'local_kbacompany', $a); }
    public static function url(string $company, string $page = 'employees', array $params = []): \moodle_url {
        return new \moodle_url('/local/kbacompany/' . $page . '.php', ['uuid' => $company] + $params);
    }
    public static function hidden(array $values): string {
        $out = '';
        foreach ($values as $name => $value) { $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]); }
        return $out;
    }
    public static function link($url, string $label, bool $primary = false): string {
        return \html_writer::link($url, s($label), ['class' => 'btn ' . ($primary ? 'btn-primary' : 'btn-outline-secondary')]);
    }
    private static function note(string $text): string { return \html_writer::tag('p', s($text), ['class' => 'bms-ecu-note']); }
    private static function panel(string $title, string $body): string {
        return \html_writer::tag('section', \html_writer::tag('h2', s($title), ['class' => 'bms-crm-section-title']) . $body,
            ['class' => 'bms-crm-section bms-ecu-panel']);
    }
    private static function field(string $label, string $value): string {
        return \html_writer::div(\html_writer::tag('dt', s($label)) . \html_writer::tag('dd', s($value !== '' ? $value : self::tr('ecu_not_set'))), 'bms-ecu-field');
    }
    private static function fields(array $values): string {
        $out = ''; foreach ($values as $label => $value) { $out .= self::field($label, (string)$value); }
        return \html_writer::tag('dl', $out, ['class' => 'bms-crm-field-grid bms-ecu-grid']);
    }
    public static function badge(array $employee): string {
        $active = !empty($employee['membership_active']);
        $out = \html_writer::tag('span', s(self::tr($active ? 'ecu_active_member' : 'ecu_archived_member')),
            ['class' => 'bms-ecu-badge ' . ($active ? 'bms-ecu-active' : 'bms-ecu-muted')]);
        if (!empty($employee['account_deleted']) || !empty($employee['account_suspended'])) {
            $out .= \html_writer::tag('span', s(self::tr(!empty($employee['account_deleted']) ? 'ecu_deleted_account' : 'ecu_suspended_account')),
                ['class' => 'bms-ecu-badge bms-ecu-warning']);
        }
        if ($employee['membership_source'] === 'local_kbacorporate_compatibility') {
            $out .= \html_writer::tag('span', s(self::tr('ecu_learning_projection')), ['class' => 'bms-ecu-badge bms-ecu-muted']);
        }
        return $out;
    }
    private static function date(int $time): string { return $time ? userdate($time, '%d.%m.%Y %H:%M') : self::tr('ecu_not_set'); }
    private static function menu(string $label, string $items): string {
        return $items === '' ? '' : \html_writer::tag('details', \html_writer::tag('summary', s($label), ['class' => 'btn btn-outline-secondary']) .
            \html_writer::div($items, 'bms-crm-action-menu-panel bms-ecu-menu-items'), ['class' => 'bms-crm-action-menu bms-ecu-menu']);
    }
    private static function table(array $heads, array $rows, string $label): string {
        $t = new \html_table(); $t->attributes['class'] = 'table bms-crm-list-table bms-ecu-table'; $t->head = array_map('s', $heads);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) { $cells[] = \html_writer::tag('span', s($heads[$i]), ['class' => 'bms-ecu-cell-label', 'aria-hidden' => 'true']) . $cell; }
            $t->data[] = $cells;
        }
        return \html_writer::div(\html_writer::table($t), 'bms-ecu-table-wrap', ['role' => 'region', 'aria-label' => $label, 'tabindex' => '0']);
    }
    private static function pager(string $company, string $route, array $model, array $params): string {
        if ($model['total'] <= $model['pagesize']) { return ''; }
        $page = $model['page']; $buttons = '';
        if ($page > 0) { $buttons .= self::link(self::url($company, $route, $params + ['page' => $page - 1]), self::tr('ecu_previous')); }
        $buttons .= self::note(self::tr('ecu_page', (object)['page' => $page + 1, 'pages' => (int)ceil($model['total'] / $model['pagesize'])]));
        if (($page + 1) * $model['pagesize'] < $model['total']) { $buttons .= self::link(self::url($company, $route, $params + ['page' => $page + 1]), self::tr('ecu_next')); }
        return \html_writer::tag('nav', $buttons, ['class' => 'bms-ecu-actions bms-ecu-pager', 'aria-label' => self::tr('ecu_pagination')]);
    }
    public static function control(string $name, string $label, string $value, array $spec = [], string $error = ''): string {
        $id = 'ecu_' . $name;
        $attrs = ['id' => $id, 'name' => $name, 'class' => 'form-control' . ($error !== '' ? ' is-invalid' : '')];
        if (!empty($spec['required'])) { $attrs['required'] = 'required'; }
        if (isset($spec['maxlength'])) { $attrs['maxlength'] = $spec['maxlength']; }
        if ($error !== '') { $attrs['aria-invalid'] = 'true'; $attrs['aria-describedby'] = $id . '_error'; }
        $out = \html_writer::label(s($label . (!empty($spec['required']) ? ' *' : '')), $id);
        if (isset($spec['options'])) { $out .= \html_writer::select($spec['options'], $name, $value, false, $attrs); }
        else { $out .= \html_writer::empty_tag('input', $attrs + ['type' => $spec['type'] ?? 'text', 'value' => $value]); }
        if ($error !== '') { $out .= \html_writer::div(s($error), 'invalid-feedback', ['id' => $id . '_error']); }
        return \html_writer::div($out, 'bms-ecu-control');
    }
    public static function errors(array $errors): string {
        if (!$errors) { return ''; }
        $body = \html_writer::tag('strong', s(self::tr('ecu_check_form')));
        foreach ($errors as $field => $error) { $body .= \html_writer::tag('p', $field[0] === '_' ? s($error) : \html_writer::link('#ecu_' . $field, s($error))); }
        return \html_writer::div($body, 'alert alert-danger', ['role' => 'alert']);
    }
    public static function listing(string $company, array $model): string {
        $buttons = $model['can_manage'] ? self::link(self::url($company, 'employee_create'), self::tr('bms_employee_create_action'), true) : '';
        $items = '';
        foreach (['organization_hub' => 'ecu_organization', 'positions' => 'bms_employee_positions_action'] as $route => $label) {
            $items .= \html_writer::link(self::url($company, $route), s(self::tr($label)));
        }
        if ($model['can_manage']) {
            foreach (['staffing_placement' => 'bms_employee_staffing_action', 'employee_diagnostics' => 'bms_employee_diagnostics_action'] as $route => $label) {
                $items .= \html_writer::link(self::url($company, $route), s(self::tr($label)));
            }
        }
        $buttons .= self::menu(self::tr('ecu_tools'), $items);
        $out = bms_workspace_ux_service::record_header(s(self::tr('bms_employees_title')), s(self::tr('ecu_intro')), s($model['company']['name']), '', $buttons);
        $f = $model['filters']; $tabs = '';
        foreach (['active' => 'ecu_active', 'archived' => 'ecu_archived', 'all' => 'ecu_all'] as $state => $label) {
            $tabs .= \html_writer::link(self::url($company, 'employees', array_merge($f, ['status' => $state])), s(self::tr($label)),
                ['class' => 'bms-ecu-tab' . ($f['status'] === $state ? ' is-active' : ''), 'aria-current' => $f['status'] === $state ? 'page' : null]);
        }
        $out .= \html_writer::tag('nav', $tabs, ['class' => 'bms-ecu-tabs', 'aria-label' => self::tr('ecu_directory_views')]);
        $form = self::hidden(['uuid' => $company, 'status' => $f['status']]);
        $form .= self::control('q', self::tr('bms_employee_filter_search'), $f['q'], ['type' => 'search', 'maxlength' => 200]);
        $form .= self::control('placement', self::tr('ecu_placement'), $f['placement'], ['options' => [
            '' => self::tr('ecu_any_placement'), 'assigned' => self::tr('ecu_assigned'), 'missing' => self::tr('ecu_unassigned')]]);
        $form .= \html_writer::div(\html_writer::tag('button', s(self::tr('ecu_apply')), ['type' => 'submit', 'class' => 'btn btn-primary']) .
            self::link(self::url($company), self::tr('ecu_reset')), 'bms-ecu-actions');
        $out .= \html_writer::tag('form', $form, ['method' => 'get', 'action' => self::url($company)->out(false), 'class' => 'bms-ecu-filters']);
        $out .= self::note(self::tr('ecu_found', $model['total']));
        $rows = [];
        foreach ($model['employees'] as $e) {
            $name = \html_writer::link(self::url($company, 'employee_view', ['userid' => $e['user_id'], 'membership' => $e['membership_uuid']]), s($e['full_name']));
            $position = $e['position'] ? s($e['position']['name']) : self::note(self::tr('ecu_unassigned'));
            if ($e['position'] && $e['position']['unit'] !== '') { $position .= self::note($e['position']['unit']); }
            $contacts = s($e['email']);
            if ($e['phone'] !== '') { $contacts .= self::note($e['phone']); }
            else if ($e['phone_needs_correction']) { $contacts .= self::note(self::tr('ecu_phone_review')); }
            $rows[] = [$name, $position, $contacts, self::badge($e), s(self::date(max($e['time_modified'], $e['profile_modified'])))];
        }
        if ($rows) { $out .= self::table([self::tr('employee'), self::tr('ecu_position_department'), self::tr('bms_employee_section_contacts'),
            self::tr('ecu_status'), self::tr('ecu_updated')], $rows, self::tr('bms_employees_title')); }
        else { $out .= self::panel(self::tr('ecu_empty'), self::note(self::tr($f['q'] !== '' || $f['placement'] !== '' || $f['status'] === 'archived' ? 'ecu_empty_filtered' : 'ecu_empty_first'))); }
        $out .= self::pager($company, 'employees', $model, $f);
        $out .= \html_writer::tag('details', \html_writer::tag('summary', s(self::tr('ecu_about'))) .
            self::note(self::tr('ecu_directory_help')) . self::note(self::tr('bms_employees_enrolment_rule_short')), ['class' => 'bms-ecu-help']);
        return $out;
    }
    public static function record_params(array $employee): array {
        return ['userid' => $employee['user_id'], 'membership' => $employee['membership_uuid']];
    }
    private static function employee2_status(string $prefix, string $status): string {
        $key = $prefix . preg_replace('/[^a-z0-9_]/', '', strtolower($status));
        if (get_string_manager()->string_exists($key, 'local_kbacompany')) {
            return self::tr($key);
        }
        return $status !== '' ? $status : self::tr('ecu_not_set');
    }
    private static function employee2_summary(array $summary): string {
        $cards = [
            ['employee2_assignments', (string)($summary['assignments'] ?? 0)],
            ['employee2_metrics', (string)($summary['metrics'] ?? 0)],
            ['employee2_open_task_count', (string)($summary['open_tasks'] ?? 0)],
            ['employee2_competency_gaps', (string)($summary['competency_gaps'] ?? 0)],
            ['employee2_learning_active', (string)($summary['learning_active'] ?? 0)],
            ['employee2_documents_pending', (string)($summary['documents_pending'] ?? 0)],
        ];
        $out = '';
        foreach ($cards as $card) {
            $out .= \html_writer::div(
                \html_writer::tag('span', s(self::tr($card[0])), ['class' => 'bms-employee2-summary-label']) .
                \html_writer::tag('strong', s($card[1]), ['class' => 'bms-employee2-summary-value']),
                'bms-employee2-summary-card'
            );
        }
        return \html_writer::div($out, 'bms-employee2-summary');
    }
    private static function employee2_restricted(): string {
        return self::panel(self::tr('employee2_restricted_title'), self::note(self::tr('employee2_restricted_help')));
    }
    private static function employee2_date(int $time, bool $timepart = false): string {
        if (!$time) { return self::tr('ecu_not_set'); }
        return userdate($time, $timepart ? '%d.%m.%Y %H:%M' : '%d.%m.%Y');
    }
    private static function employee2_fte($value): string {
        return number_format((float)$value, 2, ',', ' ');
    }
    public static function detail(string $company, array $model, string $tab, array $extra = []): string {
        $e = $model['employee']; $p = self::record_params($e);
        $out = self::link(self::url($company), self::tr('bms_employee_back_to_list'));
        // The personal-profile action must never route a regular employee into HR administration.
        // Organization, assignments and other personnel fields remain manager-only.
        $isself = (int)$e['user_id'] === (int)$GLOBALS['USER']->id;
        $actions = $isself ? self::link(self::url($company, 'employee_self_profile', $p),
            self::tr('bms_self_profile_edit'), true) :
            ($model['editable'] ? self::link(self::url($company, 'employee_edit', $p), self::tr('ecu_edit'), true) : '');
        $items = \html_writer::link(self::url($company, 'employee_competencies', ['userid' => $e['user_id']]), s(self::tr('employeecompetency_open')));
        if ($model['can_manage']) { $items .= \html_writer::link(self::url($company, 'staffing_placement'), s(self::tr('bms_employee_staffing_action'))); }
        if ($model['editable']) { $items .= \html_writer::link(self::url($company, 'employee_delete', $p), s(self::tr('bms_employee_remove_action')), ['class' => 'text-danger']); }
        $actions .= self::menu(self::tr('ecu_actions'), $items);
        $out .= bms_workspace_ux_service::record_header(s($e['full_name']), s($model['company']['name']), self::tr('employee2_card'), self::badge($e), $actions);
        // Moodle user picture remains the canonical avatar; do not duplicate images in company data.
        $avataruser = $GLOBALS['DB']->get_record('user', ['id' => (int)$e['user_id'], 'deleted' => 0],
            'id,firstname,lastname,picture,imagealt');
        if ($avataruser) {
            // Moodle renders user_picture through the page renderer, not user_picture::output().
            $avatarhtml = $GLOBALS['OUTPUT']->user_picture($avataruser, ['size' => 96]);
            if ($isself) {
                $avatarhtml = \html_writer::link(self::url($company, 'employee_avatar', $p), $avatarhtml,
                    ['title' => self::tr('bms_avatar_title')]);
            }
            $out .= \html_writer::div($avatarhtml, 'bms-employee-avatar mb-3');
        }


        $next = !$e['membership_active'] ? 'ecu_next_archived' : ($e['membership_uuid'] === '' ? 'ecu_next_projection' : (!$e['position'] ? 'ecu_next_position' : 'ecu_next_ready'));
        $out .= \html_writer::div(\html_writer::tag('strong', s(self::tr('ecu_next_step'))) . self::note(self::tr($next)), 'bms-ecu-notice');

        $caninspect = !empty($extra['can_inspect']);
        $tabs = [
            'overview' => 'employee2_tab_overview',
            'assignments' => 'employee2_tab_assignments',
            'metrics' => 'employee2_tab_metrics',
        ];
        if ($caninspect) {
            $tabs += [
                'battleplan' => 'employee2_tab_battleplan',
                'tasks' => 'employee2_tab_tasks',
                'calendar' => 'employee2_tab_calendar',
                'competencies' => 'employee2_tab_competencies',
                'learning' => 'employee2_tab_learning',
                'development' => 'employee2_tab_development',
                'documents' => 'employee2_tab_documents',
                'history' => 'employee2_tab_history',
            ];
        }
        if (!$caninspect && !empty($extra['calendar_work_available'])) {
            $tabs['calendar'] = 'employee2_tab_calendar';
        }
        if ($model['can_manage']) { $tabs['integration'] = 'ecu_integration'; }
        $links = '';
        foreach ($tabs as $key => $label) {
            $links .= \html_writer::link(self::url($company, 'employee_view', $p + ['tab' => $key]), s(self::tr($label)),
                ['class' => 'bms-ecu-tab' . ($tab === $key ? ' is-active' : ''), 'aria-current' => $tab === $key ? 'page' : null]);
        }
        $out .= \html_writer::tag('nav', $links, ['class' => 'bms-ecu-tabs bms-employee2-tabs', 'aria-label' => self::tr('ecu_record_tabs')]);

        if ($tab === 'overview') {
            // One company-scoped personnel summary; HR-only information is not exposed to colleagues.
            $out .= self::panel(self::tr('bms_personnel_quicklinks'),
                self::link(self::url($company, 'employee_view', $p + ['tab'=>'assignments']), self::tr('employee2_tab_assignments')) . ' ' .
                ($caninspect ? self::link(self::url($company, 'employee_view', $p + ['tab'=>'competencies']), self::tr('employee2_tab_competencies')) . ' ' .
                self::link(self::url($company, 'employee_view', $p + ['tab'=>'development']), self::tr('employee2_tab_development')) . ' ' .
                self::link(self::url($company, 'employee_view', $p + ['tab'=>'calendar']), self::tr('employee2_tab_calendar')) : ''));
            if ($isself || $model['can_manage']) {
                $person = $GLOBALS['DB']->get_record('user', ['id'=>(int)$e['user_id'], 'deleted'=>0],
                    'id,middlename,phone2,city,description,descriptionformat');
                if ($person) {
                    $out .= self::panel(self::tr('bms_personnel_contacts'), self::fields([
                        self::tr('bms_profile_middlename') => (string)$person->middlename,
                        self::tr('bms_profile_phone2') => (string)$person->phone2,
                        get_string('city') => (string)$person->city,
                        get_string('description') => trim(strip_tags((string)$person->description)),
                    ]));
                }
            }
            if ($caninspect || $isself) {
                $weeklyhours = employee_schedule_service::hours($company, (int)$e['user_id']);
                $schedulebody = self::fields([self::tr('bms_personnel_weekly_capacity') => (string)$weeklyhours . ' ' . self::tr('bms_personnel_hours')]);
                if ($model['can_manage'] && task_service::can_manage($company, (int)$GLOBALS['USER']->id)) {
                    $schedulebody .= self::link(self::url($company, 'employee_work_schedule', ['userid'=>(int)$e['user_id']]),
                        self::tr('bpu_capacity_edit_schedule'));
                }
                $out .= self::panel(self::tr('bms_personnel_schedule'), $schedulebody);
            }
            if ($caninspect) { $out .= self::employee2_summary($extra['summary'] ?? []); }
            $out .= self::panel(self::tr('employee2_identity'), self::fields([
                get_string('firstname') => $e['firstname'], get_string('lastname') => $e['lastname'], get_string('email') => $e['email'],
                get_string('phone') => $e['phone'], self::tr('positionname') => $e['position']['name'] ?? '',
                self::tr('bms_employee_department') => $e['position']['unit'] ?? '',
            ]) . ($e['phone_needs_correction'] ? self::note(self::tr('ecu_phone_review_help')) : ''));
            $out .= self::panel(self::tr('bms_employee_section_membership'), self::fields([
                self::tr('ecu_status') => self::tr($e['membership_active'] ? 'ecu_active_member' : 'ecu_archived_member'),
                self::tr('bms_employee_effective_from') => self::date($e['effective_from']),
                self::tr('bms_employee_effective_to') => self::date($e['effective_to'])]));
            if ($caninspect && !empty($extra['summary']['battle_plan_uuid'])) {
                $out .= self::panel(self::tr('employee2_current_battleplan'),
                    self::fields([
                        self::tr('employee2_period') => (string)$extra['summary']['battle_plan_period'],
                        get_string('status') => self::employee2_status('battleplan_status_', (string)$extra['summary']['battle_plan']),
                    ]) . self::link(new \moodle_url('/local/kbacompany/battle_plan.php', [
                        'uuid' => $company, 'plan' => (string)$extra['summary']['battle_plan_uuid'],
                    ]), self::tr('employee2_open_battleplan')));
            }
        } else if ($tab === 'assignments') {
            $rows = [];
            foreach ($extra['assignments'] ?? [] as $a) {
                $position = $a['position_uuid'] !== '' ? self::link(self::url($company, 'positions', ['position' => $a['position_uuid']]), $a['position']) : s($a['position']);
                if ($a['unit'] !== '') { $position .= self::note($a['unit']); }
                $period = self::employee2_date((int)$a['effective_from']);
                if ((int)$a['effective_to'] > 0) { $period .= ' — ' . self::employee2_date((int)$a['effective_to']); }
                $rows[] = [$position, s(self::employee2_status('assignmenttype', (string)$a['type'])), s(self::employee2_fte($a['fte'])), s($period),
                    s(self::tr($a['active'] ? 'employee2_assignment_active' : 'employee2_assignment_history'))];
            }
            $body = $rows ? self::table([self::tr('positionname'), self::tr('staffingassignmenttype'), self::tr('staffingassignmentfte'),
                self::tr('employee2_period'), self::tr('ecu_status')], $rows, self::tr('employee2_tab_assignments')) : self::note(self::tr('employee2_no_assignments'));
            if ($model['can_manage']) { $body .= self::link(self::url($company, 'staffing_placement'), self::tr('employee2_manage_assignments'), true); }
            $out .= self::panel(self::tr('employee2_tab_assignments'), $body);
            $roles = [];
            foreach ($extra['business_roles'] ?? [] as $role) {
                $roles[] = [s($role['name']), s($role['code'])];
            }
            $out .= self::panel(self::tr('employee2_business_roles'), $roles ? self::table([self::tr('employee2_role_name'), self::tr('employee2_role_code')],
                $roles, self::tr('employee2_business_roles')) : self::note(self::tr('employee2_no_business_roles')));
        } else if ($tab === 'metrics') {
            if (empty($extra['position'])) {
                $out .= self::panel(self::tr('employee2_tab_metrics'), self::note(self::tr('employee2_no_position_metrics')));
            } else {
                $rows = [];
                foreach ($extra['metrics'] ?? [] as $metric) {
                    $name = s($metric['name']);
                    if ($metric['primary']) { $name .= ' ' . \html_writer::tag('span', s(self::tr('employee2_primary_metric')), ['class' => 'badge badge-success']); }
                    $norm = $metric['target'] !== '' ? $metric['target'] : (($metric['min'] !== '' || $metric['max'] !== '') ? trim($metric['min'] . ' — ' . $metric['max'], ' —') : self::tr('ecu_not_set'));
                    $rows[] = [s($metric['code']), $name, s($metric['unit']), s(self::employee2_status('positionindicator_period_', $metric['period'])), s($norm), s($metric['weekly'] ? self::tr('employee2_battleplan_yes') : self::tr('employee2_battleplan_no'))];
                }
                $body = $rows ? self::table([self::tr('employee2_metric_code'), self::tr('employee2_metric_name'), self::tr('employee2_metric_unit'),
                    self::tr('employee2_metric_period'), self::tr('employee2_metric_norm'), self::tr('employee2_in_battleplan')], $rows, self::tr('employee2_tab_metrics')) : self::note(self::tr('employee2_no_metrics'));
                if (!empty($e['position']['uuid'])) {
                    $body .= self::link(self::url($company, 'positions', ['position' => $e['position']['uuid'], 'tab' => 'indicators']), self::tr('employee2_open_position_metrics'));
                }
                $out .= self::panel(self::tr('employee2_tab_metrics'), $body);
            }
        } else if (!empty($extra['restricted'])) {
            $out .= self::employee2_restricted();
        } else if ($tab === 'battleplan') {
            $rows = [];
            foreach ($extra['battle_plans'] ?? [] as $plan) {
                $url = new \moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $company, 'plan' => $plan['uuid']]);
                $rows[] = [self::link($url, $plan['period_start'] . ' — ' . $plan['period_end']), s($plan['position']),
                    s(self::employee2_status('battleplan_status_', $plan['status'])), s(self::employee2_date((int)$plan['modified'], true))];
            }
            $body = $rows ? self::table([self::tr('employee2_period'), self::tr('positionname'), get_string('status'), self::tr('ecu_updated')],
                $rows, self::tr('employee2_tab_battleplan')) : self::note(self::tr('employee2_no_battleplans'));
            // Close-time employee execution summary. Never use live task state for historical rates.
            // The management projection independently checks the company/plan/task ACLs.
            $weekstart = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('monday this week')->format('Y-m-d');
            $trend = battle_plan_management_overview_service::closure_discipline_trend(
                $company, (int)$GLOBALS['USER']->id, $weekstart, (int)$e['user_id']);
            $completed = 0;
            $unfinished = 0;
            $carried = 0;
            $coveredweeks = 0;
            $legacyweeks = 0;
            foreach ($trend['points'] as $point) {
                $completed += (int)$point['done'];
                $unfinished += (int)$point['unfinished'];
                $carried += (int)$point['carried'];
                if ((int)$point['plans'] > 0) { $coveredweeks++; }
                if ((int)$point['legacy'] > 0) { $legacyweeks++; }
            }
            $eligible = $completed + $unfinished;
            $percentage = $eligible > 0 ? (string)(int)round(100 * $completed / $eligible) . '%' : '—';
            $summaryrows = [[
                self::tr('bms24_employee_execution_done'), (string)$completed],
                [self::tr('bms24_employee_execution_unfinished'), (string)$unfinished],
                [self::tr('bms24_employee_execution_carried'), (string)$carried],
                [self::tr('bms24_employee_execution_rate'), $percentage],
                [self::tr('bms24_employee_execution_coverage'), (string)$coveredweeks . ' / 8'],
            ];
            $summaryhtml = self::table(['', self::tr('bms24_employee_execution_value')],
                $summaryrows, self::tr('bms24_employee_execution_title'));
            $summaryhtml .= self::note(self::tr('bms24_employee_execution_disclaimer'));
            if ($legacyweeks > 0) {
                $summaryhtml .= self::note(self::tr('bms24_employee_execution_legacy') . ': ' . $legacyweeks);
            }
            $body .= self::panel(self::tr('bms24_employee_execution_title'), $summaryhtml);
            $body .= self::link(new \moodle_url('/local/kbacompany/battle_plan.php', ['uuid' => $company]), self::tr('employee2_open_battleplans'));
            // The management report enforces company, task and plan ACLs and only reveals snapshot-backed figures.
            $body .= ' ' . self::link(new \moodle_url('/local/kbacompany/battle_plan_overview.php',
                ['uuid' => $company, 'employeeinsight' => (int)$e['user_id']]),
                self::tr('bms24_employee_open_execution_insight'));
            $out .= self::panel(self::tr('employee2_tab_battleplan'), $body);
        } else if ($tab === 'calendar') {
            if (!empty($extra['calendar_self']) && !empty($extra['calendar'])) {
                $calendar = $extra['calendar'];
                $body = \html_writer::div(s(self::tr('employee2_calendar_intro')), 'text-muted mb-3');
                $summary = (array)($calendar['summary'] ?? []);
                $body .= \html_writer::div(
                    \html_writer::span(self::tr('employee2_calendar_events') . ': ' . (int)($summary['events'] ?? 0), 'bms-employee2-chip') .
                    \html_writer::span(self::tr('employee2_calendar_meetings') . ': ' . (int)($summary['meetings'] ?? 0), 'bms-employee2-chip') .
                    \html_writer::span(self::tr('employee2_calendar_due') . ': ' . (int)($summary['tasks_due'] ?? 0), 'bms-employee2-chip'),
                    'bms-employee2-chips mb-3');
                $rows = [];
                foreach ((array)($calendar['next'] ?? []) as $event) {
                    $title = s((string)($event['name'] ?? ''));
                    $url = (string)($event['url'] ?? '');
                    $rows[] = [s((string)($event['time_label'] ?? '')), $url !== '' ? self::link(new \moodle_url($url), $title) : $title,
                        s((string)($event['source_label'] ?? ''))];
                }
                $body .= $rows ? self::table([self::tr('employee2_calendar_time'), self::tr('employee2_calendar_event'),
                    self::tr('employee2_calendar_source')], $rows, self::tr('employee2_tab_calendar'))
                    : self::note(self::tr('employee2_calendar_empty'));
                $body .= \html_writer::div(self::link(new \moodle_url((string)$calendar['calendar_url']),
                    self::tr('employee2_calendar_open')), 'mt-3');
            } else {
                $body = self::note(self::tr('employee2_calendar_private'));
            }
            if (!empty($extra['calendar_work_available']) && !empty($extra['work_calendar_url'])) {
                $body .= \html_writer::div(self::link(new \moodle_url((string)$extra['work_calendar_url']),
                    self::tr('wcal_title')), 'mt-3');
            }
            $out .= self::panel(self::tr('employee2_tab_calendar'), $body);
        } else if ($tab === 'tasks') {
            $counts = $extra['task_counts'] ?? [];
            $out .= \html_writer::div(
                \html_writer::tag('span', s(self::tr('employee2_tasks_open') . ': ' . (int)($counts['open'] ?? 0)), ['class' => 'bms-employee2-chip']) .
                \html_writer::tag('span', s(self::tr('employee2_tasks_progress') . ': ' . (int)($counts['doing'] ?? 0)), ['class' => 'bms-employee2-chip']) .
                \html_writer::tag('span', s(self::tr('employee2_tasks_review') . ': ' . (int)($counts['review'] ?? 0)), ['class' => 'bms-employee2-chip']) .
                \html_writer::tag('span', s(self::tr('employee2_tasks_overdue') . ': ' . (int)($counts['overdue'] ?? 0)), ['class' => 'bms-employee2-chip bms-employee2-chip-warn']),
                'bms-employee2-chips'
            );
            $rows = [];
            foreach ($extra['tasks'] ?? [] as $task) {
                $url = new \moodle_url('/local/kbacompany/tasks.php', ['uuid' => $company, 'task' => $task['uuid'], 'view' => 'detail', 'scope' => 'team']);
                $rows[] = [self::link($url, $task['title']), s(self::tr('taskcenter_priority_' . $task['priority'])),
                    s(self::employee2_status('taskcenter_status_', $task['status'])), s($task['due_date'] !== '' ? $task['due_date'] : self::tr('ecu_not_set')),
                    s((string)$task['plan_minutes'])];
            }
            $body = $rows ? self::table([self::tr('employee2_task'), self::tr('taskcenter_priority'), get_string('status'), self::tr('employee2_due'), self::tr('employee2_plan_minutes')],
                $rows, self::tr('employee2_tab_tasks')) : self::note(self::tr('employee2_no_tasks'));
            $body .= self::link(new \moodle_url('/local/kbacompany/tasks.php', ['uuid' => $company, 'scope' => 'team']), self::tr('employee2_open_tasks'));
            $out .= self::panel(self::tr('employee2_tab_tasks'), $body);
        } else if ($tab === 'competencies') {
            $ctx = $extra['competencies'] ?? null;
            if (!$ctx) {
                $out .= self::panel(self::tr('employee2_tab_competencies'), self::note(self::tr('employee2_no_competency_profile')));
            } else {
                $summary = $ctx['summary'];
                $out .= \html_writer::div(
                    \html_writer::tag('span', s(self::tr('employee2_comp_total') . ': ' . (int)$summary['total']), ['class' => 'bms-employee2-chip']) .
                    \html_writer::tag('span', s(self::tr('employee2_comp_met') . ': ' . (int)$summary['met']), ['class' => 'bms-employee2-chip']) .
                    \html_writer::tag('span', s(self::tr('employee2_comp_gap') . ': ' . (int)$summary['gap']), ['class' => 'bms-employee2-chip bms-employee2-chip-warn']) .
                    \html_writer::tag('span', s(self::tr('employee2_comp_unknown') . ': ' . (int)$summary['unknown']), ['class' => 'bms-employee2-chip']),
                    'bms-employee2-chips'
                );
                $ratings = $extra['moodle_ratings'] ?? [];
                $courses = $extra['development_courses'] ?? [];
                $headers = [self::tr('employee2_metric_code'), self::tr('employee2_competency'),
                    self::tr('employee2_target_level'), self::tr('employee2_current_level'), get_string('status')];
                if ($ratings) {
                    $headers[] = self::tr('employee2_moodle_rating');
                }
                if ($courses) {
                    $headers[] = self::tr('employee2_development_courses');
                }
                $rows = [];
                foreach ($ctx['requirements'] as $row) {
                    $cells = [s($row['code']), s($row['name']), s($row['target_level'] === null ? self::tr('ecu_not_set') : (string)$row['target_level']),
                        s($row['current_level'] === null ? self::tr('ecu_not_set') : (string)$row['current_level']),
                        s(self::tr('employee2_comp_state_' . $row['state']))];
                    if ($ratings) {
                        $rating = $ratings[$row['competency_id']] ?? null;
                        $cells[] = $rating ? \html_writer::link(new \moodle_url('/admin/tool/lp/user_competency.php',
                            ['id' => $rating['id']]), s($rating['label'])) : '—';
                    }
                    if ($courses) {
                        $links = [];
                        foreach ($courses[$row['competency_id']] ?? [] as $course) {
                            $progress = $course['percentage'] === null
                                ? self::tr('employee2_course_progress_unavailable')
                                : self::tr('employee2_course_progress', (int)round($course['percentage']));
                            $links[] = \html_writer::link(new \moodle_url('/course/view.php', ['id' => $course['id']]),
                                $course['name']) . ' · ' . s($progress);
                        }
                        $cells[] = $links ? \html_writer::alist($links) : '—';
                    }
                    $rows[] = $cells;
                }
                $body = $rows ? self::table($headers,
                    $rows, self::tr('employee2_tab_competencies')) : self::note(self::tr('employee2_no_competencies'));
                if ($ratings) {
                    $body .= self::note(self::tr('employee2_moodle_rating_note'));
                }
                $body .= self::link(self::url($company, 'employee_competencies', ['userid' => $e['user_id']]), self::tr('employee2_open_competencies'), true);
                $out .= self::panel(self::tr('employee2_tab_competencies'), $body);
            }
        } else if ($tab === 'learning') {
            $learning = $extra['employee'] ?? [];
            $out .= self::panel(self::tr('employee2_tab_learning'), self::fields([
                get_string('status') => self::employee2_status('bms_learning_status_', (string)($learning['status'] ?? 'not_started')),
                self::tr('employee2_learning_active') => (string)(int)($learning['active_courses'] ?? 0),
                self::tr('employee2_learning_completed') => (string)(int)($learning['completed_courses'] ?? 0),
            ]) . self::note(self::tr('employee2_learning_boundary')) .
                \html_writer::div(self::link(self::url($company, 'learning'), self::tr('employee2_open_learning'), true) .
                (!empty($learning['corporate_url']) ? self::link(new \moodle_url($learning['corporate_url']), self::tr('employee2_open_learning_profile')) : ''), 'bms-ecu-actions'));
        } else if ($tab === 'development') {
            $rows = [];
            foreach ($extra['development_plans'] ?? [] as $plan) {
                $url = new \moodle_url('/local/kbacompany/development_plan.php', ['uuid' => $company, 'userid' => $e['user_id'], 'plan' => $plan['uuid']]);
                $progress = (string)$plan['completed_items'] . ' / ' . (string)$plan['items'];
                $rows[] = [self::link($url, $plan['title']), s(self::employee2_status('developmentplan_status_', $plan['status'])),
                    s($plan['target_date'] ? self::employee2_date((int)$plan['target_date']) : self::tr('ecu_not_set')), s($progress)];
            }
            $body = $rows ? self::table([self::tr('employee2_plan'), get_string('status'), self::tr('employee2_target_date'), self::tr('employee2_progress')],
                $rows, self::tr('employee2_tab_development')) : self::note(self::tr('employee2_no_development'));
            $body .= self::link(new \moodle_url('/local/kbacompany/development_plan.php', ['uuid' => $company, 'userid' => $e['user_id']]), self::tr('employee2_open_development'), true);
            $out .= self::panel(self::tr('employee2_tab_development'), $body);
        } else if ($tab === 'documents') {
            $rows = [];
            foreach ($extra['documents'] ?? [] as $doc) {
                $url = new \moodle_url('/local/kbacompany/documents.php', ['uuid' => $company, 'document' => $doc['uuid'], 'revision' => $doc['revision']]);
                $when = $doc['acknowledged'] ? self::employee2_date((int)$doc['acknowledged'], true) : self::employee2_date((int)$doc['created'], true);
                $rows[] = [s($doc['code']), self::link($url, $doc['title']), s(self::employee2_status('document_type_', $doc['type'])), s(self::employee2_status('document_ack_', $doc['status'])), s($when)];
            }
            $body = $rows ? self::table([self::tr('employee2_document_code'), self::tr('employee2_document'), self::tr('employee2_document_type'), get_string('status'), self::tr('ecu_updated')],
                $rows, self::tr('employee2_tab_documents')) : self::note(self::tr('employee2_no_documents'));
            $body .= self::link(new \moodle_url('/local/kbacompany/documents.php', ['uuid' => $company]), self::tr('employee2_open_documents'));
            $out .= self::panel(self::tr('employee2_tab_documents'), $body);
        } else if ($tab === 'history') {
            $history = $extra['membership_history'] ?? ['rows' => [], 'total' => 0, 'pagesize' => 25, 'page' => 0];
            $rows = [];
            foreach ($history['rows'] as $r) {
                $rows[] = [s(self::tr($r->active ? 'ecu_active_member' : 'ecu_archived_member')), s(self::date((int)$r->effectivefrom)),
                    s(self::date((int)$r->effectiveto)), s(self::source_label((string)$r->sourcekind)),
                    self::link(self::url($company, 'employee_view', ['userid' => $e['user_id'], 'membership' => $r->uuid]), self::tr('ecu_open_period'))];
            }
            $out .= self::note(self::tr('employee2_history_help'));
            $out .= $rows ? self::table([self::tr('ecu_status'), self::tr('bms_employee_effective_from'), self::tr('bms_employee_effective_to'),
                self::tr('ecu_source'), self::tr('ecu_membership_period')], $rows, self::tr('employee2_tab_history')) : self::panel(self::tr('employee2_tab_history'), self::note(self::tr('bms_employee_history_empty')));
            $out .= self::pager($company, 'employee_view', $history, $p + ['tab' => $tab]);
        } else if ($tab === 'integration' && $model['can_manage']) {
            $integration = $extra['integration'] ?? ['status' => '', 'time' => 0, 'contact' => '', 'error' => ''];
            $key = 'bms_sync_' . preg_replace('/[^a-z_]/', '', (string)$integration['status']);
            $status = get_string_manager()->string_exists($key, 'local_kbacompany') ? self::tr($key) : self::tr('ecu_not_set');
            $out .= self::panel(self::tr('ecu_integration'), self::fields([
                self::tr('bms_sync_status') => $status, self::tr('ecu_last_command') => self::date((int)$integration['time'])]) .
                self::note(self::tr('ecu_sync_help')) . self::link(self::url($company, 'employee_diagnostics'), self::tr('bms_employee_diagnostics_action')));
            $technical = self::fields([self::tr('ecu_source') => self::source_label($e['membership_source']),
                self::tr('bms_employee_membership_uuid') => $e['membership_uuid'], self::tr('bms_employee_moodle_user_id') => (string)$e['user_id'],
                self::tr('bms_bitrix_contact') => (string)$integration['contact'], self::tr('ecu_last_error') => (string)$integration['error']]);
            $out .= \html_writer::tag('details', \html_writer::tag('summary', s(self::tr('bms_employee_section_advanced'))) . $technical, ['class' => 'bms-ecu-help']);
        }
        return $out;
    }
    public static function source_label(string $source): string {
        if ($source === 'bitrix24') { return 'Bitrix24'; }
        if ($source === 'local_kbacorporate_compatibility') { return self::tr('ecu_learning_projection'); }
        return self::tr('ecu_bms_membership');
    }
    public static function form(string $company, string $operation, array $values, array $errors, array $proof, ?array $model = null): string {
        $creating = $operation === 'create'; $closing = $operation === 'close';
        $p = $model ? self::record_params($model['employee']) : [];
        $back = self::url($company, $creating ? 'employees' : 'employee_view', $p);
        $out = self::link($back, self::tr($creating ? 'bms_employee_back_to_list' : 'bms_employee_back_to_record'));
        $title = $creating ? self::tr('bms_employee_create_title') : ($closing ? self::tr('bms_employee_remove_action') : self::tr('ecu_edit'));
        $out .= bms_workspace_ux_service::record_header(s($title), $model ? s($model['employee']['full_name']) : s(self::tr('ecu_create_intro')));
        $out .= self::errors($errors);
        $body = self::hidden($proof + ['uuid' => $company, 'sesskey' => sesskey()] + $p);
        if ($closing) {
            $body .= self::panel(self::tr('ecu_close_title'), self::note(self::tr('bms_employee_delete_explain')) . self::note(self::tr('ecu_close_help')));
            $body .= \html_writer::tag('label', \html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'confirm', 'id' => 'ecu_confirm',
                'value' => 1, 'required' => 'required', 'checked' => !empty($values['confirm']) ? 'checked' : null]) . ' ' . s(self::tr('ecu_close_confirm')), ['class' => 'bms-ecu-confirm']);
        } else {
            foreach (self::form_schema($creating ? 'create' : 'edit') as $section) {
                if (!$section['fields']) { continue; }
                $part = '';
                if (!empty($section['readonly'])) {
                    $part = self::fields([self::tr('positionname') => $model['employee']['position']['name'] ?? '',
                        self::tr('bms_employee_department') => $model['employee']['position']['unit'] ?? '']) . self::note(self::tr('bms_employee_edit_org_readonly'));
                } else {
                    foreach ($section['fields'] as $field) { $part .= self::control($field, get_string($field === 'phone' ? 'phone' : $field),
                        (string)($values[$field] ?? ''), ['type' => $field === 'phone' ? 'tel' : self::input_type($field),
                            'required' => self::required($field), 'maxlength' => $field === 'phone' ? 20 : 100], $errors[$field] ?? ''); }
                    $part = \html_writer::div($part, 'bms-crm-field-grid bms-ecu-grid');
                }
                $body .= self::panel(self::tr(self::section_label_key($section['key'])), $part);
            }
            $body .= \html_writer::div(self::note(self::tr($creating ? 'ecu_create_help' : 'ecu_shared_profile')) .
                self::note(self::tr('bms_employees_enrolment_rule_short')), 'bms-ecu-notice');
        }
        $body .= \html_writer::div(\html_writer::tag('button', s(self::tr($closing ? 'bms_employee_remove_action' : 'ecu_save')),
            ['class' => 'btn ' . ($closing ? 'btn-danger' : 'btn-primary'), 'type' => 'submit']) . self::link($back, get_string('cancel')), 'bms-ecu-actions bms-ecu-footer');
        $route = $creating ? 'employee_create' : ($closing ? 'employee_delete' : 'employee_edit');
        return $out . \html_writer::tag('form', $body, ['method' => 'post', 'action' => self::url($company, $route, $p)->out(false), 'class' => 'bms-ecu-form']);
    }

}
