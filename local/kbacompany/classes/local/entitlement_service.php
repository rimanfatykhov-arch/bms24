<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Commercial entitlement boundary for BMS Management. */
class entitlement_service {
    public const CONTRACT_VERSION = '0.6.7';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'owner' => 'bms_cloud_entitlement_service',
            'default_mode' => workspace_mapping_service::MODE_LEGACY,
            'snapshot_contract' => entitlement_snapshot_service::contract(),
            'check_order' => ['entitlement', 'company_scope', 'moodle_capability', 'business_rule'],
            'rules' => [
                'edition_names_are_not_business_conditions',
                'entitlement_is_not_acl',
                'feature_flags_are_separate_from_entitlements',
                'existing_companies_are_legacy_compatible_until_explicit_cloud_cutover',
                'cloud_snapshot_mode_uses_local_last_known_good_without_synchronous_cloud_request',
                'cloud_snapshot_mode_fails_closed_without_a_usable_snapshot',
            ],
        ];
    }

    public static function has(string $companyuuid, string $code): bool {
        return !empty(self::explain($companyuuid, $code)['allowed']);
    }

    public static function explain(string $companyuuid, string $code): array {
        $companyuuid = company_context_service::require_exists($companyuuid);
        $code = self::normalise_code($code);
        $workspace = workspace_mapping_service::status($companyuuid);
        $mode = (string)$workspace['entitlement_mode'];

        if ((string)$workspace['cloud_status'] === 'suspended' || $mode === workspace_mapping_service::MODE_SUSPENDED) {
            return self::result($companyuuid, $code, $workspace, false, 'WORKSPACE_SUSPENDED');
        }
        if ($mode === workspace_mapping_service::MODE_LEGACY) {
            return self::result($companyuuid, $code, $workspace, true, 'LEGACY_COMPATIBILITY');
        }
        if ($mode === workspace_mapping_service::MODE_CLOUD) {
            $resolved = entitlement_snapshot_service::resolve($companyuuid, $code);
            return self::result($companyuuid, $code, $workspace, !empty($resolved['allowed']),
                (string)$resolved['reason_code'], $resolved);
        }
        return self::result($companyuuid, $code, $workspace, false, 'UNKNOWN_ENTITLEMENT_MODE');
    }

    private static function result(string $companyuuid, string $code, array $workspace,
            bool $allowed, string $reason, array $snapshot = []): array {
        return [
            'company_uuid' => $companyuuid,
            'workspace_uuid' => (string)$workspace['workspace_uuid'],
            'code' => $code,
            'allowed' => $allowed,
            'mode' => (string)$workspace['entitlement_mode'],
            'cloud_status' => (string)$workspace['cloud_status'],
            'snapshot_revision' => (string)($snapshot['snapshot_revision'] ?? $workspace['snapshot_revision']),
            'snapshot_state' => (string)($snapshot['snapshot_state'] ?? 'none'),
            'catalog_version' => (string)($snapshot['catalog_version'] ?? ''),
            'resolver_version' => (string)($snapshot['resolver_version'] ?? ''),
            'valid_until' => (int)($snapshot['valid_until'] ?? 0),
            'entitlement_state' => (string)($snapshot['entitlement_state'] ?? ''),
            'value' => $snapshot['value'] ?? null,
            'reason_code' => $reason,
        ];
    }

    private static function normalise_code(string $code): string {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $code)) {
            throw new \invalid_parameter_exception('Invalid BMS entitlement code.');
        }
        return $code;
    }
}
