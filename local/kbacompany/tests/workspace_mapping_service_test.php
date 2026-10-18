<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\workspace_mapping_service;

/** v0.6.8 Cloud Workspace mapping and diagnostics regression tests. */
final class workspace_mapping_service_test extends \advanced_testcase {
    public function test_binding_is_stable_once_created(): void {
        $this->resetAfterTest();
        $companyuuid = '68000000-0000-4000-8000-000000000001';
        $workspaceone = '68000000-0000-4000-8000-000000000101';
        $workspacetwo = '68000000-0000-4000-8000-000000000102';
        $this->create_company($companyuuid);
        workspace_mapping_service::bind($companyuuid, $workspaceone);

        $this->expectException(\invalid_parameter_exception::class);
        workspace_mapping_service::bind($companyuuid, $workspacetwo);
    }

    public function test_projection_revision_is_monotonic(): void {
        $this->resetAfterTest();
        $companyuuid = '68000000-0000-4000-8000-000000000002';
        $workspaceuuid = '68000000-0000-4000-8000-000000000202';
        $this->create_company($companyuuid);
        workspace_mapping_service::apply_projection($this->projection($companyuuid, $workspaceuuid, 4));

        $this->expectException(\invalid_parameter_exception::class);
        workspace_mapping_service::apply_projection($this->projection($companyuuid, $workspaceuuid, 3));
    }

    public function test_projection_exposes_provider_neutral_runtime_diagnostics(): void {
        $this->resetAfterTest();
        $companyuuid = '68000000-0000-4000-8000-000000000003';
        $workspaceuuid = '68000000-0000-4000-8000-000000000303';
        $this->create_company($companyuuid);
        $projection = $this->projection($companyuuid, $workspaceuuid, 7);
        $projection['erp_application_state'] = 'provisioning';
        $projection['integration_health'] = 'healthy';
        $status = workspace_mapping_service::apply_projection($projection);

        $this->assertSame(7, $status['projection_revision']);
        $this->assertSame('provisioning', $status['erp_application_state']);
        $this->assertSame('healthy', $status['integration_health']);
        $this->assertSame('fresh', $status['sync_freshness']);
        $this->assertSame($workspaceuuid, $status['workspace_uuid']);
    }

    private function projection(string $companyuuid, string $workspaceuuid, int $revision): array {
        return [
            'schema_version' => '1.0',
            'company_uuid' => $companyuuid,
            'workspace_uuid' => $workspaceuuid,
            'projection_revision' => $revision,
            'cloud_status' => 'active',
            'entitlement_mode' => workspace_mapping_service::MODE_LEGACY,
            'snapshot_revision' => '',
            'erp_application_state' => 'not_provisioned',
            'integration_health' => 'unknown',
        ];
    }

    private function create_company(string $uuid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $uuid,
            'name' => 'Workspace test',
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
