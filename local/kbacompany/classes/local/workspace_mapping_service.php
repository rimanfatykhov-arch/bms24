<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Local projection of the external BMS Cloud Workspace identity and runtime state.
 *
 * BMS Cloud owns Workspace/application lifecycle. Moodle stores only the minimum
 * mapping and diagnostics cache required by BMS Management runtime.
 */
class workspace_mapping_service {
    public const CONTRACT_VERSION = '0.6.8';
    public const PROJECTION_SCHEMA_VERSION = '1.0';
    public const TABLE = 'local_kbacompany_cloudmap';
    public const MODE_LEGACY = 'legacy_compatibility';
    public const MODE_CLOUD = 'cloud_snapshot';
    public const MODE_SUSPENDED = 'suspended';
    public const SYNC_STALE_AFTER = 21600; // Diagnostic threshold only: six hours.

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'projection_schema_version' => self::PROJECTION_SCHEMA_VERSION,
            'owner' => 'bms_cloud_control_plane',
            'moodle_role' => 'projection_cache',
            'storage' => self::TABLE,
            'keys' => ['company_uuid', 'workspace_uuid'],
            'projection_fields' => [
                'projection_revision', 'cloud_status', 'entitlement_mode',
                'erp_application_state', 'integration_health',
            ],
            'diagnostics' => [
                'last_sync', 'sync_age_seconds', 'sync_freshness', 'snapshot_revision',
                'erp_application_state', 'integration_health',
            ],
            'rules' => [
                'workspace_is_not_a_copy_of_company',
                'workspace_uuid_is_external_control_plane_identity',
                'moodle_does_not_own_workspace_lifecycle',
                'unmapped_companies_remain_legacy_compatible',
                'company_workspace_binding_is_stable_once_created',
                'projection_revision_is_monotonic',
                'provider_specific_ids_are_not_stored_in_workspace_projection',
            ],
        ];
    }

    public static function ready(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table(self::TABLE));
    }

    /** Return mapping state without requiring the caller to special-case pre-Cloud companies. */
    public static function status(string $companyuuid): array {
        global $DB;
        $companyuuid = company_context_service::require_exists($companyuuid);
        if (!self::ready()) {
            return self::unmapped($companyuuid, 'schema_not_ready');
        }
        $row = $DB->get_record(self::TABLE, ['companyuuid' => $companyuuid]);
        if (!$row) {
            return self::unmapped($companyuuid, 'not_mapped');
        }
        $lastsync = (int)$row->lastsync;
        $age = $lastsync > 0 ? max(0, time() - $lastsync) : 0;
        return [
            'mapped' => true,
            'company_uuid' => $companyuuid,
            'workspace_uuid' => (string)$row->workspaceuuid,
            'projection_revision' => property_exists($row, 'cloudrevision') ? (int)$row->cloudrevision : 0,
            'cloud_status' => (string)$row->cloudstatus,
            'entitlement_mode' => (string)$row->entitlementmode,
            'snapshot_revision' => (string)$row->snapshotrevision,
            'erp_application_state' => property_exists($row, 'erpappstate') ? (string)$row->erpappstate : 'not_provisioned',
            'integration_health' => property_exists($row, 'integrationhealth') ? (string)$row->integrationhealth : 'unknown',
            'last_sync' => $lastsync,
            'sync_age_seconds' => $age,
            'sync_freshness' => self::freshness($lastsync, $age),
            'time_modified' => (int)$row->timemodified,
            'reason' => 'mapped',
        ];
    }

    /**
     * Bind/update the local projection. The Company↔Workspace identity is stable:
     * a different Workspace UUID cannot silently replace an existing binding.
     */
    public static function bind(string $companyuuid, string $workspaceuuid, string $cloudstatus = 'active',
            string $entitlementmode = self::MODE_LEGACY, string $snapshotrevision = '', int $actorid = 0,
            int $projectionrevision = 0, string $erpappstate = 'not_provisioned',
            string $integrationhealth = 'unknown'): void {
        global $DB;
        $companyuuid = company_context_service::require_exists($companyuuid);
        $workspaceuuid = self::normalise_uuid($workspaceuuid, 'Workspace');
        self::validate_status($cloudstatus);
        self::validate_mode($entitlementmode);
        self::validate_erp_state($erpappstate);
        self::validate_integration_health($integrationhealth);
        if ($projectionrevision < 0) {
            throw new \invalid_parameter_exception('Invalid BMS Cloud projection revision.');
        }
        if (!self::ready()) {
            throw new \moodle_exception('bmsplatformnotready', 'local_kbacompany');
        }

        $byworkspace = $DB->get_record(self::TABLE, ['workspaceuuid' => $workspaceuuid]);
        if ($byworkspace && (string)$byworkspace->companyuuid !== $companyuuid) {
            throw new \moodle_exception('bmsplatformworkspaceduplicate', 'local_kbacompany');
        }

        $now = time();
        $row = $DB->get_record(self::TABLE, ['companyuuid' => $companyuuid]);
        if ($row) {
            if (!hash_equals((string)$row->workspaceuuid, $workspaceuuid)) {
                throw new \invalid_parameter_exception('BMS Company is already bound to a different Workspace UUID.');
            }
            $currentrevision = property_exists($row, 'cloudrevision') ? (int)$row->cloudrevision : 0;
            if ($projectionrevision > 0 && $currentrevision > 0 && $projectionrevision < $currentrevision) {
                throw new \invalid_parameter_exception('Stale BMS Cloud projection revision.');
            }
            $row->cloudstatus = $cloudstatus;
            $row->entitlementmode = $entitlementmode;
            $row->snapshotrevision = trim($snapshotrevision);
            if (property_exists($row, 'cloudrevision')) {
                $row->cloudrevision = max($currentrevision, $projectionrevision);
            }
            if (property_exists($row, 'erpappstate')) {
                $row->erpappstate = $erpappstate;
            }
            if (property_exists($row, 'integrationhealth')) {
                $row->integrationhealth = $integrationhealth;
            }
            $row->lastsync = $now;
            $row->modifiedby = $actorid;
            $row->timemodified = $now;
            $DB->update_record(self::TABLE, $row);
            return;
        }

        $record = (object)[
            'companyuuid' => $companyuuid,
            'workspaceuuid' => $workspaceuuid,
            'cloudstatus' => $cloudstatus,
            'entitlementmode' => $entitlementmode,
            'snapshotrevision' => trim($snapshotrevision),
            'lastsync' => $now,
            'createdby' => $actorid,
            'modifiedby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        if (self::has_field('cloudrevision')) {
            $record->cloudrevision = $projectionrevision;
        }
        if (self::has_field('erpappstate')) {
            $record->erpappstate = $erpappstate;
        }
        if (self::has_field('integrationhealth')) {
            $record->integrationhealth = $integrationhealth;
        }
        $DB->insert_record(self::TABLE, $record);
    }

    /** Apply one versioned Cloud projection payload. */
    public static function apply_projection(array $projection, int $actorid = 0): array {
        $schema = trim((string)($projection['schema_version'] ?? ''));
        if ($schema !== self::PROJECTION_SCHEMA_VERSION) {
            throw new \invalid_parameter_exception('Unsupported BMS Cloud workspace projection schema version.');
        }
        $companyuuid = company_context_service::require_exists((string)($projection['company_uuid'] ?? ''));
        $workspaceuuid = self::normalise_uuid((string)($projection['workspace_uuid'] ?? ''), 'Workspace');
        $revision = (int)($projection['projection_revision'] ?? 0);
        if ($revision < 1) {
            throw new \invalid_parameter_exception('BMS Cloud projection revision must be a positive integer.');
        }
        $cloudstatus = trim((string)($projection['cloud_status'] ?? 'active'));
        $mode = trim((string)($projection['entitlement_mode'] ?? self::MODE_LEGACY));
        $snapshotrevision = trim((string)($projection['snapshot_revision'] ?? ''));
        $erpstate = trim((string)($projection['erp_application_state'] ?? 'not_provisioned'));
        $integrationhealth = trim((string)($projection['integration_health'] ?? 'unknown'));

        self::bind($companyuuid, $workspaceuuid, $cloudstatus, $mode, $snapshotrevision, $actorid,
            $revision, $erpstate, $integrationhealth);
        return self::status($companyuuid);
    }

    /** Record the accepted local snapshot revision without changing entitlement enforcement mode. */
    public static function record_snapshot_revision(string $companyuuid, string $snapshotrevision, int $actorid = 0): void {
        global $DB;
        $companyuuid = company_context_service::require_exists($companyuuid);
        $snapshotrevision = trim($snapshotrevision);
        if ($snapshotrevision === '' || \core_text::strlen($snapshotrevision) > 100) {
            throw new \invalid_parameter_exception('Invalid BMS entitlement snapshot revision.');
        }
        if (!self::ready()) {
            throw new \moodle_exception('bmsplatformnotready', 'local_kbacompany');
        }
        $row = $DB->get_record(self::TABLE, ['companyuuid' => $companyuuid], '*', MUST_EXIST);
        $row->snapshotrevision = $snapshotrevision;
        $row->lastsync = time();
        $row->modifiedby = $actorid;
        $row->timemodified = time();
        $DB->update_record(self::TABLE, $row);
    }

    private static function unmapped(string $companyuuid, string $reason): array {
        return [
            'mapped' => false,
            'company_uuid' => $companyuuid,
            'workspace_uuid' => '',
            'projection_revision' => 0,
            'cloud_status' => 'unmapped',
            'entitlement_mode' => self::MODE_LEGACY,
            'snapshot_revision' => '',
            'erp_application_state' => 'not_provisioned',
            'integration_health' => 'unknown',
            'last_sync' => 0,
            'sync_age_seconds' => 0,
            'sync_freshness' => 'never',
            'time_modified' => 0,
            'reason' => $reason,
        ];
    }

    private static function freshness(int $lastsync, int $age): string {
        if ($lastsync <= 0) {
            return 'never';
        }
        return $age <= self::SYNC_STALE_AFTER ? 'fresh' : 'stale';
    }

    private static function validate_status(string $status): void {
        if (!in_array($status, ['pending', 'active', 'degraded', 'suspended', 'closed', 'error'], true)) {
            throw new \invalid_parameter_exception('Unsupported BMS Cloud workspace status.');
        }
    }

    private static function validate_mode(string $mode): void {
        if (!in_array($mode, [self::MODE_LEGACY, self::MODE_CLOUD, self::MODE_SUSPENDED], true)) {
            throw new \invalid_parameter_exception('Unsupported BMS entitlement mode.');
        }
    }

    private static function validate_erp_state(string $state): void {
        if (!in_array($state, ['not_provisioned', 'provisioning', 'ready', 'active', 'degraded',
                'suspended', 'failed', 'retired'], true)) {
            throw new \invalid_parameter_exception('Unsupported BMS ERP application state.');
        }
    }

    private static function validate_integration_health(string $health): void {
        if (!in_array($health, ['unknown', 'healthy', 'degraded', 'failed', 'blocked', 'suspended', 'closed'], true)) {
            throw new \invalid_parameter_exception('Unsupported BMS integration health state.');
        }
    }

    private static function normalise_uuid(string $uuid, string $label): string {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new \invalid_parameter_exception('Invalid BMS ' . $label . ' UUID.');
        }
        return $uuid;
    }

    private static function has_field(string $name): bool {
        global $DB;
        $columns = $DB->get_columns(self::TABLE);
        return isset($columns[$name]);
    }
}
