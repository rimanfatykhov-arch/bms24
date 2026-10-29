<?php
namespace local_kbacompany\external;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\external\cloud_compat_external_api as external_api;
use local_kbacompany\external\cloud_compat_external_function_parameters as external_function_parameters;
use local_kbacompany\external\cloud_compat_external_value as external_value;
use local_kbacompany\local\entitlement_snapshot_service;
use local_kbacompany\local\workspace_mapping_service;

if (!class_exists(__NAMESPACE__ . '\\cloud_compat_external_api', false)) {
    if (class_exists('\\core_external\\external_api')) {
        foreach (['external_api', 'external_function_parameters', 'external_value'] as $classname) {
            class_alias('\\core_external\\' . $classname, __NAMESPACE__ . '\\cloud_compat_' . $classname);
        }
    } else {
        global $CFG;
        require_once($CFG->libdir . '/externallib.php');
        foreach (['external_api', 'external_function_parameters', 'external_value'] as $classname) {
            class_alias('\\' . $classname, __NAMESPACE__ . '\\cloud_compat_' . $classname);
        }
    }
}

/** Restricted internal BMS Cloud contract surface. Disabled by default. */
class cloud_api extends external_api {

    public static function workspace_contract_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function workspace_contract(): string {
        self::authorise();
        return self::encode(['success' => true, 'contract' => workspace_mapping_service::contract()]);
    }

    public static function workspace_contract_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON BMS Cloud Workspace projection contract');
    }

    public static function apply_workspace_projection_parameters(): external_function_parameters {
        return new external_function_parameters([
            'projectionjson' => new external_value(PARAM_RAW, 'UTF-8 JSON BMS Cloud Workspace projection'),
        ]);
    }

    public static function apply_workspace_projection(string $projectionjson): string {
        global $USER;
        $params = self::validate_parameters(self::apply_workspace_projection_parameters(), compact('projectionjson'));
        self::authorise();
        $projection = json_decode($params['projectionjson'], true);
        if (!is_array($projection)) {
            throw new \invalid_parameter_exception('Invalid BMS Cloud Workspace projection JSON.');
        }
        return self::encode(['success' => true, 'workspace' =>
            workspace_mapping_service::apply_projection($projection, (int)$USER->id)]);
    }

    public static function apply_workspace_projection_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON accepted BMS Cloud Workspace projection metadata');
    }

    public static function workspace_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
        ]);
    }

    public static function workspace_status(string $companyuuid): string {
        $params = self::validate_parameters(self::workspace_status_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'workspace' =>
            workspace_mapping_service::status($params['companyuuid'])]);
    }

    public static function workspace_status_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON BMS Cloud Workspace mapping diagnostics');
    }

    public static function contract_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function contract(): string {
        self::authorise();
        return self::encode(['success' => true, 'contract' => entitlement_snapshot_service::contract()]);
    }

    public static function contract_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON BMS Cloud entitlement snapshot contract');
    }

    public static function apply_entitlement_snapshot_parameters(): external_function_parameters {
        return new external_function_parameters([
            'snapshotjson' => new external_value(PARAM_RAW, 'UTF-8 JSON BMS entitlement snapshot'),
        ]);
    }

    public static function apply_entitlement_snapshot(string $snapshotjson): string {
        global $USER;
        $params = self::validate_parameters(self::apply_entitlement_snapshot_parameters(), compact('snapshotjson'));
        self::authorise();
        $snapshot = json_decode($params['snapshotjson'], true);
        if (!is_array($snapshot)) {
            throw new \invalid_parameter_exception('Invalid BMS entitlement snapshot JSON.');
        }
        return self::encode(['success' => true, 'snapshot' =>
            entitlement_snapshot_service::apply($snapshot, (int)$USER->id)]);
    }

    public static function apply_entitlement_snapshot_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON accepted entitlement snapshot metadata');
    }

    public static function entitlement_status_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
        ]);
    }

    public static function entitlement_status(string $companyuuid): string {
        $params = self::validate_parameters(self::entitlement_status_parameters(), compact('companyuuid'));
        self::authorise();
        return self::encode(['success' => true, 'snapshot' =>
            entitlement_snapshot_service::diagnostics($params['companyuuid'])]);
    }

    public static function entitlement_status_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON entitlement snapshot diagnostics');
    }

    private static function authorise(): void {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/kbacompany:cloudsync', $context);
    }

    private static function encode(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \coding_exception('Unable to encode BMS Cloud API response.');
        }
        return $json;
    }
}
