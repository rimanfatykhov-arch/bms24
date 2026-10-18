<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\employee_gap;
use local_kbacorporate\local\repository;

/** Regression tests for the company-scoped employee GAP projection. */
final class employee_gap_test extends \advanced_testcase {
    public function test_employee_without_role_has_explicit_state(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'GAP Company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$user->id, 'employee');

        $result = employee_gap::calculate($companyid, (int)$user->id);
        $this->assertSame(employee_gap::STATE_NO_ROLES, $result->state);
        $this->assertSame(0, $result->counts->roles);
        $this->assertSame(0, $result->counts->competencies);
        $this->assertSame([], $result->rows);
    }

    public function test_confirmed_levels_create_known_gap_and_unassessed_is_not_zero(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Employee GAP Company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$user->id, 'employee');

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $gapcompetency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'BMS-C99-01',
            'shortname' => 'Known GAP competency',
        ]);
        $metcompetency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'BMS-C99-02',
            'shortname' => 'Met competency',
        ]);
        $unknowncompetency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'BMS-C99-03',
            'shortname' => 'Unassessed competency',
        ]);

        $roleid = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-GAP-TEST',
            'name' => 'GAP test role',
            'active' => 1,
        ]);
        business_roles::set_role_competency($roleid, (int)$gapcompetency->get('id'), 3, 'critical', true);
        business_roles::set_role_competency($roleid, (int)$metcompetency->get('id'), 1, 'core', true);
        business_roles::set_role_competency($roleid, (int)$unknowncompetency->get('id'), 2, 'additional', false);
        business_roles::assign_user_role($companyid, (int)$user->id, $roleid);

        // Core stores custom-scale grades as one-based positions. Grade 2 maps to KBA level 1.
        \core_competency\api::grade_competency(
            (int)$user->id, (int)$gapcompetency->get('id'), 2, 'Confirmed current level'
        );
        \core_competency\api::grade_competency(
            (int)$user->id, (int)$metcompetency->get('id'), 2, 'Confirmed target level'
        );

        $result = employee_gap::calculate($companyid, (int)$user->id);
        $this->assertSame(employee_gap::STATE_NEEDS_ASSESSMENT, $result->state);
        $this->assertSame(1, $result->counts->roles);
        $this->assertSame(3, $result->counts->competencies);
        $this->assertSame(2, $result->counts->mandatory);
        $this->assertSame(1, $result->counts->supporting);
        $this->assertSame(2, $result->counts->assessed);
        $this->assertSame(1, $result->counts->unassessed);
        $this->assertSame(1, $result->counts->gaps);
        $this->assertSame(1, $result->counts->mandatorygaps);
        $this->assertSame(1, $result->counts->criticalgaps);
        $this->assertSame(1, $result->counts->met);
        $this->assertSame(2, $result->counts->gappoints);
        $this->assertSame(67, $result->counts->assessmentcoverage);
        $this->assertSame(33, $result->counts->targetreadiness);

        $gaprows = employee_gap::filter($result->rows, employee_gap::ROW_GAP);
        $this->assertCount(1, $gaprows);
        $this->assertSame('BMS-C99-01', $gaprows[0]->idnumber);
        $this->assertSame(2, $gaprows[0]->gap);

        $supportingrows = employee_gap::filter($result->rows, 'all', 'all', 'supporting');
        $this->assertCount(1, $supportingrows);
        $this->assertSame(employee_gap::ROW_UNASSESSED, $supportingrows[0]->status);
        $this->assertNull($supportingrows[0]->currentlevel);
        $this->assertNull($supportingrows[0]->gap);
    }
}
