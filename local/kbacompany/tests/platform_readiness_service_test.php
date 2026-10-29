<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\entitlement_snapshot_service;
use local_kbacompany\local\platform_readiness_service;
use local_kbacompany\local\workspace_mapping_service;

/** v0.6.9 Pre-0.7 architecture gate regression tests. */
final class platform_readiness_service_test extends \advanced_testcase {
    public function test_legacy_unmapped_company_is_ready_with_warning(): void {
        $this->resetAfterTest();
        $companyuuid = '69000000-0000-4000-8000-000000000001';
        $this->create_company($companyuuid);

        $gate = platform_readiness_service::evaluate($companyuuid);
        $this->assertTrue($gate['pre_0_7_ready']);
        $this->assertSame('ready_with_warnings', $gate['status']);
        $this->assertSame('LEGACY_COMPANY_NOT_CLOUD_MAPPED', $gate['next_action']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $gate['result_hash']);
    }

    public function test_cloud_cutover_without_snapshot_is_blocked(): void {
        $this->resetAfterTest();
        $companyuuid = '69000000-0000-4000-8000-000000000002';
        $workspaceuuid = '69000000-0000-4000-8000-000000000102';
        $this->create_company($companyuuid);
        workspace_mapping_service::bind($companyuuid, $workspaceuuid, 'active',
            workspace_mapping_service::MODE_CLOUD, '', 0, 1);

        $gate = platform_readiness_service::evaluate($companyuuid);
        $this->assertFalse($gate['pre_0_7_ready']);
        $this->assertSame('blocked', $gate['status']);
        $this->assertSame('CLOUD_SNAPSHOT_NOT_USABLE', $gate['next_action']);
    }

    public function test_cloud_cutover_with_valid_snapshot_is_ready(): void {
        $this->resetAfterTest();
        $companyuuid = '69000000-0000-4000-8000-000000000003';
        $workspaceuuid = '69000000-0000-4000-8000-000000000103';
        $this->create_company($companyuuid);
        workspace_mapping_service::bind($companyuuid, $workspaceuuid, 'active',
            workspace_mapping_service::MODE_CLOUD, '', 0, 1, 'not_provisioned', 'healthy');
        entitlement_snapshot_service::apply($this->snapshot($companyuuid, $workspaceuuid));

        $gate = platform_readiness_service::evaluate($companyuuid);
        $this->assertTrue($gate['pre_0_7_ready']);
        $this->assertSame('ready', $gate['status']);
        $this->assertSame('START_0_7_DOMAIN_WORK', $gate['next_action']);
        $this->assertSame(0, $gate['summary']['block']);
        $this->assertSame(0, $gate['summary']['warning']);
    }

    public function test_contract_is_diagnostic_and_non_mutating(): void {
        $contract = platform_readiness_service::contract();
        $this->assertSame('0.6.9', $contract['version']);
        $this->assertContains('readiness_is_diagnostic_and_does_not_mutate_company_or_cloud_state', $contract['rules']);
        $this->assertContains('cloud_snapshot_mode_requires_usable_last_known_good_snapshot', $contract['rules']);
    }

    private function snapshot(string $companyuuid, string $workspaceuuid): array {
        $snapshot = [
            'schema_version' => entitlement_snapshot_service::SNAPSHOT_SCHEMA_VERSION,
            'workspace_uuid' => $workspaceuuid,
            'company_uuid' => $companyuuid,
            'snapshot_revision' => 'pre07-ready-1',
            'catalog_version' => '0.1',
            'resolver_version' => 'cloud-test-1',
            'generated_at' => time() - 10,
            'valid_until' => time() + 3600,
            'entitlements' => [
                'mgmt.core' => ['allowed' => true, 'state' => 'enabled', 'value' => null],
            ],
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
            'name' => 'Pre-0.7 Gate Test',
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
