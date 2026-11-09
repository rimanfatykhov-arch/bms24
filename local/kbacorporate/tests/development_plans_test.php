<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\business_roles;
use local_kbacorporate\local\development_plans;
use local_kbacorporate\local\development_notifier;
use local_kbacorporate\local\competency_audits;
use local_kbacorporate\local\repository;

final class development_plans_test extends \advanced_testcase {
    public function test_learning_plan_schedule_uses_priority_phases_and_deadlines(): void {
        $critical = development_plans::learning_plan_schedule(business_roles::PRIORITY_CRITICAL);
        $core = development_plans::learning_plan_schedule(business_roles::PRIORITY_CORE);
        $additional = development_plans::learning_plan_schedule(business_roles::PRIORITY_ADDITIONAL);

        $this->assertSame(1, $critical->phase);
        $this->assertSame(30, $critical->days);
        $this->assertSame(2, $core->phase);
        $this->assertSame(60, $core->days);
        $this->assertSame(3, $additional->phase);
        $this->assertSame(90, $additional->days);
    }

    public function test_progress_summary_distinguishes_completed_overdue_and_due_soon(): void {
        $today = make_timestamp(2026, 9, 2, 12, 0, 0);
        $items = [
            (object)['status' => development_plans::ITEM_COMPLETED, 'duedate' => $today - DAYSECS],
            (object)['status' => development_plans::ITEM_INPROGRESS, 'duedate' => $today - DAYSECS],
            (object)['status' => development_plans::ITEM_PLANNED, 'duedate' => $today + (7 * DAYSECS)],
            (object)['status' => development_plans::ITEM_PLANNED, 'duedate' => $today + (30 * DAYSECS)],
        ];
        $summary = development_plans::get_progress_summary($items, $today);
        $this->assertSame(4, $summary->total);
        $this->assertSame(1, $summary->completed);
        $this->assertSame(1, $summary->inprogress);
        $this->assertSame(2, $summary->planned);
        $this->assertSame(1, $summary->overdue);
        $this->assertSame(1, $summary->duesoon);
        $this->assertSame(25, $summary->percent);
        $due = development_notifier::due_items($items, usergetmidnight($today));
        $this->assertCount(2, $due);
        $this->assertSame(development_plans::ITEM_INPROGRESS, $due[0]->status);
        $this->assertSame(development_plans::ITEM_PLANNED, $due[1]->status);
    }

    public function test_outcome_summary_separates_improvement_new_assessment_and_remaining_gap(): void {
        $items = [
            (object)['competencyid' => 10, 'currentlevel' => 1, 'targetlevel' => 3],
            (object)['competencyid' => 20, 'currentlevel' => null, 'targetlevel' => 2],
            (object)['competencyid' => 30, 'currentlevel' => 2, 'targetlevel' => 2],
            (object)['competencyid' => 40, 'currentlevel' => 1, 'targetlevel' => 3],
        ];
        $summary = development_plans::summarise_outcomes($items, [10 => 2, 20 => 1, 30 => 2]);
        $this->assertSame(4, $summary->total);
        $this->assertSame(3, $summary->assessed);
        $this->assertSame(1, $summary->newlyassessed);
        $this->assertSame(1, $summary->improved);
        $this->assertSame(1, $summary->reached);
        $this->assertSame(2, $summary->remaininggaps);
        $this->assertSame(1, $summary->rows[10]->delta);
        $this->assertNull($summary->rows[40]->current);
    }

    public function test_effectiveness_timeline_uses_only_comparable_baselines_for_growth(): void {
        $january = make_timestamp(2026, 1, 15, 12, 0, 0);
        $february = make_timestamp(2026, 2, 15, 12, 0, 0);
        $records = [
            (object)['baseline' => 1, 'finallevel' => 3, 'targetlevel' => 3, 'timeconfirmed' => $january],
            (object)['baseline' => 2, 'finallevel' => 2, 'targetlevel' => 3, 'timeconfirmed' => $january],
            (object)['baseline' => null, 'finallevel' => 2, 'targetlevel' => 2, 'timeconfirmed' => $february],
        ];
        $timeline = development_plans::summarise_effectiveness_timeline($records);
        $this->assertCount(2, $timeline->periods);
        $this->assertSame(3, $timeline->totals->assessed);
        $this->assertSame(2, $timeline->totals->comparable);
        $this->assertSame(1, $timeline->totals->improved);
        $this->assertSame(2, $timeline->totals->reached);
        $this->assertSame(1.5, $timeline->totals->averagebefore);
        $this->assertSame(2.5, $timeline->totals->averageafter);
        $this->assertSame(1.0, $timeline->totals->averagegrowth);
        $this->assertNull($timeline->periods[1]->averagegrowth);
    }

    public function test_plan_is_generated_only_for_role_gap_and_reuses_active_plan(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Development Company', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$user->id, 'employee');
        repository::add_member($companyid, (int)$manager->id, 'manager');
        $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
        role_assign($managerrole->id, $manager->id, \context_system::instance()->id);
        $this->setUser($manager);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $roleid = business_roles::save_role((object)[
            'companyid' => $companyid, 'code' => 'BMS-ROLE-DEVELOPMENT',
            'name' => 'Development role', 'active' => 1,
        ]);
        business_roles::set_role_competency($roleid, $competency->get('id'), 3, 'critical', true);
        business_roles::assign_user_role($companyid, (int)$user->id, $roleid);

        $planid = development_plans::create_from_gaps($companyid, (int)$user->id, (int)$manager->id);
        $this->assertSame($planid,
            development_plans::create_from_gaps($companyid, (int)$user->id, (int)$manager->id));
        $items = development_plans::get_items($planid);
        $this->assertCount(1, $items);
        $this->assertNull($items[0]->currentlevel);
        $this->assertSame('critical', $items[0]->priority);
        $this->assertSame(3, (int)$items[0]->targetlevel);

        development_plans::update_plan($planid, 'Owner development 2026', time() + (120 * DAYSECS));
        $plan = development_plans::get_plan($planid);
        $this->assertSame('Owner development 2026', $plan->title);

        development_plans::update_item((int)$items[0]->id, 0, time() + (30 * DAYSECS),
            development_plans::ITEM_INPROGRESS);
        $updated = development_plans::get_items($planid);
        $this->assertSame(development_plans::ITEM_INPROGRESS, $updated[0]->status);

        development_plans::delete_item((int)$items[0]->id);
        $this->assertCount(0, development_plans::get_items($planid));

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        repository::add_course($companyid, (int)$course->id);
        $newitemid = development_plans::add_profile_item($planid, (int)$competency->get('id'), 0,
            time() + (45 * DAYSECS));
        development_plans::update_item($newitemid, (int)$course->id, time() + (45 * DAYSECS),
            development_plans::ITEM_PLANNED);
        development_plans::submit_for_agreement($planid, (int)$manager->id);
        $pending = development_plans::get_plan($planid);
        $this->assertSame(development_plans::STATUS_PENDING, $pending->status);
        development_plans::employee_decision($planid, (int)$user->id, true, 'Agreed');
        $active = development_plans::get_plan($planid);
        $this->assertSame(development_plans::STATUS_ACTIVE, $active->status);
        $this->assertGreaterThan(0, (int)$active->timeacknowledged);

        $completiontime = time() - HOURSECS;
        $DB->insert_record('course_completions', (object)[
            'userid' => (int)$user->id,
            'course' => (int)$course->id,
            'timecompleted' => $completiontime,
        ]);
        $syncresult = development_plans::sync_course_completions($planid);
        $this->assertSame(1, $syncresult->actions);
        $this->assertSame(1, $syncresult->plans);
        $completeditems = development_plans::get_items($planid);
        $this->assertSame(development_plans::ITEM_COMPLETED, $completeditems[0]->status);
        $this->assertSame($completiontime, (int)$completeditems[0]->timecompleted);
        $this->assertSame(development_plans::STATUS_COMPLETED,
            development_plans::get_plan($planid)->status);

        $followupauditid = competency_audits::create_development_audit($planid, (int)$manager->id);
        $this->assertSame($followupauditid,
            competency_audits::create_development_audit($planid, (int)$manager->id));
        $followupaudit = competency_audits::get_audit($followupauditid);
        $this->assertSame(competency_audits::AUDIT_TYPE_DEVELOPMENT, $followupaudit->audittype);
        $this->assertCount(1, competency_audits::get_items($followupauditid));
        $effectiveness = development_plans::get_company_effectiveness($companyid, [(int)$user->id]);
        $this->assertSame(1, $effectiveness->plans);
        $this->assertSame(1, $effectiveness->completed);
        $this->assertSame(100, $effectiveness->progress);
        $this->assertSame(100, $effectiveness->auditcoverage);
        $this->assertCount(1, $effectiveness->rows);
        $breakdowns = development_plans::get_effectiveness_breakdowns($companyid, $effectiveness->rows);
        $this->assertCount(1, $breakdowns->roles);
        $this->assertSame('Development role', $breakdowns->roles[0]->name);
        $this->assertSame(1, $breakdowns->roles[0]->plans);
        $this->assertSame(100, $breakdowns->roles[0]->progress);
        $this->assertCount(1, $breakdowns->departments);
        $this->assertSame(0, $breakdowns->departments[0]->id);
        $filtered = development_plans::get_company_effectiveness(
            $companyid, [(int)$user->id], development_plans::STATUS_COMPLETED, $user->lastname);
        $this->assertSame(1, $filtered->plans);
        $this->assertSame(0, development_plans::get_company_effectiveness(
            $companyid, [(int)$user->id], development_plans::STATUS_ACTIVE, '')->plans);
        $this->assertSame(0, development_plans::get_company_effectiveness(
            $companyid, [(int)$user->id], 'all', 'no-such-employee')->plans);
        competency_audits::delete_audit($followupauditid);
        $this->assertSame(0, (int)development_plans::get_plan($planid)->followupauditid);

        development_plans::manager_transition($planid, 'reopen');
        development_plans::submit_for_agreement($planid, (int)$manager->id);
        development_plans::employee_decision($planid, (int)$user->id, false, 'Adjust the deadline');
        $returned = development_plans::get_plan($planid);
        $this->assertSame(development_plans::STATUS_DRAFT, $returned->status);
        $this->assertSame('Adjust the deadline', $returned->employeecomment);
    }
}
