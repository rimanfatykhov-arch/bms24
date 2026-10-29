<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\entitlement_service;
use local_kbacompany\local\entitlement_snapshot_service;
use local_kbacompany\local\workspace_mapping_service;

/** v0.6.7 entitlement snapshot cache and resolver regression tests. */
final class entitlement_snapshot_service_test extends \advanced_testcase {
    public function test_applying_snapshot_does_not_switch_legacy_company_to_cloud_enforcement(): void {
        $this->resetAfterTest();
        $companyuuid = '67000000-0000-4000-8000-000000000001';
        $workspaceuuid = '67000000-0000-4000-8000-000000000101';
        $this->create_company($companyuuid);
        workspace_mapping_service::bind($companyuuid, $workspaceuuid, 'active',
            workspace_mapping_service::MODE_LEGACY, '', 0);

        entitlement_snapshot_service::apply($this->snapshot($companyuuid, $workspaceuuid, 'rev-1', [
            'mgmt.core' => true,
        ]));

        $status = workspace_mapping_service::status($companyuuid);
        $this->assertSame(workspace_mapping_service::MODE_LEGACY, $status['entitlement_mode']);
        $this->assertSame('rev-1', $status['snapshot_revision']);
        $explain = entitlement_service::explain($companyuuid, 'mgmt.finance.budget');
        $this->assertTrue($explain['allowed']);
        $this->assertSame('LEGACY_COMPATIBILITY', $explain['reason_code']);
    }

    public function test_cloud_mode_resolves_valid_snapshot_and_fails_closed_for_missing_code(): void {
        $this->resetAfterTest();
        $companyuuid = '67000000-0000-4000-8000-000000000002';
        $workspaceuuid = '67000000-0000-4000-8000-000000000102';
        $this->create_company($companyuuid);
        workspace_mapping_service::bind($companyuuid, $workspaceuuid, 'active',
            workspace_mapping_service::MODE_CLOUD, '', 0);
        entitlement_snapshot_service::apply($this->snapshot($companyuuid, $workspaceuuid, 'rev-2', [
            'mgmt.core' => true,
            'mgmt.finance.budget' => ['allowed' => true, 'state' => 'enabled', 'value' => null],
        ]));

        $core = entitlement_service::explain($companyuuid, 'mgmt.core');
        $this->assertTrue($core['allowed']);
        $this->assertSame('ENABLED_SNAPSHOT', $core['reason_code']);
        $this->assertSame('rev-2', $core['snapshot_revision']);

        $missing = entitlement_service::explain($companyuuid, 'mgmt.competency.360');
        $this->assertFalse($missing['allowed']);
        $this->assertSame('NOT_ASSIGNED', $missing['reason_code']);
    }

    public function test_stale_snapshot_is_last_known_good_only_inside_grace_window(): void {
        $this->resetAfterTest();
        $companyuuid = '67000000-0000-4000-8000-000000000003';
        $workspaceuuid = '67000000-0000-4000-8000-000000000103';
        $this->create_company($companyuuid);
        workspace_mapping_service::bind($companyuuid, $workspaceuuid, 'active',
            workspace_mapping_service::MODE_CLOUD, '', 0);
        $now = time();
        entitlement_snapshot_service::apply($this->snapshot($companyuuid, $workspaceuuid, 'rev-3', [
            'mgmt.core' => true,
        ], $now - 7200, $now - 60));

        $core = entitlement_service::explain($companyuuid, 'mgmt.core');
        $this->assertTrue($core['allowed']);
        $this->assertSame('ENABLED_STALE_LAST_KNOWN_GOOD', $core['reason_code']);
        $this->assertSame('stale_grace', $core['snapshot_state']);
    }

    public function test_snapshot_expired_beyond_grace_fails_closed(): void {
        $this->resetAfterTest();
        $companyuuid = '67000000-0000-4000-8000-000000000004';
        $workspaceuuid = '67000000-0000-4000-8000-000000000104';
        $this->create_company($companyuuid);
        workspace_mapping_service::bind($companyuuid, $workspaceuuid, 'active',
            workspace_mapping_service::MODE_CLOUD, '', 0);
        $now = time();
        entitlement_snapshot_service::apply($this->snapshot($companyuuid, $workspaceuuid, 'rev-4', [
            'mgmt.core' => true,
        ], $now - 200000, $now - entitlement_snapshot_service::STALE_GRACE_SECONDS - 60));

        $core = entitlement_service::explain($companyuuid, 'mgmt.core');
        $this->assertFalse($core['allowed']);
        $this->assertSame('SNAPSHOT_EXPIRED', $core['reason_code']);
    }

    public function test_same_revision_with_different_hash_is_rejected(): void {
        $this->resetAfterTest();
        $companyuuid = '67000000-0000-4000-8000-000000000005';
        $workspaceuuid = '67000000-0000-4000-8000-000000000105';
        $this->create_company($companyuuid);
        workspace_mapping_service::bind($companyuuid, $workspaceuuid, 'active',
            workspace_mapping_service::MODE_LEGACY, '', 0);
        entitlement_snapshot_service::apply($this->snapshot($companyuuid, $workspaceuuid, 'rev-5', [
            'mgmt.core' => true,
        ]));

        $this->expectException(\invalid_parameter_exception::class);
        entitlement_snapshot_service::apply($this->snapshot($companyuuid, $workspaceuuid, 'rev-5', [
            'mgmt.core' => false,
        ]));
    }

    private function snapshot(string $companyuuid, string $workspaceuuid, string $revision,
            array $entitlements, int $generatedat = 0, int $validuntil = 0): array {
        if ($generatedat <= 0) {
            $generatedat = time() - 60;
        }
        if ($validuntil <= 0) {
            $validuntil = time() + 3600;
        }
        $normal = [];
        foreach ($entitlements as $code => $entry) {
            if (is_bool($entry)) {
                $entry = ['allowed' => $entry, 'state' => $entry ? 'enabled' : 'disabled', 'value' => null];
            }
            $normal[$code] = [
                'allowed' => (bool)$entry['allowed'],
                'state' => (string)$entry['state'],
                'value' => $entry['value'] ?? null,
            ];
        }
        ksort($normal, SORT_STRING);
        $snapshot = [
            'schema_version' => entitlement_snapshot_service::SNAPSHOT_SCHEMA_VERSION,
            'workspace_uuid' => $workspaceuuid,
            'company_uuid' => $companyuuid,
            'snapshot_revision' => $revision,
            'catalog_version' => '0.1',
            'resolver_version' => 'cloud-test-1',
            'generated_at' => $generatedat,
            'valid_until' => $validuntil,
            'entitlements' => $normal,
        ];
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $snapshot['snapshot_hash'] = hash('sha256', $json);
        return $snapshot;
    }

    private function create_company(string $uuid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $uuid,
            'name' => 'Entitlement Snapshot Test',
            'code' => '',
            'primaryactivity' => null,
            'foundedyear' => 0,
            'bmsclient' => 1,
            'sourcekind' => 'manual',
            'legacycompanyid' => 0,
            'syncstate' => 'local',
            'active' => 1,
            'archivedat' => 0,
            'createdby' => 0,
            'modifiedby' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
