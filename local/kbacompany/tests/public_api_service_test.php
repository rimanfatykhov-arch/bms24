<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\public_api_service;

/** v0.6.5 BMS Management Public API foundation regression tests. */
final class public_api_service_test extends \advanced_testcase {
    public function test_contract_is_read_only_provider_neutral_and_versioned(): void {
        $contract = public_api_service::contract();
        $this->assertSame('0.6.5', $contract['version']);
        $this->assertSame('v1', $contract['api_version']);
        $this->assertSame('1.0', $contract['schema_version']);
        $this->assertSame('disabled_by_default_restricted_users', $contract['service_policy']);
        $this->assertContains('provider_specific_ids_are_not_part_of_public_resource_dtos', $contract['rules']);
        $this->assertContains('public_api_is_read_only_in_v1_foundation', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_company_snapshot_uses_stable_envelope_and_legacy_workspace_projection(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $companyuuid = '65000000-0000-4000-8000-000000000001';
        $this->create_company($companyuuid, 'API Company');

        $one = public_api_service::company_snapshot($companyuuid, (int)get_admin()->id);
        $two = public_api_service::company_snapshot($companyuuid, (int)get_admin()->id);

        $this->assertSame('v1', $one['api_version']);
        $this->assertSame('company', $one['resource']);
        $this->assertSame($companyuuid, $one['company_uuid']);
        $this->assertSame($companyuuid, $one['data']['company_uuid']);
        $this->assertFalse($one['data']['workspace']['mapped']);
        $this->assertSame('legacy_compatibility', $one['data']['workspace']['entitlement_mode']);
        $this->assertSame($one['snapshot_hash'], $two['snapshot_hash']);
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
