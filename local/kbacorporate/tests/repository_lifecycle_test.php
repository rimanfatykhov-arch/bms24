<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\repository;

/** Lifecycle tests for membership and business-role assignments. */
final class repository_lifecycle_test extends \advanced_testcase {
    public function test_removing_employee_flag_preserves_business_roles_while_other_access_role_remains(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Lifecycle Company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $memberid = repository::add_member($companyid, (int)$user->id, 'employee');
        repository::add_member($companyid, (int)$user->id, 'manager');
        $roleid = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-LIFECYCLE',
            'name' => 'Lifecycle role',
            'active' => 1,
        ]);
        business_roles::assign_user_role($companyid, (int)$user->id, $roleid);

        repository::remove_member_role($memberid, $companyid, 'employee');
        $this->assertTrue($DB->record_exists('local_kbacorp_member_busrole', [
            'companyid' => $companyid,
            'userid' => (int)$user->id,
            'roleid' => $roleid,
        ]));

        repository::delete_member($memberid, $companyid);
        $this->assertFalse($DB->record_exists('local_kbacorp_member_busrole', [
            'companyid' => $companyid,
            'userid' => (int)$user->id,
        ]));
    }
}
