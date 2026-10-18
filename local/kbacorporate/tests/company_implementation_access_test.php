<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\access;
use local_kbacorporate\local\repository;

/** Regression tests for customer-scoped BMS implementation access. */
final class company_implementation_access_test extends \advanced_testcase {
    /** Create one active company record. */
    private function create_company(string $name): int {
        global $DB;

        return (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => $name,
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    public function test_corporate_manager_can_manage_only_own_company_implementation(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $owncompanyid = $this->create_company('Own BMS Company');
        $othercompanyid = $this->create_company('Other BMS Company');
        repository::add_member($owncompanyid, (int)$user->id, 'manager');

        $context = \context_system::instance();
        $this->assertTrue(has_capability(
            'local/kbacorporate:managecompanyimplementation',
            $context,
            (int)$user->id
        ));
        $this->assertFalse(has_capability(
            'local/kbacorporate:manageimplementationaudits',
            $context,
            (int)$user->id
        ));
        $this->assertTrue(access::can_manage_company_implementation(
            $owncompanyid,
            (int)$user->id
        ));
        $this->assertFalse(access::can_manage_company_implementation(
            $othercompanyid,
            (int)$user->id
        ));
    }

    public function test_capability_without_company_membership_does_not_grant_access(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Membership Guard Company');
        $context = \context_system::instance();
        $roleid = create_role('Scoped implementation tester', 'scopedimplementationtester', '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability(
            'local/kbacorporate:managecompanyimplementation',
            CAP_ALLOW,
            $roleid,
            $context->id,
            true
        );
        role_assign($roleid, (int)$user->id, $context->id);

        $this->assertTrue(has_capability(
            'local/kbacorporate:managecompanyimplementation',
            $context,
            (int)$user->id
        ));
        $this->assertFalse(access::can_manage_company_implementation(
            $companyid,
            (int)$user->id
        ));
    }

    public function test_require_access_returns_company_for_scoped_manager(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $companyid = $this->create_company('Required Access Company');
        repository::add_member($companyid, (int)$user->id, 'manager');

        $company = access::require_company_implementation_access(
            $companyid,
            (int)$user->id
        );
        $this->assertSame($companyid, (int)$company->id);
    }
}
