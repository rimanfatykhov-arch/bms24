<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\access_role_service;
use local_kbacompany\local\identity_registry;
use local_kbacorporate\local\repository;

/** Company-scoped RBAC regression; requires Moodle PHPUnit runtime. */
final class access_role_service_test extends \advanced_testcase {
    public function test_custom_role_is_company_scoped_and_revocable(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int)$USER->id;
        $employee = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();
        $firstid = repository::save_company((object)['name' => 'RBAC company A', 'active' => 1]);
        $secondid = repository::save_company((object)['name' => 'RBAC company B', 'active' => 1]);
        $first = identity_registry::company_uuid($firstid);
        $second = identity_registry::company_uuid($secondid);
        $now = time();
        foreach ([[$firstid, $employee->id], [$secondid, $outsider->id]] as $row) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $row[0], 'userid' => $row[1], 'membertype' => 'employee',
                'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0,
                'ishr' => 0, 'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $roleid = access_role_service::create_role($first, $adminid, 'CEO limited', 'Access test');
        access_role_service::set_grant($first, $adminid, $roleid, 'management_cycle', 'view', true);
        $this->assertFalse(access_role_service::can($first, (int)$employee->id, 'management_cycle', 'view'));
        access_role_service::assign($first, $adminid, $roleid, (int)$employee->id, true);
        $this->assertTrue(access_role_service::can($first, (int)$employee->id, 'management_cycle', 'view'));
        $this->assertFalse(access_role_service::can($first, (int)$employee->id, 'management_cycle', 'edit'));
        $this->assertFalse(access_role_service::can($first, (int)$employee->id, 'document', 'view'));
        $this->assertFalse(access_role_service::can($second, (int)$outsider->id, 'management_cycle', 'view'));
        access_role_service::assign($first, $adminid, $roleid, (int)$employee->id, false);
        $this->assertFalse(access_role_service::can($first, (int)$employee->id, 'management_cycle', 'view'));
        $this->assertGreaterThanOrEqual(4, $DB->count_records(access_role_service::AUDIT_TABLE,
            ['companyuuid' => $first]));
    }

    public function test_separate_grants_multiple_roles_and_immediate_revocation(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int)$USER->id;
        $employee = $this->getDataGenerator()->create_user();
        $companyid = repository::save_company((object)['name' => 'RBAC granular cycles', 'active' => 1]);
        $uuid = identity_registry::company_uuid($companyid);
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid, 'userid' => $employee->id, 'membertype' => 'employee',
            'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0,
            'ishr' => 0, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $reader = access_role_service::create_role($uuid, $adminid, 'Cycle reader', '');
        $editor = access_role_service::create_role($uuid, $adminid, 'Cycle editor', '');
        access_role_service::set_grant($uuid, $adminid, $reader, 'management_cycle', 'view', true);
        access_role_service::set_grant($uuid, $adminid, $editor, 'management_cycle', 'edit', true);
        access_role_service::assign($uuid, $adminid, $reader, (int)$employee->id, true);
        $this->assertTrue(access_role_service::can($uuid, (int)$employee->id, 'management_cycle', 'view'));
        $this->assertFalse(access_role_service::can($uuid, (int)$employee->id, 'management_cycle', 'edit'));
        $this->assertFalse(access_role_service::can($uuid, (int)$employee->id, 'management_cycle', 'create'));
        access_role_service::assign($uuid, $adminid, $editor, (int)$employee->id, true);
        $this->assertTrue(access_role_service::can($uuid, (int)$employee->id, 'management_cycle', 'edit'));
        access_role_service::assign($uuid, $adminid, $editor, (int)$employee->id, false);
        $this->assertFalse(access_role_service::can($uuid, (int)$employee->id, 'management_cycle', 'edit'));
        $this->assertTrue(access_role_service::can($uuid, (int)$employee->id, 'management_cycle', 'view'));
        access_role_service::set_grant($uuid, $adminid, $reader, 'management_cycle', 'view', false);
        $this->assertFalse(access_role_service::can($uuid, (int)$employee->id, 'management_cycle', 'view'));
        $this->assertFalse(access_role_service::can($uuid, (int)$employee->id, 'employee', 'view'));
        $this->assertFalse(access_role_service::can($uuid, (int)$employee->id, 'document', 'view'));
    }

    public function test_invalid_company_grant_is_not_configurable(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $companyid = repository::save_company((object)['name' => 'RBAC scope check', 'active' => 1]);
        $uuid = identity_registry::company_uuid($companyid);
        $id = access_role_service::create_role($uuid, (int)$USER->id, 'Scoped reader', '');
        $this->expectException(\invalid_parameter_exception::class);
        access_role_service::set_grant($uuid, (int)$USER->id, $id, 'employee', 'view', true);
    }
}
