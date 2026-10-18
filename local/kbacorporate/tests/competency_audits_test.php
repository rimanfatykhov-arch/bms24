<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\competency_audits;
use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\repository;

/** Tests for preliminary and confirmed competency audit results. */
final class competency_audits_test extends \advanced_testcase {
    public function test_pending_assessment_does_not_grade_moodle_competency(): void {
        global $DB;
        $this->resetAfterTest();
        [$companyid, $user, $competency] = $this->fixture();
        $auditid = competency_audits::create_audit($companyid, (int)$user->id, 'Audit', '', 0);
        competency_audits::add_assessment(
            $auditid, (int)$competency->get('id'), 'self', 1, 'Preliminary', '', (int)$user->id
        );
        $this->assertFalse($DB->record_exists('competency_usercomp', [
            'userid' => $user->id,
            'competencyid' => $competency->get('id'),
        ]));
        $latest = competency_audits::get_latest_user_items($companyid, (int)$user->id);
        $this->assertSame(competency_audits::ITEM_PENDING,
            $latest[(int)$competency->get('id')]->status);
    }

    public function test_confirmed_assessment_uses_core_grade_and_completes_audit(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$companyid, $user, $competency] = $this->fixture();
        $auditid = competency_audits::create_audit($companyid, (int)$user->id, 'Expert audit', '', 2);
        $itemid = competency_audits::add_assessment(
            $auditid, (int)$competency->get('id'), 'expert', 1, 'Verified', '', 2
        );
        competency_audits::confirm_assessment($itemid, 2);

        $usercompetency = $DB->get_record('competency_usercomp', [
            'userid' => $user->id,
            'competencyid' => $competency->get('id'),
        ], '*', MUST_EXIST);
        $this->assertSame(2, (int)$usercompetency->grade);
        $this->assertSame(competency_audits::ITEM_CONFIRMED,
            $DB->get_field('local_kbacorp_audit_item', 'status', ['id' => $itemid]));
        $this->assertSame(competency_audits::AUDIT_COMPLETED,
            $DB->get_field('local_kbacorp_audit', 'status', ['id' => $auditid]));
    }

    public function test_rejected_assessment_does_not_grade_competency(): void {
        global $DB;
        $this->resetAfterTest();
        [$companyid, $user, $competency] = $this->fixture();
        $auditid = competency_audits::create_audit($companyid, (int)$user->id, 'Audit', '', 0);
        $itemid = competency_audits::add_assessment(
            $auditid, (int)$competency->get('id'), 'ai', 1, '', '', 0
        );
        competency_audits::reject_assessment($itemid, 0);
        $this->assertFalse($DB->record_exists('competency_usercomp', [
            'userid' => $user->id,
            'competencyid' => $competency->get('id'),
        ]));
    }

    public function test_company_deletion_removes_audit_children_first(): void {
        global $DB;
        $this->resetAfterTest();
        [$companyid, $user, $competency] = $this->fixture();
        $auditid = competency_audits::create_audit($companyid, (int)$user->id, 'Audit', '', 0);
        $itemid = competency_audits::add_assessment(
            $auditid, (int)$competency->get('id'), 'expert', 1, '', '', 0
        );
        $DB->insert_record('local_kbacorp_audit_ev', (object)[
            'audititemid' => $itemid,
            'sourcetype' => 'manual_assessment',
            'sourcecomponent' => 'local_kbacorporate',
            'sourceitemid' => 0,
            'title' => 'Evidence',
            'timeobserved' => time(),
            'timecreated' => time(),
        ]);
        repository::delete_company($companyid);
        $this->assertFalse($DB->record_exists('local_kbacorp_audit', ['id' => $auditid]));
        $this->assertSame(0, $DB->count_records('local_kbacorp_audit_item'));
        $this->assertSame(0, $DB->count_records('local_kbacorp_audit_ev'));
    }

    public function test_role_audit_creates_missing_item_without_inventing_level_zero(): void {
        global $DB;
        $this->resetAfterTest();
        [$companyid, $user, $competency] = $this->fixture();
        $this->assign_role_requirement($companyid, (int)$user->id, (int)$competency->get('id'), 2);

        $auditid = competency_audits::create_role_audit(
            $companyid, (int)$user->id, '', '', 0
        );
        $items = competency_audits::get_items($auditid);
        $this->assertCount(1, $items);
        $this->assertSame(competency_audits::ITEM_MISSING, $items[0]->status);
        $this->assertSame(2, (int)$items[0]->targetlevel);
        $this->assertSame('critical', $items[0]->priority);
        $this->assertSame(1, (int)$items[0]->required);
        $this->assertSame(0, (int)$items[0]->timeassessed);
        $this->assertSame(competency_audits::AUDIT_TYPE_ROLE,
            $DB->get_field('local_kbacorp_audit', 'audittype', ['id' => $auditid]));

        $itemid = competency_audits::add_assessment(
            $auditid, (int)$competency->get('id'), 'interview', 1, 'Observed', '', 0
        );
        $this->assertSame((int)$items[0]->id, $itemid);
        $this->assertSame(1, $DB->count_records('local_kbacorp_audit_item', ['auditid' => $auditid]));
        $this->assertSame(competency_audits::ITEM_PENDING,
            $DB->get_field('local_kbacorp_audit_item', 'status', ['id' => $itemid]));
        $this->assertSame(1, $DB->count_records('local_kbacorp_audit_ev', ['audititemid' => $itemid]));
    }

    public function test_role_audit_accepts_existing_moodle_level_as_confirmed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$companyid, $user, $competency] = $this->fixture();
        $this->assign_role_requirement($companyid, (int)$user->id, (int)$competency->get('id'), 2);
        \core_competency\api::grade_competency(
            (int)$user->id, (int)$competency->get('id'), 2, 'Existing confirmed grade'
        );

        $auditid = competency_audits::create_role_audit(
            $companyid, (int)$user->id, 'Role audit', '', 2
        );
        $items = competency_audits::get_items($auditid);
        $this->assertSame(competency_audits::ITEM_CONFIRMED, $items[0]->status);
        $this->assertSame(1, (int)$items[0]->proposedlevel);
        $this->assertSame('moodle_current', $items[0]->source);
        $this->assertGreaterThanOrEqual(1,
            $DB->count_records('local_kbacorp_audit_ev', ['audititemid' => $items[0]->id]));
        $this->assertSame(competency_audits::AUDIT_COMPLETED,
            $DB->get_field('local_kbacorp_audit', 'status', ['id' => $auditid]));
    }

    public function test_audit_can_be_renamed_without_changing_results(): void {
        global $DB;
        $this->resetAfterTest();
        [$companyid, $user, $competency] = $this->fixture();
        $auditid = competency_audits::create_audit($companyid, (int)$user->id, 'Old title', '', 0);
        $itemid = competency_audits::add_assessment(
            $auditid, (int)$competency->get('id'), 'expert', 1, '', '', 0
        );

        competency_audits::rename_audit($auditid, 'New title');

        $this->assertSame('New title', $DB->get_field('local_kbacorp_audit', 'title', ['id' => $auditid]));
        $this->assertTrue($DB->record_exists('local_kbacorp_audit_item', ['id' => $itemid]));
    }

    public function test_audit_deletion_removes_items_and_collected_evidence(): void {
        global $DB;
        $this->resetAfterTest();
        [$companyid, $user, $competency] = $this->fixture();
        $auditid = competency_audits::create_audit($companyid, (int)$user->id, 'Delete me', '', 0);
        $itemid = competency_audits::add_assessment(
            $auditid, (int)$competency->get('id'), 'expert', 1, '', '', 0
        );
        $DB->insert_record('local_kbacorp_audit_ev', (object)[
            'audititemid' => $itemid,
            'sourcetype' => 'manual_assessment',
            'sourcecomponent' => 'local_kbacorporate',
            'sourceitemid' => 0,
            'title' => 'Evidence',
            'timeobserved' => time(),
            'timecreated' => time(),
        ]);

        competency_audits::delete_audit($auditid);

        $this->assertFalse($DB->record_exists('local_kbacorp_audit', ['id' => $auditid]));
        $this->assertFalse($DB->record_exists('local_kbacorp_audit_item', ['id' => $itemid]));
        $this->assertSame(0, $DB->count_records('local_kbacorp_audit_ev'));
    }

    private function assign_role_requirement(int $companyid, int $userid, int $competencyid,
            int $targetlevel): void {
        $roleid = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-AUDIT-TEST',
            'name' => 'Audit test role',
            'active' => 1,
        ]);
        business_roles::set_role_competency(
            $roleid, $competencyid, $targetlevel, business_roles::PRIORITY_CRITICAL, true
        );
        business_roles::assign_user_role($companyid, $userid, $roleid);
    }

    private function fixture(): array {
        global $DB;
        $now = time();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Audit company',
            'active' => 1,
            'trainingminutesday' => 90,
            'trainingweekdays' => '1,2,3,4,5',
            'alertsenabled' => 0,
            'alertfrequency' => 'weekly',
            'lastalertsent' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $user = $this->getDataGenerator()->create_user();
        repository::add_member($companyid, (int)$user->id, 'employee');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
        ]);
        return [$companyid, $user, $competency];
    }
}
