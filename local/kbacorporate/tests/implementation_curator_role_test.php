<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_curator_role;

/** Least privilege and backward compatibility for assigned implementation curators. */
final class implementation_curator_role_test extends \advanced_testcase {
    public function test_new_capability_can_be_assigned_without_program_admin_rights(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();
        $roleid = implementation_curator_role::provision_role();
        $this->assertGreaterThan(0, $roleid);
        $this->assertSame($roleid, implementation_curator_role::provision_role());
        $this->assertTrue(has_capability('local/kbacorporate:curateimplementation', $context,
            (int)get_admin()->id));
        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, (int)$user->id, $context->id);
        $this->assertTrue(implementation_curator_role::is_eligible((int)$user->id));
        $this->assertFalse(has_capability('local/kbacorporate:manageimplementationprograms',
            $context, (int)$user->id));
        $this->assertFalse(has_capability('local/kbacorporate:manageimplementationaudits',
            $context, (int)$user->id));
        $this->assertFalse(has_capability('local/kbacorporate:managecompanies',
            $context, (int)$user->id));
        $this->assertArrayHasKey((int)$user->id, implementation_curator_role::candidates());
    }

    public function test_regular_user_not_eligible_and_legacy_appointment_preserved(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->assertFalse(implementation_curator_role::is_eligible((int)$user->id));
        $this->assertTrue(implementation_curator_role::is_eligible((int)$user->id,
            (int)$user->id));
        $this->assertFalse(implementation_curator_role::is_eligible(0));
    }

    public function test_provisioning_is_restricted_to_role_admin(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        implementation_curator_role::provision_role();
    }
}
