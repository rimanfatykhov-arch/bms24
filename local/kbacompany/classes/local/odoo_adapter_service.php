<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Odoo adapter pull boundary.
 *
 * BMS remains owner of the management target model. This service exposes only
 * human-confirmed Odoo identities plus hashes of authorised Public API v1
 * snapshots. It stores no Odoo credentials, performs no remote call and does
 * not import operational actuals in the 0.9.1 foundation slice.
 */
class odoo_adapter_service {
    public const CONTRACT_VERSION = '0.9.1.3';
    public const API_VERSION = 'v1';
    public const PROVIDER = 'odoo';
    public const PAGE_SIZE = 100;

    public static function contract(): array {
        $contract = [
            'version' => self::CONTRACT_VERSION,
            'api_version' => self::API_VERSION,
            'provider' => self::PROVIDER,
            'service_shortname' => 'local_kbacompany_odoo_v1',
            'service_policy' => 'disabled_by_default_restricted_users',
            'mode' => 'external_adapter_pull_boundary',
            'preferred_odoo_transport' => 'json2_for_odoo_19_plus',
            'moodle_to_odoo_transport' => 'json2_transient_probe_cli',
            'credentials_in_moodle' => false,
            'direct_odoo_database_access' => false,
            'actual_ingestion_enabled' => true,
            'actual_ingestion_mode' => 'human_confirmed_only',
            'actual_read_staging_enabled' => true,
            'actual_staging_contract' => odoo_actual_staging_service::contract(),
            'actual_import_contract' => odoo_actual_import_service::contract(),
            'remote_transport_probe' => odoo_json2_transport::contract(),
            'identity_source' => 'local_kbacompany_extmap_human_confirmed',
            'canonical_resource_source' => 'bms_management_public_api_v1',
            'company_mapping_model' => 'res.company',
            'supported_mapping_kinds' => ['company', 'position', 'indicator'],
            'resources' => ['company', 'positions', 'indicators', 'budgets'],
            'canonical_functions_in_same_service' => [
                'local_kbacompany_public_company', 'local_kbacompany_public_positions',
                'local_kbacompany_public_indicators', 'local_kbacompany_public_budgets',
            ],
            'rules' => [
                'company_uuid_is_security_boundary',
                'company_scope_is_applied_before_database_reads',
                'active_odoo_company_mapping_is_required',
                'closed_or_legacy_unscoped_mappings_are_not_adapter_ready',
                'external_ids_do_not_replace_bms_uuid',
                'manifest_is_read_only',
                'missing_actual_is_not_zero',
                'remote_actual_is_staged_before_any_canonical_fact_write',
                'staging_packet_has_provenance_and_idempotency_key',
                'bms_does_not_claim_odoo_object_existence_without_remote_verification',
                'provider_credentials_and_operational_transactions_remain_outside_moodle',
            ],
        ];
        $contract['schema_hash'] = hash('sha256', self::json($contract));
        return $contract;
    }

    /** Return active Odoo environments declared for this company, bounded by the company scope. */
    public static function environments(string $companyuuid, int $userid): array {
        global $DB;
        $company = self::authorise($companyuuid, $userid);
        $rows = $DB->get_records_sql(
            "SELECT MIN(id) AS id, environment\n" .
            "  FROM {local_kbacompany_extmap}\n" .
            " WHERE companyuuid = :companyuuid AND provider = :provider AND active = 1\n" .
            "   AND environment <> '' AND namespacekey <> ''\n" .
            ' GROUP BY environment ORDER BY environment ASC',
            ['companyuuid' => (string)$company['uuid'], 'provider' => self::PROVIDER], 0, 100);
        $out = [];
        foreach ($rows as $row) {
            $out[] = (string)$row->environment;
        }
        return $out;
    }

    /** Readiness of one exact company + Odoo environment. No remote Odoo claim is made. */
    public static function readiness(string $companyuuid, int $userid, string $environment): array {
        global $DB;
        $company = self::authorise($companyuuid, $userid);
        $companyuuid = (string)$company['uuid'];
        $environment = self::environment($environment);

        $base = [
            'companyuuid' => $companyuuid,
            'provider' => self::PROVIDER,
            'environment' => $environment,
            'active' => 1,
        ];
        $companymapping = $DB->get_record('local_kbacompany_extmap', $base + [
            'entitykind' => 'company', 'localref' => $companyuuid, 'externalmodel' => 'res.company',
        ], 'id,mappinguuid,externalid,namespacekey,externalmodel');
        $counts = [];
        foreach (['company', 'position', 'indicator'] as $kind) {
            $counts[$kind] = $DB->count_records('local_kbacompany_extmap', $base + ['entitykind' => $kind]);
        }
        $blockers = [];
        if (!$companymapping || empty($companymapping->namespacekey) || empty($companymapping->mappinguuid)) {
            $blockers[] = 'active_company_mapping_required';
        }
        if (!public_api_service::contract()) {
            $blockers[] = 'public_api_contract_unavailable';
        }
        return [
            'provider' => self::PROVIDER,
            'environment' => $environment,
            'company_uuid' => $companyuuid,
            'ready' => !$blockers,
            'remote_verified' => false,
            'company_mapping' => $companymapping ? [
                'mapping_uuid' => (string)$companymapping->mappinguuid,
                'external_model' => (string)$companymapping->externalmodel,
                'external_id' => (string)$companymapping->externalid,
            ] : null,
            'mapping_counts' => $counts,
            'blockers' => $blockers,
            'warnings' => [
                'remote_odoo_connection_not_verified',
                'actual_ingestion_not_enabled',
            ],
        ];
    }

    /**
     * Adapter manifest: stable hashes/counts of canonical BMS target snapshots plus mapping coverage.
     * The actual resource payloads remain owned by Public API v1 and are not duplicated here.
     */
    public static function manifest(string $companyuuid, int $userid, string $environment): array {
        $ready = self::readiness($companyuuid, $userid, $environment);
        if (!$ready['ready']) {
            throw new \moodle_exception('bms_odoo_company_mapping_required', 'local_kbacompany');
        }
        $companyuuid = (string)$ready['company_uuid'];
        $company = public_api_service::company_snapshot($companyuuid, $userid);
        $positions = public_api_service::positions_snapshot($companyuuid, $userid);
        $indicators = public_api_service::indicators_snapshot($companyuuid, $userid);
        $budgets = public_api_service::budgets_snapshot($companyuuid, $userid);
        $resources = [
            'company' => self::resource_descriptor($company, 1),
            'positions' => self::resource_descriptor($positions,
                (int)($positions['data']['summary']['positions'] ?? count($positions['data']['positions'] ?? []))),
            'indicators' => self::resource_descriptor($indicators,
                (int)($indicators['data']['count'] ?? count($indicators['data']['indicators'] ?? []))),
            'budgets' => self::resource_descriptor($budgets,
                (int)($budgets['data']['count'] ?? count($budgets['data']['budgets'] ?? []))),
        ];
        $manifest = [
            'adapter_version' => self::CONTRACT_VERSION,
            'provider' => self::PROVIDER,
            'environment' => (string)$ready['environment'],
            'company_uuid' => $companyuuid,
            'odoo_company' => $ready['company_mapping'],
            'mapping_counts' => $ready['mapping_counts'],
            'public_api_service' => 'local_kbacompany_public_v1',
            'resources' => $resources,
            'actual_ingestion_enabled' => true,
            'actual_ingestion_mode' => 'human_confirmed_only',
            'remote_verified' => false,
        ];
        $manifest['manifest_hash'] = hash('sha256', self::json($manifest));
        $manifest['generated_at'] = gmdate('c');
        return $manifest;
    }

    /** Company-scoped paged identity map required by the external Odoo adapter. */
    public static function mappings(string $companyuuid, int $userid, string $environment, int $page = 0): array {
        global $DB;
        $company = self::authorise($companyuuid, $userid);
        $companyuuid = (string)$company['uuid'];
        $environment = self::environment($environment);
        if ($page < 0 || $page > 100000) {
            throw new \moodle_exception('bms_odoo_invalid_environment', 'local_kbacompany');
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
            throw new \moodle_exception('bms_odoo_invalid_environment', 'local_kbacompany');
        }
        return $environment;
    }

    private static function json(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \coding_exception('Unable to encode Odoo adapter contract.');
        }
        return $json;
    }
}
