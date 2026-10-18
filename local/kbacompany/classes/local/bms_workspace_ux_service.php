<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Reusable BMS workspace presentation primitives.
 *
 * Domain routes, permissions and data remain owned by local_kbacompany.
 * This service only renders a consistent record/workspace interaction layer.
 */
class bms_workspace_ux_service {
    public const CONTRACT_VERSION = '0.1';

    /** Architecture-local navigation inspired by focused ERP module navigation. */
    public static function architecture_nav(string $companyuuid, string $active): string {
        $items = [
            'owner' => ['ownermodel_title', '/local/kbacompany/owner.php'],
            'philosophy' => ['companycontext_philosophy', '/local/kbacompany/philosophy.php'],
            'market' => ['companycontext_market', '/local/kbacompany/market_assessment.php'],
            'businessmodel' => ['bmc_title', '/local/kbacompany/business_model.php'],
            'strategy' => ['strategy_title', '/local/kbacompany/strategy.php'],
            'finance' => ['financialmodel_title', '/local/kbacompany/financial_model.php'],
            'adminscale' => ['adminscale_title', '/local/kbacompany/administrative_scale.php'],
        ];
        $links = [];
        foreach ($items as $key => $item) {
            $classes = 'bms-workspace-subnav-link' . ($key === $active ? ' is-active' : '');
            $links[] = \html_writer::link(new \moodle_url($item[1], ['uuid' => $companyuuid]),
                get_string($item[0], 'local_kbacompany'), ['class' => $classes,
                    'aria-current' => $key === $active ? 'page' : null]);
        }
        return \html_writer::tag('nav', implode('', $links), [
            'class' => 'bms-workspace-subnav',
            'aria-label' => get_string('bmsux_architecture_nav', 'local_kbacompany'),
        ]);
    }

    /** People-local navigation for the stabilized People Architecture workspace. */
    public static function people_nav(string $companyuuid, string $active): string {
        $items = [
            'overview' => ['people_title', '/local/kbacompany/people.php'],
            'employees' => ['people_open_employees', '/local/kbacompany/employees.php'],
            'positions' => ['people_open_positions', '/local/kbacompany/positions.php'],
            'culture' => ['culture_title', '/local/kbacompany/culture.php'],
            'transfers' => ['functiontransfer_title', '/local/kbacompany/function_transfers.php'],
            'learning' => ['people_open_learning', '/local/kbacompany/learning.php'],
            'feedback360' => ['competency360_inbox_title', '/local/kbacompany/competency_360_inbox.php'],
        ];
        $links = [];
        foreach ($items as $key => $item) {
            $classes = 'bms-workspace-subnav-link' . ($key === $active ? ' is-active' : '');
            $links[] = \html_writer::link(new \moodle_url($item[1], ['uuid' => $companyuuid]),
                get_string($item[0], 'local_kbacompany'), ['class' => $classes,
                    'aria-current' => $key === $active ? 'page' : null]);
        }
        return \html_writer::tag('nav', implode('', $links), [
            'class' => 'bms-workspace-subnav',
            'aria-label' => get_string('people_nav_aria', 'local_kbacompany'),
        ]);
    }

    /**
     * Management navigation is a small section bar plus the current section's links.
     * Link visibility reuses existing company permissions; it is not an ACL grant.
     */
    public static function management_nav(string $companyuuid, string $active, string $week = ''): string {
        global $USER;
        $canmanage = permission_service::can_manage_management($companyuuid, (int)$USER->id);
        $model = self::management_nav_model($companyuuid, $active, $canmanage, $week);
        $out = self::navigation_list($model['sections'], 'bms-management-nav-sections');
        if ($model['items']) {
            $out .= self::navigation_list($model['items'], 'bms-management-nav-items');
        }
        return \html_writer::tag('nav', $out, [
            'class' => 'bms-management-nav',
            'aria-label' => get_string('management_nav_aria', 'local_kbacompany'),
        ]);
    }

    /**
     * Pure presentation model. No data reads, writes, counters or role inference.
     * All destinations keep their canonical controllers and company UUID.
     */
    public static function management_nav_model(string $companyuuid, string $active, bool $canmanage,
            string $week = ''): array {
        // Only date context is carried. Never copy arbitrary request parameters,
        // action, sesskey or another object's UUID into navigation URLs.
        $parts = [];
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $week, $parts) ||
                !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
            $week = '';
        }
        // key => [label, path, params, requires management access, supports week].
        $catalogue = [
            'overview' => ['bmsnav_overview', 'management.php', [], true, true],
            'week' => ['bmsnav_week', 'battle_plan_overview.php', [], false, true],
            'battleplan' => ['battleplan_title', 'battle_plan.php', [], false, true],
            'cycles' => ['mcycle_title', 'management_cycles.php', [], true, false],
            'indicators' => ['bmsworkspace_indicators', 'indicators.php', [], false, false],
            'exceptions' => ['indicator_tab_exceptions', 'indicators.php', ['tab' => 'exceptions'], false, false],
            'loop' => ['management_loop_title', 'management_loop.php', [], true, true],
            'review' => ['management_review_title', 'management_review.php', [], true, true],
            'history' => ['management_history_title', 'management_history.php', [], true, true],
            'patterns' => ['management_patterns_title', 'management_patterns.php', [], true, false],
            'ownerws' => ['owner_ws_title', 'owner_workspace.php', [], false, false],
            'curator' => ['curator_workspace_title', 'curator_workspace.php', [], true, false],
        ];
        $definitions = [
            'overview' => ['bmsnav_overview', ['overview']],
            'planning' => ['bmsnav_planning', ['week', 'battleplan', 'cycles']],
            'indicators' => ['bmsnav_indicators', ['indicators', 'exceptions']],
            'decisions' => ['bmsnav_decisions', ['loop', 'review', 'history', 'patterns']],
            'workspaces' => ['bmsnav_workspaces', ['ownerws', 'curator']],
        ];
        $sections = [];
        $currentitems = [];
        foreach ($definitions as $sectionkey => $definition) {
            $items = [];
            $sectionactive = false;
            foreach ($definition[1] as $key) {
                $item = $catalogue[$key];
                if ($item[3] && !$canmanage) {
                    continue;
                }
                $params = array_merge(['uuid' => $companyuuid], $item[2]);
                if ($week !== '' && $item[4]) {
                    $params['week'] = $week;
                }
                $isactive = $key === $active;
                $sectionactive = $sectionactive || $isactive;
                $items[] = [
                    'key' => $key,
                    'label' => get_string($item[0], 'local_kbacompany'),
                    'url' => (new \moodle_url('/local/kbacompany/' . $item[1], $params))->out(false),
                    'active' => $isactive,
                    'current' => $isactive ? 'page' : '',
                ];
            }
            if (!$items) {
                continue;
            }
            $sections[] = [
                'key' => $sectionkey,
                'label' => get_string($definition[0], 'local_kbacompany'),
                'url' => $items[0]['url'],
                'active' => $sectionactive,
                // The top row is the section, not a second current-page link.
                'current' => $sectionactive ? ($sectionkey === 'overview' ? 'page' : 'location') : '',
            ];
            if ($sectionactive && $sectionkey !== 'overview') {
                $currentitems = $items;
            }
        }
        return ['sections' => $sections, 'items' => $currentitems];
    }

    /** Semantic link lists, usable with keyboard and without JavaScript. */
    private static function navigation_list(array $items, string $class): string {
        $out = '';
        foreach ($items as $item) {
            $attrs = [
                'class' => 'bms-workspace-subnav-link' . ($item['active'] ? ' is-active' : ''),
                'data-bms-nav-key' => $item['key'],
            ];
            if ($item['current'] !== '') {
                $attrs['aria-current'] = $item['current'];
            }
            $out .= \html_writer::tag('li', \html_writer::link($item['url'], s($item['label']), $attrs));
        }
        return \html_writer::tag('ul', $out, ['class' => $class]);
    }


    /** Local navigation for the governance/document-quality contour. */
    public static function governance_nav(string $companyuuid, string $active): string {
        $items = [
            'documents' => ['bmsnav_documents', '/local/kbacompany/documents.php'],
            'policies' => ['bmsnav_policies', '/local/kbacompany/policies.php'],
            'deviations' => ['bmsnav_deviations', '/local/kbacompany/deviations.php'],
        ];
        $links = [];
        foreach ($items as $key => $item) {
            $classes = 'bms-workspace-subnav-link' . ($key === $active ? ' is-active' : '');
            $links[] = \html_writer::link(new \moodle_url($item[1], ['uuid' => $companyuuid]),
                get_string($item[0], 'local_kbacompany'), ['class' => $classes,
                    'aria-current' => $key === $active ? 'page' : null]);
        }
        return \html_writer::tag('nav', implode('', $links), [
            'class' => 'bms-workspace-subnav bms-governance-subnav',
            'aria-label' => get_string('management_nav_aria', 'local_kbacompany'),
        ]);
    }

    /** Compact ERP-style page/record header. */
    public static function record_header(string $title, string $subtitle = '', string $eyebrow = '',
            string $metahtml = '', string $actionshtml = ''): string {
        $copy = '';
        if ($eyebrow !== '') {
            $copy .= \html_writer::div($eyebrow, 'bms-workspace-eyebrow');
        }
        $copy .= \html_writer::tag('h1', $title, ['class' => 'bms-workspace-title']);
        if ($subtitle !== '') {
            $copy .= \html_writer::div($subtitle, 'bms-workspace-subtitle');
        }
        if ($metahtml !== '') {
            $copy .= \html_writer::div($metahtml, 'bms-workspace-meta');
        }
        $left = \html_writer::div($copy, 'bms-workspace-header-copy');
        $right = $actionshtml === '' ? '' : \html_writer::div($actionshtml, 'bms-workspace-header-actions');
        return \html_writer::div($left . $right, 'bms-workspace-header');
    }

    /** Shared list action bar foundation: primary actions + view controls. */
    public static function action_bar(string $actionshtml = '', string $viewshtml = ''): string {
        return \html_writer::div(
            \html_writer::div($actionshtml, 'bms-workspace-actionbar-main') .
            \html_writer::div($viewshtml, 'bms-workspace-actionbar-views'),
            'bms-workspace-actionbar');
    }

    /** Compact view switcher, routes are supplied by the domain controller. */
    public static function view_switcher(array $views, string $active): string {
        $out = '';
        foreach ($views as $key => $view) {
            if (empty($view['url']) || empty($view['label'])) {
                continue;
            }
            $class = 'btn btn-sm ' . ($key === $active ? 'btn-secondary' : 'btn-outline-secondary');
            $out .= \html_writer::link($view['url'], $view['label'], ['class' => $class,
                'aria-current' => $key === $active ? 'page' : null]);
        }
        return \html_writer::div($out, 'btn-group bms-view-switcher', ['role' => 'group']);
    }

    // Shared BMS CRUD primitives extracted without output changes from the Position UI.
    // No domain routes, permissions or writes belong in these renderers.
    private static function record_tr(string $key, $a = null): string {
        return get_string($key, 'local_kbacompany', $a);
    }
    public static function form_hidden(array $values): string {
        $out = '';
        foreach ($values as $key => $value) { $out .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $key, 'value' => $value]); }
        return $out;
    }

    public static function record_link($url, string $label, bool $primary = false): string {
        return \html_writer::link($url, s($label), ['class' => 'btn ' . ($primary ? 'btn-primary' : 'btn-outline-secondary')]);
    }

    public static function record_note(string $value): string { return \html_writer::tag('p', s($value), ['class' => 'bms-pdu-note']); }

    public static function record_panel(string $title, string $body): string {
        return \html_writer::tag('section', \html_writer::tag('h2', s($title), ['class' => 'bms-crm-section-title']) . $body,
            ['class' => 'bms-crm-section bms-pdu-panel']);
    }

    public static function record_fields(array $fields): string {
        $body = '';
        foreach ($fields as $label => $value) {
            $body .= \html_writer::div(\html_writer::tag('dt', s($label)) . \html_writer::tag('dd', s((string)$value !== '' ? (string)$value : self::record_tr('pdu_not_set'))), 'bms-pdu-field');
        }
        return \html_writer::tag('dl', $body, ['class' => 'bms-crm-field-grid bms-pdu-grid']);
    }

    public static function record_menu(string $title, array $items): string {
        if (!$items) { return ''; }
        $body = ''; foreach ($items as $item) { $body .= \html_writer::link($item[0], s($item[1]), isset($item[2]) ? ['class' => $item[2]] : []); }
        return \html_writer::tag('details', \html_writer::tag('summary', s($title), ['class' => 'btn btn-outline-secondary']) .
            \html_writer::div($body, 'bms-crm-action-menu-panel bms-pdu-menu-items'), ['class' => 'bms-crm-action-menu bms-pdu-menu']);
    }

    public static function record_table(array $heads, array $rows): string {
        $t = new \html_table(); $t->head = array_map('s', $heads); $t->attributes['class'] = 'table bms-crm-list-table bms-pdu-table';
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $value) { $cells[] = \html_writer::span(s($heads[$i]), 'bms-pdu-cell-label', ['aria-hidden' => 'true']) . $value; }
            $t->data[] = $cells;
        }
        return \html_writer::div(\html_writer::table($t), 'bms-pdu-table-wrap', ['role' => 'region', 'aria-label' => self::record_tr('pdu_records'), 'tabindex' => '0']);
    }

    public static function form_control(string $name, string $label, string $value, array $spec = [], string $error = ''): string {
        $id = 'pdu_' . $name; $desc = [];
        $attrs = ['id' => $id, 'name' => $name, 'class' => 'form-control' . ($error !== '' ? ' is-invalid' : '')];
        if (!empty($spec['required'])) { $attrs['required'] = 'required'; }
        foreach (['maxlength', 'min', 'max'] as $key) { if (isset($spec[$key])) { $attrs[$key] = $spec[$key]; } }
        if ($error !== '') { $attrs['aria-invalid'] = 'true'; $desc[] = $id . '_error'; }
        if (!empty($spec['help'])) { $desc[] = $id . '_help'; }
        if ($desc) { $attrs['aria-describedby'] = implode(' ', $desc); }
        $out = \html_writer::label(s($label . (!empty($spec['required']) ? ' *' : '')), $id);
        if (isset($spec['options'])) { $out .= \html_writer::select($spec['options'], $name, $value, false, $attrs); }
        else if (($spec['type'] ?? '') === 'textarea') { $out .= \html_writer::tag('textarea', s($value), $attrs + ['rows' => 5]); }
        else { $out .= \html_writer::empty_tag('input', $attrs + ['type' => $spec['type'] ?? 'text', 'value' => $value]); }
        if ($error !== '') { $out .= \html_writer::div(s($error), 'invalid-feedback', ['id' => $id . '_error']); }
        if (!empty($spec['help'])) { $out .= \html_writer::tag('p', s($spec['help']), ['class' => 'bms-pdu-note', 'id' => $id . '_help']); }
        return \html_writer::div($out, 'bms-pdu-control');
    }

    public static function form_errors(array $errors): string {
        if (!$errors) { return ''; }
        $out = \html_writer::tag('strong', s(self::record_tr('pdu_check_form')));
        foreach ($errors as $field => $error) { $out .= \html_writer::tag('p', $field[0] === '_' ? s($error) : \html_writer::link('#pdu_' . $field, s($error))); }
        return \html_writer::div($out, 'alert alert-danger', ['role' => 'alert']);
    }
}
