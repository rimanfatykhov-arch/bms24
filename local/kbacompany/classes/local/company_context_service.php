<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Canonical company boundary for platform-facing code.
 *
 * This service deliberately does not own Company data. It normalises the stable
 * Company UUID and delegates authorised resolution to company_service so future
 * Cloud/API clients have one narrow company-scope boundary.
 */
class company_context_service {
    public const CONTRACT_VERSION = '0.6.4';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'identity' => 'company_uuid',
            'security_boundary' => 'company_uuid',
            'source' => 'local_kbacompany.company_service',
            'rules' => [
                'company_uuid_is_required_for_company_scoped_platform_operations',
                'company_uuid_is_normalised_before_lookup',
                'authorised_user_context_is_resolved_server_side',
                'site_admin_bypass_remains_explicit_in_existing_permission_policy',
                'external_platform_services_must_not_query_company_business_tables_without_scope',
            ],
        ];
    }

    /** Normalise and validate a stable UUID v4. */
    public static function normalise_uuid(string $uuid): string {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new \invalid_parameter_exception('Invalid BMS Company UUID.');
        }
        return $uuid;
    }

    /** Whether the UUID resolves to either the canonical registry or a preserved legacy identity. */
    public static function exists(string $companyuuid): bool {
        $companyuuid = self::normalise_uuid($companyuuid);
        if (company_registry_service::by_uuid($companyuuid)) {
            return true;
        }
        $identity = identity_registry::resolve($companyuuid);
        return $identity &&
            (string)$identity->sourcecomponent === identity_registry::SOURCE_COMPONENT &&
            (string)$identity->sourceentity === identity_registry::COMPANY_ENTITY;
    }

    /** Require that the Company UUID exists and return its normalised form. */
    public static function require_exists(string $companyuuid): string {
        $companyuuid = self::normalise_uuid($companyuuid);
        if (!self::exists($companyuuid)) {
            throw new \moodle_exception('companynotavailable', 'local_kbacompany');
        }
        return $companyuuid;
    }

    /** Resolve one authorised company context for one Moodle user. */
    public static function context(string $companyuuid, int $userid): array {
        $companyuuid = self::normalise_uuid($companyuuid);
        return company_service::company_context($companyuuid, $userid);
    }

    /** Assert that two references belong to the same company boundary. */
    public static function assert_same(string $expectedcompanyuuid, string $actualcompanyuuid): void {
        $expectedcompanyuuid = self::normalise_uuid($expectedcompanyuuid);
        $actualcompanyuuid = self::normalise_uuid($actualcompanyuuid);
        if (!hash_equals($expectedcompanyuuid, $actualcompanyuuid)) {
            throw new \moodle_exception('companynotavailable', 'local_kbacompany');
        }
    }
}
