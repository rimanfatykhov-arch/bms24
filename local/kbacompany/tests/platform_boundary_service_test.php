<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\company_context_service;
use local_kbacompany\local\entitlement_service;
use local_kbacompany\local\platform_boundary_service;
use local_kbacompany\local\platform_event_envelope;
use local_kbacompany\local\workspace_mapping_service;

/** v0.6.4 platform boundary regression tests. */
final class platform_boundary_service_test extends \advanced_testcase {
    public function test_unmapped_company_remains_legacy_compatible(): void {
        $this->resetAfterTest();
        $companyuuid = '64000000-0000-4000-8000-000000000001';
        $this->create_company($companyuuid, 'Legacy compatible');

        $this->assertTrue(company_context_service::exists($companyuuid));
        $status = workspace_mapping_service::status($companyuuid);
        $this->assertFalse($status['mapped']);
        $this->assertSame(workspace_mapping_service::MODE_LEGACY, $status['entitlement_mode']);

        $explain = entitlement_service::explain($companyuuid, 'mgmt.finance.budget');
        $this->assertTrue($explain['allowed']);
        $this->assertSame('LEGACY_COMPATIBILITY', $explain['reason_code']);
    }

    public function test_cloud_snapshot_mode_fails_closed_until_snapshot_transport_exists(): void {
        $this->resetAfterTest();
        $companyuuid = '64000000-0000-4000-8000-000000000002';
        $workspaceuuid = '64000000-0000-4000-8000-000000000102';
        $this->create_company($companyuuid, 'Cloud mapped');

        workspace_mapping_service::bind($companyuuid, $workspaceuuid, 'active',
            workspace_mapping_service::MODE_CLOUD, 'rev-1', 0);
        $explain = entitlement_service::explain($companyuuid, 'mgmt.core');
        $this->assertFalse($explain['allowed']);
        $this->assertSame('SNAPSHOT_NOT_AVAILABLE', $explain['reason_code']);
        $this->assertSame($workspaceuuid, $explain['workspace_uuid']);
    }

    public function test_provider_neutral_event_envelope_has_stable_idempotency_key(): void {
        $this->resetAfterTest();
        $companyuuid = '64000000-0000-4000-8000-000000000003';
        $entityuuid = '64000000-0000-4000-8000-000000000203';
        $this->create_company($companyuuid, 'Event envelope');
        $payload = ['position_code' => 'CEO', 'status' => 'published'];

        $one = platform_event_envelope::create('organization.position.published', $companyuuid,
            'position', $entityuuid, 2, $payload, 1800000000);
        $two = platform_event_envelope::create('organization.position.published', $companyuuid,
            'position', $entityuuid, 2, $payload, 1800000000);

        $this->assertSame('1.1', $one['schema_version']);
        $this->assertSame($one['idempotency_key'], $two['idempotency_key']);
        $this->assertNotSame($one['event_id'], $two['event_id']);
        $this->assertSame($companyuuid, $one['company_uuid']);
    }

    public function test_platform_contract_keeps_cloud_and_erp_outside_bms_management_ownership(): void {
        $contract = platform_boundary_service::contract();
        $this->assertSame('local_kbacompany', $contract['owners']['management_model']);
        $this->assertSame('external_bms_cloud', $contract['owners']['cloud_control_plane']);
        $this->assertSame('odoo_provider', $contract['owners']['operational_erp_actual']);
        $this->assertContains('cross_company_fetch_then_php_filter', $contract['forbidden_dependencies']);
        $this->assertSame('0.6.9', $contract['version']);
        $this->assertSame('0.6.9', $contract['interfaces']['pre_0_7_readiness']['version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    private function create_company(string $uuid, string $name): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $uuid,
            'name' => $name,
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
