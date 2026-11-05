<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\company_service;
use local_kbacompany\local\company_registry_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\organization_service;
use local_kbacompany\local\business_role_service;
use local_kbacompany\local\organizing_board_service;
use local_kbacompany\local\position_service;
use local_kbacompany\local\staffing_service;
use local_kbacompany\local\permission_service;
use local_kbacorporate\local\repository;

/** Company Core foundation regression tests. */
final class company_core_test extends \advanced_testcase {
    public function test_contract_declares_existing_source_without_duplication(): void {
        $contract = company_service::contract();
        $this->assertSame('0.6.0', $contract['version']);
        $this->assertSame('canonical_with_compatibility_adapter', $contract['storage_mode']);
        $this->assertSame('runtime_optional_adapter', $contract['dependency_policy']);
        $this->assertSame('local_kbacompany.company', $contract['source_of_truth']['company_profile']);
        $this->assertContains('no_company_duplication', $contract['guarantees']);
        $this->assertContains('centralised_company_permissions', $contract['guarantees']);
        $this->assertContains('optimistic_live_org_apply', $contract['guarantees']);
        $this->assertContains('ofs_is_product_functional_not_only_reporting', $contract['guarantees']);
        $this->assertContains('process_is_not_forced_to_equal_department', $contract['guarantees']);
        $this->assertArrayHasKey('policies_directives', $contract['subcontracts']);
        $this->assertArrayHasKey('permissions', $contract['subcontracts']);
        $this->assertArrayHasKey('semantic_core', $contract['subcontracts']);
        $this->assertArrayHasKey('ofs_ckp', $contract['subcontracts']);
        $this->assertArrayHasKey('process_architecture', $contract['subcontracts']);
        $this->assertArrayHasKey('process_model', $contract['subcontracts']);
        $this->assertArrayHasKey('stp_raci', $contract['subcontracts']);
        $this->assertArrayHasKey('function_model', $contract['subcontracts']);
        $this->assertArrayHasKey('document_control', $contract['subcontracts']);
        $this->assertArrayHasKey('ownership_strategy_bsc', $contract['subcontracts']);
        $this->assertArrayHasKey('administrative_scale', $contract['subcontracts']);
        $this->assertArrayHasKey('task_center', $contract['subcontracts']);
        $this->assertArrayHasKey('battle_plan', $contract['subcontracts']);
        $this->assertArrayHasKey('communication_core', $contract['subcontracts']);
        $this->assertArrayHasKey('unified_task_sources', $contract['subcontracts']);
        $this->assertArrayHasKey('communication_center', $contract['subcontracts']);
        $this->assertArrayHasKey('financial_model', $contract['subcontracts']);
        $this->assertContains('StrategicGoal', $contract['objects']);
        $this->assertContains('AdministrativeScaleItem', $contract['objects']);
        $this->assertContains('CommunicationThread', $contract['objects']);
        $this->assertContains('CommunicationMessage', $contract['objects']);
        $this->assertContains('FinancialScenario', $contract['objects']);
        $this->assertContains('FinancialResponsibilityCenter', $contract['objects']);
        $this->assertContains('semantic_context', $contract['operations']);
        $this->assertContains('ofs_context', $contract['operations']);
        $this->assertContains('process_context', $contract['operations']);
        $this->assertContains('process_model_context', $contract['operations']);
        $this->assertContains('stp_context', $contract['operations']);
        $this->assertContains('functions_context', $contract['operations']);
        $this->assertContains('documents_context', $contract['operations']);
        $this->assertContains('strategy_context', $contract['operations']);
        $this->assertContains('administrative_scale_context', $contract['operations']);
        $this->assertContains('task_center_context', $contract['operations']);
        $this->assertContains('battle_plan_context', $contract['operations']);
        $this->assertContains('communication_context', $contract['operations']);
        $this->assertContains('unified_task_context', $contract['operations']);
        $this->assertContains('communication_center_context', $contract['operations']);
        $this->assertContains('financial_model_context', $contract['operations']);
        $this->assertContains('strategic_causal_map_is_acyclic', $contract['guarantees']);
        $this->assertContains('administrative_scale_links_strategy_to_execution', $contract['guarantees']);
        $this->assertContains('communication_threads_are_participant_scoped', $contract['guarantees']);
        $this->assertContains('message_and_task_remain_separate_objects_with_traceable_conversion', $contract['guarantees']);
        $this->assertContains('financial_model_centres_reference_canonical_ofs_targets', $contract['guarantees']);
        $this->assertContains('policies_context', $contract['operations']);
        $this->assertSame(64, strlen($contract['schema_hash']));
    }

    public function test_permission_contract_centralises_company_role_policies(): void {
        $contract = permission_service::contract();
        $this->assertSame('0.5.1.3', $contract['version']);
        $this->assertSame(['owner', 'manager', 'hr'], $contract['policies']['manage_organization']);
        $this->assertSame(['owner', 'manager'], $contract['policies']['manage_management']);
        $this->assertSame(['owner', 'manager'], $contract['policies']['manage_tasks']);
        $this->assertSame(['owner', 'manager'], $contract['policies']['manage_communications']);
        $this->assertContains('no_service_specific_role_checks', $contract['rules']);
    }

    public function test_stable_company_identity_is_idempotent_and_unique(): void {
        global $DB;
        $this->resetAfterTest();
        $firstid = $this->create_company('First');
        $secondid = $this->create_company('Second');

        $first = identity_registry::company_uuid($firstid);
        $again = identity_registry::company_uuid($firstid);
        $second = identity_registry::company_uuid($secondid);

        $this->assertSame($first, $again);
        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first);
        $this->assertSame(2, $DB->count_records('local_kbacompany_idreg'));
        $this->assertSame(2, $DB->count_records('local_kbacorp_company'));
    }

    public function test_user_can_belong_to_multiple_companies_with_scoped_roles(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $firstid = $this->create_company('Alpha');
        $secondid = $this->create_company('Beta');
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $firstid, 'userid' => $user->id, 'membertype' => 'owner',
            'isemployee' => 1, 'ismanager' => 1, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $secondid, 'userid' => $user->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 1,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $companies = company_service::companies_for_user((int)$user->id);
        $this->assertCount(2, $companies);
        $this->assertContains('owner', $companies[0]['roles']);
        $this->assertContains('manager', $companies[0]['roles']);
        $this->assertContains('hr', $companies[1]['roles']);
    }

    public function test_company_context_rejects_user_outside_company(): void {
        $this->resetAfterTest();
        $companyid = $this->create_company('Protected');
        $uuid = identity_registry::company_uuid($companyid);
        $outsider = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        company_service::company_context($uuid, (int)$outsider->id);
    }

    public function test_site_admin_can_view_canonical_company_without_membership_record(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();

        $result = company_registry_service::upsert_external('bitrix24', '385', true, [
            'name' => 'Canonical without membership',
        ], (int)$admin->id);

        $this->assertSame('created', $result['status']);
        $this->assertFalse($DB->record_exists('local_kbacompany_member', [
            'companyuuid' => $result['company_uuid'],
            'userid' => (int)$admin->id,
        ]));

        $companies = company_service::companies_for_user((int)$admin->id);
        $canonical = array_values(array_filter($companies, static function(array $company) use ($result): bool {
            return $company['uuid'] === $result['company_uuid'];
        }));
        $this->assertCount(1, $canonical);
        $this->assertSame([], $canonical[0]['roles']);
    }

    public function test_organization_contract_preserves_unknown_positions(): void {
        $contract = organization_service::contract();
        $this->assertSame('0.2.0', $contract['version']);
        $this->assertSame('unknown', $contract['null_policy']['missing_position']);
        $this->assertContains('no_position_fabrication', $contract['guarantees']);
        $this->assertSame(64, strlen($contract['schema_hash']));
    }

    public function test_organization_context_is_company_scoped_and_read_only(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Organisation test');
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $user->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $departmentid = $DB->insert_record('local_kbacorp_department', (object)[
            'companyid' => $companyid, 'name' => 'Operations', 'code' => 'OPS',
            'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacorp_dept_member', (object)[
            'departmentid' => $departmentid, 'userid' => $user->id, 'deptrole' => 'employee',
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $snapshot = organization_service::organization_context(
            identity_registry::company_uuid($companyid), (int)$user->id);

        $this->assertSame(1, $snapshot['summary']['org_units']);
        $this->assertSame(1, $snapshot['summary']['employees']);
        $this->assertSame(0, $snapshot['summary']['positions']);
        $this->assertSame(0, $snapshot['summary']['without_org_unit']);
        $this->assertNull($snapshot['employees'][0]['position']);
        $this->assertCount(1, $snapshot['employees'][0]['org_assignments']);
        $this->assertSame(1, $DB->count_records('local_kbacorp_department'));
    }

    public function test_business_role_contract_separates_targets_from_assessment(): void {
        $contract = business_role_service::contract();
        $this->assertSame('0.2.1', $contract['version']);
        $this->assertContains('bms_domain_nodes_are_not_working_competencies', $contract['rules']);
        $this->assertContains('course_completion_does_not_confirm_competency_level', $contract['rules']);
        $this->assertContains('unassessed_competency_does_not_become_level_zero', $contract['rules']);
        $this->assertSame(64, strlen($contract['schema_hash']));
    }

    public function test_business_role_assignment_is_projected_without_source_mutation(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Role test');
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $user->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $roleid = $DB->insert_record('local_kbacorp_busrole', (object)[
            'companyid' => $companyid, 'code' => 'BMS-ROLE-OPS',
            'uuid' => '33333333-3333-4333-8333-333333333333', 'name' => 'Operations manager',
            'active' => 1, 'source' => 'manual', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacorp_member_busrole', (object)[
            'companyid' => $companyid, 'userid' => $user->id, 'roleid' => $roleid,
            'source' => 'manual', 'active' => 1, 'effectivefrom' => 0, 'effectiveto' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);

        $snapshot = business_role_service::business_roles_context(
            identity_registry::company_uuid($companyid), (int)$user->id);

        $this->assertTrue($snapshot['available']);
        $this->assertSame(1, $snapshot['summary']['roles']);
        $this->assertSame(1, $snapshot['summary']['assigned_employees']);
        $this->assertSame(0, $snapshot['summary']['without_role']);
        $this->assertSame('BMS-ROLE-OPS', $snapshot['employees'][0]['roles'][0]['code']);
        $this->assertSame(1, $DB->count_records('local_kbacorp_busrole'));
        $this->assertSame(1, $DB->count_records('local_kbacorp_member_busrole'));
    }

    public function test_organizing_board_template_is_explicit_and_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Board test');
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $user->id, 'membertype' => 'owner',
            'isemployee' => 1, 'ismanager' => 1, 'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $companyuuid = identity_registry::company_uuid($companyid);
        $this->assertSame(0, $DB->count_records('local_kbacompany_orgbranch'));

        $first = organizing_board_service::install_seven_division_template($companyuuid, (int)$user->id);
        $second = organizing_board_service::install_seven_division_template($companyuuid, (int)$user->id);

        $this->assertSame(1, $first['summary']['branches']);
        $this->assertSame(7, $first['summary']['divisions']);
        $this->assertSame(7, $second['summary']['nodes']);
        $this->assertSame(['7.0.0', '1.0.0', '2.0.0', '3.0.0', '4.0.0', '5.0.0', '6.0.0'],
            array_column($second['nodes'], 'code'));
        $this->assertSame(1, $DB->count_records('local_kbacompany_orgbranch'));
        $this->assertSame(7, $DB->count_records('local_kbacompany_orgnode'));
    }

    public function test_organizing_board_contract_forbids_automatic_seed_and_formula_import(): void {
        $contract = organizing_board_service::contract();
        $this->assertSame('0.2.7.3', $contract['version']);
        $this->assertSame('never_seeded_by_upgrade', $contract['template_policy']);
        $this->assertContains('no_formula_import', $contract['guarantees']);
        $this->assertSame(64, strlen($contract['schema_hash']));
    }

    public function test_position_stays_vacant_until_explicit_employee_assignment(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Staffing test');
        $now = time();
        foreach ([[$owner, 'owner', 1], [$employee, 'employee', 0]] as [$user, $type, $manager]) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $companyid, 'userid' => $user->id, 'membertype' => $type,
                'isemployee' => 1, 'ismanager' => $manager, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $companyuuid = identity_registry::company_uuid($companyid);
        $board = organizing_board_service::install_seven_division_template($companyuuid, (int)$owner->id);
        $nodeuuid = $board['nodes'][0]['uuid'];
        $positionuuid = position_service::create_position($companyuuid, (int)$owner->id, [
            'orgnodeuuid' => $nodeuuid, 'code' => '7.1.1-P01', 'name' => 'Руководитель',
            'plannedcount' => 1, 'ismanager' => 1,
        ]);

        $vacant = position_service::positions_context($companyuuid, (int)$owner->id, $nodeuuid);
        $this->assertSame(1, $vacant['summary']['vacant_places']);
        $this->assertSame(0, $vacant['summary']['current_assignments']);

        $assignmentid = position_service::assign_employee($companyuuid, (int)$owner->id,
            $positionuuid, (int)$employee->id, 'primary');
        $filled = position_service::positions_context($companyuuid, (int)$owner->id, $nodeuuid);
        $this->assertSame(0, $filled['summary']['vacant_places']);
        $this->assertSame(1, $filled['summary']['current_assignments']);

        position_service::end_assignment($companyuuid, (int)$owner->id, $assignmentid);
        $ended = position_service::positions_context($companyuuid, (int)$owner->id, $nodeuuid);
        $this->assertSame(1, $ended['summary']['vacant_places']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_posassign'));
    }

    public function test_staffing_plan_and_placement_are_separate(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Separated staffing');
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $owner->id, 'membertype' => 'owner',
            'isemployee' => 1, 'ismanager' => 1, 'isdepartmentmanager' => 0,
            'ishr' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $companyuuid = identity_registry::company_uuid($companyid);
        \local_kbacompany\local\employee_service::ensure_membership($companyuuid, (int)$employee->id,
            ['employee' => true], 'bms_manual');
        $board = organizing_board_service::install_seven_division_template($companyuuid, (int)$owner->id);
        $positionuuid = position_service::create_position($companyuuid, (int)$owner->id, [
            'orgnodeuuid' => $board['nodes'][0]['uuid'], 'code' => 'ADM-001', 'name' => 'Administrator',
        ]);
        $planuuid = staffing_service::create_plan($companyuuid, (int)$owner->id, [
            'branchuuid' => $board['branches'][0]['uuid'], 'name' => 'Plan A', 'currency' => 'RUB',
        ]);
        staffing_service::add_line($companyuuid, (int)$owner->id, $planuuid, [
            'positionuuid' => $positionuuid, 'plannedfte' => 2.5, 'budgetamount' => 250000,
        ]);
        staffing_service::approve_plan($companyuuid, (int)$owner->id, $planuuid);
        $placement = staffing_service::placement_context($companyuuid, (int)$owner->id, $planuuid);
        $this->assertContains((int)$employee->id, array_column($placement['employees'], 'user_id'));
        $this->assertContains((int)$owner->id, array_column($placement['employees'], 'user_id'));
        $this->assertCount(3, $placement['slots']);
        $this->assertEquals(2.5, $placement['summary']['planned_fte']);
        $this->assertEquals(2.5, $placement['summary']['vacant_fte']);
        $this->assertSame([], $placement['slots'][0]['employee_names']);
        $assignmentid = staffing_service::assign_slot($companyuuid, (int)$owner->id,
            $placement['slots'][0]['uuid'], (int)$employee->id, 'primary');
        $filled = staffing_service::placement_context($companyuuid, (int)$owner->id, $planuuid);
        $this->assertSame('occupied', $filled['slots'][0]['status']);
        staffing_service::end_slot_assignment($companyuuid, (int)$owner->id, $assignmentid);
        $this->assertSame(1, $DB->count_records('local_kbacompany_posassign'));
        $this->assertSame('vacant', staffing_service::placement_context(
            $companyuuid, (int)$owner->id, $planuuid)['slots'][0]['status']);
    }

    public function test_staffing_plan_approval_carries_forward_compatible_assignments(): void {
        global $DB;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Staffing carry forward');
        $now = time();
        foreach ([[$owner, 'owner', 1], [$employee, 'employee', 0]] as [$user, $type, $manager]) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $companyid, 'userid' => $user->id, 'membertype' => $type,
                'isemployee' => 1, 'ismanager' => $manager, 'isdepartmentmanager' => 0,
                'ishr' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $companyuuid = identity_registry::company_uuid($companyid);
        $board = organizing_board_service::install_seven_division_template($companyuuid, (int)$owner->id);
        $positionuuid = position_service::create_position($companyuuid, (int)$owner->id, [
            'orgnodeuuid' => $board['nodes'][0]['uuid'], 'code' => 'DIR-001', 'name' => 'Director',
        ]);
        $plan1 = staffing_service::create_plan($companyuuid, (int)$owner->id, [
            'branchuuid' => $board['branches'][0]['uuid'], 'name' => 'Plan 1', 'currency' => 'RUB',
        ]);
        staffing_service::add_line($companyuuid, (int)$owner->id, $plan1, [
            'positionuuid' => $positionuuid, 'plannedfte' => 1.0, 'budgetamount' => 100000,
        ]);
        staffing_service::approve_plan($companyuuid, (int)$owner->id, $plan1);
        $placement1 = staffing_service::placement_context($companyuuid, (int)$owner->id, $plan1);
        $assignmentid = staffing_service::assign_slot($companyuuid, (int)$owner->id,
            $placement1['slots'][0]['uuid'], (int)$employee->id, 'primary');

        $plan2 = staffing_service::create_plan($companyuuid, (int)$owner->id, [
            'branchuuid' => $board['branches'][0]['uuid'], 'name' => 'Plan 2', 'currency' => 'RUB',
            'copycurrent' => 1,
        ]);
        staffing_service::approve_plan($companyuuid, (int)$owner->id, $plan2);
        $placement2 = staffing_service::placement_context($companyuuid, (int)$owner->id, $plan2);
        $this->assertEquals(1.0, $placement2['summary']['occupied_fte']);
        $this->assertEquals(0.0, $placement2['summary']['vacant_fte']);
        $this->assertSame('occupied', $placement2['slots'][0]['status']);
        $this->assertSame((int)$employee->id, $placement2['slots'][0]['employee_id']);
        $this->assertSame($assignmentid, $placement2['slots'][0]['assignment_id']);
        $this->assertSame(1, $DB->count_records('local_kbacompany_posassign', [
            'companyuuid' => $companyuuid, 'userid' => (int)$employee->id, 'active' => 1,
        ]));
    }

    public function test_position_contract_keeps_staffing_separate_from_competency(): void {
        $contract = position_service::contract();
        $this->assertSame('0.2.3.3', $contract['version']);
        $this->assertSame('vacant', $contract['null_policy']['unfilled_position']);
        $this->assertContains('assignment_does_not_change_competency', $contract['rules']);
        $this->assertSame(64, strlen($contract['schema_hash']));
    }

    private function create_company(string $name): int {
        return repository::save_company((object)[
            'name' => $name,
            'active' => 1,
        ]);
    }
}
