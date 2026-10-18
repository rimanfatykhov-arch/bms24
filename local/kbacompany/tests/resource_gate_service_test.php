<?php
namespace local_kbacompany;

use local_kbacompany\local\resource_gate_service;

defined('MOODLE_INTERNAL') || die();

/** @covers \local_kbacompany\local\resource_gate_service */
class resource_gate_service_test extends \advanced_testcase {

    public function test_contract_is_stable(): void {
        $contract = resource_gate_service::contract();
        $this->assertSame('0.6.3', $contract['version']);
        $this->assertSame(['money', 'people', 'competency', 'time', 'technology', 'information', 'infrastructure'],
            resource_gate_service::RESOURCE_KINDS);
        $this->assertContains('all_seven_resource_dimensions_are_explicit_before_submission', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_ready_gate_requires_all_required_resources_to_pass_or_be_waived(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $companyuuid = '63000000-0000-4000-8000-000000000001';
        $targetuuid = '63000000-0000-4000-8000-000000000002';
        $this->create_company($companyuuid);
        $this->create_target($companyuuid, $targetuuid, 'project');
        $items = $this->items('pass');
        $items[6]['status'] = 'waived';
        $items[6]['evidence'] = 'Existing shared infrastructure is accepted for pilot.';

        $gateuuid = resource_gate_service::save_gate($companyuuid, (int)$admin->id, [
            'code' => 'RG-PILOT', 'name' => 'Pilot launch gate', 'targettype' => 'project',
            'targetuuid' => $targetuuid, 'scenariouuid' => '', 'plannedstart' => '2027-02-01',
            'ownerpositionuuid' => '',
        ], $items);
        resource_gate_service::submit_gate($companyuuid, (int)$admin->id, $gateuuid, 1);
        resource_gate_service::mark_ready($companyuuid, (int)$admin->id, $gateuuid, 2, 'Resources confirmed.');
        $this->assertSame('ready', $DB->get_field('local_kbacompany_resgate', 'status',
            ['uuid' => $gateuuid, 'revision' => 3]));
        $this->assertEquals(3, $DB->count_records('local_kbacompany_resgate', ['uuid' => $gateuuid]));
        $this->assertEquals(7, $DB->count_records('local_kbacompany_resitem',
            ['gateuuid' => $gateuuid, 'gaterevision' => 3]));
    }

    public function test_missing_resource_dimension_cannot_submit(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $companyuuid = '63000000-0000-4000-8000-000000000003';
        $targetuuid = '63000000-0000-4000-8000-000000000004';
        $this->create_company($companyuuid);
        $this->create_target($companyuuid, $targetuuid, 'goal');
        $items = $this->items('pass');
        array_pop($items);
        $gateuuid = resource_gate_service::save_gate($companyuuid, (int)$admin->id, [
            'code' => 'RG-MISSING', 'name' => 'Missing dimension', 'targettype' => 'goal',
            'targetuuid' => $targetuuid, 'scenariouuid' => '', 'plannedstart' => '', 'ownerpositionuuid' => '',
        ], $items);
        $this->expectException(\moodle_exception::class);
        resource_gate_service::submit_gate($companyuuid, (int)$admin->id, $gateuuid, 1);
    }

    private function items(string $status): array {
        $items = [];
        foreach (resource_gate_service::RESOURCE_KINDS as $kind) {
            $items[] = [
                'itemcode' => strtoupper(substr($kind, 0, 4)), 'resourcekind' => $kind,
                'name' => ucfirst($kind) . ' readiness', 'required' => 1, 'status' => $status,
                'referencekind' => 'none', 'referenceuuid' => '', 'referencerevision' => 0,
                'expected' => 'Required', 'available' => 'Available', 'gap' => '', 'evidence' => 'Confirmed',
                'evidenceurl' => '', 'ownerpositionuuid' => '', 'duedate' => '',
            ];
        }
        return $items;
    }

    private function create_company(string $uuid): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => $uuid, 'name' => 'Resource Gate test', 'code' => '', 'primaryactivity' => null,
            'foundedyear' => 0, 'bmsclient' => 1, 'sourcekind' => 'manual', 'legacycompanyid' => 0,
            'syncstate' => 'local', 'active' => 1, 'archivedat' => 0, 'createdby' => 0, 'modifiedby' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
    }

    private function create_target(string $companyuuid, string $uuid, string $kind): void {
        global $DB;
        $now = time();
        $DB->insert_record('local_kbacompany_adminitem', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => 1, 'status' => 'published',
            'kind' => $kind, 'code' => 'TARGET', 'name' => 'Launch target', 'description' => '',
            'expectedresult' => '', 'parentuuid' => '', 'parentrevision' => 0, 'sourcekind' => '',
            'sourceuuid' => '', 'sourcerevision' => 0, 'ownerpositionuuid' => '', 'productuuid' => '',
            'metricuuid' => '', 'targetvalue' => '', 'startdate' => '', 'duedate' => '', 'sortorder' => 0,
            'createdby' => 0, 'modifiedby' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
    }
}
