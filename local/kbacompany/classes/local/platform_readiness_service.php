<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Pre-0.7 architecture readiness gate for one BMS Company.
 *
 * This is deliberately a platform diagnostic, not a commercial entitlement
 * decision. Legacy-compatible companies are allowed to proceed with a warning;
 * Cloud-cutover companies must have a usable local entitlement snapshot.
 */
class platform_readiness_service {
    public const CONTRACT_VERSION = '0.6.9';

    public static function contract(): array {
        return [
            'version' => self::CONTRACT_VERSION,
            'purpose' => 'pre_0_7_architecture_stabilization_gate',
            'statuses' => ['ready', 'ready_with_warnings', 'blocked'],
            'check_statuses' => ['pass', 'warning', 'block'],
            'checks' => [
                'company_context',
                'workspace_projection_storage',
                'event_outbox_storage',
                'entitlement_snapshot_storage',
                'workspace_binding',
                'workspace_projection_freshness',
                'entitlement_runtime',
                'provider_neutral_outbox_health',
            ],
            'rules' => [
                'legacy_unmapped_company_is_allowed_with_warning',
                'cloud_snapshot_mode_requires_usable_last_known_good_snapshot',
                'storage_contract_failures_block_pre_0_7_readiness',
                'provider_neutral_outbox_errors_warn_but_do_not_hide_business_data',
                'readiness_does_not_replace_acl_or_entitlement_checks',
                'readiness_is_diagnostic_and_does_not_mutate_company_or_cloud_state',
            ],
        ];
    }

    /** Return one deterministic architecture gate result for a canonical Company UUID. */
    public static function evaluate(string $companyuuid): array {
        $companyuuid = company_context_service::require_exists($companyuuid);
        $checks = [];

        self::add($checks, 'company_context', 'pass', 'COMPANY_CONTEXT_READY');

        self::add($checks, 'workspace_projection_storage',
            workspace_mapping_service::ready() ? 'pass' : 'block',
            workspace_mapping_service::ready() ? 'WORKSPACE_STORAGE_READY' : 'WORKSPACE_STORAGE_NOT_READY');

        self::add($checks, 'event_outbox_storage',
            integration_event_service::ready() ? 'pass' : 'block',
            integration_event_service::ready() ? 'EVENT_OUTBOX_READY' : 'EVENT_OUTBOX_NOT_READY');

        self::add($checks, 'entitlement_snapshot_storage',
            entitlement_snapshot_service::ready() ? 'pass' : 'block',
            entitlement_snapshot_service::ready() ? 'ENTITLEMENT_SNAPSHOT_STORAGE_READY' :
                'ENTITLEMENT_SNAPSHOT_STORAGE_NOT_READY');

        $workspace = workspace_mapping_service::status($companyuuid);
        if (!empty($workspace['mapped'])) {
            self::add($checks, 'workspace_binding', 'pass', 'WORKSPACE_MAPPED', [
                'workspace_uuid' => (string)$workspace['workspace_uuid'],
                'projection_revision' => (int)$workspace['projection_revision'],
            ]);
            $freshness = (string)$workspace['sync_freshness'];
            self::add($checks, 'workspace_projection_freshness',
                $freshness === 'stale' ? 'warning' : 'pass',
                $freshness === 'stale' ? 'WORKSPACE_PROJECTION_STALE' : 'WORKSPACE_PROJECTION_FRESH', [
                    'freshness' => $freshness,
                    'age_seconds' => (int)$workspace['sync_age_seconds'],
                ]);
        } else {
            self::add($checks, 'workspace_binding', 'warning', 'LEGACY_COMPANY_NOT_CLOUD_MAPPED');
            self::add($checks, 'workspace_projection_freshness', 'pass', 'WORKSPACE_PROJECTION_NOT_REQUIRED_IN_LEGACY');
        }

        $mode = (string)$workspace['entitlement_mode'];
        $snapshot = entitlement_snapshot_service::diagnostics($companyuuid);
        if ($mode === workspace_mapping_service::MODE_LEGACY) {
            self::add($checks, 'entitlement_runtime', 'pass', 'LEGACY_COMPATIBILITY_ACTIVE', [
                'mode' => $mode,
            ]);
        } else if ($mode === workspace_mapping_service::MODE_CLOUD) {
            $state = (string)$snapshot['state'];
            if ($state === 'valid') {
                self::add($checks, 'entitlement_runtime', 'pass', 'CLOUD_SNAPSHOT_VALID', [
                    'mode' => $mode,
                    'snapshot_revision' => (string)$snapshot['snapshot_revision'],
                    'snapshot_state' => $state,
                ]);
            } else if ($state === 'stale_grace') {
                self::add($checks, 'entitlement_runtime', 'warning', 'CLOUD_SNAPSHOT_STALE_LAST_KNOWN_GOOD', [
                    'mode' => $mode,
                    'snapshot_revision' => (string)$snapshot['snapshot_revision'],
                    'snapshot_state' => $state,
                ]);
            } else {
                self::add($checks, 'entitlement_runtime', 'block', 'CLOUD_SNAPSHOT_NOT_USABLE', [
                    'mode' => $mode,
                    'snapshot_revision' => (string)$snapshot['snapshot_revision'],
                    'snapshot_state' => $state,
                ]);
            }
        } else if ($mode === workspace_mapping_service::MODE_SUSPENDED ||
                (string)$workspace['cloud_status'] === 'suspended') {
            self::add($checks, 'entitlement_runtime', 'warning', 'WORKSPACE_SUSPENDED', [
                'mode' => $mode,
            ]);
        } else {
            self::add($checks, 'entitlement_runtime', 'block', 'UNKNOWN_ENTITLEMENT_MODE', [
                'mode' => $mode,
            ]);
        }

        $events = integration_event_service::diagnostics($companyuuid);
        $eventerrors = (int)$events['summary']['error'];
        self::add($checks, 'provider_neutral_outbox_health',
            $eventerrors > 0 ? 'warning' : 'pass',
            $eventerrors > 0 ? 'EVENT_OUTBOX_HAS_ERRORS' : 'EVENT_OUTBOX_HEALTHY', [
                'pending' => (int)$events['summary']['pending'],
                'error' => $eventerrors,
                'done' => (int)$events['summary']['done'],
            ]);

        $summary = ['pass' => 0, 'warning' => 0, 'block' => 0];
        foreach ($checks as $check) {
            $summary[$check['status']]++;
        }
        $status = $summary['block'] > 0 ? 'blocked' : ($summary['warning'] > 0 ? 'ready_with_warnings' : 'ready');
        $result = [
            'contract_version' => self::CONTRACT_VERSION,
            'company_uuid' => $companyuuid,
            'workspace_uuid' => (string)$workspace['workspace_uuid'],
            'status' => $status,
            'pre_0_7_ready' => $summary['block'] === 0,
            'summary' => $summary,
            'checks' => $checks,
            'next_action' => self::next_action($checks),
        ];
        $result['result_hash'] = hash('sha256', json_encode($result,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $result;
    }

    private static function add(array &$checks, string $key, string $status, string $code,
            array $details = []): void {
        $checks[] = [
            'key' => $key,
            'status' => $status,
            'code' => $code,
            'details' => $details,
        ];
    }

    private static function next_action(array $checks): string {
        foreach ($checks as $check) {
            if ($check['status'] === 'block') {
                return (string)$check['code'];
            }
        }
        foreach ($checks as $check) {
            if ($check['status'] === 'warning') {
                return (string)$check['code'];
            }
        }
        return 'START_0_7_DOMAIN_WORK';
    }
}
