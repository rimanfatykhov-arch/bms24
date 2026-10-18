<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\access;
use local_kbacorporate\local\repository;

/** Tests for deterministic company context selection. */
final class access_test extends \advanced_testcase {
    public function test_single_self_membership_is_selected_automatically(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Only Company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$user->id, 'employee');

        $selected = access::resolve_competency_company_context((int)$user->id, (int)$user->id, 0);
        $this->assertNotNull($selected);
        $this->assertSame($companyid, (int)$selected->id);
    }

    public function test_multiple_self_memberships_require_explicit_company(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyids = [];
        foreach (['Company A', 'Company B'] as $name) {
            $companyids[] = (int)$DB->insert_record('local_kbacorp_company', (object)[
                'name' => $name,
                'active' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        foreach ($companyids as $companyid) {
            repository::add_member($companyid, (int)$user->id, 'employee');
        }

        $this->assertNull(access::resolve_competency_company_context((int)$user->id, (int)$user->id, 0));
        $selected = access::resolve_competency_company_context(
            (int)$user->id,
            (int)$user->id,
            $companyids[1]
        );
        $this->assertSame($companyids[1], (int)$selected->id);
    }
}
