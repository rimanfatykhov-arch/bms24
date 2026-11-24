<?php
namespace local_kbacompany\external;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\external\public_compat_external_api as external_api;
use local_kbacompany\external\public_compat_external_function_parameters as external_function_parameters;
use local_kbacompany\external\public_compat_external_value as external_value;
use local_kbacompany\local\max_inbound_service;

// Moodle 4.0 compatibility: the aliases are shared with public_api.
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

/**
 * RC33 restricted inbound service local_kbacompany_public_inbound_v1 (disabled by default).
 *
 * The web-service user is the company's service_integration principal: a transport account, never the author.
 * The payload is one JSON object (contract §5) decoded strictly by the service. Refusals are Moodle exceptions
 * whose errorcode carries the safe category; clients must inspect the envelope, not only HTTP status.
 */
class inbound_api extends external_api {
    public static function message_parameters(): external_function_parameters {
        return new external_function_parameters([
            'payload' => new external_value(PARAM_RAW, 'UTF-8 JSON rc33.inbound/1 message_created DTO'),
        ]);
    }

    public static function message(string $payload): string {
        global $USER;
        $params = self::validate_parameters(self::message_parameters(), compact('payload'));
        self::authorise();
        return self::encode(max_inbound_service::receive_message((int)$USER->id, (string)$params['payload']));
    }

    public static function message_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON {schema_version, outcome, receipt_uuid, correlation_id, ...}');
    }

    public static function control_parameters(): external_function_parameters {
        return new external_function_parameters([
            'payload' => new external_value(PARAM_RAW, 'UTF-8 JSON rc33.inbound/1 message_edited|message_removed DTO'),
        ]);
    }

    public static function control(string $payload): string {
        global $USER;
        $params = self::validate_parameters(self::control_parameters(), compact('payload'));
        self::authorise();
        return self::encode(max_inbound_service::receive_control((int)$USER->id, (string)$params['payload']));
    }

    public static function control_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON {schema_version, outcome, correlation_id}');
    }

    private static function authorise(): void {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/kbacompany:importinbound', $context);
    }

    private static function encode(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \coding_exception('Unable to encode BMS inbound response.');
        }
        return $json;
    }
}
