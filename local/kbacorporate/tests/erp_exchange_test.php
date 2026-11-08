<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\bridge;
use local_kbacorporate\local\erp_exchange;

/** Regression tests for the provider-neutral ERP exchange boundary. */
final class erp_exchange_test extends \advanced_testcase {
    public function test_contract_is_versioned_and_declares_data_authorities(): void {
        $contract = erp_exchange::contract();
        $this->assertSame(1, $contract['version']);
        $this->assertSame('1.3.1', $contract['release']);
        $this->assertSame('stable', $contract['stability']);
        $this->assertSame(64, strlen($contract['schema_hash']));
        $this->assertSame($contract['schema_hash'], erp_exchange::contract()['schema_hash']);
        $this->assertSame('upsert_and_merge', $contract['write_strategy']);
        $this->assertContains('company', array_keys($contract['push_entities']));
        $this->assertContains('company_snapshot', $contract['pull_resources']);
        $this->assertFalse($contract['privacy']['employee_pii_exported']);
        $this->assertContains('unassessed_competency_is_null_not_zero', $contract['semantic_guards']);
        $this->assertSame('erp_created_accounts_only', $contract['push_entities']['employee']['profile_update_policy']);
        $this->assertSame('1.26', bridge::API_RELEASE);
        $this->assertTrue($contract['hardening']['entity_locking']);
        $this->assertTrue($contract['hardening']['transactional_writes']);
        $this->assertSame('hashed_identifiers_no_payload_or_pii',
            $contract['hardening']['operational_audit']);
        $this->assertArrayHasKey('push_erp_entity', bridge::contract()['methods']);
        $this->assertArrayHasKey('get_erp_exchange_status', bridge::contract()['methods']);
        $this->assertArrayHasKey('sync_erp_employee', bridge::contract()['methods']);
        $this->assertArrayHasKey('sync_erp_department', bridge::contract()['methods']);
        $this->assertArrayHasKey('sync_erp_department_membership', bridge::contract()['methods']);
        $this->assertArrayHasKey('sync_erp_business_role', bridge::contract()['methods']);
        $this->assertArrayHasKey('sync_erp_learning_catalog_assignment', bridge::contract()['methods']);
        $this->assertArrayHasKey('sync_erp_economic_value', bridge::contract()['methods']);
        $this->assertSame('moodle_kba_owned_unchanged',
            $contract['push_entities']['business_role']['competency_profile_policy']);
        $this->assertSame('unchanged',
            $contract['push_entities']['learning_catalog_assignment']['learning_plan_policy']);
        $this->assertSame('always_unverified_until_moodle_review',
            $contract['push_entities']['economic_value']['verification_policy']);
    }

    public function test_company_and_employee_push_are_idempotent(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $first = erp_exchange::push_entity('odoo', 'company', 'company-42', [
            'name' => 'ERP Company', 'code' => 'ERP-42',
        ], 'request-1');
        $second = erp_exchange::push_entity('odoo', 'company', 'company-42', [
            'name' => 'ERP Company', 'code' => 'ERP-42',
        ], 'request-1');
        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['moodle_company_id'], $second['moodle_company_id']);
        $this->assertSame(1, $DB->count_records('local_kbacorp_extmap', [
            'companyid' => 0, 'system' => 'odoo', 'entitytype' => 'company',
            'externalid' => 'company-42',
        ]));

        $employee = erp_exchange::push_entity('odoo', 'employee', 'employee-7', [
            'company_external_id' => 'company-42',
            'moodle_user_id' => (int)$user->id,
            'access_roles' => ['employee', 'hr'],
        ]);
        $again = erp_exchange::push_entity('odoo', 'employee', 'employee-7', [
            'company_external_id' => 'company-42',
            'moodle_user_id' => (int)$user->id,
            'access_roles' => ['employee', 'hr'],
        ]);
        $this->assertTrue($employee['created']);
        $this->assertFalse($again['created']);
        $member = $DB->get_record('local_kbacorp_member', [
            'companyid' => $first['moodle_company_id'], 'userid' => $user->id,
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int)$member->isemployee);
        $this->assertSame(1, (int)$member->ishr);
    }

    public function test_employee_sync_can_create_update_deactivate_and_reactivate_managed_account(): void {
        global $DB;
        $this->resetAfterTest();

        $company = erp_exchange::push_entity('erp_test', 'company', 'company-employee-sync', [
            'name' => 'Employee Sync Company',
        ]);
        $created = erp_exchange::push_entity('erp_test', 'employee', 'employee-created', [
            'company_external_id' => 'company-employee-sync',
            'username' => 'erp.employee.created',
            'firstname' => 'ERP',
            'lastname' => 'Employee',
            'email' => 'erp.employee.created@example.invalid',
            'create_if_missing' => true,
        ]);
        $this->assertTrue($created['account_created']);
        $this->assertTrue($created['membership_created']);
        $this->assertTrue($created['password_reset_required']);
        $this->assertTrue($created['managed_account']);
        $userid = (int)$created['moodle_user_id'];
        $this->assertSame('1', (string)$DB->get_field('user_preferences', 'value', [
            'userid' => $userid, 'name' => 'auth_forcepasswordchange',
        ]));

        $updated = erp_exchange::push_entity('erp_test', 'employee', 'employee-created', [
            'company_external_id' => 'company-employee-sync',
            'firstname' => 'Updated',
        ]);
        $this->assertFalse($updated['account_created']);
        $this->assertTrue($updated['profile_changed']);
        $this->assertSame('Updated', $DB->get_field('user', 'firstname', ['id' => $userid]));

        $inactive = erp_exchange::push_entity('erp_test', 'employee', 'employee-created', [
            'company_external_id' => 'company-employee-sync',
            'active' => false,
        ]);
        $this->assertSame('employee_deactivated', $inactive['status']);
        $this->assertSame(0, (int)$DB->get_field('local_kbacorp_member', 'isemployee', [
            'companyid' => (int)$company['moodle_company_id'], 'userid' => $userid,
        ]));
        $this->assertSame(0, (int)$DB->get_field('user', 'suspended', ['id' => $userid]));
        $this->assertSame('inactive', $DB->get_field('local_kbacorp_extmap', 'status', [
            'companyid' => (int)$company['moodle_company_id'],
            'system' => 'erp_test', 'entitytype' => 'employee', 'externalid' => 'employee-created',
        ]));

        $reactivated = erp_exchange::push_entity('erp_test', 'employee', 'employee-created', [
            'company_external_id' => 'company-employee-sync',
            'active' => true,
        ]);
        $this->assertTrue($reactivated['membership_created']);
        $this->assertSame(1, (int)$DB->get_field('local_kbacorp_member', 'isemployee', [
            'companyid' => (int)$company['moodle_company_id'], 'userid' => $userid,
        ]));
    }

    public function test_employee_sync_preserves_preexisting_account_and_manual_membership(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user([
            'username' => 'manual.employee',
            'firstname' => 'Manual',
            'lastname' => 'Employee',
            'email' => 'manual.employee@example.invalid',
        ]);
        $company = erp_exchange::push_entity('erp_test', 'company', 'company-manual-user', [
            'name' => 'Manual User Company',
        ]);
        \local_kbacorporate\local\repository::add_member(
            (int)$company['moodle_company_id'], (int)$user->id, 'employee'
        );

        $linked = erp_exchange::push_entity('erp_test', 'employee', 'employee-manual', [
            'company_external_id' => 'company-manual-user',
            'moodle_user_id' => (int)$user->id,
            'firstname' => 'Must Not Overwrite',
        ]);
        $this->assertFalse($linked['managed_account']);
        $this->assertFalse($linked['profile_changed']);
        $this->assertSame('Manual', $DB->get_field('user', 'firstname', ['id' => $user->id]));

        $inactive = erp_exchange::push_entity('erp_test', 'employee', 'employee-manual', [
            'company_external_id' => 'company-manual-user',
            'active' => false,
        ]);
        $this->assertSame('manual_membership_preserved', $inactive['status']);
        $this->assertSame(1, (int)$DB->get_field('local_kbacorp_member', 'isemployee', [
            'companyid' => (int)$company['moodle_company_id'], 'userid' => (int)$user->id,
        ]));
    }

    public function test_employee_sync_requires_explicit_account_creation_opt_in(): void {
        $this->resetAfterTest();
        erp_exchange::push_entity('erp_test', 'company', 'company-no-auto-account', [
            'name' => 'No Automatic Account Company',
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        erp_exchange::push_entity('erp_test', 'employee', 'employee-no-opt-in', [
            'company_external_id' => 'company-no-auto-account',
            'username' => 'must.not.be.created',
            'firstname' => 'Must',
            'lastname' => 'Not Create',
            'email' => 'must.not.be.created@example.invalid',
        ]);
    }

    public function test_org_structure_and_role_sync_lifecycle(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $company = erp_exchange::push_entity('erp_test', 'company', 'org-company', [
            'name' => 'Organisation Company',
        ]);
        erp_exchange::push_entity('erp_test', 'employee', 'org-employee', [
            'company_external_id' => 'org-company',
            'moodle_user_id' => (int)$user->id,
        ]);

        $department = bridge::sync_erp_department('erp_test', 'department-sales', [
            'company_external_id' => 'org-company',
            'name' => 'Продажи',
            'code' => 'SALES',
        ]);
        $this->assertTrue($department['created']);
        $placement = bridge::sync_erp_department_membership('erp_test', 'placement-sales-1', [
            'company_external_id' => 'org-company',
            'department_external_id' => 'department-sales',
            'employee_external_id' => 'org-employee',
            'department_role' => 'employee',
        ]);
        $this->assertSame('assigned', $placement['status']);
        $this->assertTrue($DB->record_exists('local_kbacorp_dept_member', [
            'id' => (int)$placement['assignment_id'],
        ]));

        $role = bridge::sync_erp_business_role('erp_test', 'role-sales-head', [
            'company_external_id' => 'org-company',
            'role_code' => 'BMS-ROLE-SALES-HEAD',
            'name' => 'Руководитель продаж',
        ]);
        $this->assertTrue($role['created']);
        $this->assertFalse($role['competency_profile_changed']);
        $assigned = erp_exchange::push_entity('erp_test', 'business_role_assignment', 'role-assignment-1', [
            'company_external_id' => 'org-company',
            'employee_external_id' => 'org-employee',
            'role_code' => 'BMS-ROLE-SALES-HEAD',
        ]);
        $this->assertSame('assigned', $assigned['status']);

        $snapshot = erp_exchange::company_snapshot('erp_test', 'org-company');
        $this->assertCount(1, $snapshot['organisation']['departments']);
        $this->assertCount(1, $snapshot['organisation']['business_roles']);
        $this->assertSame('department-sales',
            $snapshot['organisation']['departments'][0]['external_id']);

        $placementoff = bridge::sync_erp_department_membership('erp_test', 'placement-sales-1', [
            'company_external_id' => 'org-company',
            'department_external_id' => 'department-sales',
            'employee_external_id' => 'org-employee',
            'department_role' => 'employee',
            'active' => false,
        ]);
        $this->assertSame('deactivated', $placementoff['status']);
        $this->assertFalse($DB->record_exists('local_kbacorp_dept_member', [
            'id' => (int)$placement['assignment_id'],
        ]));

        $roleoff = bridge::sync_erp_business_role('erp_test', 'role-sales-head', [
            'company_external_id' => 'org-company',
            'role_code' => 'BMS-ROLE-SALES-HEAD',
            'active' => false,
        ]);
        $this->assertSame('deactivated', $roleoff['status']);
        $this->assertSame(0, (int)$DB->get_field('local_kbacorp_busrole', 'active', [
            'id' => (int)$role['business_role_id'],
        ]));
        $this->assertTrue($DB->record_exists('local_kbacorp_member_busrole', [
            'id' => (int)$assigned['assignment_id'],
        ]));

        $departmentoff = bridge::sync_erp_department('erp_test', 'department-sales', [
            'company_external_id' => 'org-company',
            'active' => false,
        ]);
        $this->assertSame('deactivated', $departmentoff['status']);
        $this->assertSame((int)$company['moodle_company_id'], (int)$departmentoff['moodle_company_id']);
    }

    public function test_org_sync_preserves_manual_records_and_assignments(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $company = erp_exchange::push_entity('erp_test', 'company', 'manual-org-company', [
            'name' => 'Manual Organisation Company',
        ]);
        erp_exchange::push_entity('erp_test', 'employee', 'manual-org-employee', [
            'company_external_id' => 'manual-org-company',
            'moodle_user_id' => (int)$user->id,
        ]);
        $departmentid = \local_kbacorporate\local\repository::save_department((object)[
            'companyid' => (int)$company['moodle_company_id'],
            'name' => 'Ручное подразделение',
            'code' => 'MANUAL',
            'active' => 1,
        ]);
        $assignmentid = \local_kbacorporate\local\repository::add_department_member(
            $departmentid, (int)$user->id, 'employee'
        );
        $linked = erp_exchange::push_entity('erp_test', 'department', 'manual-department', [
            'company_external_id' => 'manual-org-company',
            'name' => 'Не перезаписывать',
            'code' => 'MANUAL',
        ]);
        $this->assertSame('manual_department_preserved', $linked['status']);
        $this->assertSame('Ручное подразделение', $DB->get_field(
            'local_kbacorp_department', 'name', ['id' => $departmentid]
        ));
        erp_exchange::push_entity('erp_test', 'department_membership', 'manual-placement', [
            'company_external_id' => 'manual-org-company',
            'department_external_id' => 'manual-department',
            'employee_external_id' => 'manual-org-employee',
            'department_role' => 'employee',
        ]);
        $off = erp_exchange::push_entity('erp_test', 'department_membership', 'manual-placement', [
            'company_external_id' => 'manual-org-company',
            'department_external_id' => 'manual-department',
            'employee_external_id' => 'manual-org-employee',
            'department_role' => 'employee',
            'active' => false,
        ]);
        $this->assertSame('manual_assignment_preserved', $off['status']);
        $this->assertTrue($DB->record_exists('local_kbacorp_dept_member', ['id' => $assignmentid]));

        $manualroleid = business_roles::save_role((object)[
            'companyid' => (int)$company['moodle_company_id'],
            'code' => 'BMS-ROLE-MANUAL-ORG',
            'name' => 'Ручная роль',
            'active' => 1,
        ]);
        $rolelink = erp_exchange::push_entity('erp_test', 'business_role', 'manual-business-role', [
            'company_external_id' => 'manual-org-company',
            'role_code' => 'BMS-ROLE-MANUAL-ORG',
            'name' => 'Не перезаписывать роль',
        ]);
        $this->assertSame('manual_business_role_preserved', $rolelink['status']);
        erp_exchange::push_entity('erp_test', 'business_role', 'manual-business-role', [
            'company_external_id' => 'manual-org-company',
            'role_code' => 'BMS-ROLE-MANUAL-ORG',
            'active' => false,
        ]);
        $this->assertSame(1, (int)$DB->get_field('local_kbacorp_busrole', 'active', [
            'id' => $manualroleid,
        ]));
    }

    public function test_learning_catalog_sync_does_not_enrol_or_change_learning_plans(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'ERP-COURSE-1']);
        $company = erp_exchange::push_entity('erp_test', 'company', 'learning-company', [
            'name' => 'Learning Company',
        ]);

        $created = bridge::sync_erp_learning_catalog_assignment('erp_test', 'catalogue-course-1', [
            'company_external_id' => 'learning-company',
            'course_idnumber' => 'ERP-COURSE-1',
        ]);
        $this->assertTrue($created['created']);
        $this->assertFalse($created['enrolment_changed']);
        $this->assertFalse($created['learning_plan_changed']);
        $this->assertFalse($created['competency_level_changed']);
        $this->assertSame(1, (int)$DB->get_field('local_kbacorp_course', 'active', [
            'id' => (int)$created['catalogue_mapping_id'],
        ]));

        $off = bridge::sync_erp_learning_catalog_assignment('erp_test', 'catalogue-course-1', [
            'company_external_id' => 'learning-company',
            'moodle_course_id' => (int)$course->id,
            'active' => false,
        ]);
        $this->assertSame('deactivated', $off['status']);
        $this->assertSame(0, (int)$DB->get_field('local_kbacorp_course', 'active', [
            'id' => (int)$created['catalogue_mapping_id'],
        ]));

        $manualcourse = $this->getDataGenerator()->create_course(['idnumber' => 'MANUAL-COURSE-1']);
        $manualmappingid = \local_kbacorporate\local\repository::add_course(
            (int)$company['moodle_company_id'], (int)$manualcourse->id
        );
        $manual = erp_exchange::push_entity('erp_test', 'learning_catalog_assignment',
            'manual-catalogue-course', [
                'company_external_id' => 'learning-company',
                'course_idnumber' => 'MANUAL-COURSE-1',
            ]);
        $this->assertSame('manual_catalogue_assignment_preserved', $manual['status']);
        erp_exchange::push_entity('erp_test', 'learning_catalog_assignment',
            'manual-catalogue-course', [
                'company_external_id' => 'learning-company',
                'course_idnumber' => 'MANUAL-COURSE-1',
                'active' => false,
            ]);
        $this->assertSame(1, (int)$DB->get_field('local_kbacorp_course', 'active', [
            'id' => $manualmappingid,
        ]));
    }

    public function test_economic_sync_is_unverified_idempotent_and_non_causal(): void {
        global $DB;
        $this->resetAfterTest();
        \local_kbacorporate\local\economic_indicators::seed_defaults();
        erp_exchange::push_entity('erp_test', 'company', 'economic-company', [
            'name' => 'Economic Company',
        ]);
        $payload = [
            'company_external_id' => 'economic-company',
            'indicator_code' => 'REV',
            'period_type' => 'month',
            'period_start' => make_timestamp(2026, 9, 1),
            'scenario' => 'actual',
            'value' => '1250000.50',
            'currency' => 'RUB',
        ];
        $created = bridge::sync_erp_economic_value('erp_test', 'rev-2026-09', $payload);
        $this->assertTrue($created['created']);
        $this->assertFalse($created['verified']);
        $this->assertFalse($created['causality_established']);
        $this->assertFalse($created['competency_level_changed']);
        $this->assertFalse($created['bms_maturity_changed']);
        $this->assertSame('erp', $DB->get_field('local_kbacorp_econ_val', 'source', [
            'id' => (int)$created['economic_value_id'],
        ]));
        $logcount = $DB->count_records('local_kbacorp_econ_log', [
            'entitytype' => 'value',
            'entityid' => (int)$created['economic_value_id'],
        ]);

        $again = bridge::sync_erp_economic_value('erp_test', 'rev-2026-09', $payload);
        $this->assertSame('unchanged', $again['status']);
        $this->assertSame($logcount, $DB->count_records('local_kbacorp_econ_log', [
            'entitytype' => 'value',
            'entityid' => (int)$created['economic_value_id'],
        ]));

        $payload['value'] = '1300000';
        $updated = bridge::sync_erp_economic_value('erp_test', 'rev-2026-09', $payload);
        $this->assertSame('updated', $updated['status']);
        $this->assertFalse($updated['verified']);
        $snapshot = erp_exchange::company_snapshot('erp_test', 'economic-company');
        $this->assertFalse($snapshot['economic_context']['causality_established']);
        $this->assertCount(1, $snapshot['economic_context']['values']);
    }

    public function test_economic_sync_never_overwrites_manual_value(): void {
        global $DB;
        $this->resetAfterTest();
        \local_kbacorporate\local\economic_indicators::seed_defaults();
        $company = erp_exchange::push_entity('erp_test', 'company', 'manual-economic-company', [
            'name' => 'Manual Economic Company',
        ]);
        $indicatorid = (int)$DB->get_field('local_kbacorp_econ_ind', 'id', [
            'companyid' => 0,
            'code' => 'REV',
        ], MUST_EXIST);
        \local_kbacorporate\local\economic_indicators::save_value((object)[
            'companyid' => (int)$company['moodle_company_id'],
            'indicatorid' => $indicatorid,
            'periodtype' => 'month',
            'periodstart' => make_timestamp(2026, 8, 1),
            'periodend' => make_timestamp(2026, 8, 31),
            'scenario' => 'actual',
            'value' => '100',
            'currency' => 'RUB',
            'source' => 'manual',
        ], 0);

        $this->expectException(\invalid_parameter_exception::class);
        bridge::sync_erp_economic_value('erp_test', 'manual-rev-2026-08', [
            'company_external_id' => 'manual-economic-company',
            'indicator_code' => 'REV',
            'period_type' => 'month',
            'period_start' => make_timestamp(2026, 8, 1),
            'value' => '999',
            'currency' => 'RUB',
        ]);
    }

    public function test_role_assignment_can_be_deactivated_by_external_id(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $company = erp_exchange::push_entity('bms_erp', 'company', 'c-1', ['name' => 'Company']);
        erp_exchange::push_entity('bms_erp', 'employee', 'e-1', [
            'company_external_id' => 'c-1', 'moodle_user_id' => (int)$user->id,
        ]);
        business_roles::save_role((object)[
            'companyid' => $company['moodle_company_id'],
            'code' => 'BMS-ROLE-OWNER', 'name' => 'Owner', 'active' => 1,
        ]);

        $assigned = erp_exchange::push_entity('bms_erp', 'business_role_assignment', 'a-1', [
            'company_external_id' => 'c-1', 'employee_external_id' => 'e-1',
            'role_code' => 'BMS-ROLE-OWNER', 'active' => true,
        ]);
        $this->assertTrue($assigned['active']);
        $this->assertCount(1, business_roles::get_user_roles(
            (int)$user->id, (int)$company['moodle_company_id']
        ));

        $removed = erp_exchange::push_entity('bms_erp', 'business_role_assignment', 'a-1', [
            'company_external_id' => 'c-1', 'employee_external_id' => 'e-1',
            'role_code' => 'BMS-ROLE-OWNER', 'active' => false,
        ]);
        $this->assertSame('deactivated', $removed['status']);
        $this->assertCount(0, business_roles::get_user_roles(
            (int)$user->id, (int)$company['moodle_company_id']
        ));
        $this->assertSame('inactive', $DB->get_field('local_kbacorp_extmap', 'status', [
            'companyid' => $company['moodle_company_id'],
            'system' => 'bms_erp', 'entitytype' => 'business_role_assignment', 'externalid' => 'a-1',
        ]));
    }

    public function test_snapshot_is_paged_and_does_not_export_pii(): void {
        $this->resetAfterTest();
        $users = [$this->getDataGenerator()->create_user(), $this->getDataGenerator()->create_user()];
        erp_exchange::push_entity('odoo', 'company', 'company-1', ['name' => 'Company']);
        foreach ($users as $index => $user) {
            erp_exchange::push_entity('odoo', 'employee', 'employee-' . ($index + 1), [
                'company_external_id' => 'company-1', 'moodle_user_id' => (int)$user->id,
            ]);
        }
        $page = erp_exchange::company_snapshot('odoo', 'company-1', 0, 1);
        $this->assertCount(1, $page['employees']);
        $this->assertTrue($page['page']['has_more']);
        $this->assertArrayNotHasKey('email', $page['employees'][0]);
        $this->assertArrayNotHasKey('firstname', $page['employees'][0]);
        $next = erp_exchange::company_snapshot(
            'odoo', 'company-1', $page['page']['next_after_moodle_user_id'], 1
        );
        $this->assertCount(1, $next['employees']);
        $this->assertFalse($next['page']['has_more']);
    }

    public function test_erp_does_not_deactivate_a_preexisting_manual_role_assignment(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $company = erp_exchange::push_entity('odoo', 'company', 'company-x', ['name' => 'Company']);
        erp_exchange::push_entity('odoo', 'employee', 'employee-x', [
            'company_external_id' => 'company-x', 'moodle_user_id' => (int)$user->id,
        ]);
        $roleid = business_roles::save_role((object)[
            'companyid' => $company['moodle_company_id'],
            'code' => 'BMS-ROLE-MANUAL', 'name' => 'Manual role', 'active' => 1,
        ]);
        $assignmentid = business_roles::assign_user_role(
            (int)$company['moodle_company_id'], (int)$user->id, $roleid, 'manual'
        );
        $result = erp_exchange::push_entity('odoo', 'business_role_assignment', 'external-a', [
            'company_external_id' => 'company-x', 'employee_external_id' => 'employee-x',
            'role_code' => 'BMS-ROLE-MANUAL', 'active' => true,
        ]);
        $this->assertSame('already_assigned_preserved', $result['status']);
        erp_exchange::push_entity('odoo', 'business_role_assignment', 'external-a', [
            'company_external_id' => 'company-x', 'employee_external_id' => 'employee-x',
            'role_code' => 'BMS-ROLE-MANUAL', 'active' => false,
        ]);
        $assignment = $DB->get_record('local_kbacorp_member_busrole', ['id' => $assignmentid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$assignment->active);
        $this->assertSame('manual', (string)$assignment->source);
    }
}
