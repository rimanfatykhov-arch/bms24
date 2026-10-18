<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Platform-facing ownership and diagnostics boundary for BMS Management. */
class platform_boundary_service {
    public const CONTRACT_VERSION = '0.6.9';

    public static function contract(): array {
        $contract = [
            'version' => self::CONTRACT_VERSION,
            'product_role' => 'bms_management_cloud',
            'owners' => [
                'management_model' => 'local_kbacompany',
                'learning_execution' => 'local_kbacorporate_and_moodle_core',
                'cloud_control_plane' => 'external_bms_cloud',
                'operational_erp_actual' => 'odoo_provider',
                'crm_provider' => 'bitrix24_currently_provider_neutral_future',
            ],
            'interfaces' => [
                'company_context' => company_context_service::contract(),
                'workspace_projection' => workspace_mapping_service::contract(),
                'entitlement' => entitlement_service::contract(),
                'entitlement_snapshot' => entitlement_snapshot_service::contract(),
                'event_envelope' => platform_event_envelope::contract(),
                'event_outbox' => integration_event_service::contract(),
                'public_api' => public_api_service::contract(),
                'pre_0_7_readiness' => platform_readiness_service::contract(),
            ],
            'forbidden_dependencies' => [
                'cloud_registry_tables_inside_bms_business_model',
                'direct_odoo_database_access',
                'direct_bitrix_rest_from_bms_domain_services',
                'edition_name_conditions_in_business_modules',
                'cross_company_fetch_then_php_filter',
                'external_consumers_reading_moodle_or_bms_tables_directly',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    /** Management-only platform diagnostics without exposing integration payloads. */
    public static function diagnostics(string $companyuuid, int $userid): array {
        $company = company_context_service::context($companyuuid, $userid);
        $companyuuid = (string)$company['uuid'];
        if (!permission_service::can_manage_organization($companyuuid, $userid) && !is_siteadmin($userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
        $workspace = workspace_mapping_service::status($companyuuid);
        $entitlement = entitlement_service::explain($companyuuid, 'mgmt.core');
        $snapshot = entitlement_snapshot_service::diagnostics($companyuuid);
        $integration = integration_service::diagnostics($companyuuid, $userid);
        return [
            'company_uuid' => $companyuuid,
            'workspace' => $workspace,
            'cloud_runtime' => [
                'sync_freshness' => (string)$workspace['sync_freshness'],
                'projection_revision' => (int)$workspace['projection_revision'],
                'erp_application_state' => (string)$workspace['erp_application_state'],
                'integration_health' => (string)$workspace['integration_health'],
                'snapshot_revision' => (string)$workspace['snapshot_revision'],
            ],
            'entitlement' => $entitlement,
            'entitlement_snapshot' => $snapshot,
            'integration' => [
                'bitrix_company_id' => (string)$integration['external_company_id'],
                'consumer_available' => !empty($integration['consumer_available']),
                'outbox' => [
                    'pending' => (int)$integration['summary']['pending'],
                    'error' => (int)$integration['summary']['error'],
                    'done' => (int)$integration['summary']['done'],
                ],
                'legacy_command_outbox' => [
                    'pending' => (int)$integration['summary']['pending'],
                    'error' => (int)$integration['summary']['error'],
                    'done' => (int)$integration['summary']['done'],
                ],
                'provider_neutral_event_outbox' => [
                    'ready' => !empty($integration['event_outbox']['ready']),
                    'pending' => (int)$integration['event_outbox']['summary']['pending'],
                    'error' => (int)$integration['event_outbox']['summary']['error'],
                    'done' => (int)$integration['event_outbox']['summary']['done'],
                ],
            ],
            'readiness' => platform_readiness_service::evaluate($companyuuid),
            'contracts' => [
                'company_context' => company_context_service::CONTRACT_VERSION,
                'entitlement' => entitlement_service::CONTRACT_VERSION,
                'entitlement_snapshot' => entitlement_snapshot_service::CONTRACT_VERSION,
                'event_envelope' => platform_event_envelope::SCHEMA_VERSION,
                'event_outbox' => integration_event_service::CONTRACT_VERSION,
                'public_api' => public_api_service::API_VERSION,
                'pre_0_7_readiness' => platform_readiness_service::CONTRACT_VERSION,
            ],
        ];
    }
}
