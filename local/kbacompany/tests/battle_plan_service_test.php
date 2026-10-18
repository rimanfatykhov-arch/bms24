<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_service;
use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Weekly Battle Plan regression tests. */
final class battle_plan_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Battle Plan', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $now = time();
        foreach ([[$employee, 0], [$reviewer, 1]] as [$user, $manager]) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $companyid, 'userid' => $user->id, 'membertype' => 'employee',
                'isemployee' => 1, 'ismanager' => $manager, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        $positionuuid = '10000000-0000-4000-8000-000000000001';
        $DB->insert_record('local_kbacompany_position', (object)[
            'companyuuid' => $company, 'branchuuid' => '10000000-0000-4000-8000-000000000002',
            'orgnodeuuid' => '10000000-0000-4000-8000-000000000003', 'uuid' => $positionuuid,
            'code' => 'P-TEST', 'name' => 'Test position', 'purpose' => 'Produce results', 'ismanager' => 0,
            'plannedcount' => 1, 'sortorder' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $DB->insert_record('local_kbacompany_posassign', (object)[
            'companyuuid' => $company, 'positionuuid' => $positionuuid, 'slotuuid' => '',
            'userid' => (int)$employee->id, 'assignmenttype' => 'primary', 'effectivefrom' => $now - 60,
            'effectiveto' => 0, 'active' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        return compact('admin', 'companyid', 'company', 'employee', 'reviewer', 'positionuuid');
    }

    public function test_contract_declares_projection_not_task_copy(): void {
        $contract = battle_plan_service::contract();
        $this->assertSame('bms_battle_plan', $contract['contract']);
        $this->assertSame('0.6.2', $contract['version']);
        $this->assertContains('approved_position_coverage_is_manager_visible_without_rewriting_plan_or_metric_acl',
            $contract['rules']);
        $this->assertContains('battle_plan_references_task_provider_objects_without_copying', $contract['rules']);
        $this->assertContains('task_library_template_can_be_confirmed_as_one_off_canonical_task_in_draft_plan', $contract['rules']);
        $this->assertContains('employee_may_choose_self_as_battle_plan_reviewer_and_self_approve', $contract['rules']);
        $this->assertContains('battle_plan_is_exportable_as_real_docx', $contract['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_week_is_normalised_to_monday(): void {
        $this->assertSame('2026-09-14', battle_plan_service::week_start('2026-09-17'));
        $this->assertSame('2027-01-04', battle_plan_service::week_start('2027-01-10'));
    }

    public function test_period_label_is_human_readable_and_draft_plan_is_available_for_direct_library_add(): void {
        $label = battle_plan_service::period_label('2026-09-07', '2026-09-13');
        $this->assertStringNotContainsString('[[', $label);
        $this->assertStringContainsString('2026', $label);
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-07', (int)$f['reviewer']->id);
        $options = battle_plan_service::draft_plan_options($f['company'], $f['admin']);
        $this->assertCount(1, $options);
        $this->assertSame($plan, $options[0]['uuid']);
        $this->assertStringContainsString('2026', $options[0]['label']);
    }

    public function test_battle_plan_references_one_canonical_task_and_closes_after_execution(): void {
        global $DB;
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-17', (int)$f['reviewer']->id);
        $task = battle_plan_service::create_manual_task($f['company'], $f['admin'], $plan, [
            'title' => 'Deliver weekly output', 'description' => '', 'product' => 'Accepted weekly output',
            'reviewerid' => 0, 'priority' => 'high', 'stream' => 'operate',
            'startdate' => '2026-09-14', 'duedate' => '2026-09-20', 'planminutes' => 60, 'parentuuid' => '',
        ]);
        $this->assertSame(1, $DB->count_records('local_kbacompany_task', ['companyuuid' => $f['company']]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_bplanitem', ['planuuid' => $plan, 'taskuuid' => $task]));
        $ref = $DB->get_record('local_kbacompany_bplanitem', ['planuuid' => $plan], '*', MUST_EXIST);
        $this->assertSame('bms', $ref->provider);
        $this->assertSame($task, $ref->externalid);

        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'submit');
        $this->setUser($f['reviewer']);
        battle_plan_service::transition($f['company'], (int)$f['reviewer']->id, $plan, 'approve');

        $this->setUser($f['employee']);
        task_service::transition($f['company'], (int)$f['employee']->id, $task, 1, 'start');
        task_service::transition($f['company'], (int)$f['employee']->id, $task, 2, 'submit', [
            'result' => 'Weekly output accepted', 'factminutes' => 55,
        ]);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'close');
        $this->assertSame('closed', $DB->get_field('local_kbacompany_bplan', 'status', ['uuid' => $plan]));
        $this->assertSame(1, $DB->count_records('local_kbacompany_task', ['companyuuid' => $f['company']]));
    }

    public function test_library_template_creates_one_canonical_task_and_adds_it_to_plan(): void {
        global $DB;
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-17', (int)$f['reviewer']->id);
        $taskuuid = battle_plan_service::add_from_template($f['company'], $f['admin'], $plan, 'rtpl_executive_10', [
            'product' => 'Directors coordinated for the day', 'description' => '', 'priority' => 'normal',
            'stream' => 'operate', 'startdate' => '2026-09-14', 'duedate' => '2026-09-20', 'planminutes' => 30,
        ]);
        $task = $DB->get_record('local_kbacompany_task', ['uuid' => $taskuuid], '*', MUST_EXIST);
        $this->assertSame('template', $task->sourcekind);
        $this->assertSame('2026-09-14', $task->periodkey);
        $this->assertSame((int)$f['employee']->id, (int)$task->assigneeid);
        $this->assertSame('Directors coordinated for the day', $task->product);
        $this->assertSame(1, $DB->count_records('local_kbacompany_bplanitem', ['planuuid' => $plan, 'taskuuid' => $taskuuid]));

        $same = battle_plan_service::add_from_template($f['company'], $f['admin'], $plan, 'rtpl_executive_10', [
            'product' => 'Directors coordinated for the day', 'description' => '', 'priority' => 'normal',
            'stream' => 'operate', 'startdate' => '2026-09-14', 'duedate' => '2026-09-20', 'planminutes' => 30,
        ]);
        $this->assertSame($taskuuid, $same);
        $this->assertSame(1, $DB->count_records('local_kbacompany_task', [
            'companyuuid' => $f['company'], 'sourcekind' => 'template',
        ]));
    }

    public function test_close_rejects_unfinished_task(): void {
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-14', (int)$f['reviewer']->id);
        battle_plan_service::create_manual_task($f['company'], $f['admin'], $plan, [
            'title' => 'Still open', 'description' => '', 'product' => 'Future result', 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '', 'planminutes' => 0,
            'parentuuid' => '',
        ]);
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'submit');
        $this->setUser($f['reviewer']);
        battle_plan_service::transition($f['company'], (int)$f['reviewer']->id, $plan, 'approve');
        $this->setUser($f['employee']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('battleplan_unfinished', 'local_kbacompany'));
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'close');
    }

    public function test_position_work_import_pins_exact_source_revision_and_archived_latest_is_not_reused(): void {
        global $DB;
        $f = $this->fixture();
        $sourceuuid = '20000000-0000-4000-8000-000000000001';
        $now = time();
        $base = [
            'companyuuid' => $f['company'], 'uuid' => $sourceuuid, 'positionuuid' => $f['positionuuid'],
            'functionuuid' => '20000000-0000-4000-8000-000000000002', 'functionrevision' => 1,
            'action' => 'Review weekly plan', 'product' => 'Approved weekly plan', 'frequency' => 'weekly',
            'schedule' => 'Monday', 'sortorder' => 0, 'changereason' => '', 'createdby' => $f['admin'],
            'modifiedby' => $f['admin'], 'timecreated' => $now, 'timemodified' => $now,
        ];
        $DB->insert_record('local_kbacompany_poswork', (object)($base + ['revision' => 1, 'status' => 'published']));
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-17', (int)$f['reviewer']->id);
        $taskuuid = battle_plan_service::add_from_position_work($f['company'], $f['admin'], $plan, $sourceuuid);
        $task = $DB->get_record('local_kbacompany_task', ['uuid' => $taskuuid], '*', MUST_EXIST);
        $this->assertSame('positionwork', $task->sourcekind);
        $this->assertSame(1, (int)$task->sourcerevision);
        $this->assertSame('2026-09-14', $task->periodkey);
        $this->assertSame('run', $task->stream);

        $DB->insert_record('local_kbacompany_poswork', (object)($base + [
            'revision' => 2, 'status' => 'archived', 'changereason' => 'Retired', 'timemodified' => $now + 1,
        ]));
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('taskcenter_sourceerror', 'local_kbacompany'));
        task_service::ensure_from_position_work($f['company'], $f['admin'], $sourceuuid,
            (int)$f['employee']->id, $f['positionuuid'], '2026-09-21', '2026-09-27');
    }

    public function test_employee_can_self_approve_own_plan(): void {
        global $DB;
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-14', (int)$f['employee']->id);
        battle_plan_service::create_manual_task($f['company'], $f['admin'], $plan, [
            'title' => 'Own weekly task', 'description' => '', 'product' => 'Own result', 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '', 'planminutes' => 15,
            'parentuuid' => '',
        ]);
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'selfapprove');
        $row = $DB->get_record('local_kbacompany_bplan', ['uuid' => $plan], '*', MUST_EXIST);
        $this->assertSame('active', $row->status);
        $this->assertSame((int)$f['employee']->id, (int)$row->approvedby);
        $this->assertGreaterThan(0, (int)$row->submittedat);
        $this->assertGreaterThan(0, (int)$row->approvedat);
    }

    public function test_reviewer_can_return_specific_plan_item_for_correction_and_resubmit(): void {
        global $DB;
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-14', (int)$f['reviewer']->id);
        battle_plan_service::create_manual_task($f['company'], $f['admin'], $plan, [
            'title' => 'Prepare result', 'description' => '', 'product' => 'Accepted result', 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '', 'planminutes' => 30,
            'parentuuid' => '',
        ]);
        $item = $DB->get_record('local_kbacompany_bplanitem', ['planuuid'=>$plan], '*', MUST_EXIST);
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'submit');
        $this->setUser($f['reviewer']);
        battle_plan_service::review($f['company'], (int)$f['reviewer']->id, $plan, 'changes',
            'Clarify expected product', [(string)$item->uuid]);
        $row = $DB->get_record('local_kbacompany_bplan', ['uuid'=>$plan], '*', MUST_EXIST);
        $this->assertSame('draft', $row->status);
        $review = $DB->get_record('local_kbacompany_bplanrev', ['planuuid'=>$plan], '*', MUST_EXIST);
        $this->assertSame('changes', $review->decision);
        $this->assertSame((string)$item->uuid, $review->itemuuid);
        $this->assertSame('Clarify expected product', $review->comment);
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'submit');
        $this->assertSame('submitted', $DB->get_field('local_kbacompany_bplan', 'status', ['uuid'=>$plan]));
    }

    public function test_reviewer_approval_is_recorded_and_activates_same_plan(): void {
        global $DB;
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-14', (int)$f['reviewer']->id);
        battle_plan_service::create_manual_task($f['company'], $f['admin'], $plan, [
            'title' => 'Execute plan', 'description' => '', 'product' => 'Planned result', 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '', 'planminutes' => 45,
            'parentuuid' => '',
        ]);
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'submit');
        $this->setUser($f['reviewer']);
        battle_plan_service::review($f['company'], (int)$f['reviewer']->id, $plan, 'approve', 'Plan is aligned');
        $row = $DB->get_record('local_kbacompany_bplan', ['uuid'=>$plan], '*', MUST_EXIST);
        $this->assertSame('active', $row->status);
        $this->assertSame((int)$f['reviewer']->id, (int)$row->approvedby);
        $this->assertSame(1, $DB->count_records('local_kbacompany_bplanrev', [
            'planuuid'=>$plan, 'decision'=>'approved',
        ]));
    }

    public function test_plan_owner_cannot_review_submitted_plan_when_another_reviewer_is_selected(): void {
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-09-14', (int)$f['reviewer']->id);
        battle_plan_service::create_manual_task($f['company'], $f['admin'], $plan, [
            'title' => 'Execute', 'description' => '', 'product' => 'Result', 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '', 'planminutes' => 15,
            'parentuuid' => '',
        ]);
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'submit');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('battleplan_reviewpermission', 'local_kbacompany'));
        battle_plan_service::review($f['company'], (int)$f['employee']->id, $plan, 'approve');
    }

    /** A post-approval manual task is traceable and does not rewrite the approved plan. */
    public function test_active_plan_accepts_scoped_manual_task_but_closed_plan_rejects_it(): void {
        global $DB;
        $f = $this->fixture();
        $plan = battle_plan_service::create_plan($f['company'], $f['admin'], (int)$f['employee']->id,
            $f['positionuuid'], '2026-10-12', (int)$f['reviewer']->id);
        $this->setUser($f['employee']);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'submit');
        $this->setUser($f['reviewer']);
        battle_plan_service::transition($f['company'], (int)$f['reviewer']->id, $plan, 'approve');
        $approved = $DB->get_record('local_kbacompany_bplan', ['companyuuid'=>$f['company'], 'uuid'=>$plan], '*', MUST_EXIST);
        $this->assertSame('active', $approved->status);
        $this->setAdminUser();
        $task = battle_plan_service::create_manual_task($f['company'], $f['admin'], $plan, [
            'title'=>'Additional weekly work', 'description'=>'After approval',
            'product'=>'Documented operational result', 'reviewerid'=>0, 'observerid'=>0,
            'priority'=>'normal', 'stream'=>'operate', 'startdate'=>'2026-10-12',
            'duedate'=>'2026-10-18', 'planminutes'=>30, 'parentuuid'=>'',
        ]);
        $item = $DB->get_record('local_kbacompany_bplanitem', [
            'companyuuid'=>$f['company'], 'planuuid'=>$plan, 'taskuuid'=>$task], '*', MUST_EXIST);
        $this->assertSame('operate', $item->stream);
        $this->assertGreaterThan((int)$approved->approvedat, (int)$item->timecreated);
        $this->assertSame(1, $DB->count_records('local_kbacompany_task', [
            'companyuuid'=>$f['company'], 'uuid'=>$task]));
        $after = $DB->get_record('local_kbacompany_bplan', ['companyuuid'=>$f['company'], 'uuid'=>$plan], '*', MUST_EXIST);
        $this->assertSame('active', $after->status);
        $this->assertSame($approved->approvedby, $after->approvedby);
        $this->assertSame($approved->approvedat, $after->approvedat);
        // Existing provider linking remains forbidden after approval.
        try {
            battle_plan_service::add_task($f['company'], $f['admin'], $plan, $task);
            $this->fail('Adding an existing task to an approved plan must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('battleplan_state', $e->errorcode);
        }
        $this->setUser($f['employee']);
        task_service::transition($f['company'], (int)$f['employee']->id, $task, 1, 'start');
        task_service::transition($f['company'], (int)$f['employee']->id, $task, 2, 'submit', [
            'result'=>'Completed extra work', 'factminutes'=>20,
        ]);
        battle_plan_service::transition($f['company'], (int)$f['employee']->id, $plan, 'close');
        $this->setAdminUser();
        try {
            battle_plan_service::create_manual_task($f['company'], $f['admin'], $plan, [
                'title'=>'Should not be created', 'product'=>'Never', 'stream'=>'operate', 'priority'=>'normal',
                'duedate'=>'2026-10-18',
            ]);
            $this->fail('Closed plan must reject new tasks');
        } catch (\moodle_exception $e) {
            $this->assertSame('battleplan_state', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('local_kbacompany_task', [
            'companyuuid'=>$f['company']]));
    }

}
