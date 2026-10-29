<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\employee_service;
use local_kbacompany\local\employee_ui_service;

/** Contract regression source for the Employee BMS CRUD UX. */
class employee_crm_service_test extends \advanced_testcase {
    public function test_employee_contract_keeps_soft_delete_and_moodle_user_identity(): void {
        $contract = employee_service::contract();
        $this->assertSame('0.6.9.9.2', $contract['version']);
        $this->assertSame('moodle_user', $contract['person_identity']);
        $this->assertContains('employee_profile_edit_updates_moodle_user_and_queues_sync', $contract['rules']);
        $this->assertContains('employee_delete_is_soft_membership_close_not_person_delete', $contract['rules']);
        $this->assertContains('employee_list_search_is_company_scoped_server_side_for_canonical_memberships', $contract['rules']);
        $this->assertContains('employee_organization_data_is_managed_by_staffing_not_profile_form', $contract['rules']);
    }

    public function test_employee_ui_schema_separates_identity_contacts_and_organization(): void {
        $schema = employee_ui_service::form_schema('edit');
        $this->assertCount(3, $schema);
        $this->assertSame('identity', $schema[0]['key']);
        $this->assertSame(['firstname', 'lastname'], $schema[0]['fields']);
        $this->assertSame('contacts', $schema[1]['key']);
        $this->assertSame(['email', 'phone'], $schema[1]['fields']);
        $this->assertSame('organization', $schema[2]['key']);
        $this->assertTrue($schema[2]['readonly']);
    }
}
