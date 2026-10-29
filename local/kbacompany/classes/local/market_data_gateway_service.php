<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Provider-neutral read-only Market Data Tool Gateway foundation. */
class market_data_gateway_service {
    public const CONTRACT_VERSION = '0.1';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'market_data_tool_gateway',
            'version' => self::CONTRACT_VERSION,
            'mode' => 'read_only',
            'canonical_owner' => 'BMS Market Assessment',
            'provider_credentials_in_ai_context' => false,
            'provider_adapters' => ['rosstat_open_data'],
            'planned_adapters' => ['iswork', 'fns_open_data', 'dadata'],
            'rules' => [
                'provider_data_is_evidence_not_canonical_market_record',
                'no_provider_endpoint_is_invented_without_verified_contract',
                'company_scope_is_required_for_saved_evidence',
                'ai_never_receives_provider_secrets',
            ],
        ];
    }

    public static function configured_providers(): array {
        return [rosstat_open_data_adapter::descriptor()];
    }

    /** Company-scoped, read-only provider query. */
    public static function search(string $companyuuid, int $userid, string $provider, string $query, int $limit = 10): array {
        // Enforce the same Company boundary as the canonical Market domain before any external lookup.
        company_context_service::context($companyuuid, $userid);
        if ($provider === rosstat_open_data_adapter::PROVIDER_ID) {
            return rosstat_open_data_adapter::search($query, $limit);
        }
        throw new \invalid_parameter_exception('Unsupported market data provider.');
    }
}
