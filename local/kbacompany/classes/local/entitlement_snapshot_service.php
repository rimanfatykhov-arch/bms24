<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Local last-known-good cache for BMS Cloud entitlement snapshots.
 *
 * BMS Cloud owns entitlement decisions. Moodle stores only a validated runtime
 * snapshot so normal page requests never require a synchronous Cloud call.
 */
class entitlement_snapshot_service {
    public const CONTRACT_VERSION = '0.6.7';
    public const SNAPSHOT_SCHEMA_VERSION = '1.0';
    public const TABLE = 'local_kbacompany_entsnap';
    public const STALE_GRACE_SECONDS = 86400; // One-day continuity window after valid_until.

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'owner' => 'bms_cloud_entitlement_service',
            'moodle_role' => 'validated_last_known_good_cache',
            'storage' => self::TABLE,
            'scope' => 'workspace_company_projection',
            'hash_algorithm' => 'sha256',
            'stale_grace_seconds' => self::STALE_GRACE_SECONDS,
            'transport_authentication' => 'moodle_restricted_service_user_capability',
            'rules' => [
                'snapshot_apply_does_not_enable_cloud_enforcement_automatically',
                'workspace_mapping_must_exist_and_match',
                'same_revision_with_different_hash_is_rejected',
                'older_generated_snapshot_cannot_replace_active_snapshot',
                'only_mgmt_namespace_is_accepted_in_bms_management_snapshot_v1',
                'valid_snapshot_is_used_without_synchronous_cloud_call',
                'stale_last_known_good_is_allowed_for_mgmt_namespace_only_within_local_grace',
                'expired_snapshot_beyond_grace_fails_closed_in_cloud_snapshot_mode',
                'legacy_compatibility_mode_ignores_snapshot_for_enforcement',
            ],
            'required_fields' => [
                'schema_version', 'workspace_uuid', 'company_uuid', 'snapshot_revision',
                'catalog_version', 'resolver_version', 'generated_at', 'valid_until',
                'entitlements', 'snapshot_hash',
            ],
        ];
    }

    public static function ready(): bool {
        global $DB;
        return $DB->get_manager()->table_exists(new \xmldb_table(self::TABLE));
    }

    /**
     * Validate and persist one Cloud snapshot. Idempotent for the same revision/hash.
     * Applying a snapshot never switches entitlement mode from legacy to cloud.
     */
    public static function apply(array $snapshot, int $actorid = 0): array {
        global $DB;
        if (!self::ready()) {
            throw new \moodle_exception('bmsplatformnotready', 'local_kbacompany');
        }

        $normal = self::normalise_snapshot($snapshot);
        $companyuuid = company_context_service::require_exists($normal['company_uuid']);
        $workspace = workspace_mapping_service::status($companyuuid);
        if (empty($workspace['mapped'])) {
            throw new \invalid_parameter_exception('BMS Cloud Workspace mapping is required before applying a snapshot.');
        }
        if (!hash_equals((string)$workspace['workspace_uuid'], $normal['workspace_uuid'])) {
            throw new \invalid_parameter_exception('Entitlement snapshot Workspace UUID does not match Company mapping.');
        }

        $existing = $DB->get_record(self::TABLE, [
            'workspaceuuid' => $normal['workspace_uuid'],
            'snapshotrevision' => $normal['snapshot_revision'],
        ]);
        if ($existing) {
            if (!hash_equals((string)$existing->snapshothash, $normal['snapshot_hash'])) {
                throw new \invalid_parameter_exception('Snapshot revision conflict: the same revision has a different hash.');
            }
            return self::row_result($existing, true);
        }

        $active = self::active_row($companyuuid);
        if ($active && (int)$normal['generated_at'] < (int)$active->generatedat) {
            throw new \invalid_parameter_exception('Entitlement snapshot is older than or equal to the active snapshot.');
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        if ($active) {
            $active->status = 'superseded';
            $active->supersededat = $now;
            $active->timemodified = $now;
            $DB->update_record(self::TABLE, $active);
        }

        $id = $DB->insert_record(self::TABLE, (object)[
            'companyuuid' => $companyuuid,
            'workspaceuuid' => $normal['workspace_uuid'],
            'snapshotrevision' => $normal['snapshot_revision'],
            'catalogversion' => $normal['catalog_version'],
            'resolverversion' => $normal['resolver_version'],
            'generatedat' => $normal['generated_at'],
            'validuntil' => $normal['valid_until'],
            'snapshotjson' => self::encode_json($normal['canonical']),
            'snapshothash' => $normal['snapshot_hash'],
            'status' => 'active',
            'receivedat' => $now,
            'activatedat' => $now,
            'supersededat' => 0,
            'createdby' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        workspace_mapping_service::record_snapshot_revision($companyuuid, $normal['snapshot_revision'], $actorid);
        $transaction->allow_commit();
        return self::row_result($DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST), false);
    }

    /** Resolve one entitlement from the locally cached snapshot. */
    public static function resolve(string $companyuuid, string $code): array {
        $companyuuid = company_context_service::require_exists($companyuuid);
        $code = self::normalise_code($code);
        $row = self::active_row($companyuuid);
        if (!$row) {
            return self::resolution($code, false, 'SNAPSHOT_NOT_AVAILABLE');
        }

        $state = self::row_state($row);
        if ($state === 'future') {
            return self::resolution($code, false, 'SNAPSHOT_NOT_YET_VALID', $row);
        }
        if ($state === 'expired') {
            return self::resolution($code, false, 'SNAPSHOT_EXPIRED', $row);
        }

        $snapshot = json_decode((string)$row->snapshotjson, true);
        if (!is_array($snapshot) || !isset($snapshot['entitlements']) || !is_array($snapshot['entitlements'])) {
            return self::resolution($code, false, 'SNAPSHOT_INVALID_LOCAL_CACHE', $row);
        }
        if (!array_key_exists($code, $snapshot['entitlements'])) {
            return self::resolution($code, false, 'NOT_ASSIGNED', $row);
        }
        $entry = $snapshot['entitlements'][$code];
        $allowed = !empty($entry['allowed']);
        $reason = $allowed ? 'ENABLED_SNAPSHOT' : 'DISABLED_SNAPSHOT';
        if ($state === 'stale_grace') {
            // Runtime continuity is intentionally limited to management capabilities.
            if (strpos($code, 'mgmt.') !== 0) {
                return self::resolution($code, false, 'SNAPSHOT_EXPIRED', $row);
            }
            $reason = $allowed ? 'ENABLED_STALE_LAST_KNOWN_GOOD' : 'DISABLED_STALE_LAST_KNOWN_GOOD';
        }
        $result = self::resolution($code, $allowed, $reason, $row);
        $result['entitlement_state'] = (string)$entry['state'];
        $result['value'] = $entry['value'];
        return $result;
    }

    public static function diagnostics(string $companyuuid): array {
        $companyuuid = company_context_service::require_exists($companyuuid);
        $workspace = workspace_mapping_service::status($companyuuid);
        $row = self::active_row($companyuuid);
        if (!$row) {
            return [
                'ready' => self::ready(),
                'company_uuid' => $companyuuid,
                'workspace_uuid' => (string)$workspace['workspace_uuid'],
                'enforcement_mode' => (string)$workspace['entitlement_mode'],
                'state' => 'none',
                'snapshot_revision' => '',
                'catalog_version' => '',
                'resolver_version' => '',
                'generated_at' => 0,
                'valid_until' => 0,
                'age_seconds' => 0,
                'remaining_seconds' => 0,
                'stale_grace_seconds' => self::STALE_GRACE_SECONDS,
                'entitlements_count' => 0,
                'snapshot_hash' => '',
            ];
        }
        $snapshot = json_decode((string)$row->snapshotjson, true);
        $count = is_array($snapshot) && isset($snapshot['entitlements']) && is_array($snapshot['entitlements'])
            ? count($snapshot['entitlements']) : 0;
        $now = time();
        return [
            'ready' => true,
            'company_uuid' => $companyuuid,
            'workspace_uuid' => (string)$row->workspaceuuid,
            'enforcement_mode' => (string)$workspace['entitlement_mode'],
            'state' => self::row_state($row),
            'snapshot_revision' => (string)$row->snapshotrevision,
            'catalog_version' => (string)$row->catalogversion,
            'resolver_version' => (string)$row->resolverversion,
            'generated_at' => (int)$row->generatedat,
            'valid_until' => (int)$row->validuntil,
            'age_seconds' => max(0, $now - (int)$row->generatedat),
            'remaining_seconds' => (int)$row->validuntil - $now,
            'stale_grace_seconds' => self::STALE_GRACE_SECONDS,
            'entitlements_count' => $count,
            'snapshot_hash' => (string)$row->snapshothash,
        ];
    }

    public static function active_row(string $companyuuid): ?\stdClass {
        global $DB;
        if (!self::ready()) {
            return null;
        }
        $row = $DB->get_record(self::TABLE, ['companyuuid' => $companyuuid, 'status' => 'active']);
        return $row ?: null;
    }

    private static function normalise_snapshot(array $snapshot): array {
        $schema = trim((string)($snapshot['schema_version'] ?? ''));
        if ($schema !== self::SNAPSHOT_SCHEMA_VERSION) {
            throw new \invalid_parameter_exception('Unsupported BMS entitlement snapshot schema version.');
        }
        $workspaceuuid = self::normalise_uuid((string)($snapshot['workspace_uuid'] ?? ''), 'Workspace');
        $companyuuid = self::normalise_uuid((string)($snapshot['company_uuid'] ?? ''), 'Company');
        $revision = trim((string)($snapshot['snapshot_revision'] ?? ''));
        $catalog = trim((string)($snapshot['catalog_version'] ?? ''));
        $resolver = trim((string)($snapshot['resolver_version'] ?? ''));
        if ($revision === '' || \core_text::strlen($revision) > 100) {
            throw new \invalid_parameter_exception('Invalid entitlement snapshot revision.');
        }
        if ($catalog === '' || \core_text::strlen($catalog) > 50 || \core_text::strlen($resolver) > 50) {
            throw new \invalid_parameter_exception('Invalid entitlement snapshot catalog/resolver version.');
        }
        $generated = (int)($snapshot['generated_at'] ?? 0);
        $validuntil = (int)($snapshot['valid_until'] ?? 0);
        if ($generated <= 0 || $validuntil <= $generated) {
            throw new \invalid_parameter_exception('Invalid entitlement snapshot validity period.');
        }
        $rawentitlements = $snapshot['entitlements'] ?? null;
        if (!is_array($rawentitlements)) {
            throw new \invalid_parameter_exception('Entitlement snapshot entitlements must be an object/map.');
        }
        $entitlements = [];
        foreach ($rawentitlements as $code => $entry) {
            $code = self::normalise_code((string)$code);
            if (strpos($code, 'mgmt.') !== 0) {
                throw new \invalid_parameter_exception('BMS Management snapshot v1 accepts only mgmt.* entitlement codes.');
            }
            if (is_bool($entry)) {
                $entry = ['allowed' => $entry, 'state' => $entry ? 'enabled' : 'disabled', 'value' => null];
            }
            if (!is_array($entry) || !array_key_exists('allowed', $entry) || !is_bool($entry['allowed'])) {
                throw new \invalid_parameter_exception('Invalid entitlement snapshot entry for ' . $code . '.');
            }
            $state = strtolower(trim((string)($entry['state'] ?? (!empty($entry['allowed']) ? 'enabled' : 'disabled'))));
            if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $state)) {
                throw new \invalid_parameter_exception('Invalid entitlement state for ' . $code . '.');
            }
            $value = $entry['value'] ?? null;
            if (is_array($value) || is_object($value) || is_resource($value)) {
                throw new \invalid_parameter_exception('Entitlement value must be scalar or null.');
            }
            $entitlements[$code] = [
                'allowed' => !empty($entry['allowed']),
                'state' => $state,
                'value' => $value,
            ];
        }
        ksort($entitlements, SORT_STRING);
        $canonical = [
            'schema_version' => self::SNAPSHOT_SCHEMA_VERSION,
            'workspace_uuid' => $workspaceuuid,
            'company_uuid' => $companyuuid,
            'snapshot_revision' => $revision,
            'catalog_version' => $catalog,
            'resolver_version' => $resolver,
            'generated_at' => $generated,
            'valid_until' => $validuntil,
            'entitlements' => $entitlements,
        ];
        $calculatedhash = hash('sha256', self::encode_json($canonical));
        $suppliedhash = strtolower(trim((string)($snapshot['snapshot_hash'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $suppliedhash) || !hash_equals($calculatedhash, $suppliedhash)) {
            throw new \invalid_parameter_exception('Entitlement snapshot hash mismatch.');
        }
        return [
            'workspace_uuid' => $workspaceuuid,
            'company_uuid' => $companyuuid,
            'snapshot_revision' => $revision,
            'catalog_version' => $catalog,
            'resolver_version' => $resolver,
            'generated_at' => $generated,
            'valid_until' => $validuntil,
            'snapshot_hash' => $calculatedhash,
            'canonical' => $canonical,
        ];
    }

    private static function row_state(\stdClass $row): string {
        $now = time();
        if ((int)$row->generatedat > $now) {
            return 'future';
        }
        if ((int)$row->validuntil >= $now) {
            return 'valid';
        }
        if (((int)$row->validuntil + self::STALE_GRACE_SECONDS) >= $now) {
            return 'stale_grace';
        }
        return 'expired';
    }

    private static function resolution(string $code, bool $allowed, string $reason, ?\stdClass $row = null): array {
        return [
            'code' => $code,
            'allowed' => $allowed,
            'reason_code' => $reason,
            'snapshot_state' => $row ? self::row_state($row) : 'none',
            'snapshot_revision' => $row ? (string)$row->snapshotrevision : '',
            'catalog_version' => $row ? (string)$row->catalogversion : '',
            'resolver_version' => $row ? (string)$row->resolverversion : '',
            'generated_at' => $row ? (int)$row->generatedat : 0,
            'valid_until' => $row ? (int)$row->validuntil : 0,
            'snapshot_hash' => $row ? (string)$row->snapshothash : '',
            'entitlement_state' => '',
            'value' => null,
        ];
    }

    private static function row_result(\stdClass $row, bool $idempotent): array {
        return [
            'idempotent' => $idempotent,
            'company_uuid' => (string)$row->companyuuid,
            'workspace_uuid' => (string)$row->workspaceuuid,
            'snapshot_revision' => (string)$row->snapshotrevision,
            'catalog_version' => (string)$row->catalogversion,
            'resolver_version' => (string)$row->resolverversion,
            'generated_at' => (int)$row->generatedat,
            'valid_until' => (int)$row->validuntil,
            'snapshot_hash' => (string)$row->snapshothash,
            'status' => (string)$row->status,
            'received_at' => (int)$row->receivedat,
        ];
    }

    private static function normalise_code(string $code): string {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $code)) {
            throw new \invalid_parameter_exception('Invalid BMS entitlement code.');
        }
        return $code;
    }

    private static function normalise_uuid(string $uuid, string $label): string {
        $uuid = strtolower(trim($uuid));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new \invalid_parameter_exception('Invalid BMS ' . $label . ' UUID.');
        }
        return $uuid;
    }

    private static function encode_json(array $value): string {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \invalid_parameter_exception('BMS entitlement snapshot is not JSON serializable.');
        }
        return $json;
    }
}
