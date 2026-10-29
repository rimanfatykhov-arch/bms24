<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\permission_service;
use local_kbacorporate\local\repository;

/** Central company permission policy regression tests. */
final class permission_service_test extends \advanced_testcase {
    public function test_company_roles_are_applied_consistently(): void {
        global $DB;
        $this->resetAfterTest();

        $owner = $this->getDataGenerator()->create_user();
        $hr = $this->getDataGenerator()->create_user();
        $employee = $this->getDataGenerator()->create_user();
        $companyid = repository::save_company((object)['name' => 'Permissions', 'active' => 1]);
        $now = time();
        foreach ([
            [$owner, 'owner', 1, 0],
            [$hr, 'employee', 0, 1],
            [$employee, 'employee', 0, 0],
        ] as [$user, $type, $manager, $ishr]) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $companyid,
                'userid' => $user->id,
                'membertype' => $type,
                'isemployee' => 1,
                'ismanager' => $manager,
                'isdepartmentmanager' => 0,
                'ishr' => $ishr,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        $company = identity_registry::company_uuid($companyid);

        $this->assertTrue(permission_service::can_manage_organization($company, (int)$owner->id));
        $this->assertTrue(permission_service::can_manage_management($company, (int)$owner->id));
        $this->assertTrue(permission_service::can_manage_tasks($company, (int)$owner->id));
        $this->assertTrue(permission_service::can_manage_communications($company, (int)$owner->id));
        $this->assertTrue(permission_service::can_manage_organization($company, (int)$hr->id));
        $this->assertFalse(permission_service::can_manage_management($company, (int)$hr->id));
        $this->assertFalse(permission_service::can_manage_tasks($company, (int)$hr->id));
        $this->assertFalse(permission_service::can_manage_communications($company, (int)$hr->id));
        $this->assertFalse(permission_service::can_manage_organization($company, (int)$employee->id));
        $this->assertFalse(permission_service::can_manage_management($company, (int)$employee->id));
        $this->assertFalse(permission_service::can_manage_tasks($company, (int)$employee->id));
        $this->assertFalse(permission_service::can_manage_communications($company, (int)$employee->id));
    }

    public function test_site_admin_can_manage_authorised_company_context(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $companyid = repository::save_company((object)['name' => 'Admin permissions', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);

        $this->assertTrue(permission_service::can_manage_organization($company, (int)$USER->id));
        $this->assertTrue(permission_service::can_manage_management($company, (int)$USER->id));
        $this->assertTrue(permission_service::can_manage_tasks($company, (int)$USER->id));
        $this->assertTrue(permission_service::can_manage_communications($company, (int)$USER->id));
    }
}
