<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * BMS UI presentation model for optional KBA Theme enhancement.
 *
 * BMS owns navigation semantics, counters, routes and page context. The theme
 * owns visual presentation only.
 */
class bms_ui_service {
    public const CONTRACT_VERSION = '0.7.0.17';
    public const KBA_THEME_MIN_VERSION = 2026091325;

    /** Return the soft presentation-integration contract. */
    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_ui_presentation_model',
            'version' => self::CONTRACT_VERSION,
            'theme_enhancement' => [
                'component' => 'theme_kba',
                'minimum_version' => self::KBA_THEME_MIN_VERSION,
                'layout' => 'bms',
                'template' => 'theme_kba/bms/app_shell',
                'optional' => true,
            ],
            'owned_shell' => [
                'component' => 'local_kbacompany',
                'template' => 'local_kbacompany/bms24_app_shell',
                'enabled_by' => 'local_kbacompany/bms24_shell_mode=owned',
                'default' => 'legacy',
                'uses_moodle_standard_layout' => true,
            ],
            'workspace' => [
                'navigation_groups' => ['workspace', 'architecture', 'people', 'management'],
                'dashboard_blocks' => ['personal_counters', 'my_work', 'attention', 'architecture', 'company'],
                'architecture_tree_location' => 'business_architecture_center',
                'architecture_sequence' => ['owner', 'philosophy', 'market', 'business_model', 'financial_model', 'gaps_strategy',
                    'organization', 'process_architecture', 'process_design_stp', 'functions_positions_roles',
                    'documents_metrics_improvement'],
                'quick_actions' => ['create_task', 'message'],
            ],
            'rules' => [
                'bms_owns_navigation_routes_permissions_and_data',
                'theme_owns_visual_presentation_only',
                'normal_moodle_fallback_is_required',
                'no_business_permission_is_decided_by_theme',
                'no_business_route_is_hardcoded_in_theme',
                'workspace_prioritises_daily_work_over_architecture_navigation',
                'architecture_tree_is_shown_in_dedicated_architecture_center',
                'company_php_is_a_compatibility_entry_to_workspace',
                'one_company_one_app_shell_entry_point',
                'ordinary_bms_pages_use_shared_page_shell_adapter',
                'business_architecture_follows_product_first_design_sequence',
                'architecture_navigator_is_grouped_by_methodology_not_route_creation_order',
                'architecture_center_is_a_guided_workspace_not_a_duplicate_catalogue',
                'architecture_readiness_is_evidence_based_and_stage_specific',
                'architecture_center_recommends_the_next_incomplete_design_stage',
                'ofs_visual_designer_projects_the_design_model_into_the_agreed_poster_form',
                'valuable_final_product_is_the_bottom_result_of_the_ofs_visual',
            ],
        ];
    }

    /** Whether the current request can safely use KBA Theme BMS App Shell v1. */
    public static function supports_kba_shell(): bool {
        global $PAGE;

        try {
            $themename = (string)($PAGE->theme->name ?? '');
        } catch (\Throwable $e) {
            return false;
        }
        if ($themename !== 'kba') {
            return false;
        }

        $version = (int)get_config('theme_kba', 'version');
        if ($version <= 0 && function_exists('get_component_version')) {
            $version = (int)get_component_version('theme_kba');
        }
        return $version >= self::KBA_THEME_MIN_VERSION;
    }

    /**
     * Build the daily-work model for BMS Workspace.
     * The optional visible-card list avoids hydrating hidden cards; null preserves
     * the historical full model used by other callers.
     */
    public static function workspace_model(string $companyuuid, int $userid, ?array $visiblewidgets = null,
            bool $includeattentionmanagement = true): array {
        $company = company_context_service::context($companyuuid, $userid);
        $load = bms_workspace_load_policy::requirements($visiblewidgets, $includeattentionmanagement);
        $organization = $load['organization']
            ? organization_service::organization_context($companyuuid, $userid)
            : ['summary' => ['employees' => 0, 'org_units' => 0, 'positions' => 0]];
        $processes = $load['processes']
            ? process_service::context($companyuuid, $userid)
            : ['summary' => ['total' => 0]];
        $work = $load['work']
            ? communication_center_service::context($companyuuid, $userid, 'inbox', 'my', 'all')
            : null;
        if ($load['work']) {
            // The canonical BMS task list must agree with Tasks.php. The aggregated task
            // provider intentionally isolates provider failures and may otherwise show an
            // empty dashboard even while Tasks.php can read the records. Read the same
            // company/user-scoped list and its SQL counts for the homepage.
            // Keep full open/overdue counters and a separately paged open-task list.
            // Completed tasks must not hide open tasks on a populated workspace.
            $canonicalcounts = task_service::list_page($companyuuid, $userid, ['scope' => 'my'], 0, 1);
            $canonical = task_service::list_page($companyuuid, $userid, ['scope' => 'my', 'openonly' => true], 0, 25);
            $tasks = $work['task_center'];
            $alltasks = [];
            foreach ($canonical['tasks'] as $task) {
                if (in_array($task['status'], ['done', 'cancelled'], true)) { continue; }
                $task['provider'] = 'bms';
                $task['provider_label'] = get_string('taskprovider_bms', 'local_kbacompany');
                $task['source_url'] = (new \moodle_url('/local/kbacompany/tasks.php', [
                    'uuid' => $companyuuid, 'task' => $task['uuid'],
                ]))->out(false);
                $alltasks[] = $task;
            }
            $externalopen = 0;
            $externaloverdue = 0;
            foreach ($tasks['tasks'] as $task) {
                if (($task['provider'] ?? '') === 'bms' ||
                        in_array($task['status'], ['done', 'cancelled'], true)) { continue; }
                $alltasks[] = $task;
                $externalopen++;
                if (!empty($task['is_overdue'])) { $externaloverdue++; }
            }
            $tasks['tasks'] = $alltasks;
            $tasks['counts']['total'] = (int)$canonicalcounts['counts']['open'] + $externalopen;
            $tasks['counts']['overdue'] = (int)$canonicalcounts['counts']['overdue'] + $externaloverdue;
            $work['task_center'] = $tasks;
            $work['counts']['tasks'] = $tasks['counts']['total'];
            $work['counts']['overdue'] = $tasks['counts']['overdue'];
        } else {
            // Counters are needed for navigation even when all work cards are hidden;
            // shell projection intentionally does not fetch messages or task bodies.
            $light = communication_center_service::shell_counters($companyuuid, $userid);
            $work = [
                'task_center' => ['tasks' => [], 'counts' => ['total' => (int)$light['tasks'],
                    'overdue' => (int)$light['overdue']]],
                'counts' => ['tasks' => (int)$light['tasks'], 'overdue' => (int)$light['overdue'],
                    'unread_threads' => (int)$light['unread_threads'],
                    'unread_notifications' => (int)$light['unread_notifications'],
                    'develop' => (int)$light['learning']],
            ];
        }
        // Both paths must expose the same bounded task-center shape to callers.
        $tasks = $work['task_center'];
        $canmanagement = permission_service::can_manage_management($companyuuid, $userid);
        $canowner = permission_service::can_manage_owner_private($companyuuid, $userid);

        $counters = self::workspace_counters($work);
        if (!$load['work']) {
            $counters['control'] = (int)$light['control'];
        }
        if ($load['learning']) {
            $counters['learning'] = learning_contour_service::workspace_learning_count(
                $companyuuid, $userid, (int)$counters['learning']);
        }
        $routes = self::routes($companyuuid, $counters, $canmanagement, $canowner, 'workspace', deviation_service::can_report($companyuuid, $userid), access_role_service::can($companyuuid, $userid, 'management_cycle', 'view'));

        $managementhome = [
            'available' => false,
            'exceptions' => ['summary' => ['total' => 0, 'critical' => 0, 'high' => 0, 'medium' => 0,
                'without_task' => 0, 'overdue' => 0, 'needs_decision' => 0, 'in_progress' => 0,
                'followup_worsened' => 0, 'followup_improved' => 0], 'rows' => []],
            'battleplans' => ['summary' => ['active_plans' => 0, 'critical_indicators' => 0,
                'critical_exceptions' => 0, 'needs_decision' => 0, 'open_tasks' => 0, 'overdue_tasks' => 0],
                'rows' => []],
        ];
        if ($canmanagement && $load['management']) {
            try {
                $managementhome['exceptions'] = indicator_management_exception_service::context($companyuuid, $userid);
                $managementhome['battleplans'] = battle_plan_management_overview_service::context($companyuuid, $userid);
                $managementhome['available'] = true;
            } catch (\Throwable $e) {
                debugging('BMS Management Home read-model failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Availability remains capability-based when the manager card is hidden.
        // We must not drop the user's widget preference just because it is not loaded.
        if ($canmanagement && !$load['management']) {
            $managementhome['available'] = true;
        }

        return [
            'company' => $company,
            'organization_loaded' => $load['organization'],
            'organization' => $organization,
            'process_context' => $processes,
            'task_context' => $tasks,
            'communication_context' => $work,
            'workspace_counters' => $counters,
            'management_home' => $managementhome,
            'navigation_groups' => self::navigation_groups($routes),
            'tree' => self::tree_model($routes),
            'route_map' => self::route_map($routes),
            'roles' => self::role_labels($company['roles']),
            'theme_supported' => !bms24_shell_service::is_enabled() && self::supports_kba_shell(),
            'contract' => self::contract(),
        ];
    }

    /** Build the lightweight App Shell model used by ordinary BMS pages. */
    public static function shell_model(string $companyuuid, int $userid, string $active): array {
        $company = company_context_service::context($companyuuid, $userid);
        $counters = communication_center_service::shell_counters($companyuuid, $userid);
        $canmanagement = permission_service::can_manage_management($companyuuid, $userid);
        $canowner = permission_service::can_manage_owner_private($companyuuid, $userid);
        $routes = self::routes($companyuuid, $counters, $canmanagement, $canowner, $active, deviation_service::can_report($companyuuid, $userid), access_role_service::can($companyuuid, $userid, 'management_cycle', 'view'));

        return [
            'company' => $company,
            'workspace_counters' => $counters,
            'navigation_groups' => self::navigation_groups($routes),
            'route_map' => self::route_map($routes),
            'theme_supported' => !bms24_shell_service::is_enabled() && self::supports_kba_shell(),
            'contract' => self::contract(),
        ];
    }

    /** Build the dedicated guided Business Architecture workspace model. */
    public static function architecture_model(string $companyuuid, int $userid, string $selectedstage = '',
            string $selectedfocus = ''): array {
        $company = company_context_service::context($companyuuid, $userid);
        $organization = organization_service::organization_context($companyuuid, $userid);
        $processes = process_service::context($companyuuid, $userid);
        $functions = function_service::context($companyuuid, $userid);
        $strategy = strategy_service::context($companyuuid, $userid);
        $companycontext = company_context_domain_service::context($companyuuid, $userid);
        $market = market_model_service::context($companyuuid, $userid);
        $products = product_service::context($companyuuid, $userid);
        $positions = position_service::positions_context($companyuuid, $userid);
        $roles = business_role_service::business_roles_context($companyuuid, $userid);
        $documents = document_service::master_list($companyuuid, $userid);
        $work = communication_center_service::context($companyuuid, $userid, 'inbox', 'my', 'all');
        $counters = self::workspace_counters($work);
        $canmanagement = permission_service::can_manage_management($companyuuid, $userid);
        $canowner = permission_service::can_manage_owner_private($companyuuid, $userid);
        $routes = self::routes($companyuuid, $counters, $canmanagement, $canowner, 'architecturecenter', deviation_service::can_report($companyuuid, $userid), access_role_service::can($companyuuid, $userid, 'management_cycle', 'view'));
        $semantics = self::architecture_semantics();
        $readiness = self::architecture_readiness_model($companyuuid, $canmanagement, $strategy, $companycontext, $market,
            $organization, $processes, $functions, $products, $positions, $roles, $documents);
        $flow = self::architecture_flow_model($companyuuid, $routes, $semantics, $readiness);
        $recommended = self::architecture_recommended_stage($flow);
        $selected = self::architecture_selected_stage($selectedstage, $selectedfocus, $flow, $semantics,
            $routes, $recommended);
        $tree = self::architecture_tree_model($companyuuid, $routes, $semantics,
            $selected['key'], $selected['focus']);
        $stageworkspace = self::architecture_stage_workspace($selected, $routes, $semantics, $readiness,
            $recommended);

        return [
            'company' => $company,
            'organization' => $organization,
            'process_context' => $processes,
            'function_context' => $functions,
            'workspace_counters' => $counters,
            'navigation_groups' => self::navigation_groups($routes),
            'tree' => $tree,
            'architecture_flow' => $flow,
            'architecture_readiness' => $readiness,
            'architecture_selected' => $selected,
            'architecture_stage_workspace' => $stageworkspace,
            'architecture_recommended' => $recommended,
            'route_map' => self::route_map($routes),
            'theme_supported' => !bms24_shell_service::is_enabled() && self::supports_kba_shell(),
            'contract' => self::contract(),
        ];
    }

    /**
     * Methodological order for Business Architecture.
     *
     * Stage keys are stable BMS semantics. Navigator items can focus a specific
     * module while remaining inside one guided design stage.
     */
    public static function architecture_semantics(): array {
        return [
            'flow' => [
                ['key' => 'owner', 'route' => 'owner', 'labelkey' => 'bmsarchitecture_step_owner',
                    'descriptionkey' => 'bmsarchitecture_step_owner_desc', 'guidancekey' => 'bmsarchitecture_guidance_owner'],
                ['key' => 'philosophy', 'route' => 'philosophy', 'labelkey' => 'bmsarchitecture_step_philosophy',
                    'descriptionkey' => 'bmsarchitecture_step_philosophy_desc', 'guidancekey' => 'bmsarchitecture_guidance_philosophy'],
                ['key' => 'market', 'route' => 'market', 'labelkey' => 'bmsarchitecture_step_market',
                    'descriptionkey' => 'bmsarchitecture_step_market_desc', 'guidancekey' => 'bmsarchitecture_guidance_market'],
                ['key' => 'business_model', 'route' => 'businessmodel', 'labelkey' => 'bmsarchitecture_step_businessmodel',
                    'descriptionkey' => 'bmsarchitecture_step_businessmodel_desc', 'guidancekey' => 'bmsarchitecture_guidance_businessmodel'],
                ['key' => 'financial_model', 'route' => 'financialmodel', 'labelkey' => 'bmsarchitecture_step_financialmodel',
                    'descriptionkey' => 'bmsarchitecture_step_financialmodel_desc', 'guidancekey' => 'bmsarchitecture_guidance_financialmodel'],
                ['key' => 'gaps_strategy', 'route' => 'strategy', 'labelkey' => 'bmsarchitecture_step_gapsstrategy',
                    'descriptionkey' => 'bmsarchitecture_step_gapsstrategy_desc', 'guidancekey' => 'bmsarchitecture_guidance_gapsstrategy'],
                ['key' => 'organization', 'route' => 'organization', 'labelkey' => 'bmsarchitecture_step_organization',
                    'descriptionkey' => 'bmsarchitecture_step_organization_desc', 'guidancekey' => 'bmsarchitecture_guidance_organization'],
                ['key' => 'process_architecture', 'route' => 'processes', 'labelkey' => 'bmsarchitecture_step_processarchitecture',
                    'descriptionkey' => 'bmsarchitecture_step_processarchitecture_desc', 'guidancekey' => 'bmsarchitecture_guidance_processarchitecture'],
                ['key' => 'process_design_stp', 'route' => 'processes', 'labelkey' => 'bmsarchitecture_step_processdesign',
                    'descriptionkey' => 'bmsarchitecture_step_processdesign_desc', 'guidancekey' => 'bmsarchitecture_guidance_processdesign'],
                ['key' => 'functions_positions_roles', 'route' => 'functions', 'labelkey' => 'bmsarchitecture_step_functionspositions',
                    'descriptionkey' => 'bmsarchitecture_step_functionspositions_desc', 'guidancekey' => 'bmsarchitecture_guidance_functionspositions'],
                ['key' => 'documents_metrics_improvement', 'route' => 'documents', 'labelkey' => 'bmsarchitecture_step_documents',
                    'descriptionkey' => 'bmsarchitecture_step_documents_desc', 'guidancekey' => 'bmsarchitecture_guidance_documents'],
            ],
            'groups' => [
                ['key' => 'foundation', 'labelkey' => 'bmsarchitecture_group_foundation', 'items' => [
                    ['route' => 'owner', 'stage' => 'owner', 'labelkey' => 'ownermodel_title'],
                    ['route' => 'philosophy', 'stage' => 'philosophy', 'labelkey' => 'companycontext_philosophy'],
                    ['route' => 'market', 'stage' => 'market', 'labelkey' => 'companycontext_market'],
                    ['route' => 'businessmodel', 'stage' => 'business_model', 'labelkey' => 'marketmodel_title'],
                    ['route' => 'financialmodel', 'stage' => 'financial_model', 'labelkey' => 'financialmodel_title'],
                    ['route' => 'deviations', 'stage' => 'gaps_strategy', 'labelkey' => 'dv_title', 'optional' => true],
                    ['route' => 'strategy', 'stage' => 'gaps_strategy', 'labelkey' => 'strategy_title_short'],
                    ['route' => 'adminscale', 'stage' => 'gaps_strategy', 'labelkey' => 'adminscale_title'],
                ]],
                ['key' => 'company_architecture', 'labelkey' => 'bmsarchitecture_group_company', 'items' => [
                    ['route' => 'organization', 'stage' => 'organization', 'labelkey' => 'bmsarchitecture_nav_organization'],
                    ['route' => 'positions', 'stage' => 'functions_positions_roles', 'labelkey' => 'bmsarchitecture_nav_positions'],
                    ['route' => 'roles', 'stage' => 'functions_positions_roles', 'labelkey' => 'bmsarchitecture_nav_roles'],
                ]],
                ['key' => 'operating_model', 'labelkey' => 'bmsarchitecture_group_operating', 'items' => [
                    ['route' => 'processes', 'stage' => 'process_architecture', 'labelkey' => 'company_processes'],
                    ['route' => 'functions', 'stage' => 'functions_positions_roles', 'labelkey' => 'company_functions'],
                    ['route' => 'flows', 'stage' => 'process_architecture', 'labelkey' => 'flow_title'],
                    ['route' => 'products', 'stage' => 'organization', 'labelkey' => 'productregistry'],
                    ['route' => 'metrics', 'stage' => 'organization', 'labelkey' => 'bmsworkspace_indicators'],
                    ['route' => 'informationcenter', 'stage' => 'organization', 'labelkey' => 'productinfocentertitle'],
                ]],
                ['key' => 'management_regulations', 'labelkey' => 'bmsarchitecture_group_regulations', 'items' => [
                    ['route' => 'policies', 'stage' => 'gaps_strategy', 'labelkey' => 'policy_title'],
                    ['route' => 'documents', 'stage' => 'documents_metrics_improvement', 'labelkey' => 'document_title'],
                ]],
            ],
        ];
    }

    /** Resolve the methodological flow into guided stage links and readiness. */
    private static function architecture_flow_model(string $companyuuid, array $routes, array $semantics,
            array $readiness): array {
        $steps = [];
        $number = 1;
        foreach ($semantics['flow'] as $definition) {
            $routekey = $definition['route'];
            if (!isset($routes[$routekey])) {
                continue;
            }
            $stage = $readiness[$definition['key']];
            $steps[] = [
                'number' => $number++,
                'key' => $definition['key'],
                'route' => $routekey,
                'label' => get_string($definition['labelkey'], 'local_kbacompany'),
                'description' => get_string($definition['descriptionkey'], 'local_kbacompany'),
                'guidance' => get_string($definition['guidancekey'], 'local_kbacompany'),
                'url' => self::architecture_stage_url($companyuuid, $definition['key'], $routekey),
                'target_url' => $routes[$routekey]['url'],
                'icon' => $routes[$routekey]['icon'] ?? null,
                'progress' => $stage['progress'],
                'state' => $stage['state'],
                'state_label' => $stage['state_label'],
                'variant' => $stage['variant'],
                'facts' => $stage['facts'],
            ];
        }
        return $steps;
    }

    /** Build the grouped guided Business Architecture navigator. */
    private static function architecture_tree_model(string $companyuuid, array $routes, array $semantics,
            string $selectedstage, string $selectedfocus): array {
        $nodes = [];
        $groupnumber = 1;
        foreach ($semantics['groups'] as $group) {
            $children = [];
            foreach ($group['items'] as $item) {
                $routekey = $item['route'];
                if (!isset($routes[$routekey])) {
                    continue;
                }
                $route = $routes[$routekey];
                $children[] = [
                    'label' => get_string($item['labelkey'], 'local_kbacompany'),
                    'url' => self::architecture_stage_url($companyuuid, $item['stage'], $routekey),
                    'icon' => $route['icon'] ?? null,
                    'active' => $selectedfocus === $routekey,
                    'depth' => 1,
                    'haschildren' => false,
                    'expanded' => false,
                    'togglelabel' => '',
                    'children' => [],
                ];
            }
            if (!$children) {
                continue;
            }
            $nodes[] = [
                'label' => $groupnumber . '. ' . get_string($group['labelkey'], 'local_kbacompany'),
                'url' => '',
                'icon' => null,
                'active' => false,
                'depth' => 0,
                'haschildren' => true,
                'expanded' => true,
                'togglelabel' => get_string('bmsarchitecture_group_toggle', 'local_kbacompany'),
                'children' => $children,
            ];
            $groupnumber++;
        }

        return [
            'arialabel' => get_string('bmsworkspace_tree_label', 'local_kbacompany'),
            'nodes' => $nodes,
        ];
    }

    /** Evidence-based readiness for each design stage. This is guidance, not maturity scoring. */
    private static function architecture_readiness_model(string $companyuuid, bool $canmanagement, array $strategy,
            array $companycontext, array $market, array $organization, array $processes, array $functions, array $products,
            array $positions, array $roles, array $documents): array {
        global $DB;

        $publishedprocesses = 0;
        $processtypes = [];
        foreach ($processes['processes'] as $process) {
            if (($process['status'] ?? '') === 'published') {
                $publishedprocesses++;
            }
            if (($process['status'] ?? '') !== 'archived' && !empty($process['type'])) {
                $processtypes[(string)$process['type']] = true;
            }
        }

        $publishedmodels = [];
        foreach ($DB->get_records('local_kbacompany_procmodel', ['companyuuid' => $companyuuid, 'status' => 'published']) as $model) {
            $publishedmodels[(string)$model->processuuid] = true;
        }

        $publishedfunctions = 0;
        $functionwithproduct = 0;
        $functionwithsource = 0;
        foreach ($functions['functions'] as $function) {
            if (($function['status'] ?? '') === 'published') {
                $publishedfunctions++;
            }
            if (!empty($function['product_name'])) {
                $functionwithproduct++;
            }
            if (!empty($function['source_activity_uuid'])) {
                $functionwithsource++;
            }
        }

        $effectivedocuments = 0;
        foreach ($documents as $document) {
            if (!empty($document['effective'])) {
                $effectivedocuments++;
            }
        }
        $deviations = $canmanagement ? $DB->count_records('local_kbacompany_deviation', ['companyuuid' => $companyuuid]) : 0;

        $readiness = [];
        $ownercount = $DB->count_records('local_kbacompany_ownerframe', ['companyuuid' => $companyuuid]);
        $philosophies = $companycontext['philosophies'] ?? [];
        $publishedphilosophies = count(array_filter($philosophies, static function(array $row): bool {
            return ($row['status'] ?? '') === 'published';
        }));
        $markets = $companycontext['markets'] ?? [];
        $publishedmarkets = count(array_filter($markets, static function(array $row): bool {
            return ($row['status'] ?? '') === 'published';
        }));
        $marketevidence = $DB->count_records('local_kbacompany_marketev', ['companyuuid' => $companyuuid]);
        $businessmodels = count($market['business_models'] ?? []);
        $finscenarios = $DB->count_records('local_kbacompany_finscenario', ['companyuuid' => $companyuuid]);
        $finstatements = $DB->count_records('local_kbacompany_finstmt', ['companyuuid' => $companyuuid]);
        $gaps = $DB->count_records('local_kbacompany_bmcgap', ['companyuuid' => $companyuuid]);
        $adminitems = $DB->count_records('local_kbacompany_adminitem', ['companyuuid' => $companyuuid]);

        $readiness['owner'] = self::architecture_stage_readiness(
            self::milestone_progress([$ownercount > 0]),
            [self::architecture_fact('bmsarchitecture_fact_ownerpackages', $ownercount)]
        );
        $readiness['philosophy'] = self::architecture_stage_readiness(
            self::milestone_progress([count($philosophies) > 0, $publishedphilosophies > 0]),
            [
                self::architecture_fact('bmsarchitecture_fact_philosophies', count($philosophies)),
                self::architecture_fact('bmsarchitecture_fact_publishedphilosophies', $publishedphilosophies),
            ]
        );
        $readiness['market'] = self::architecture_stage_readiness(
            self::milestone_progress([count($markets) > 0, $publishedmarkets > 0, $marketevidence > 0]),
            [
                self::architecture_fact('bmsarchitecture_fact_marketassessments', count($markets)),
                self::architecture_fact('bmsarchitecture_fact_publishedmarkets', $publishedmarkets),
                self::architecture_fact('bmsarchitecture_fact_marketevidence', $marketevidence),
            ]
        );
        $readiness['business_model'] = self::architecture_stage_readiness(
            self::milestone_progress([$businessmodels > 0]),
            [self::architecture_fact('bmsarchitecture_fact_businessmodels', $businessmodels)]
        );
        $readiness['financial_model'] = self::architecture_stage_readiness(
            self::milestone_progress([$finscenarios > 0, $finstatements > 0]),
            [
                self::architecture_fact('bmsarchitecture_fact_finscenarios', $finscenarios),
                self::architecture_fact('bmsarchitecture_fact_finstatements', $finstatements),
            ]
        );
        $readiness['gaps_strategy'] = self::architecture_stage_readiness(
            self::milestone_progress([$gaps > 0, !empty($strategy['strategies']), $adminitems > 0]),
            [
                self::architecture_fact('bmsarchitecture_fact_gaps', $gaps),
                self::architecture_fact('bmsarchitecture_fact_strategies', count($strategy['strategies'])),
                self::architecture_fact('bmsarchitecture_fact_adminitems', $adminitems),
            ]
        );
        $readiness['organization'] = self::architecture_stage_readiness(
            self::milestone_progress([
                !empty($products['products']),
                (int)$organization['summary']['org_units'] > 0,
                (int)$positions['summary']['positions'] > 0,
            ]),
            [
                self::architecture_fact('bmsarchitecture_fact_products', count($products['products'])),
                self::architecture_fact('bmsarchitecture_fact_orgunits', (int)$organization['summary']['org_units']),
                self::architecture_fact('bmsarchitecture_fact_positions', (int)$positions['summary']['positions']),
            ]
        );
        $processprogress = 0;
        if ((int)$processes['summary']['total'] > 0) {
            $typecoverage = min(1, count($processtypes) / 4);
            $publishcoverage = min(1, $publishedprocesses / max(1, (int)$processes['summary']['total']));
            $processprogress = (int)round(50 * $typecoverage + 50 * $publishcoverage);
        }
        $readiness['process_architecture'] = self::architecture_stage_readiness($processprogress, [
            self::architecture_fact('bmsarchitecture_fact_processes', (int)$processes['summary']['total']),
            self::architecture_fact('bmsarchitecture_fact_publishedprocesses', $publishedprocesses),
            self::architecture_fact('bmsarchitecture_fact_processtypes', count($processtypes)),
        ]);
        $modelprogress = 0;
        if ($publishedprocesses > 0) {
            $modelprogress = (int)round(min(1, count($publishedmodels) / $publishedprocesses) * 100);
        }
        $readiness['process_design_stp'] = self::architecture_stage_readiness($modelprogress, [
            self::architecture_fact('bmsarchitecture_fact_publishedprocesses', $publishedprocesses),
            self::architecture_fact('bmsarchitecture_fact_publishedmodels', count($publishedmodels)),
        ]);
        $functioncount = count($functions['functions']);
        $functionprogress = 0;
        if ($functioncount > 0) {
            $functionprogress = (int)round(50 + 25 * ($functionwithproduct / $functioncount) +
                25 * ($functionwithsource / $functioncount));
        }
        $positionroleprogress = self::milestone_progress([
            (int)$positions['summary']['positions'] > 0,
            !empty($roles['roles']),
        ]);
        $readiness['functions_positions_roles'] = self::architecture_stage_readiness(
            (int)round(($functionprogress + $positionroleprogress) / 2),
            [
                self::architecture_fact('bmsarchitecture_fact_functions', $functioncount),
                self::architecture_fact('bmsarchitecture_fact_functionproducts', $functionwithproduct),
                self::architecture_fact('bmsarchitecture_fact_functionsources', $functionwithsource),
                self::architecture_fact('bmsarchitecture_fact_publishedfunctions', $publishedfunctions),
                self::architecture_fact('bmsarchitecture_fact_positions', (int)$positions['summary']['positions']),
                self::architecture_fact('bmsarchitecture_fact_roles', count($roles['roles'])),
            ]
        );
        $readiness['documents_metrics_improvement'] = self::architecture_stage_readiness(
            self::milestone_progress([
                $effectivedocuments > 0,
                !empty($products['statistics']),
            ]),
            [
                self::architecture_fact('bmsarchitecture_fact_effectivedocuments', $effectivedocuments),
                self::architecture_fact('bmsarchitecture_fact_statistics', count($products['statistics'])),
                self::architecture_fact('bmsarchitecture_fact_deviations', $deviations),
            ]
        );

        return $readiness;
    }

    /** Build a single stage workspace: readiness, evidence, recommended action and module links. */
    private static function architecture_stage_workspace(array $selected, array $routes, array $semantics,
            array $readiness, array $recommended): array {
        $stage = $readiness[$selected['key']];
        $actions = [];
        $stagelabels = self::architecture_stage_route_labels($selected['key'], $semantics);
        foreach (self::architecture_stage_routes($selected['key'], $semantics) as $routekey) {
            if (!isset($routes[$routekey])) {
                continue;
            }
            $actions[] = [
                'label' => $stagelabels[$routekey] ?? $routes[$routekey]['label'],
                'url' => $routes[$routekey]['url'],
                'primary' => $routekey === $selected['focus'],
            ];
        }
        if (!$actions && isset($routes[$selected['route']])) {
            $actions[] = [
                'label' => $routes[$selected['route']]['label'],
                'url' => $routes[$selected['route']]['url'],
                'primary' => true,
            ];
        }

        return [
            'key' => $selected['key'],
            'number' => $selected['number'],
            'title' => $selected['label'],
            'description' => $selected['description'],
            'guidance' => $selected['guidance'],
            'progress' => $stage['progress'],
            'state' => $stage['state'],
            'state_label' => $stage['state_label'],
            'variant' => $stage['variant'],
            'facts' => $stage['facts'],
            'actions' => $actions,
            'recommended' => $recommended,
            'is_recommended' => $recommended && $recommended['key'] === $selected['key'],
        ];
    }

    /** First incomplete stage is the next recommended design step. */
    private static function architecture_recommended_stage(array $flow): ?array {
        foreach ($flow as $stage) {
            if ((int)$stage['progress'] < 100) {
                return [
                    'key' => $stage['key'],
                    'number' => $stage['number'],
                    'label' => $stage['label'],
                    'url' => $stage['url'],
                    'progress' => $stage['progress'],
                ];
            }
        }
        return null;
    }

    /** Resolve selected stage and optional module focus. */
    private static function architecture_selected_stage(string $selectedstage, string $selectedfocus, array $flow,
            array $semantics, array $routes, ?array $recommended): array {
        $bykey = [];
        foreach ($flow as $stage) {
            $bykey[$stage['key']] = $stage;
        }
        if (!isset($bykey[$selectedstage])) {
            $selectedstage = $recommended && isset($bykey[$recommended['key']]) ? $recommended['key'] : key($bykey);
        }
        $selected = $bykey[$selectedstage];
        $allowed = self::architecture_stage_routes($selectedstage, $semantics);
        if (!in_array($selectedfocus, $allowed, true) || !isset($routes[$selectedfocus])) {
            $selectedfocus = $selected['route'];
        }
        $selected['focus'] = $selectedfocus;
        return $selected;
    }

    /** Return all route keys represented inside one guided stage. */
    private static function architecture_stage_routes(string $stagekey, array $semantics): array {
        $routes = [];
        foreach ($semantics['flow'] as $flow) {
            if ($flow['key'] === $stagekey) {
                $routes[] = $flow['route'];
            }
        }
        foreach ($semantics['groups'] as $group) {
            foreach ($group['items'] as $item) {
                if (($item['stage'] ?? '') === $stagekey) {
                    $routes[] = $item['route'];
                }
            }
        }
        return array_values(array_unique($routes));
    }

    /** User-facing labels for module actions inside one guided stage. */
    private static function architecture_stage_route_labels(string $stagekey, array $semantics): array {
        $labels = [];
        foreach ($semantics['groups'] as $group) {
            foreach ($group['items'] as $item) {
                if (($item['stage'] ?? '') !== $stagekey) {
                    continue;
                }
                $labels[$item['route']] = get_string($item['labelkey'], 'local_kbacompany');
            }
        }
        return $labels;
    }

    /** Architecture center URL for one stage/module focus. */
    private static function architecture_stage_url(string $companyuuid, string $stage, string $focus = ''): string {
        $params = ['uuid' => $companyuuid, 'stage' => $stage];
        if ($focus !== '') {
            $params['focus'] = $focus;
        }
        return (new \moodle_url('/local/kbacompany/bms_architecture.php', $params))->out(false);
    }

    /** Convert milestone booleans into simple guidance progress. */
    private static function milestone_progress(array $milestones): int {
        if (!$milestones) {
            return 0;
        }
        $done = 0;
        foreach ($milestones as $milestone) {
            if ($milestone) {
                $done++;
            }
        }
        return (int)round(($done / count($milestones)) * 100);
    }

    /** Normalised stage status for presentation. */
    private static function architecture_stage_readiness(int $progress, array $facts): array {
        $progress = max(0, min(100, $progress));
        if ($progress <= 0) {
            $state = 'not_started';
            $label = get_string('bmsarchitecture_state_notstarted', 'local_kbacompany');
            $variant = 'neutral';
        } else if ($progress >= 100) {
            $state = 'ready';
            $label = get_string('bmsarchitecture_state_ready', 'local_kbacompany');
            $variant = 'success';
        } else {
            $state = 'in_progress';
            $label = get_string('bmsarchitecture_state_inprogress', 'local_kbacompany');
            $variant = 'warning';
        }
        return [
            'progress' => $progress,
            'state' => $state,
            'state_label' => $label,
            'variant' => $variant,
            'facts' => $facts,
        ];
    }

    /** One evidence fact displayed in the selected architecture stage. */
    private static function architecture_fact(string $labelkey, int $value): array {
        return [
            'label' => get_string($labelkey, 'local_kbacompany'),
            'value' => $value,
        ];
    }

    /** Personal workspace counters used by the dashboard and sidebar badges. */
    private static function workspace_counters(array $work): array {
        $counts = $work['counts'];
        $review = 0;
        foreach ($work['task_center']['tasks'] as $task) {
            if (($task['status'] ?? '') === 'review') {
                $review++;
            }
        }
        $incoming = (int)($counts['unread_threads'] ?? 0) + (int)($counts['unread_notifications'] ?? 0);
        return [
            'incoming' => $incoming,
            'tasks' => (int)($counts['tasks'] ?? 0),
            'overdue' => (int)($counts['overdue'] ?? 0),
            'control' => $review,
            'learning' => (int)($counts['develop'] ?? 0),
            'unread_threads' => (int)($counts['unread_threads'] ?? 0),
            'unread_notifications' => (int)($counts['unread_notifications'] ?? 0),
        ];
    }

    /** Business-owned route catalogue used by sidebar and architecture tree. */
    /** Render a compact trusted SVG icon for BMS navigation. */
    public static function navigation_icon_html(string $name): string {
        $paths = [
            'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M9 20v-6h6v6"/>',
            'message' => '<path d="M4 5.5h16v11H8l-4 3v-14z"/>',
            'tasks' => '<path d="M5 4h14v16H5z"/><path d="m8 9 2 2 5-5"/><path d="M8 15h8"/>',
            'flag' => '<path d="M6 21V4"/><path d="M6 5h11l-2 4 2 4H6"/>',
            'chart' => '<path d="M4 20V10"/><path d="M10 20V4"/><path d="M16 20v-7"/><path d="M22 20H2"/>',
            'clipboard-check' => '<rect x="5" y="4" width="14" height="17" rx="2"/><rect x="9" y="2" width="6" height="4" rx="1"/><path d="m8 14 2.5 2.5 5-5"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c.7-4.2 3.1-6.5 7.5-6.5s6.8 2.3 7.5 6.5"/>',
            'book' => '<path d="M3 5.5c3.5-1.2 6.5-.7 9 1.5v13c-2.5-2.2-5.5-2.7-9-1.5v-13z"/><path d="M21 5.5c-3.5-1.2-6.5-.7-9 1.5v13c2.5-2.2 5.5-2.7 9-1.5v-13z"/>',
            'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c3 3.2 4.2 6.2 4.2 9S15 17.8 12 21"/><path d="M12 3c-3 3.2-4.2 6.2-4.2 9S9 17.8 12 21"/>',
            'layout' => '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 9h18"/><path d="M9 9v11"/>',
            'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>',
            'finance' => '<path d="M12 2v20"/><path d="M17 6.5c-1.1-1.4-2.8-2-5-2-2.8 0-5 1.4-5 3.5s2.1 3 5 3.5c2.9.5 5 1.4 5 3.7S14.8 19 12 19c-2.4 0-4.2-.8-5.4-2.3"/>',
            'users' => '<circle cx="9" cy="8" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M3.5 19c.5-3.3 2.3-5 5.5-5s5 1.7 5.5 5"/><path d="M14.5 15c3.2-.2 5.2 1.2 6 4"/>',
            'employee' => '<circle cx="10" cy="8" r="3"/><path d="M4 19c.6-3.4 2.6-5 6-5 2 0 3.6.6 4.7 1.8"/><path d="m16 17 2 2 4-5"/>',
            'briefcase' => '<rect x="3" y="7" width="18" height="12" rx="2"/><path d="M9 7V4h6v3"/><path d="M3 12h18"/>',
            'award' => '<circle cx="12" cy="8" r="5"/><path d="m8.5 12-2 9 5.5-3 5.5 3-2-9"/>',
            'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
            'learning' => '<path d="m2 8 10-5 10 5-10 5L2 8z"/><path d="M6 10.5V16c3.8 2.3 8.2 2.3 12 0v-5.5"/>',
            'list' => '<path d="M8 6h13"/><path d="M8 12h13"/><path d="M8 18h13"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
            'cycle' => '<path d="M20 8a8 8 0 0 0-13.7-2L4 8"/><path d="M4 4v4h4"/><path d="M4 16a8 8 0 0 0 13.7 2L20 16"/><path d="M20 20v-4h-4"/>',
            'document' => '<path d="M6 2h8l4 4v16H6z"/><path d="M14 2v5h5"/><path d="M9 12h6"/><path d="M9 16h6"/>',
            'shield' => '<path d="M12 2 20 5v6c0 5-3 8.5-8 11-5-2.5-8-6-8-11V5l8-3z"/><path d="m8.5 12 2.2 2.2 4.8-5"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/>',
            'alert' => '<path d="M12 3 2.5 20h19L12 3z"/><path d="M12 9v5"/><path d="M12 17.5h.01"/>',
        ];
        if (!isset($paths[$name])) {
            $name = 'grid';
        }
        return '<span class="bms-nav-svg" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" role="img" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $paths[$name] . '</svg></span>';
    }

    private static function routes(string $companyuuid, array $counts, bool $canmanagement, bool $canowner, string $active, bool $canreport = false, bool $cancycles = false): array {
        $url = static function(string $path, array $params = []) use ($companyuuid): string {
            return (new \moodle_url('/local/kbacompany/' . $path,
                array_merge(['uuid' => $companyuuid], $params)))->out(false);
        };
        $defaulticon = ['key' => 'i/navigationitem', 'component' => 'moodle'];
        $homeicon = ['key' => 'i/home', 'component' => 'moodle'];
        $navicons = [
            'workspace' => 'home', 'calendar' => 'calendar', 'communications' => 'message', 'tasks' => 'tasks', 'recurring' => 'cycle', 'battleplan' => 'flag', 'products' => 'chart',
            'architecturecenter' => 'grid', 'owner' => 'user', 'philosophy' => 'book', 'market' => 'globe', 'businessmodel' => 'layout',
            'strategy' => 'target', 'financialmodel' => 'finance', 'people' => 'users', 'employees' => 'employee', 'people_roles' => 'briefcase',
            'coverage' => 'briefcase', 'people_competencies' => 'award', 'culture' => 'heart', 'learning' => 'learning', 'management' => 'cycle', 'personalcycle' => 'clipboard-check', 'managementcycles' => 'cycle',
            'informationcenter' => 'chart', 'adminscale' => 'list', 'admincycle' => 'cycle', 'documents' => 'document',
            'policies' => 'shield', 'deviations' => 'alert',
        ];

        $taskbadge = null;
        if ($counts['tasks'] > 0) {
            $taskbadge = [
                'value' => (string)$counts['tasks'],
                'variant' => $counts['overdue'] > 0 ? 'warning' : 'brand',
                'title' => get_string('bmsworkspace_mytasks', 'local_kbacompany'),
            ];
        }
        $communicationbadge = null;
        if ($counts['incoming'] > 0) {
            $communicationbadge = [
                'value' => (string)$counts['incoming'],
                'variant' => 'brand',
                'title' => get_string('communication_unread', 'local_kbacompany'),
            ];
        }

        $routes = [
            'workspace' => [
                'group' => 'workspace', 'sidebar' => true, 'tree' => false,
                'label' => get_string('bmsworkspace_nav', 'local_kbacompany'),
                'url' => $url('bms_workspace.php'), 'icon' => $homeicon,
            ],
            'calendar' => [
                'group' => 'workspace', 'sidebar' => true, 'tree' => false,
                'label' => calendar_workspace_ui_service::text('bmscalendar_title'),
                'url' => $url('calendar.php'), 'icon' => $defaulticon,
            ],
            'communications' => [
                'group' => 'workspace', 'sidebar' => true, 'tree' => false,
                'label' => get_string('communicationcenter_title', 'local_kbacompany'),
                'url' => $url('communication_center.php'), 'icon' => $defaulticon, 'badge' => $communicationbadge,
            ],
            'tasks' => [
                'group' => 'workspace', 'sidebar' => true, 'tree' => false,
                'label' => get_string('taskcenter_title', 'local_kbacompany'),
                'url' => $url('tasks.php', ['scope' => 'my']),
                'icon' => $defaulticon,
            ],
            // A recurring definition is an ongoing task workflow, not a new Task Center provider.
            // BMS owns the route; the KBA theme only renders this semantic item.
            'recurring' => [
                'group' => 'workspace', 'sidebar' => true, 'tree' => false,
                'label' => get_string('recurringtask_title', 'local_kbacompany'),
                'url' => $url('recurring_tasks.php', ['view' => 'list']),
                'icon' => $defaulticon,
            ],
            'battleplan' => [
                'group' => 'workspace', 'sidebar' => true, 'tree' => false,
                'label' => get_string('battleplan_title', 'local_kbacompany'),
                'url' => $url('battle_plan.php'), 'icon' => $defaulticon,
            ],
            'products' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => true,
                'label' => get_string('productregistry', 'local_kbacompany'),
                'tree_label' => get_string('productregistry', 'local_kbacompany'),
                'url' => $url('products.php'), 'icon' => $defaulticon,
            ],
            'metrics' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => true,
                'label' => get_string('bmsworkspace_indicators', 'local_kbacompany'),
                'url' => $url('indicators.php'), 'icon' => $defaulticon,
            ],
            'informationcenter' => [
                // Keep the Architecture Center tree entry, while exposing the same
                // read-only information workspace in the Management sidebar.
                // The route, page ACL and company context remain unchanged.
                'group' => 'management', 'sidebar' => true, 'tree' => true,
                'label' => get_string('productinfocentertitle', 'local_kbacompany'),
                'url' => $url('information_center.php'), 'icon' => $defaulticon,
            ],
            'architecturecenter' => [
                'group' => 'architecture', 'sidebar' => true, 'tree' => false,
                'label' => get_string('bmsarchitecture_title', 'local_kbacompany'),
                'url' => $url('bms_architecture.php'), 'icon' => $defaulticon,
            ],
            'owner' => [
                'group' => 'architecture', 'sidebar' => $canowner, 'tree' => false,
                'label' => get_string('ownermodel_title', 'local_kbacompany'),
                'url' => $url('owner.php'), 'icon' => $defaulticon,
            ],
            'philosophy' => [
                'group' => 'architecture', 'sidebar' => $canmanagement, 'tree' => false,
                'label' => get_string('companycontext_philosophy', 'local_kbacompany'),
                'url' => $url('philosophy.php'), 'icon' => $defaulticon,
            ],
            'market' => [
                'group' => 'architecture', 'sidebar' => $canmanagement, 'tree' => false,
                'label' => get_string('companycontext_market', 'local_kbacompany'),
                'url' => $url('market_assessment.php'), 'icon' => $defaulticon,
            ],
            'strategy' => [
                'group' => 'architecture', 'sidebar' => true, 'tree' => true,
                'label' => get_string('strategy_title_short', 'local_kbacompany'),
                'url' => $url('strategy.php'), 'icon' => $defaulticon,
            ],
            'financialmodel' => [
                'group' => 'architecture', 'sidebar' => $canmanagement, 'tree' => true,
                'label' => get_string('financialmodel_title', 'local_kbacompany'),
                'url' => $url('financial_model.php'), 'icon' => $defaulticon,
            ],
            'people' => [
                'group' => 'people', 'sidebar' => true, 'tree' => false,
                'label' => get_string('people_title', 'local_kbacompany'),
                'url' => $url('people.php'), 'icon' => $defaulticon,
            ],
            'employees' => [
                'group' => 'people', 'sidebar' => true, 'tree' => false,
                'label' => get_string('bms_employees_title', 'local_kbacompany'),
                'url' => $url('employees.php'), 'icon' => $defaulticon,
            ],
            'learning' => [
                'group' => 'people', 'sidebar' => true, 'tree' => false,
                'label' => get_string('bms_learning_title', 'local_kbacompany'),
                'url' => $url('learning.php'), 'icon' => $defaulticon,
            ],
            'people_roles' => [
                'group' => 'people', 'sidebar' => true, 'tree' => false,
                'label' => get_string('people_sidebar_roles', 'local_kbacompany'),
                'url' => $url('people.php') . '#roles', 'icon' => $defaulticon,
            ],
            'coverage' => [
                'group' => 'people', 'sidebar' => $canmanagement, 'tree' => false,
                'label' => get_string('coverage_title', 'local_kbacompany'),
                'url' => $url('position_coverage.php'), 'icon' => $defaulticon,
            ],
            'people_competencies' => [
                'group' => 'people', 'sidebar' => true, 'tree' => false,
                'label' => get_string('people_sidebar_competencies', 'local_kbacompany'),
                'url' => $url('people.php') . '#competencies', 'icon' => $defaulticon,
            ],
            'culture' => [
                'group' => 'people', 'sidebar' => true, 'tree' => false,
                'label' => get_string('culture_title_short', 'local_kbacompany'),
                'url' => $url('culture.php'), 'icon' => $defaulticon,
            ],
            'settings' => [
                'group' => 'utility', 'sidebar' => false, 'tree' => false,
                'label' => get_string('bms_settings_title', 'local_kbacompany'),
                'url' => $url('bms_settings.php'), 'icon' => ['key' => 'i/settings', 'component' => 'moodle'],
            ],
            'management' => [
                'group' => 'management', 'sidebar' => $canmanagement, 'tree' => false,
                'label' => get_string('management_workspace_title', 'local_kbacompany'),
                'sidebar_label' => get_string('bmsnav_management_overview', 'local_kbacompany'),
                'url' => $url('management.php'), 'icon' => $defaulticon,
            ],
            'personalcycle' => [
                'group' => 'management', 'sidebar' => true, 'tree' => false,
                'label' => get_string('pmc_title', 'local_kbacompany'),
                'url' => $url('my_management_cycle.php'), 'icon' => $defaulticon,
            ],
            'managementcycles' => [
                'group' => 'management', 'sidebar' => $cancycles, 'tree' => false,
                'label' => get_string('mcycle_title', 'local_kbacompany'),
                'url' => $url('management_cycles.php'), 'icon' => $defaulticon,
            ],
            'qualitycenter' => [
                'group' => 'management', 'sidebar' => true, 'tree' => false,
                'label' => get_string('qc20_title', 'local_kbacompany'),
                'url' => $url('quality_center.php'), 'icon' => $defaulticon,
            ],
            'documents' => [
                'group' => 'management', 'sidebar' => true, 'tree' => true,
                'label' => get_string('document_title', 'local_kbacompany'),
                'sidebar_label' => get_string('bmsnav_documents', 'local_kbacompany'),
                'url' => $url('documents.php'), 'icon' => $defaulticon,
            ],
            'execution' => [
                // Legacy compatibility endpoint. Hidden from customer-facing BMS navigation.
                'group' => 'legacy', 'sidebar' => false, 'tree' => false,
                'label' => get_string('wf_title', 'local_kbacompany'),
                'url' => $url('execution.php'), 'icon' => $defaulticon,
            ],
            'admincycle' => [
                // URL compatibility only; project execution belongs to the canonical project card.
                'group' => 'legacy', 'sidebar' => false, 'tree' => false,
                'label' => get_string('admincycle_title', 'local_kbacompany'),
                'sidebar_label' => get_string('bmsnav_execution', 'local_kbacompany'),
                'url' => $url('administrative_cycle.php'), 'icon' => $defaulticon,
            ],
            'adminscale' => [
                'group' => 'architecture', 'sidebar' => true, 'tree' => true,
                'label' => get_string('adminscale_title', 'local_kbacompany'),
                'url' => $url('administrative_scale.php'), 'icon' => $defaulticon,
            ],
            'policies' => [
                'group' => 'management', 'sidebar' => true, 'tree' => true,
                'label' => get_string('policy_title', 'local_kbacompany'),
                'sidebar_label' => get_string('bmsnav_policies', 'local_kbacompany'),
                'url' => $url('policies.php'), 'icon' => $defaulticon,
            ],

            // Architecture-tree destinations. They are intentionally not repeated in the global sidebar.
            'businessmodel' => [
                'group' => 'architecture', 'sidebar' => true, 'tree' => true,
                'label' => get_string('marketmodel_title', 'local_kbacompany'),
                'url' => $url('business_model.php'), 'icon' => $defaulticon,
            ],
            'organization' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => true,
                'label' => get_string('ofsdesigner_title', 'local_kbacompany'),
                'url' => $url('ofs_designer.php'), 'icon' => $defaulticon,
            ],
            'organizationhub' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => false,
                'label' => get_string('orghub_title', 'local_kbacompany'),
                'url' => $url('organization_hub.php'), 'icon' => $defaulticon,
            ],
            'positions' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => false,
                'label' => get_string('positiondirectory', 'local_kbacompany'),
                'url' => $url('positions.php'), 'icon' => $defaulticon,
            ],
            'processes' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => true,
                'label' => get_string('company_processes', 'local_kbacompany'),
                'url' => $url('processes.php'), 'icon' => $defaulticon,
            ],
            'functions' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => true,
                'label' => get_string('company_functions', 'local_kbacompany'),
                'url' => $url('functions.php'), 'icon' => $defaulticon,
            ],
            'roles' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => true,
                'label' => get_string('openbusinessroles', 'local_kbacompany'),
                'url' => $url('business_roles.php'), 'icon' => $defaulticon,
            ],
            'cascade' => [
                // Legacy compatibility endpoint. Hidden from customer-facing BMS navigation.
                'group' => 'legacy', 'sidebar' => false, 'tree' => false,
                'label' => get_string('ac_title', 'local_kbacompany'),
                'url' => $url('cascade.php'), 'icon' => $defaulticon,
            ],
            'flows' => [
                'group' => 'architecture', 'sidebar' => false, 'tree' => true,
                'label' => get_string('flow_title', 'local_kbacompany'),
                'url' => $url('flows.php'), 'icon' => $defaulticon,
            ],
        ];

        if ($canreport || $canmanagement) {
            $routes['deviations'] = [
                'group' => 'management', 'sidebar' => true, 'tree' => true,
                'label' => get_string('dv_title', 'local_kbacompany'),
                'sidebar_label' => get_string('bmsnav_deviations', 'local_kbacompany'),
                'url' => $url('deviations.php'), 'icon' => $defaulticon,
            ];
        }

        // A detail page can belong to a tree-only route. Keep its real route
        // active for the architecture tree, and highlight the visible parent in
        // the sidebar instead of collapsing every group on that page.
        $sidebarparents = [
            'organization' => 'architecturecenter', 'organizationhub' => 'architecturecenter',
            'processes' => 'architecturecenter', 'functions' => 'architecturecenter',
            'flows' => 'architecturecenter', 'roles' => 'people_roles', 'positions' => 'people_roles',
            'execution' => 'adminscale', 'admincycle' => 'adminscale', 'cascade' => 'adminscale',
        ];
        $sidebaractive = $sidebarparents[$active] ?? $active;
        foreach ($routes as $key => &$route) {
            $route['sidebar_active'] = ($key === $sidebaractive);
            if (isset($navicons[$key])) {
                $route['iconhtml'] = self::navigation_icon_html($navicons[$key]);
            }
            $route['active'] = ($key === $active);
        }
        unset($route);

        return $routes;
    }

    /** Group route catalogue for the BMS24 App Shell sidebar. */
    private static function navigation_groups(array $routes): array {
        // Global navigation is intentionally contour-oriented: the sidebar exposes
        // the user's major BMS workspaces, while fine-grained domain entities stay
        // inside their owning workspace. This prevents the App Shell from becoming
        // a database-table catalogue as BMS grows.
        $definitions = [
            'workspace' => [
                'label' => get_string('bmsworkspace_group_workspace', 'local_kbacompany'),
                'order' => ['workspace', 'calendar', 'tasks', 'recurring', 'battleplan', 'communications'],
            ],
            'architecture' => [
                'label' => get_string('bmsworkspace_group_architecture_short', 'local_kbacompany'),
                'order' => ['architecturecenter', 'owner', 'philosophy', 'market', 'businessmodel', 'financialmodel', 'strategy', 'adminscale'],
            ],
            'people' => [
                'label' => get_string('bmsworkspace_group_people_short', 'local_kbacompany'),
                'order' => ['people', 'employees', 'people_roles', 'coverage', 'people_competencies', 'culture', 'learning'],
            ],
            'management' => [
                'label' => get_string('bmsworkspace_group_management', 'local_kbacompany'),
                'order' => ['personalcycle', 'management', 'informationcenter', 'managementcycles', 'products', 'documents', 'policies', 'deviations'],
            ],
        ];
        $groups = [];
        foreach ($definitions as $key => $definition) {
            $items = [];
            $hasactive = false;
            foreach ($definition['order'] as $routekey) {
                if (!isset($routes[$routekey]) || empty($routes[$routekey]['sidebar'])) {
                    continue;
                }
                $route = $routes[$routekey];
                $active = !empty($route['sidebar_active'] ?? $route['active']);
                $hasactive = $hasactive || $active;
                $items[] = [
                    'key' => $routekey,
                    'label' => $route['sidebar_label'] ?? $route['label'],
                    'url' => $route['url'],
                    'icon' => $route['icon'] ?? null,
                    'iconhtml' => $route['iconhtml'] ?? '',
                    'active' => $active,
                    'disabled' => false,
                    'badge' => $route['badge'] ?? null,
                ];
            }
            if ($items) {
                $groups[] = [
                    'key' => $key,
                    'label' => $definition['label'],
                    'items' => $items,
                    'active' => $hasactive,
                    // The active contour opens by default. KBA Theme may persist a
                    // user's manual accordion state in the browser, but BMS remains
                    // the source of the semantic active state.
                    'expanded' => $hasactive,
                    'collapsed' => !$hasactive,
                    'togglelabel' => get_string('bmsworkspace_group_toggle', 'local_kbacompany', $definition['label']),
                ];
            }
        }
        return $groups;
    }

    /** Build the tree shown only inside Business Architecture Center. */
    private static function tree_model(array $routes): array {
        $nodes = [];
        foreach ($routes as $route) {
            if (empty($route['tree'])) {
                continue;
            }
            $nodes[] = [
                'label' => $route['tree_label'] ?? $route['label'],
                'url' => $route['url'],
                'icon' => $route['icon'] ?? null,
                'active' => !empty($route['active']),
                'depth' => 0,
                'haschildren' => false,
                'expanded' => false,
                'togglelabel' => '',
                'children' => [],
            ];
        }
        return [
            'arialabel' => get_string('bmsworkspace_tree_label', 'local_kbacompany'),
            'nodes' => $nodes,
        ];
    }

    /** Minimal route map for page-level actions. */
    private static function route_map(array $routes): array {
        $map = [];
        foreach ($routes as $key => $route) {
            $map[$key] = [
                'label' => $route['label'],
                'url' => $route['url'],
            ];
        }
        return $map;
    }

    /** User-facing company role labels. */
    private static function role_labels(array $roles): array {
        $labels = [
            'owner' => get_string('roleowner', 'local_kbacompany'),
            'employee' => get_string('roleemployee', 'local_kbacompany'),
            'manager' => get_string('rolemanager', 'local_kbacompany'),
            'hr' => get_string('rolehr', 'local_kbacompany'),
            'department_manager' => get_string('roledepartmentmanager', 'local_kbacompany'),
        ];
        return array_values(array_map(static function(string $role) use ($labels): string {
            return $labels[$role] ?? $role;
        }, $roles));
    }
}
