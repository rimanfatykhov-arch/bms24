<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\bridge;
use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\repository;

/** Tests for company isolation in the business-role domain. */
final class business_roles_test extends \advanced_testcase {
    public function test_role_queries_require_explicit_company_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Company A');
        repository::add_member($companyid, (int)$user->id, 'employee');
        $roleid = business_roles::save_role((object)[
            'companyid' => 0,
            'code' => 'BMS-ROLE-EMPLOYEE',
            'name' => 'Employee',
            'active' => 1,
        ]);
        business_roles::assign_user_role($companyid, (int)$user->id, $roleid);

        $this->assertSame([], business_roles::get_user_roles((int)$user->id, 0));
        $this->assertCount(1, business_roles::get_user_roles((int)$user->id, $companyid));
    }

    public function test_global_catalog_does_not_include_other_company_roles(): void {
        $this->resetAfterTest();
        $companyid = $this->create_company('Company B');
        business_roles::save_role((object)[
            'companyid' => 0,
            'code' => 'BMS-ROLE-GLOBAL',
            'name' => 'Global role',
            'active' => 1,
        ]);
        business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-COMPANY',
            'name' => 'Company role',
            'active' => 1,
        ]);

        $global = bridge::get_business_role_catalog(0);
        $scoped = bridge::get_business_role_catalog($companyid);
        $this->assertCount(1, $global['roles']);
        $this->assertSame('BMS-ROLE-GLOBAL', $global['roles'][0]['code']);
        $this->assertCount(2, $scoped['roles']);
    }


    public function test_bms_domain_competency_codes_are_detected(): void {
        $this->assertTrue(business_roles::is_domain_competency_code('BMS-D00'));
        $this->assertTrue(business_roles::is_domain_competency_code('bms-d14'));
        $this->assertFalse(business_roles::is_domain_competency_code('BMS-C00-01'));
        $this->assertFalse(business_roles::is_domain_competency_code('BMS-D001'));
        $this->assertFalse(business_roles::is_domain_competency_code(''));
    }

    private function create_company(string $name): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => $name,
            'active' => 1,
            'trainingminutesday' => 90,
            'trainingweekdays' => '1,2,3,4,5',
            'alertsenabled' => 0,
            'alertfrequency' => 'weekly',
            'lastalertsent' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
