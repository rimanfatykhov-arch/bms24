<?php

defined('MOODLE_INTERNAL') || die();

/** Serve files attached to BMS implementation-audit criteria. */
function local_kbacorporate_pluginfile($course, $cm, $context, $filearea, $args,
        $forcedownload, array $options = []) {
    global $DB, $USER;
    if ($context->contextlevel !== CONTEXT_SYSTEM || !in_array($filearea,
            ['implementation_evidence', 'implementation_task_evidence',
                'implementation_evidence_record', 'implementation_economic_evidence'], true)) {
        return false;
    }
    require_login();
    $itemid = (int)array_shift($args);
    if ($filearea === 'implementation_economic_evidence') {
        $snapshot = $DB->get_record('local_kbacorp_impl_econ', ['id' => $itemid], '*', MUST_EXIST);
        $audit = $DB->get_record('local_kbacorp_impl_audit', ['id' => $snapshot->auditid], '*', MUST_EXIST);
    } else if ($filearea === 'implementation_task_evidence') {
        $task = \local_kbacorporate\local\implementation_task_discussion::get_task((int)$itemid);
        \local_kbacorporate\local\implementation_task_discussion::require_access($task, (int)$USER->id);
        $audit = $DB->get_record('local_kbacorp_impl_audit', ['id' => $task->auditid], '*', MUST_EXIST);
    } else if ($filearea === 'implementation_evidence_record') {
        $evidence = $DB->get_record('local_kbacorp_impl_evid', ['id' => $itemid], '*', MUST_EXIST);
        $task = \local_kbacorporate\local\implementation_task_discussion::get_task((int)$evidence->taskid);
        \local_kbacorporate\local\implementation_task_discussion::require_access($task, (int)$USER->id);
        $audit = $DB->get_record('local_kbacorp_impl_audit', ['id' => $task->auditid], '*', MUST_EXIST);
    } else {
        $item = $DB->get_record('local_kbacorp_impl_item', ['id' => $itemid], '*', MUST_EXIST);
        $audit = $DB->get_record('local_kbacorp_impl_audit', ['id' => $item->auditid], '*', MUST_EXIST);
    }
    if (!in_array($filearea, ['implementation_task_evidence', 'implementation_evidence_record'], true)) {
        \local_kbacorporate\local\access::require_company_implementation_access(
            (int)$audit->companyid, (int)$USER->id);
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file($context->id, 'local_kbacorporate', $filearea,
        $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Renders a consistent return link from a company-scoped page to its dashboard.
 *
 * @param int $companyid Company id.
 * @return string
 */
function local_kbacorporate_company_dashboard_navigation(int $companyid): string {
    if ($companyid > 0 && has_capability('local/kbacorporate:viewdashboard', context_system::instance())) {
        $url = new moodle_url('/local/kbacorporate/index.php', ['companyid' => $companyid]);
        $label = get_string('backtodashboard', 'local_kbacorporate');
    } else {
        // Employees can always return from their competency profile even when
        // they do not have access to the corporate manager dashboard.
        $url = new moodle_url('/my/');
        $label = get_string('backtopersonaldashboard', 'local_kbacorporate');
    }

    $link = html_writer::link($url, '← ' . $label);

    return html_writer::div($link, 'kbacorp-page-navigation', ['role' => 'navigation']);
}

/**
 * Render the active company prominently on company-scoped working pages.
 *
 * @param stdClass $company Company record.
 * @return string
 */
function local_kbacorporate_company_context_banner(stdClass $company): string {
    $label = html_writer::span(get_string('currentclient', 'local_kbacorporate'),
        'kbacorp-current-client-label');
    $name = html_writer::span(format_string($company->name), 'kbacorp-current-client-name');

    return html_writer::div($label . $name, 'kbacorp-current-client', [
        'role' => 'status',
        'aria-label' => get_string('currentclientvalue', 'local_kbacorporate', format_string($company->name)),
    ]);
}

/**
 * Check whether a user is an actual corporate or department manager.
 *
 * The capability alone is not enough because it may be inherited by a broad
 * system role. The membership record keeps the corporate shortcut scoped to
 * users who really manage a company or department.
 *
 * @param int $userid User id.
 * @return bool
 */
function local_kbacorporate_is_corporate_manager(int $userid): bool {
    global $DB;

    if (is_siteadmin($userid)) {
        return true;
    }
    $dbman = $DB->get_manager();
    $membertable = new xmldb_table('local_kbacorp_member');
    if ($dbman->table_exists($membertable) && $DB->record_exists('local_kbacorp_member', [
            'userid' => $userid,
            'ismanager' => 1,
        ])) {
        return true;
    }
    $departmentmembertable = new xmldb_table('local_kbacorp_dept_member');
    return $dbman->table_exists($departmentmembertable) &&
        $DB->record_exists('local_kbacorp_dept_member', [
            'userid' => $userid,
            'deptrole' => 'manager',
        ]);
}

/**
 * Render the common BMS implementation workspace menu.
 *
 * @param int $companyid Validated company context, or zero for the cross-company workspace.
 * @param string $active Active menu item.
 * @return string
 */
function local_kbacorporate_implementation_navigation(int $companyid = 0, string $active = '', int $shortcutcompanyid = 0): string {
    global $DB, $USER;

    $context = context_system::instance();
    $dbman = $DB->get_manager();
    $canmethodology = has_capability('local/kbacorporate:manageimplementationaudits', $context);
    $canscopedimplementation = has_capability(
        'local/kbacorporate:managecompanyimplementation', $context);
    $caneconomics = has_capability('local/kbacorporate:vieweconomicindicators', $context);
    $hasownedtasks = $dbman->table_exists(new xmldb_table('local_kbacorp_impl_task')) &&
        $DB->record_exists('local_kbacorp_impl_task', ['ownerid' => (int)$USER->id, 'deleted' => 0]);
    $iscurator = $dbman->table_exists(new xmldb_table('local_kbacorp_impl_program')) &&
        $DB->record_exists('local_kbacorp_impl_program', ['curatorid' => (int)$USER->id]);
    $iscorporatemanager = has_capability('local/kbacorporate:viewdashboard', $context) &&
        local_kbacorporate_is_corporate_manager((int)$USER->id);
    $canregistry = $canmethodology || ($canscopedimplementation && $iscorporatemanager);
    $cancompanyimplementation = $companyid > 0 &&
        \local_kbacorporate\local\access::can_manage_company_implementation(
            $companyid, (int)$USER->id);

    // Keep the BMS workspace navigation compact. Customer-facing links are
    // separated from global methodology administration so a corporate manager
    // can work with their own company without gaining access to Framework
    // Manager, the global tool library or JSON administration.
    $items = [];
    $companyparams = $companyid > 0 ? ['companyid' => $companyid] : [];

    if ($iscorporatemanager) {
        $items[] = [
            'dashboard',
            new moodle_url('/local/kbacorporate/index.php', $companyparams),
            '← ' . get_string('backtodashboard', 'local_kbacorporate'),
        ];
    }

    if ($companyid > 0 && $iscorporatemanager) {
        $items[] = [
            'competencymap',
            new moodle_url('/local/kbacorporate/competency_map.php', ['companyid' => $companyid]),
            get_string('competencymap', 'local_kbacorporate'),
        ];
    }

    if ($canregistry) {
        $items[] = [
            'clients',
            new moodle_url('/local/kbacorporate/implementation.php', $companyparams),
            get_string($companyid > 0 ? 'implementationnavclient' : 'implementationnavclients',
                'local_kbacorporate'),
        ];
    }

    $bmscompanyid = $companyid > 0 ? $companyid : $shortcutcompanyid;
    $canbmscompany = $bmscompanyid > 0 &&
        \local_kbacorporate\local\access::can_manage_company_implementation(
            $bmscompanyid, (int)$USER->id);

    if ($canbmscompany) {
        $items[] = [
            'management',
            new moodle_url('/local/kbacorporate/management_dashboards.php', ['companyid' => $bmscompanyid]),
            get_string('managementdashboardsshort', 'local_kbacorporate'),
        ];
        $items[] = [
            'audits',
            new moodle_url('/local/kbacorporate/implementation_audits.php', ['companyid' => $bmscompanyid]),
            get_string('implementationnavaudits', 'local_kbacorporate'),
        ];
        $items[] = [
            'comparison',
            new moodle_url('/local/kbacorporate/implementation_audit_comparison.php', ['companyid' => $bmscompanyid]),
            get_string('implementationnavcomparison', 'local_kbacorporate'),
        ];
        if ($caneconomics) {
            $items[] = [
                'economics',
                new moodle_url('/local/kbacorporate/economic_dashboard.php', ['companyid' => $bmscompanyid]),
                get_string('economicdashboardshort', 'local_kbacorporate'),
            ];
        }
    }

    // Global methodology and data-contract administration stays restricted to
    // the original high-privilege capability.
    if ($bmscompanyid > 0 && $canmethodology) {
        $items[] = [
            'framework',
            new moodle_url('/local/kbacorporate/framework.php', ['companyid' => $bmscompanyid]),
            get_string('implementationnavframework', 'local_kbacorporate'),
        ];
        $items[] = [
            'tools',
            new moodle_url('/local/kbacorporate/bms_tools.php', ['companyid' => $bmscompanyid]),
            get_string('bmstoollibrary', 'local_kbacorporate'),
        ];
        if ($companyid > 0) {
            $items[] = [
                'jsonio',
                new moodle_url('/local/kbacorporate/bms_competency_io.php', ['companyid' => $companyid]),
                get_string('bmsjsoniomenu', 'local_kbacorporate'),
            ];
        }
    }

    if ($companyid > 0 && $iscurator && !$canbmscompany) {
        $items[] = [
            'comparison',
            new moodle_url('/local/kbacorporate/implementation_audit_comparison.php', ['companyid' => $companyid]),
            get_string('implementationnavcomparison', 'local_kbacorporate'),
        ];
    }

    if ($hasownedtasks) {
        $items[] = [
            'mytasks',
            new moodle_url('/local/kbacorporate/implementation_my_tasks.php'),
            get_string('myimplementationtasks', 'local_kbacorporate'),
        ];
    }

    if ($iscurator || $canmethodology) {
        $items[] = [
            'curatorportfolio',
            new moodle_url('/local/kbacorporate/management_dashboards.php', ['panel' => 'curator']),
            get_string('managementpanelcurator', 'local_kbacorporate'),
        ];
        $items[] = [
            'students',
            new moodle_url('/local/kbacorporate/implementation_curator.php'),
            get_string('implementationnavstudents', 'local_kbacorporate'),
        ];
        $items[] = [
            'board',
            new moodle_url('/local/kbacorporate/implementation_board.php'),
            get_string('implementationnavboard', 'local_kbacorporate'),
        ];
        $items[] = [
            'analytics',
            new moodle_url('/local/kbacorporate/implementation_analytics.php'),
            get_string('implementationnavanalytics', 'local_kbacorporate'),
        ];
    }

    if ($iscurator || $canmethodology || $cancompanyimplementation) {
        $items[] = [
            'sectionprogress',
            new moodle_url('/local/kbacorporate/implementation_section_progress.php', $companyparams),
            get_string('implementationnavsectionprogress', 'local_kbacorporate'),
        ];
    }

    if (!$items) {
        $items[] = [
            'competencies',
            new moodle_url('/local/kbacorporate/mycompetencies.php'),
            get_string('mycompetencies', 'local_kbacorporate'),
        ];
    }

    $parts = [];
    foreach ($items as [$key, $url, $label]) {
        $attributes = ['class' => 'kbacorp-implementation-nav-link' . ($active === $key ? ' is-active' : '')];
        if ($active === $key) {
            $attributes['aria-current'] = 'page';
        }
        $parts[] = html_writer::link($url, $label, $attributes);
    }

    return html_writer::div(implode(html_writer::span('·', 'kbacorp-implementation-nav-separator'), $parts),
        'kbacorp-implementation-nav kbacorp-implementation-nav-compact', [
            'role' => 'navigation',
            'aria-label' => get_string('workspacenavtitle', 'local_kbacorporate'),
        ]);
}

/**
 * Resolve a safe company context for permanent navigation shortcuts.
 *
 * A direct company is returned only when the viewer has exactly one available
 * company. Multi-company administrators must always enter through the client
 * registry, even while viewing a company-scoped page, so the permanent BMS
 * shortcut also acts as a reliable company switcher.
 *
 * @return int Company id or zero when a choice is required.
 */
function local_kbacorporate_navigation_companyid(): int {
    global $DB, $USER;

    $dbman = $DB->get_manager();
    if (!$dbman->table_exists(new xmldb_table('local_kbacorp_company'))) {
        return 0;
    }
    $companies = \local_kbacorporate\local\access::get_allowed_companies((int)$USER->id);
    return count($companies) === 1 ? (int)array_key_first($companies) : 0;
}

function local_kbacorporate_extend_navigation(global_navigation $navigation) {
    global $DB, $USER;
    if (!isloggedin() || isguestuser()) {
        return;
    }
    $context = context_system::instance();

    // Personal workspace is always first for every authenticated user.
    $navigation->add(
        get_string('mycompetencies', 'local_kbacorporate'),
        new moodle_url('/local/kbacorporate/mycompetencies.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_kbacorporate_mycompetencies',
        new pix_icon('i/grades', '')
    );
    if ($DB->get_manager()->table_exists(new xmldb_table('local_kbacorp_impl_task')) &&
            $DB->record_exists('local_kbacorp_impl_task', ['ownerid' => (int)$USER->id, 'deleted' => 0])) {
        $navigation->add(
            get_string('myimplementationtasks', 'local_kbacorporate'),
            new moodle_url('/local/kbacorporate/implementation_my_tasks.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_kbacorporate_mytasks',
            new pix_icon('i/checkpermissions', '')
        );
    }
    if (has_capability('local/kbacorporate:viewdashboard', $context) &&
            local_kbacorporate_is_corporate_manager((int)$USER->id)) {
        $navigation->add(
            get_string('dashboard', 'local_kbacorporate'),
            new moodle_url('/local/kbacorporate/index.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_kbacorporate_dashboard',
            new pix_icon('i/report', '')
        );
    }
    if ($DB->get_manager()->table_exists(new xmldb_table('local_kbacorp_impl_program')) &&
            ($DB->record_exists('local_kbacorp_impl_program', ['curatorid' => (int)$USER->id]) ||
            has_capability('local/kbacorporate:manageimplementationaudits', $context))) {
        $navigation->add(
            get_string('curatorworkspace', 'local_kbacorporate'),
            new moodle_url('/local/kbacorporate/implementation_curator.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_kbacorporate_curatorworkspace',
            new pix_icon('t/message', '')
        );
    }
    if (has_capability('local/kbacorporate:manageimplementationaudits', $context) ||
            (has_capability('local/kbacorporate:managecompanyimplementation', $context) &&
            local_kbacorporate_is_corporate_manager((int)$USER->id))) {
        $companyid = local_kbacorporate_navigation_companyid();
        $params = $companyid > 0 ? ['companyid' => $companyid] : [];
        $navigation->add(
            get_string('bmsimplementation', 'local_kbacorporate'),
            new moodle_url('/local/kbacorporate/implementation.php', $params),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_kbacorporate_implementation',
            new pix_icon('i/settings', '')
        );
    }
    if (has_capability('local/kbacorporate:viewcompetencies', $context)) {
        $navigation->add(
            get_string('competencymap', 'local_kbacorporate'),
            new moodle_url('/local/kbacorporate/competency_map.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_kbacorporate_competencymap',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Adds a permanent Corporate Dashboard shortcut to the Moodle navbar.
 *
 * Moodle 4.x does not render every custom global-navigation node in the
 * primary header navigation. navbar_plugin_output is rendered by Boost and
 * Boost-derived themes in the right side of the navbar, so this provides a
 * reliable entry point for corporate managers without modifying Moodle core.
 *
 * @param core_renderer $renderer
 * @return string
 */
function local_kbacorporate_render_navbar_output($renderer) {
    global $DB, $USER;

    if (!isloggedin() || isguestuser() || during_initial_install()) {
        return '';
    }

    $entries = [];
    $implementationentry = '';

    // Personal competency profile is available to every authenticated user.
    $competencyurl = new moodle_url('/local/kbacorporate/mycompetencies.php');
    $competencyicon = $renderer->pix_icon('i/grades', '', 'moodle', ['class' => 'kbacorp-navbar-icon']);
    $competencylabel = html_writer::span(
        get_string('mycompetencies', 'local_kbacorporate'),
        'kbacorp-navbar-label'
    );
    $entries[] = html_writer::link($competencyurl, $competencyicon . $competencylabel, [
        'class' => 'kbacorp-navbar-link kbacorp-navbar-competencies',
        'title' => get_string('mycompetencies', 'local_kbacorporate'),
        'aria-label' => get_string('mycompetencies', 'local_kbacorporate'),
    ]);

    $context = context_system::instance();
    if ($DB->get_manager()->table_exists(new xmldb_table('local_kbacorp_impl_task')) &&
            $DB->record_exists('local_kbacorp_impl_task', ['ownerid' => (int)$USER->id, 'deleted' => 0])) {
        $taskurl = new moodle_url('/local/kbacorporate/implementation_my_tasks.php');
        $taskicon = $renderer->pix_icon('i/checkpermissions', '', 'moodle', ['class' => 'kbacorp-navbar-icon']);
        $tasklabel = html_writer::span(get_string('myimplementationtasks', 'local_kbacorporate'),
            'kbacorp-navbar-label');
        $entries[] = html_writer::link($taskurl, $taskicon . $tasklabel, [
            'class' => 'kbacorp-navbar-link kbacorp-navbar-mytasks',
            'title' => get_string('myimplementationtasks', 'local_kbacorporate'),
            'aria-label' => get_string('myimplementationtasks', 'local_kbacorporate'),
        ]);
    }
    if ($DB->get_manager()->table_exists(new xmldb_table('local_kbacorp_impl_program')) &&
            $DB->record_exists('local_kbacorp_impl_program', ['curatorid' => (int)$USER->id])) {
        $curatorurl = new moodle_url('/local/kbacorporate/implementation_curator.php');
        $curatoricon = $renderer->pix_icon('t/message', '', 'moodle', ['class' => 'kbacorp-navbar-icon']);
        $curatorlabel = html_writer::span(get_string('implementationnavstudents', 'local_kbacorporate'),
            'kbacorp-navbar-label');
        $entries[] = html_writer::link($curatorurl, $curatoricon . $curatorlabel, [
            'class' => 'kbacorp-navbar-link kbacorp-navbar-curator',
            'title' => get_string('implementationnavstudents', 'local_kbacorporate'),
            'aria-label' => get_string('implementationnavstudents', 'local_kbacorporate'),
        ]);
    }
    if (has_capability('local/kbacorporate:manageimplementationaudits', $context) ||
            (has_capability('local/kbacorporate:managecompanyimplementation', $context) &&
            local_kbacorporate_is_corporate_manager((int)$USER->id))) {
        $implementationcompanyid = local_kbacorporate_navigation_companyid();
        $implementationparams = $implementationcompanyid > 0
            ? ['companyid' => $implementationcompanyid]
            : [];
        $implementationurl = new moodle_url('/local/kbacorporate/implementation.php', $implementationparams);
        $implementationicon = $renderer->pix_icon('i/settings', '', 'moodle', [
            'class' => 'kbacorp-navbar-icon',
        ]);
        $implementationlabel = html_writer::span(
            get_string('bmsimplementation', 'local_kbacorporate'),
            'kbacorp-navbar-label'
        );
        $implementationentry = html_writer::link(
            $implementationurl,
            $implementationicon . $implementationlabel,
            [
                'class' => 'kbacorp-navbar-link kbacorp-navbar-implementation',
                'title' => get_string('bmsimplementation', 'local_kbacorporate'),
                'aria-label' => get_string('bmsimplementation', 'local_kbacorporate'),
            ]
        );
    }
    if (has_capability('local/kbacorporate:viewdashboard', $context)) {
        // Corporate Dashboard shortcut is restricted to actual corporate or
        // department managers, matching the existing dashboard access model.
        $showdashboard = local_kbacorporate_is_corporate_manager((int)$USER->id);

        if ($showdashboard) {
            $url = new moodle_url('/local/kbacorporate/index.php');
            $icon = $renderer->pix_icon('i/report', '', 'moodle', ['class' => 'kbacorp-navbar-icon']);
            $label = html_writer::span(
                get_string('navbarlink', 'local_kbacorporate'),
                'kbacorp-navbar-label'
            );
            $entries[] = html_writer::link($url, $icon . $label, [
                'class' => 'kbacorp-navbar-link',
                'title' => get_string('navbarlink', 'local_kbacorporate'),
                'aria-label' => get_string('navbarlink', 'local_kbacorporate'),
            ]);
        }
    }
    // Administrative implementation access follows the personal and company
    // shortcuts, keeping role progression readable from left to right.
    if ($implementationentry !== '') {
        $entries[] = $implementationentry;
    }

    return html_writer::div(implode('', $entries), 'kbacorp-navbar-entry');
}
