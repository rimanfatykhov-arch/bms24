<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\repository;
use local_kbacorporate\local\target_profile;

/** Tests for target aggregation inside one company. */
final class target_profile_test extends \advanced_testcase {
    public function test_profile_does_not_merge_roles_from_another_company(): void {
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
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        foreach ($companyids as $index => $companyid) {
            $roleid = business_roles::save_role((object)[
                'companyid' => $companyid,
                'code' => 'BMS-ROLE-COMPANY-' . ($index + 1),
                'name' => 'Company role ' . ($index + 1),
                'active' => 1,
            ]);
            business_roles::set_role_competency(
                $roleid,
                $competency->get('id'),
                $index + 1,
                $index === 0 ? 'core' : 'critical',
                true
            );
            business_roles::assign_user_role($companyid, (int)$user->id, $roleid);
        }

        $profilea = target_profile::for_user((int)$user->id, $companyids[0]);
        $profileb = target_profile::for_user((int)$user->id, $companyids[1]);
        $this->assertSame(1, $profilea[$competency->get('id')]->targetlevel);
        $this->assertSame(2, $profileb[$competency->get('id')]->targetlevel);
        $this->assertCount(1, $profilea[$competency->get('id')]->roles);
        $this->assertCount(1, $profileb[$competency->get('id')]->roles);
    }

    public function test_profile_uses_highest_target_and_strongest_priority(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Target Company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$user->id, 'employee');

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        $roleone = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-ONE',
            'name' => 'Role one',
            'active' => 1,
        ]);
        $roletwo = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-TWO',
            'name' => 'Role two',
            'active' => 1,
        ]);
        business_roles::set_role_competency($roleone, $competency->get('id'), 2, 'core', false);
        business_roles::set_role_competency($roletwo, $competency->get('id'), 3, 'critical', true);
        business_roles::assign_user_role($companyid, (int)$user->id, $roleone);
        business_roles::assign_user_role($companyid, (int)$user->id, $roletwo);

        $profile = target_profile::for_user((int)$user->id, $companyid);
        $item = $profile[$competency->get('id')];
        $this->assertSame(3, $item->targetlevel);
        $this->assertSame('critical', $item->priority);
        $this->assertTrue($item->required);
        $this->assertCount(2, $item->roles);
        $this->assertSame([], target_profile::for_user((int)$user->id, 0));
    }
}
