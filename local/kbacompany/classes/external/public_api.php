<?php
namespace local_kbacompany\external;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\external\public_compat_external_api as external_api;
use local_kbacompany\external\public_compat_external_function_parameters as external_function_parameters;
use local_kbacompany\external\public_compat_external_value as external_value;
use local_kbacompany\local\public_api_service;

// Moodle 4.0 compatibility: support both legacy and namespaced external API classes.
if (!class_exists(__NAMESPACE__ . '\\public_compat_external_api', false)) {
    if (class_exists('\\core_external\\external_api')) {
        foreach (['external_api', 'external_function_parameters', 'external_value'] as $classname) {
            class_alias('\\core_external\\' . $classname, __NAMESPACE__ . '\\public_compat_' . $classname);
        }
    } else {
        global $CFG;
        require_once($CFG->libdir . '/externallib.php');
        foreach (['external_api', 'external_function_parameters', 'external_value'] as $classname) {
            class_alias('\\' . $classname, __NAMESPACE__ . '\\public_compat_' . $classname);
        }
    }
}

/** Read-only stable BMS Management Public API v1. */
class public_api extends external_api {
    public static function contract_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function contract(): string {
        self::authorise();
        return self::encode(['success' => true, 'contract' => public_api_service::contract()]);
    }

    public static function contract_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON BMS Management Public API v1 contract');
    }

    public static function company_parameters(): external_function_parameters {
        return self::company_parameters_definition();
    }

    public static function company(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::company_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'snapshot' =>
            public_api_service::company_snapshot($params['companyuuid'], (int)$USER->id)]);
    }

    public static function company_returns(): external_value {
        return self::snapshot_returns('company');
    }

    public static function employees_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
            'includeinactive' => new external_value(PARAM_BOOL, 'Include inactive historical memberships', VALUE_DEFAULT, 0),
            'includecontact' => new external_value(PARAM_BOOL, 'Include email and phone; management permission required', VALUE_DEFAULT, 0),
        ]);
    }

    public static function employees(string $companyuuid, bool $includeinactive = false,
            bool $includecontact = false): string {
        global $USER;
        $params = self::validate_parameters(self::employees_parameters(),
            compact('companyuuid', 'includeinactive', 'includecontact'));
        self::authorise();
        return self::encode(['success' => true, 'snapshot' => public_api_service::employees_snapshot(
            $params['companyuuid'], (int)$USER->id, (bool)$params['includeinactive'], (bool)$params['includecontact'])]);
    }

    public static function employees_returns(): external_value {
        return self::snapshot_returns('employees');
    }

    public static function organization_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
            'branchuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional organization branch UUID', VALUE_DEFAULT, ''),
        ]);
    }

    public static function organization(string $companyuuid, string $branchuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::organization_parameters(), compact('companyuuid', 'branchuuid'));
        self::authorise();
        return self::encode(['success' => true, 'snapshot' => public_api_service::organization_snapshot(
            $params['companyuuid'], (int)$USER->id, $params['branchuuid'])]);
    }

    public static function organization_returns(): external_value {
        return self::snapshot_returns('organization');
    }

    public static function positions_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
            'orgnodeuuid' => new external_value(PARAM_RAW_TRIMMED, 'Optional organization node UUID filter', VALUE_DEFAULT, ''),
        ]);
    }

    public static function positions(string $companyuuid, string $orgnodeuuid = ''): string {
        global $USER;
        $params = self::validate_parameters(self::positions_parameters(), compact('companyuuid', 'orgnodeuuid'));
        self::authorise();
        return self::encode(['success' => true, 'snapshot' => public_api_service::positions_snapshot(
            $params['companyuuid'], (int)$USER->id, $params['orgnodeuuid'])]);
    }

    public static function positions_returns(): external_value {
        return self::snapshot_returns('positions');
    }

    public static function indicators_parameters(): external_function_parameters {
        return self::company_parameters_definition();
    }

    public static function indicators(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::indicators_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'snapshot' =>
            public_api_service::indicators_snapshot($params['companyuuid'], (int)$USER->id)]);
    }

    public static function indicators_returns(): external_value {
        return self::snapshot_returns('indicators');
    }

    public static function budgets_parameters(): external_function_parameters {
        return self::company_parameters_definition();
    }

    public static function budgets(string $companyuuid): string {
        global $USER;
        $params = self::validate_parameters(self::budgets_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'snapshot' =>
            public_api_service::budgets_snapshot($params['companyuuid'], (int)$USER->id)]);
    }

    public static function budgets_returns(): external_value {
        return self::snapshot_returns('budgets');
    }

    private static function company_parameters_definition(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
        ]);
    }

    private static function snapshot_returns(string $resource): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON BMS Public API ' . $resource . ' snapshot');
    }

    private static function authorise(): void {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/kbacompany:viewowncompany', $context);
    }

    private static function encode(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \coding_exception('Unable to encode BMS Public API response.');
        }
        return $json;
    }
}
