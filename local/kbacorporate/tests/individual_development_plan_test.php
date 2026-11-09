<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\development_plans;
use local_kbacorporate\local\repository;

/** Regression coverage for the v0.32.3 individual development-plan workflow. */
final class individual_development_plan_test extends \advanced_testcase {
    public function test_pending_review_is_a_first_class_progress_state(): void {
        $now = make_timestamp(2026, 9, 8, 12, 0, 0);
        $summary = development_plans::get_progress_summary([
            (object)['status' => development_plans::ITEM_PENDING_REVIEW, 'duedate' => $now + DAYSECS],
            (object)['status' => development_plans::ITEM_COMPLETED, 'duedate' => $now],
        ], $now);

        $this->assertSame(1, $summary->pendingreview);
        $this->assertSame(1, $summary->completed);
        $this->assertSame(50, $summary->percent);
        $this->assertContains(development_plans::ITEM_PENDING_REVIEW, development_plans::item_statuses());
    }

    public function test_goal_mentor_evidence_review_and_history_complete_one_action(): void {
        global $DB;
        $this->resetAfterTest();

        $employee = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $mentor = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'IPR Company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$employee->id, 'employee');
        repository::add_member($companyid, (int)$mentor->id, 'employee');
        repository::add_member($companyid, (int)$manager->id, 'manager');
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        role_assign($managerrole->id, $manager->id, \context_system::instance()->id);
        $this->setUser($manager);

        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Delegation',
            'idnumber' => 'IPR-COMP-001',
        ]);
        $roleid = business_roles::save_role((object)[
            'companyid' => $companyid,
            'code' => 'BMS-ROLE-IPR-001',
            'name' => 'Test role',
            'active' => 1,
        ]);
        business_roles::set_role_competency($roleid, $competency->get('id'), 4, 'critical', true);
        business_roles::assign_user_role($companyid, (int)$employee->id, $roleid);

        $planid = development_plans::create_from_gaps(
            $companyid, (int)$employee->id, (int)$manager->id);
        $plan = development_plans::get_plan($planid);
        $targetdate = (int)$plan->startdate + (120 * DAYSECS);
        $reviewdate = (int)$plan->startdate + (30 * DAYSECS);
        development_plans::update_ipr_plan(
            $planid,
            'Individual development plan 2026',
            'Apply delegation to reduce owner involvement in routine decisions.',
            (int)$mentor->id,
            $targetdate,
            $reviewdate,
            (int)$manager->id
        );

        $items = development_plans::get_items($planid);
        $this->assertCount(1, $items);
        $itemid = (int)$items[0]->id;
        $duedate = (int)$plan->startdate + (60 * DAYSECS);
        $checkpointdate = (int)$plan->startdate + (14 * DAYSECS);
        development_plans::update_ipr_item(
            $itemid,
            0,
            $duedate,
            development_plans::ITEM_PLANNED,
            'Delegate one recurring management process and establish outcome controls.',
            'Describe the delegated process, assign the owner and run two review cycles.',
            'Process card, assigned owner and two review protocols are provided.',
            (int)$mentor->id,
            true,
            $checkpointdate,
            (int)$manager->id
        );

        $updatedplan = development_plans::get_plan($planid);
        $updateditem = development_plans::get_item($itemid);
        $this->assertSame((int)$mentor->id, (int)$updatedplan->mentorid);
        $this->assertSame($reviewdate, (int)$updatedplan->reviewdate);
        $this->assertSame((int)$mentor->id, (int)$updateditem->mentorid);
        $this->assertStringContainsString('Delegate one recurring', (string)$updateditem->goal);
        $this->assertSame($checkpointdate, (int)$updateditem->checkpointdate);

        try {
            development_plans::update_ipr_plan(
                $planid,
                'Invalid shortened plan',
                'The plan deadline must not precede an action deadline.',
                (int)$mentor->id,
                (int)$plan->startdate + (30 * DAYSECS),
                (int)$plan->startdate + (14 * DAYSECS),
                (int)$manager->id
            );
            $this->fail('A plan deadline before an action deadline must be rejected.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('iprplantargetbeforeitems', $exception->errorcode);
        }

        development_plans::submit_for_agreement($planid, (int)$manager->id);
        development_plans::employee_decision($planid, (int)$employee->id, true, 'Agreed');
        development_plans::mark_item_in_progress($itemid, (int)$employee->id);
        development_plans::record_checkpoint(
            $itemid, (int)$mentor->id, $checkpointdate, 'Owner and process selected.');
        development_plans::submit_item_evidence(
            $itemid,
            (int)$employee->id,
            'https://example.test/evidence/1',
            'Two review cycles are complete.'
        );

        $pending = development_plans::get_item($itemid);
        $this->assertSame(development_plans::ITEM_PENDING_REVIEW, $pending->status);
        $this->assertSame((int)$employee->id, (int)$pending->evidencesubmittedby);

        try {
            development_plans::review_item_evidence(
                $itemid, (int)$employee->id, true, 'Self-approval must not be allowed.');
            $this->fail('The evidence submitter must not be able to approve their own evidence.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('iprevidenceselfreview', $exception->errorcode);
        }

        development_plans::review_item_evidence(
            $itemid, (int)$manager->id, true, 'Evidence accepted.');
        $completed = development_plans::get_item($itemid);
        $this->assertSame(development_plans::ITEM_COMPLETED, $completed->status);
        $this->assertSame((int)$manager->id, (int)$completed->reviewedby);
        $this->assertSame(development_plans::STATUS_COMPLETED,
            development_plans::get_plan($planid)->status);

        $events = array_map(static function($event) {
            return (string)$event->eventtype;
        }, development_plans::get_history($planid));
        foreach (['plan_created', 'plan_updated', 'item_updated', 'plan_submitted',
                'plan_accepted', 'item_started', 'checkpoint_recorded', 'evidence_submitted',
                'evidence_approved', 'plan_completed'] as $expected) {
            $this->assertContains($expected, $events);
        }

        // Completion of an IPR action must not silently grade the competency.
        $this->assertFalse($DB->record_exists('competency_usercomp', [
            'userid' => (int)$employee->id,
            'competencyid' => (int)$competency->get('id'),
        ]));
    }
}
