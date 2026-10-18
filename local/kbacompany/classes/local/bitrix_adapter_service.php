<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Bitrix24 provider extension boundary.
 *
 * Bitrix24 REST transport remains owned by KBA Connect/local_bitrixsync. This
 * service exposes only company-scoped readiness, mappings and canonical BMS
 * snapshots for that existing provider bridge. No direct Bitrix24 HTTP call is
 * performed from local_kbacompany.
 */
class bitrix_adapter_service {
    public const CONTRACT_VERSION = '0.9.2';
    public const API_VERSION = 'v1';
    public const PROVIDER = 'bitrix24';
    public const PAGE_SIZE = 100;
    public const LEGACY_ENVIRONMENT = 'legacy';

    public static function contract(): array {
        $contract = [
            'version' => self::CONTRACT_VERSION,
            'api_version' => self::API_VERSION,
            'provider' => self::PROVIDER,
            'service_shortname' => 'local_kbacompany_bitrix_v1',
            'service_policy' => 'disabled_by_default_restricted_users',
            'mode' => 'provider_extension_over_existing_kba_connect',
            'bitrix_rest_owner' => 'local_bitrixsync_kba_connect',
            'direct_bitrix_rest_from_bms' => false,
            'credentials_in_local_kbacompany' => false,
            'identity_source' => 'local_kbacompany_extmap_human_confirmed_plus_legacy_compatibility',
            'canonical_resource_source' => 'bms_management_public_api_v1',
            'company_mapping_model' => 'crm.company',
            'supported_mapping_kinds' => ['company', 'position', 'indicator'],
            'legacy_user_mapping' => 'preserved_but_not_enumerated_cross_company',
            'resources' => ['company', 'employees', 'positions'],
            'canonical_functions_in_same_service' => [
                'local_kbacompany_public_company',
                'local_kbacompany_public_employees',
                'local_kbacompany_public_positions',
            ],
            'legacy_command_outbox' => 'local_kbacompany_syncout',
            'provider_neutral_event_outbox' => integration_event_service::contract(),
            'rules' => [
                'company_uuid_is_security_boundary',
                'company_scope_is_applied_before_database_reads',
                'kba_connect_transport_is_not_reimplemented',
                'existing_legacy_company_mapping_remains_compatible',
                'scoped_provider_identity_is_preferred_for_new_links',
                'external_ids_do_not_replace_bms_uuid',
                'contact_pii_is_exposed_only_through_restricted_public_api_permissions',
                'manifest_is_read_only',
                'provider_remote_availability_is_not_inferred_from_local_mappings',
            ],
        ];
        $contract['schema_hash'] = hash('sha256', self::json($contract));
        return $contract;
    }

    /** Active scoped Bitrix24 environments plus legacy compatibility when present. */
    public static function environments(string $companyuuid, int $userid): array {
        global $DB;
        $company = self::authorise($companyuuid, $userid);
        $companyuuid = (string)$company['uuid'];
        $rows = $DB->get_records_sql(
            "SELECT MIN(id) AS id, environment\n" .
            "  FROM {local_kbacompany_extmap}\n" .
            " WHERE companyuuid = :companyuuid AND provider = :provider AND active = 1\n" .
            "   AND environment <> '' AND namespacekey <> ''\n" .
            ' GROUP BY environment ORDER BY environment ASC',
            ['companyuuid' => $companyuuid, 'provider' => self::PROVIDER], 0, 100);
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string)$row->environment;
        }
        if (integration_service::external_id(self::PROVIDER, 'company', $companyuuid) !== '') {
            $out[] = self::LEGACY_ENVIRONMENT;
        }
        return array_values(array_unique($out));
    }

    /** Local provider readiness. This is not a live Bitrix24 REST health claim. */
    public static function readiness(string $companyuuid, int $userid, string $environment): array {
        global $DB;
        $company = self::authorise($companyuuid, $userid);
        $companyuuid = (string)$company['uuid'];
        $environment = self::environment($environment);
        $legacy = $environment === self::LEGACY_ENVIRONMENT;

        $companymapping = null;
        $counts = ['company' => 0, 'position' => 0, 'indicator' => 0];
        if ($legacy) {
            $legacyrow = integration_service::mapping_by_local(self::PROVIDER, 'company', $companyuuid);
            if ($legacyrow) {
                $companymapping = [
                    'mapping_uuid' => (string)($legacyrow->mappinguuid ?? ''),
                    'external_model' => 'crm.company',
                    'external_id' => (string)$legacyrow->externalid,
                    'identity_mode' => 'legacy_unscoped',
                ];
                $counts['company'] = 1;
            }
        } else {
            $base = [
                'companyuuid' => $companyuuid,
                'provider' => self::PROVIDER,
                'environment' => $environment,
                'active' => 1,
            ];
            $row = $DB->get_record('local_kbacompany_extmap', $base + [
                'entitykind' => 'company',
                'localref' => $companyuuid,
                'externalmodel' => 'crm.company',
            ], 'id,mappinguuid,externalid,namespacekey,externalmodel');
            if ($row && !empty($row->namespacekey)) {
                $companymapping = [
                    'mapping_uuid' => (string)$row->mappinguuid,
                    'external_model' => (string)$row->externalmodel,
                    'external_id' => (string)$row->externalid,
                    'identity_mode' => 'scoped',
                ];
            }
            foreach (array_keys($counts) as $kind) {
                $counts[$kind] = $DB->count_records('local_kbacompany_extmap', $base + ['entitykind' => $kind]);
            }
        }

        $pending = (int)$DB->count_records('local_kbacompany_syncout', [
            'companyuuid' => $companyuuid, 'status' => 'pending',
        ]);
        $errors = (int)$DB->count_records('local_kbacompany_syncout', [
            'companyuuid' => $companyuuid, 'status' => 'error',
        ]);
        $consumeravailable = class_exists('\\local_bitrixsync\\services\\bms_bridge_service') ||
            class_exists('\\local_bitrixsync\\task\\process_bms_bridge');

        $blockers = [];
        if (!$companymapping) {
            $blockers[] = 'active_company_mapping_required';
        }
        if (!$consumeravailable) {
            $blockers[] = 'kba_connect_consumer_not_detected';
        }
        $warnings = ['remote_bitrix24_connection_not_verified'];
        if ($legacy) {
            $warnings[] = 'legacy_unscoped_identity_compatibility_mode';
        }
        if ($errors > 0) {
            $warnings[] = 'legacy_command_outbox_has_errors';
        }
        return [
            'provider' => self::PROVIDER,
            'environment' => $environment,
            'company_uuid' => $companyuuid,
            'ready' => !$blockers,
            'remote_verified' => false,
            'transport_owner' => 'local_bitrixsync',
            'consumer_available' => $consumeravailable,
            'company_mapping' => $companymapping,
            'mapping_counts' => $counts,
            'legacy_command_outbox' => ['pending' => $pending, 'error' => $errors],
            'event_outbox' => integration_event_service::diagnostics($companyuuid)['summary'],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * Read-only reconciliation manifest for KBA Connect.
     * Canonical payloads stay in Public API v1 and are not copied into a second CRM DTO store.
     */
    public static function manifest(string $companyuuid, int $userid, string $environment): array {
        $ready = self::readiness($companyuuid, $userid, $environment);
        if (!$ready['company_mapping']) {
            throw new \moodle_exception('bms_bitrix_company_mapping_required', 'local_kbacompany');
        }
        $companyuuid = (string)$ready['company_uuid'];
        $company = public_api_service::company_snapshot($companyuuid, $userid);
        $employees = public_api_service::employees_snapshot($companyuuid, $userid, true, true);
        $positions = public_api_service::positions_snapshot($companyuuid, $userid);
        $resources = [
            'company' => self::resource_descriptor($company, 1),
            'employees' => self::resource_descriptor($employees,
                (int)($employees['data']['count'] ?? count($employees['data']['employees'] ?? []))),
            'positions' => self::resource_descriptor($positions,
                (int)($positions['data']['summary']['positions'] ?? count($positions['data']['positions'] ?? []))),
        ];
        $manifest = [
            'adapter_version' => self::CONTRACT_VERSION,
            'provider' => self::PROVIDER,
            'environment' => (string)$ready['environment'],
            'company_uuid' => $companyuuid,
            'bitrix_company' => $ready['company_mapping'],
            'transport_owner' => 'local_bitrixsync',
            'consumer_available' => !empty($ready['consumer_available']),
            'mapping_counts' => $ready['mapping_counts'],
            'legacy_command_outbox' => $ready['legacy_command_outbox'],
            'event_outbox' => $ready['event_outbox'],
            'public_api_service' => 'local_kbacompany_public_v1',
            'resources' => $resources,
            'remote_verified' => false,
            'writes_performed' => 0,
        ];
        $manifest['manifest_hash'] = hash('sha256', self::json($manifest));
        $manifest['generated_at'] = gmdate('c');
        return $manifest;
    }

    /** Company-scoped paged identity map. Legacy mode exposes only its company identity. */
    public static function mappings(string $companyuuid, int $userid, string $environment, int $page = 0): array {
        global $DB;
        $company = self::authorise($companyuuid, $userid);
        $companyuuid = (string)$company['uuid'];
        $environment = self::environment($environment);
        if ($page < 0 || $page > 100000) {
            throw new \moodle_exception('bms_bitrix_invalid_environment', 'local_kbacompany');
        }
        if ($environment === self::LEGACY_ENVIRONMENT) {
            $row = integration_service::mapping_by_local(self::PROVIDER, 'company', $companyuuid);
            $mappings = [];
            if ($row && $page === 0) {
                $mappings[] = [
                    'mapping_uuid' => (string)($row->mappinguuid ?? ''),
                    'entity_kind' => 'company',
                    'local_uuid' => $companyuuid,
                    'external_model' => 'crm.company',
                    'external_id' => (string)$row->externalid,
                    'identity_mode' => 'legacy_unscoped',
                    'time_created' => (int)($row->timecreated ?? 0),
                    'time_modified' => (int)($row->timemodified ?? 0),
                ];
            }
            return [
                'provider' => self::PROVIDER,
                'environment' => $environment,
                'company_uuid' => $companyuuid,
                'page' => $page,
                'page_size' => self::PAGE_SIZE,
                'total' => $row ? 1 : 0,
                'mappings' => $mappings,
                'legacy_child_mappings_enumerated' => false,
            ];
        }
        $conditions = [
            'companyuuid' => $companyuuid,
            'provider' => self::PROVIDER,
            'environment' => $environment,
            'active' => 1,
        ];
        $rows = $DB->get_records('local_kbacompany_extmap', $conditions,
            'entitykind ASC, id ASC',
            'id,mappinguuid,entitykind,localref,externalmodel,externalid,timecreated,timemodified',
            $page * self::PAGE_SIZE, self::PAGE_SIZE);
        $mappings = [];
        foreach ($rows as $row) {
            if (!in_array((string)$row->entitykind, ['company', 'position', 'indicator'], true)) {
                continue;
            }
            $mappings[] = [
                'mapping_uuid' => (string)$row->mappinguuid,
                'entity_kind' => (string)$row->entitykind,
                'local_uuid' => (string)$row->localref,
                'external_model' => (string)$row->externalmodel,
                'external_id' => (string)$row->externalid,
                'identity_mode' => 'scoped',
                'time_created' => (int)$row->timecreated,
                'time_modified' => (int)$row->timemodified,
            ];
        }
        return [
            'provider' => self::PROVIDER,
            'environment' => $environment,
            'company_uuid' => $companyuuid,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'total' => $DB->count_records('local_kbacompany_extmap', $conditions),
            'mappings' => $mappings,
            'legacy_child_mappings_enumerated' => false,
        ];
    }

    private static function resource_descriptor(array $snapshot, int $count): array {
        return [
            'resource' => (string)$snapshot['resource'],
            'schema_version' => (string)$snapshot['schema_version'],
            'snapshot_hash' => (string)$snapshot['snapshot_hash'],
            'count' => $count,
            'fetch_function' => 'local_kbacompany_public_' . (string)$snapshot['resource'],
        ];
    }

    private static function authorise(string $companyuuid, int $userid): array {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        $company = company_context_service::context($companyuuid, $userid);
        if (!permission_service::can_manage_organization($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
        return $company;
    }

    private static function environment(string $environment): string {
        $environment = trim($environment);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $environment)) {
            throw new \moodle_exception('bms_bitrix_invalid_environment', 'local_kbacompany');
        }
        return $environment;
    }

    private static function json(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \coding_exception('Unable to encode Bitrix24 adapter contract.');
        }
        return $json;
    }
}
