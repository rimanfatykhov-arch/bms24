<?php
namespace local_kbacompany\external;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\external\odoo_compat_external_api as external_api;
use local_kbacompany\external\odoo_compat_external_function_parameters as external_function_parameters;
use local_kbacompany\external\odoo_compat_external_value as external_value;
use local_kbacompany\local\odoo_adapter_service;

if (!class_exists(__NAMESPACE__ . '\\odoo_compat_external_api', false)) {
    if (class_exists('\\core_external\\external_api')) {
        foreach (['external_api', 'external_function_parameters', 'external_value'] as $classname) {
            class_alias('\\core_external\\' . $classname, __NAMESPACE__ . '\\odoo_compat_' . $classname);
        }
    } else {
        global $CFG;
        require_once($CFG->libdir . '/externallib.php');
        foreach (['external_api', 'external_function_parameters', 'external_value'] as $classname) {
            class_alias('\\' . $classname, __NAMESPACE__ . '\\odoo_compat_' . $classname);
        }
    }
}

/** Read-only Odoo adapter boundary; no Odoo credentials or remote calls live here. */
class odoo_api extends external_api {
    public static function contract_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function contract(): string {
        self::authorise();
        return self::encode(['success' => true, 'contract' => odoo_adapter_service::contract()]);
    }

    public static function contract_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON BMS Odoo Adapter API v1 contract');
    }

    public static function readiness_parameters(): external_function_parameters {
        return self::company_environment_parameters();
    }

    public static function readiness(string $companyuuid, string $environment): string {
        global $USER;
        $params = self::validate_parameters(self::readiness_parameters(), compact('companyuuid', 'environment'));
        self::authorise();
        return self::encode(['success' => true, 'readiness' => odoo_adapter_service::readiness(
            $params['companyuuid'], (int)$USER->id, $params['environment'])]);
    }

    public static function readiness_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON Odoo adapter readiness');
    }

    public static function manifest_parameters(): external_function_parameters {
        return self::company_environment_parameters();
    }

    public static function manifest(string $companyuuid, string $environment): string {
        global $USER;
        $params = self::validate_parameters(self::manifest_parameters(), compact('companyuuid', 'environment'));
        self::authorise();
        return self::encode(['success' => true, 'manifest' => odoo_adapter_service::manifest(
            $params['companyuuid'], (int)$USER->id, $params['environment'])]);
    }

    public static function manifest_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON Odoo sync manifest');
    }

    public static function mappings_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
            'environment' => new external_value(PARAM_RAW_TRIMMED, 'Odoo environment identifier'),
            'page' => new external_value(PARAM_INT, 'Zero-based mapping page', VALUE_DEFAULT, 0),
        ]);
    }

    public static function mappings(string $companyuuid, string $environment, int $page = 0): string {
        global $USER;
        $params = self::validate_parameters(self::mappings_parameters(), compact('companyuuid', 'environment', 'page'));
        self::authorise();
        return self::encode(['success' => true, 'page' => odoo_adapter_service::mappings(
            $params['companyuuid'], (int)$USER->id, $params['environment'], (int)$params['page'])]);
    }

    public static function mappings_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON paged Odoo identity mappings');
    }

    private static function company_environment_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyuuid' => new external_value(PARAM_RAW_TRIMMED, 'Stable BMS Company UUID'),
            'environment' => new external_value(PARAM_RAW_TRIMMED, 'Odoo environment identifier'),
        ]);
    }

    private static function authorise(): void {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/kbacompany:viewowncompany', $context);
    }

    private static function encode(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \coding_exception('Unable to encode Odoo adapter API response.');
        }
        return $json;
    }
}
