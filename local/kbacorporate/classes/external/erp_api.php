<?php
namespace local_kbacorporate\external;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\external\compat_external_api as external_api;
use local_kbacorporate\external\compat_external_function_parameters as external_function_parameters;
use local_kbacorporate\external\compat_external_value as external_value;

// Moodle 4.1 exposes the External API in the global namespace; later versions
// provide core_external. Keep this facade compatible without patching Moodle.
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

/** Moodle External Service facade for the stable ERP exchange contract. */
class erp_api extends external_api {
    public static function contract_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function contract(): string {
        self::validate_context(\context_system::instance());
        require_capability('local/kbacorporate:useerpapi', \context_system::instance());
        return self::encode(\local_kbacorporate\local\erp_exchange::contract());
    }

    public static function contract_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON contract');
    }

    public static function status_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function status(): string {
        self::validate_context(\context_system::instance());
        require_capability('local/kbacorporate:useerpapi', \context_system::instance());
        return self::encode(\local_kbacorporate\local\erp_exchange::status());
    }

    public static function status_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON ERP API readiness status');
    }

    public static function push_entity_parameters(): external_function_parameters {
        return new external_function_parameters([
            'system' => new external_value(PARAM_ALPHANUMEXT, 'Provider token, for example erp_provider'),
            'entitytype' => new external_value(PARAM_ALPHANUMEXT, 'Supported entity type'),
            'externalid' => new external_value(PARAM_RAW_TRIMMED, 'Provider-owned immutable ID'),
            'payloadjson' => new external_value(PARAM_RAW, 'UTF-8 JSON object payload'),
            'idempotencykey' => new external_value(PARAM_RAW_TRIMMED, 'Optional request trace key', VALUE_DEFAULT, ''),
        ]);
    }

    public static function push_entity(string $system, string $entitytype, string $externalid,
            string $payloadjson, string $idempotencykey = ''): string {
        $params = self::validate_parameters(self::push_entity_parameters(), compact(
            'system', 'entitytype', 'externalid', 'payloadjson', 'idempotencykey'
        ));
        self::validate_context(\context_system::instance());
        require_capability('local/kbacorporate:useerpapi', \context_system::instance());
        if (strlen($params['payloadjson']) > \local_kbacorporate\local\erp_exchange::MAX_PAYLOAD_BYTES) {
            throw new \invalid_parameter_exception('ERP payload is too large.');
        }
        $payload = json_decode($params['payloadjson'], true);
        if (!is_array($payload) || array_values($payload) === $payload) {
            throw new \invalid_parameter_exception('ERP payload must be a JSON object.');
        }
        return self::encode(\local_kbacorporate\local\erp_exchange::push_entity(
            $params['system'], $params['entitytype'], $params['externalid'], $payload, $params['idempotencykey']
        ));
    }

    public static function push_entity_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON operation result');
    }

    public static function pull_company_snapshot_parameters(): external_function_parameters {
        return new external_function_parameters([
            'system' => new external_value(PARAM_ALPHANUMEXT, 'Provider token, for example erp_provider'),
            'companyexternalid' => new external_value(PARAM_RAW_TRIMMED, 'Provider-owned company ID'),
            'afteruserid' => new external_value(PARAM_INT, 'Exclusive Moodle user cursor', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Page size, maximum 200', VALUE_DEFAULT, 100),
        ]);
    }

    public static function pull_company_snapshot(string $system, string $companyexternalid,
            int $afteruserid = 0, int $limit = 100): string {
        $params = self::validate_parameters(self::pull_company_snapshot_parameters(), compact(
            'system', 'companyexternalid', 'afteruserid', 'limit'
        ));
        self::validate_context(\context_system::instance());
        require_capability('local/kbacorporate:useerpapi', \context_system::instance());
        return self::encode(\local_kbacorporate\local\erp_exchange::company_snapshot(
            $params['system'], $params['companyexternalid'], $params['afteruserid'], $params['limit']
        ));
    }

    public static function pull_company_snapshot_returns(): external_value {
        return new external_value(PARAM_RAW, 'UTF-8 JSON company snapshot');
    }

    private static function encode(array $data): string {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \coding_exception('Unable to encode ERP API response.');
        }
        return $json;
    }
}
