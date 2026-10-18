<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\repository;

/** Tests for additive role-profile management operations. */
final class business_role_management_test extends \advanced_testcase {
    public function test_catalog_is_scoped_to_one_company_plus_global_roles(): void {
        $this->resetAfterTest();
        $companya = $this->create_company('Company A');
        $companyb = $this->create_company('Company B');
        business_roles::save_role((object)[
            'companyid' => 0,
            'code' => 'BMS-ROLE-GLOBAL',
            'name' => 'Global',
            'active' => 1,
        ]);
        business_roles::save_role((object)[
            'companyid' => $companya,
            'code' => 'BMS-ROLE-A',
            'name' => 'Role A',
            'active' => 1,
        ]);
        business_roles::save_role((object)[
            'companyid' => $companyb,
            'code' => 'BMS-ROLE-B',
            'name' => 'Role B',
            'active' => 1,
        ]);

        $catalog = business_roles::get_catalog($companya);
        $this->assertCount(2, $catalog);
        $this->assertSame(['BMS-ROLE-GLOBAL', 'BMS-ROLE-A'], array_column($catalog, 'code'));
        $this->assertCount(1, business_roles::get_catalog(0));
    }

    public function test_assignments_are_additive_and_one_role_can_be_deactivated_explicitly(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company('Company');
        $user = $this->getDataGenerator()->create_user();
        repository::add_member($companyid, (int)$user->id, 'employee');
        $roleone = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-ONE',
            'name' => 'One',
            'active' => 1,
        ]);
        $roletwo = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-TWO',
            'name' => 'Two',
            'active' => 1,
        ]);

        $assignmentone = business_roles::assign_user_role(
            $companyid,
            (int)$user->id,
            $roleone,
            'odoo',
            'external-role-1'
        );
        business_roles::assign_user_role($companyid, (int)$user->id, $roletwo, 'manual');
        business_roles::assign_user_role($companyid, (int)$user->id, $roleone, 'manual', '', true);
        $this->assertCount(2, business_roles::get_company_assignments($companyid, (int)$user->id));
        $this->assertCount(2, business_roles::get_user_roles((int)$user->id, $companyid));
        $this->assertSame(2, $DB->count_records('local_kbacorp_member_busrole', [
            'companyid' => $companyid,
            'userid' => $user->id,
        ]));
        $preserved = $DB->get_record('local_kbacorp_member_busrole', ['id' => $assignmentone]);
        $this->assertSame('odoo', $preserved->source);
        $this->assertSame('external-role-1', $preserved->externalref);

        business_roles::deactivate_user_role($companyid, $assignmentone);
        $remaining = business_roles::get_company_assignments($companyid, (int)$user->id);
        $this->assertCount(1, $remaining);
        $this->assertSame($roletwo, (int)$remaining[0]->roleid);

        $reactivatedid = business_roles::assign_user_role(
            $companyid,
            (int)$user->id,
            $roleone,
            'manual',
            '',
            true
        );
        $this->assertSame($assignmentone, $reactivatedid);
        $this->assertCount(2, business_roles::get_company_assignments($companyid, (int)$user->id));
    }

    public function test_competency_requirement_is_upserted_and_removed_explicitly(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company('Company');
        $roleid = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-PROFILE',
            'name' => 'Profile',
            'active' => 1,
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);

        $requirementid = business_roles::set_role_competency(
            $roleid,
            (int)$competency->get('id'),
            2,
            'core',
            true
        );
        $updatedid = business_roles::set_role_competency(
            $roleid,
            (int)$competency->get('id'),
            3,
            'critical',
            true
        );
        $this->assertSame($requirementid, $updatedid);
        $this->assertSame(3, (int)$DB->get_field('local_kbacorp_rolecomp', 'targetlevel', [
            'id' => $requirementid,
        ]));

        business_roles::remove_role_competency($roleid, $requirementid);
        $this->assertFalse($DB->record_exists('local_kbacorp_rolecomp', ['id' => $requirementid]));
    }

    public function test_company_role_is_created_as_independent_template_copy(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company('Template Company');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        $templateid = business_roles::save_role((object)[
            'companyid' => 0,
            'code' => 'BMS-ROLE-TEMPLATE-OWNER',
            'name' => 'Owner template',
            'active' => 1,
        ]);
        business_roles::set_role_competency(
            $templateid, (int)$competency->get('id'), 3, 'critical', true
        );

        $roleid = business_roles::create_from_template($templateid, $companyid);
        $role = business_roles::get_role($roleid);
        $template = business_roles::get_role($templateid);
        $this->assertSame($companyid, (int)$role->companyid);
        $this->assertSame($templateid, (int)$role->templateid);
        $this->assertSame((int)$template->templateversion, (int)$role->appliedversion);
        $this->assertSame('template', $role->source);
        $requirement = $DB->get_record('local_kbacorp_rolecomp', ['roleid' => $roleid], '*', MUST_EXIST);
        $this->assertSame('template', $requirement->source);
        $this->assertSame(3, (int)$requirement->targetlevel);
        $this->assertSame($roleid, business_roles::create_from_template($templateid, $companyid));
    }

    public function test_template_update_preserves_manual_override_and_adds_new_requirement(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company('Update Company');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competencyone = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        $competencytwo = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        $templateid = business_roles::save_role((object)[
            'companyid' => 0,
            'code' => 'BMS-ROLE-TEMPLATE-UPDATE',
            'name' => 'Update template',
            'active' => 1,
        ]);
        business_roles::set_role_competency(
            $templateid, (int)$competencyone->get('id'), 2, 'core', true
        );
        $roleid = business_roles::create_from_template($templateid, $companyid);
        business_roles::set_role_competency(
            $roleid, (int)$competencyone->get('id'), 1, 'additional', false, 'manual'
        );
        business_roles::set_role_competency(
            $templateid, (int)$competencyone->get('id'), 3, 'critical', true
        );
        business_roles::set_role_competency(
            $templateid, (int)$competencytwo->get('id'), 2, 'core', true
        );

        $result = business_roles::apply_template_update($roleid);
        $manual = $DB->get_record('local_kbacorp_rolecomp', [
            'roleid' => $roleid,
            'competencyid' => (int)$competencyone->get('id'),
        ], '*', MUST_EXIST);
        $added = $DB->get_record('local_kbacorp_rolecomp', [
            'roleid' => $roleid,
            'competencyid' => (int)$competencytwo->get('id'),
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int)$manual->targetlevel);
        $this->assertSame('manual', $manual->source);
        $this->assertSame('template', $added->source);
        $this->assertSame(1, $result->preserved);
        $this->assertSame(1, $result->added);
        $role = business_roles::get_role($roleid);
        $template = business_roles::get_role($templateid);
        $this->assertSame((int)$template->templateversion, (int)$role->appliedversion);
    }

    public function test_template_rollout_reports_company_versions_and_update_state(): void {
        $this->resetAfterTest();
        $companya = $this->create_company('Alpha Company');
        $companyb = $this->create_company('Beta Company');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        $templateid = business_roles::save_role((object)[
            'companyid' => 0,
            'code' => 'BMS-ROLE-ROLLOUT',
            'name' => 'Rollout template',
            'active' => 1,
        ]);
        business_roles::set_role_competency(
            $templateid, (int)$competency->get('id'), 2, 'core', true
        );
        $rolea = business_roles::create_from_template($templateid, $companya);
        business_roles::create_from_template($templateid, $companyb);

        business_roles::set_role_competency(
            $templateid, (int)$competency->get('id'), 3, 'critical', true
        );
        business_roles::apply_template_update($rolea);

        $rollout = business_roles::get_template_rollout($templateid);
        $this->assertCount(2, $rollout);
        $this->assertSame('Alpha Company', $rollout[0]->companyname);
        $this->assertFalse($rollout[0]->updateavailable);
        $this->assertSame($rollout[0]->availableversion, (int)$rollout[0]->appliedversion);
        $this->assertSame('Beta Company', $rollout[1]->companyname);
        $this->assertTrue($rollout[1]->updateavailable);
        $this->assertGreaterThan((int)$rollout[1]->appliedversion, $rollout[1]->availableversion);
    }

    public function test_template_versions_store_snapshots_and_compare_changes(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competencyone = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'First competency',
        ]);
        $competencytwo = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Second competency',
        ]);
        $templateid = business_roles::save_role((object)[
            'companyid' => 0,
            'code' => 'BMS-ROLE-VERSIONED',
            'name' => 'Versioned template',
            'active' => 1,
            'changedby' => (int)$user->id,
            'changenote' => 'Initial template',
        ]);
        business_roles::set_role_competency(
            $templateid, (int)$competencyone->get('id'), 1, 'core', true, 'manual',
            'Initial profile', (int)$user->id
        );
        $versions = business_roles::get_template_versions($templateid);
        $versiontwo = $versions[0];

        business_roles::set_role_competency(
            $templateid, (int)$competencyone->get('id'), 3, 'critical', true, 'manual',
            'Raise target', (int)$user->id
        );
        business_roles::set_role_competency(
            $templateid, (int)$competencytwo->get('id'), 2, 'additional', false, 'manual',
            'Add second competency', (int)$user->id
        );
        $versions = business_roles::get_template_versions($templateid);
        $latest = $versions[0];
        $this->assertSame(4, (int)$latest->version);
        $this->assertSame('Add second competency', $latest->changenote);
        $this->assertSame((int)$user->id, (int)$latest->createdby);

        $diff = business_roles::compare_template_versions((int)$versiontwo->id, (int)$latest->id);
        $this->assertCount(1, $diff->added);
        $this->assertCount(1, $diff->changed);
        $this->assertCount(0, $diff->removed);
        $this->assertSame(1, (int)$diff->changed[0]->previous->targetlevel);
        $this->assertSame(3, (int)$diff->changed[0]->targetlevel);
    }

    public function test_global_template_is_created_from_company_role_without_mutating_source(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company('Source Company');
        $user = $this->getDataGenerator()->create_user();
        repository::add_member($companyid, (int)$user->id, 'employee');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        $roleid = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-SOURCE-OWNER',
            'name' => 'Source owner',
            'description' => 'Company definition',
            'active' => 1,
        ]);
        business_roles::set_role_competency(
            $roleid, (int)$competency->get('id'), 3, 'critical', true, 'manual'
        );
        business_roles::assign_user_role($companyid, (int)$user->id, $roleid);

        $templateid = business_roles::create_template_from_company_role($roleid);
        $template = business_roles::get_role($templateid);
        $requirement = $DB->get_record('local_kbacorp_rolecomp', [
            'roleid' => $templateid,
            'competencyid' => (int)$competency->get('id'),
        ], '*', MUST_EXIST);

        $this->assertSame(0, (int)$template->companyid);
        $this->assertSame('BMS-ROLE-SOURCE-OWNER', $template->code);
        $this->assertSame('Source owner', $template->name);
        $this->assertSame(1, (int)$template->templateversion);
        $this->assertSame(3, (int)$requirement->targetlevel);
        $this->assertSame('critical', $requirement->priority);
        $this->assertSame('manual', $requirement->source);
        $this->assertTrue($DB->record_exists('local_kbacorp_member_busrole', [
            'companyid' => $companyid,
            'userid' => (int)$user->id,
            'roleid' => $roleid,
            'active' => 1,
        ]));
        $this->assertSame($companyid,
            (int)$DB->get_field('local_kbacorp_busrole', 'companyid', ['id' => $roleid]));
    }

    public function test_company_role_cannot_overwrite_existing_global_template_code(): void {
        $this->resetAfterTest();
        $companyid = $this->create_company('Conflict Company');
        business_roles::save_role((object)[
            'companyid' => 0,
            'code' => 'BMS-ROLE-CODE-CONFLICT',
            'name' => 'Existing template',
            'active' => 1,
        ]);
        $roleid = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-CODE-CONFLICT',
            'name' => 'Company role',
            'active' => 1,
        ]);

        $this->expectException(\moodle_exception::class);
        business_roles::create_template_from_company_role($roleid);
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
