<?php
namespace local_kbacompany\external;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\external\compat_external_api as external_api;
use local_kbacompany\external\compat_external_function_parameters as external_function_parameters;
use local_kbacompany\external\compat_external_value as external_value;
use local_kbacompany\local\company_service;
use local_kbacompany\local\organization_service;
use local_kbacompany\local\business_role_service;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\position_service;
use local_kbacompany\local\staffing_service;
use local_kbacompany\local\policy_service;
use local_kbacompany\local\semantic_service;
use local_kbacompany\local\ofs_service;
use local_kbacompany\local\process_service;
use local_kbacompany\local\process_model_service;
use local_kbacompany\local\stp_service;
use local_kbacompany\local\function_service;
use local_kbacompany\local\document_service;
use local_kbacompany\local\position_operating_service;
use local_kbacompany\local\market_model_service;
use local_kbacompany\local\strategy_service;
use local_kbacompany\local\administrative_scale_service;
use local_kbacompany\local\task_service;
use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\communication_service;
use local_kbacompany\local\task_provider_service;
use local_kbacompany\local\communication_center_service;
use local_kbacompany\local\company_context_service;
use local_kbacompany\local\platform_boundary_service;

// Resolve external API classes without requiring the namespaced variant on older Moodle installations.
if (class_exists('\\core_external\\external_api')) {
    foreach (['external_api', 'external_function_parameters', 'external_value'] as $classname) {
        class_alias('\\core_external\\' . $classname, __NAMESPACE__ . '\\compat_' . $classname);
    }
} else {
    global $CFG;
    require_once($CFG->libdir . '/externallib.php');
    foreach (['external_api', 'external_function_parameters', 'external_value'] as $classname) {
        class_alias('\\' . $classname, __NAMESPACE__ . '\\compat_' . $classname);
    }
}

/** Read-only Company Core foundation API. */
class company_api extends external_api {
    public static function contract_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function contract(): string {
        self::authorise();
        return self::encode(company_service::contract());
    }

    public static function contract_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON Company Core contract');
    }

    public static function my_companies_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function my_companies(): string {
        global $USER;
        self::authorise();
        return self::encode([
            'success' => true,
            'companies' => company_service::companies_for_user((int)$USER->id),
        ]);
    }

    public static function my_companies_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised company list');
    }

    public static function company_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'uuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
        ]);
    }

    public static function company_context(string $uuid): string {
        global $USER;
        $params = self::validate_parameters(self::company_context_parameters(), compact('uuid'));
        self::authorise();
        return self::encode([
            'success' => true,
            'company' => company_context_service::context($params['uuid'], (int)$USER->id),
        ]);
    }

    public static function company_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised company context');
    }

    public static function organization_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
        ]);
    }

    public static function organization_context(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::organization_context_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode([
            'success' => true,
            'organization' => organization_service::organization_context(
                $params['companyuuid'], (int)$USER->id),
        ]);
    }

    public static function organization_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised Organization Core context');
    }

    public static function business_roles_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
        ]);
    }

    public static function business_roles_context(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::business_roles_context_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode([
            'success' => true,
            'business_roles' => business_role_service::business_roles_context(
                $params['companyuuid'], (int)$USER->id),
        ]);
    }

    public static function business_roles_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised Business Role context');
    }

    public static function organizing_board_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'branchuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional branch UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function organizing_board_context(string $companyuuid, string $branchuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::organizing_board_context_parameters(),
            compact('companyuuid', 'branchuuid'));
        self::authorise();
        return self::encode([
            'success' => true,
            'organizing_board' => organizing_board_service::board_context(
                $params['companyuuid'], (int)$USER->id, $params['branchuuid']),
        ]);
    }

    public static function organizing_board_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised organizing-board context');
    }


    public static function strategy_context_parameters(): external_function_parameters {
        return new external_function_parameters(['companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID')]);
    }

    public static function strategy_context(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::strategy_context_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'ownership_strategy_bsc' => strategy_service::context(
            $params['companyuuid'], (int)$USER->id)]);
    }

    public static function strategy_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON Ownership, Strategy and BSC context');
    }

    public static function administrative_scale_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
        ]);
    }

    public static function administrative_scale_context(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::administrative_scale_context_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'administrative_scale' => administrative_scale_service::context(
            $params['companyuuid'], (int)$USER->id)]);
    }

    public static function administrative_scale_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON versioned Administrative Scale context');
    }

    public static function task_center_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'scope' => new external_value(PARAM_ALPHA, 'Task Center scope', VALUE_DEFAULT, 'my'),
        ]);
    }

    public static function task_center_context(string $companyuuid, string $scope = 'my'): string {
        global $USER;
        $params = self::validate_parameters(self::task_center_context_parameters(), compact('companyuuid', 'scope'));
        self::authorise();
        return self::encode(['success' => true, 'task_center' => task_service::context(
            $params['companyuuid'], (int)$USER->id, '', $params['scope'])]);
    }

    public static function task_center_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON canonical BMS Task Center context');
    }

    public static function communication_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'threaduuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional selected Communication Thread UUID', VALUE_DEFAULT, ''),
            'scope' => new external_value(PARAM_ALPHA, 'Communication inbox scope', VALUE_DEFAULT, 'inbox'),
        ]);
    }

    public static function communication_context(string $companyuuid, string $threaduuid = '', string $scope = 'inbox'): string {
        global $USER;
        $params = self::validate_parameters(self::communication_context_parameters(),
            compact('companyuuid', 'threaduuid', 'scope'));
        self::authorise();
        return self::encode(['success' => true, 'communication_core' => communication_service::context(
            $params['companyuuid'], (int)$USER->id, $params['threaduuid'], $params['scope'])]);
    }

    public static function communication_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON participant-scoped BMS Communication Core context');
    }

    public static function unified_task_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'scope' => new external_value(PARAM_ALPHA, 'Unified task scope', VALUE_DEFAULT, 'my'),
            'provider' => new external_value(PARAM_ALPHANUMEXT, 'Provider key or all', VALUE_DEFAULT, 'all'),
        ]);
    }

    public static function unified_task_context(string $companyuuid, string $scope = 'my', string $provider = 'all'): string {
        global $USER;
        $params = self::validate_parameters(self::unified_task_context_parameters(), compact('companyuuid', 'scope', 'provider'));
        self::authorise();
        return self::encode(['success' => true, 'unified_tasks' => task_provider_service::context(
            $params['companyuuid'], (int)$USER->id, $params['scope'], $params['provider'], 0)]);
    }

    public static function unified_task_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON unified task provider projection');
    }

    public static function communication_center_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'tab' => new external_value(PARAM_ALPHA, 'Communication Center tab', VALUE_DEFAULT, 'inbox'),
            'taskscope' => new external_value(PARAM_ALPHA, 'Task scope', VALUE_DEFAULT, 'my'),
            'provider' => new external_value(PARAM_ALPHANUMEXT, 'Task provider key or all', VALUE_DEFAULT, 'all'),
        ]);
    }

    public static function communication_center_context(string $companyuuid, string $tab = 'inbox',
            string $taskscope = 'my', string $provider = 'all'): string {
        global $USER;
        $params = self::validate_parameters(self::communication_center_context_parameters(),
            compact('companyuuid', 'tab', 'taskscope', 'provider'));
        self::authorise();
        return self::encode(['success' => true, 'communication_center' => communication_center_service::context(
            $params['companyuuid'], (int)$USER->id, $params['tab'], $params['taskscope'], $params['provider'])]);
    }

    public static function communication_center_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON unified BMS Communication Center context');
    }

    public static function battle_plan_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'planuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional Battle Plan UUID', VALUE_DEFAULT, ''),
            'targetuser' => new external_value(PARAM_INT, 'Target employee user id; 0 means current user', VALUE_DEFAULT, 0),
            'week' => new external_value(PARAM_RAW_TRIMMED, 'Any date within the requested week', VALUE_DEFAULT, ''),
            'positionuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional current position UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function battle_plan_context(string $companyuuid, string $planuuid = '', int $targetuser = 0,
            string $week = '', string $positionuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::battle_plan_context_parameters(),
            compact('companyuuid', 'planuuid', 'targetuser', 'week', 'positionuuid'));
        self::authorise();
        return self::encode(['success' => true, 'battle_plan' => battle_plan_service::context(
            $params['companyuuid'], (int)$USER->id, $params['planuuid'], (int)$params['targetuser'],
            $params['week'], $params['positionuuid'])]);
    }

    public static function battle_plan_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON weekly BMS Battle Plan context');
    }

    public static function market_model_context_parameters(): external_function_parameters { return new external_function_parameters(['companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID')]); }
    public static function market_model_context(string $companyuuid): string { global $USER; $p=self::validate_parameters(self::market_model_context_parameters(),compact('companyuuid')); self::authorise(); return self::encode(['success'=>true,'market_business_model'=>market_model_service::context($p['companyuuid'],(int)$USER->id)]); }
    public static function market_model_context_returns(): external_value { return new external_value(PARAM_RAW,'UTF-8 JSON Market and Business Model context'); }

    public static function position_operating_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'positionuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional position UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function position_operating_context(string $companyuuid, string $positionuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::position_operating_context_parameters(), compact('companyuuid', 'positionuuid'));
        self::authorise();
        return self::encode(['success' => true, 'position_operating_system' => position_operating_service::context(
            $params['companyuuid'], (int)$USER->id, $params['positionuuid'])]);
    }

    public static function position_operating_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON generated Position Operating System context');
    }

    public static function positions_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'orgnodeuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional organizing-board node UUID',
                VALUE_DEFAULT, ''),
        ]);
    }

    public static function positions_context(string $companyuuid, string $orgnodeuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::positions_context_parameters(),
            compact('companyuuid', 'orgnodeuuid'));
        self::authorise();
        return self::encode([
            'success' => true,
            'positions_staffing' => position_service::positions_context(
                $params['companyuuid'], (int)$USER->id, $params['orgnodeuuid']),
        ]);
    }

    public static function positions_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised positions and staffing context');
    }

    public static function staffing_plan_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'planuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional staffing-plan UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function staffing_plan_context(string $companyuuid, string $planuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::staffing_plan_context_parameters(), compact('companyuuid', 'planuuid'));
        self::authorise();
        return self::encode(['success' => true, 'staffing_plan' => staffing_service::plan_context(
            $params['companyuuid'], (int)$USER->id, $params['planuuid'])]);
    }

    public static function staffing_plan_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised staffing-plan context');
    }

    public static function staffing_placement_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable Company Core UUID'),
            'planuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional approved staffing-plan UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function staffing_placement_context(string $companyuuid, string $planuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::staffing_placement_context_parameters(), compact('companyuuid', 'planuuid'));
        self::authorise();
        return self::encode(['success' => true, 'staffing_placement' => staffing_service::placement_context(
            $params['companyuuid'], (int)$USER->id, $params['planuuid'])]);
    }

    public static function staffing_placement_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised staffing-placement context');
    }

    public static function products_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable company UUID'),
            'statuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional statistic UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function products_context(string $companyuuid, string $statuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::products_context_parameters(), compact('companyuuid', 'statuuid'));
        self::authorise();
        return self::encode(['success' => true, 'products_statistics' =>
            \local_kbacompany\local\product_service::context($params['companyuuid'], (int)$USER->id, $params['statuuid'])]);
    }

    public static function products_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON company-scoped products and statistics');
    }

    public static function cascade_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable company UUID'),
        ]);
    }

    public static function cascade_context(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::cascade_context_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'cascade' =>
            \local_kbacompany\local\cascade_service::context($params['companyuuid'], (int)$USER->id)]);
    }

    public static function cascade_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON company-scoped draft cascade');
    }

    public static function execution_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable company UUID'),
            'workuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional plan or task UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execution_context(string $companyuuid, string $workuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::execution_context_parameters(), compact('companyuuid', 'workuuid'));
        self::authorise();
        return self::encode(['success' => true, 'execution' =>
            \local_kbacompany\local\workflow_service::context($params['companyuuid'], (int)$USER->id, $params['workuuid'])]);
    }

    public static function execution_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised execution context');
    }

    public static function deviations_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'deviationuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional deviation UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function deviations_context(string $companyuuid, string $deviationuuid = ''): string {
        global $USER;
        $p = self::validate_parameters(self::deviations_context_parameters(), compact('companyuuid', 'deviationuuid'));
        self::authorise();
        return self::encode(['success' => true, 'deviations' =>
            \local_kbacompany\local\deviation_service::context($p['companyuuid'], (int)$USER->id, $p['deviationuuid'])]);
    }

    public static function deviations_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON manager-scoped deviations');
    }

    public static function flows_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'flowuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional flow UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function flows_context(string $companyuuid, string $flowuuid = ''): string {
        global $USER;
        $p = self::validate_parameters(self::flows_context_parameters(), compact('companyuuid', 'flowuuid'));
        self::authorise();
        return self::encode(['success' => true, 'flows' =>
            \local_kbacompany\local\flow_service::context($p['companyuuid'], (int)$USER->id, $p['flowuuid'])]);
    }

    public static function flows_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised product flows');
    }

    public static function policies_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'policyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional policy or directive UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function policies_context(string $companyuuid, string $policyuuid = ''): string {
        global $USER;
        $p = self::validate_parameters(self::policies_context_parameters(), compact('companyuuid', 'policyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'policies' =>
            policy_service::context($p['companyuuid'], (int)$USER->id, $p['policyuuid'])]);
    }

    public static function policies_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON authorised company policies and management directives');
    }

    public static function process_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'processuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional process UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function process_context(string $companyuuid, string $processuuid = ''): string {
        global $USER;
        $p = self::validate_parameters(self::process_context_parameters(), compact('companyuuid', 'processuuid'));
        self::authorise();
        return self::encode(['success' => true, 'process_architecture' =>
            process_service::context($p['companyuuid'], (int)$USER->id, $p['processuuid'])]);
    }

    public static function process_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON versioned BMS process architecture');
    }

    public static function process_model_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'processuuid' => new external_value(PARAM_RAW_TRIMMED, 'Process UUID'),
        ]);
    }

    public static function process_model_context(string $companyuuid, string $processuuid): string {
        global $USER;
        $p = self::validate_parameters(self::process_model_context_parameters(), compact('companyuuid', 'processuuid'));
        self::authorise();
        return self::encode(['success' => true, 'process_model' =>
            process_model_service::context($p['companyuuid'], (int)$USER->id, $p['processuuid'])]);
    }

    public static function process_model_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON versioned SIPOC and BPMN process model');
    }

    public static function stp_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'processuuid' => new external_value(PARAM_RAW_TRIMMED, 'Process UUID'),
        ]);
    }

    public static function stp_context(string $companyuuid, string $processuuid): string {
        global $USER;
        $p = self::validate_parameters(self::stp_context_parameters(), compact('companyuuid', 'processuuid'));
        self::authorise();
        return self::encode(['success' => true, 'stp_raci' =>
            stp_service::context($p['companyuuid'], (int)$USER->id, $p['processuuid'])]);
    }

    public static function stp_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON generated STP and version-scoped RACI projection');
    }

    public static function functions_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'functionuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional canonical Function UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function functions_context(string $companyuuid, string $functionuuid = ''): string {
        global $USER;
        $p = self::validate_parameters(self::functions_context_parameters(), compact('companyuuid', 'functionuuid'));
        self::authorise();
        return self::encode(['success' => true, 'functions' =>
            function_service::context($p['companyuuid'], (int)$USER->id, $p['functionuuid'])]);
    }

    public static function functions_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON canonical Process-derived BMS Functions and Position traceability');
    }

    public static function documents_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'documentuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional controlled Document UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function documents_context(string $companyuuid, string $documentuuid = ''): string {
        global $USER;
        $p = self::validate_parameters(self::documents_context_parameters(), compact('companyuuid', 'documentuuid'));
        self::authorise();
        return self::encode(['success' => true, 'documents' =>
            document_service::context($p['companyuuid'], (int)$USER->id, $p['documentuuid'])]);
    }

    public static function documents_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON controlled documents, Master List, acknowledgements and records');
    }

    public static function ofs_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
            'branchuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional OFS branch UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function ofs_context(string $companyuuid, string $branchuuid = ''): string {
        global $USER;
        $p = self::validate_parameters(self::ofs_context_parameters(), compact('companyuuid', 'branchuuid'));
        self::authorise();
        return self::encode(['success' => true, 'ofs' =>
            ofs_service::context($p['companyuuid'], (int)$USER->id, $p['branchuuid'])]);
    }

    public static function ofs_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON OFS and valuable-final-product context');
    }

    public static function semantic_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Company UUID'),
        ]);
    }

    public static function semantic_context(string $companyuuid): string {
        global $USER;
        $p = self::validate_parameters(self::semantic_context_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'semantic' =>
            semantic_service::context($p['companyuuid'], (int)$USER->id)]);
    }

    public static function semantic_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON canonical BMS objects and explicit semantic relations');
    }


    public static function platform_context_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
        ]);
    }

    public static function platform_context(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::platform_context_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode([
            'success' => true,
            'platform' => platform_boundary_service::diagnostics($params['companyuuid'], (int)$USER->id),
        ]);
    }

    public static function platform_context_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON BMS platform boundary diagnostics');
    }

    private static function authorise(): void {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/kbacompany:viewowncompany', $context);
    }

    private static function encode(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \coding_exception('Unable to encode Company Core response.');
        }
        return $json;
    }
}
