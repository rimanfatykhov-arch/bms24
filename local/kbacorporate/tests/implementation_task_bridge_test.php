<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\bridge;
use local_kbacorporate\local\implementation_actions;
use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\point_a_template;
use local_kbacorporate\local\repository;

/** Company/actor-scoped read-only contract for BMS24 integration. */
final class implementation_task_bridge_test extends \advanced_testcase {
    public function test_contract_declares_new_read_only_methods_without_breaking_old_api(): void {
        $contract = bridge::contract();
        $this->assertSame('1.26', bridge::API_RELEASE);
        $this->assertSame(1, bridge::API_VERSION);
        $this->assertTrue($contract['backwards_compatible']);
        $this->assertSame('read', $contract['methods']['list_implementation_task_projections']['type']);
        $this->assertSame('read', $contract['methods']['get_implementation_task_projection']['type']);
        $this->assertArrayHasKey('sync_employee', $contract['methods']);
    }

    public function test_employee_sees_own_paid_task_without_comments_or_evidence(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$taskid, $companyid, $owner] = $this->create_task();
        $rows = bridge::list_implementation_task_projections($companyid, (int)$owner->id,
            (int)$owner->id, 'mine', 10, 0);
        $this->assertCount(1, $rows);
        $task = reset($rows);
        $this->assertSame('impltask:' . $taskid, $task['external_id']);
        $this->assertSame($companyid, $task['company_id']);
        $this->assertSame((int)$owner->id, $task['assignee_id']);
        $this->assertSame('planned', $task['status']);
        $this->assertTrue($task['attachable']);
        $this->assertFalse($task['editable']);
        $this->assertArrayNotHasKey('completioncomment', $task);
        $this->assertArrayNotHasKey('message', $task);
        $this->assertArrayNotHasKey('evidence', $task);
        $this->assertSame($task, bridge::get_implementation_task_projection(
            $companyid, (int)$owner->id, $taskid));
    }

    public function test_company_ownership_and_deletion_are_enforced_in_queries(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$taskid, $companyid, $owner] = $this->create_task();
        $otherid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Other test company', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $this->assertNull(bridge::get_implementation_task_projection($otherid,
            (int)$owner->id, $taskid));
        $DB->set_field('local_kbacorp_impl_task', 'deleted', 1, ['id' => $taskid]);
        $this->assertNull(bridge::get_implementation_task_projection($companyid,
            (int)$owner->id, $taskid));
        $this->assertSame([], bridge::list_implementation_task_projections($companyid,
            (int)$owner->id, (int)$owner->id));
    }

    public function test_other_employee_cannot_list_or_get_another_owners_task(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$taskid, $companyid, $owner] = $this->create_task();
        $other = $this->getDataGenerator()->create_user();
        repository::add_member($companyid, (int)$other->id, 'employee');
        $this->assertNull(bridge::get_implementation_task_projection($companyid,
            (int)$other->id, $taskid));
        try {
            bridge::list_implementation_task_projections($companyid, (int)$other->id,
                (int)$owner->id, 'employee');
            $this->fail('Cross-user listing must be denied');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotallowed', $e->errorcode);
        }
    }

    public function test_non_paid_program_and_paused_program_are_not_attachable(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$taskid, $companyid, $owner] = $this->create_task();
        $task = $DB->get_record('local_kbacorp_impl_task', ['id' => $taskid], '*', MUST_EXIST);
        $DB->set_field('local_kbacorp_impl_program', 'commercialstatus', 'requested',
            ['baselineauditid' => (int)$task->auditid]);
        $this->assertNull(bridge::get_implementation_task_projection($companyid,
            (int)$owner->id, $taskid));
        $DB->set_field('local_kbacorp_impl_program', 'commercialstatus', 'paid',
            ['baselineauditid' => (int)$task->auditid]);
        $DB->set_field('local_kbacorp_impl_program', 'status', 'paused',
            ['baselineauditid' => (int)$task->auditid]);
        $row = bridge::get_implementation_task_projection($companyid, (int)$owner->id, $taskid);
        $this->assertFalse($row['attachable']);
        $DB->set_field('local_kbacorp_impl_program', 'status', 'draft',
            ['baselineauditid' => (int)$task->auditid]);
        $this->assertNull(bridge::get_implementation_task_projection($companyid,
            (int)$owner->id, $taskid));
    }

    public function test_pagination_is_strictly_bounded_and_admin_can_list_company_scope(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$taskid, $companyid, $owner] = $this->create_task();
        $rows = bridge::list_implementation_task_projections($companyid,
            (int)get_admin()->id, 0, 'company', 1, 0);
        $this->assertCount(1, $rows);
        $this->expectException(\invalid_parameter_exception::class);
        bridge::list_implementation_task_projections($companyid,
            (int)get_admin()->id, 0, 'company', 101, 0);
    }

    public function test_paid_program_projection_is_manager_scoped_and_counts_tasks(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$taskid, $companyid, $owner] = $this->create_task();
        $adminid = (int)get_admin()->id;
        $list = bridge::list_implementation_program_projections($companyid, $adminid, 10, 0);
        $this->assertCount(1, $list);
        $program = reset($list);
        $this->assertSame($companyid, (int)$program['company_id']);
        $this->assertSame(1, (int)$program['task_count']);
        $this->assertSame(0, (int)$program['completed_count']);
        $this->assertSame($program, bridge::get_implementation_program_projection(
            $companyid, $adminid, (int)$program['program_id']));
        $this->assertArrayNotHasKey('notes', $program);
        $this->assertArrayNotHasKey('contactemail', $program);
        // A normal employee is not a company-level implementation manager.
        try {
            bridge::list_implementation_program_projections($companyid, (int)$owner->id);
            $this->fail('Employee must not list programs.');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotallowed', $e->errorcode);
        }
        // No unpaid commercial request is exported.
        $DB->set_field('local_kbacorp_impl_program', 'commercialstatus', 'requested',
            ['id' => (int)$program['program_id']]);
        $this->assertSame([], bridge::list_implementation_program_projections($companyid, $adminid));
        $this->assertNull(bridge::get_implementation_program_projection(
            $companyid, $adminid, (int)$program['program_id']));
    }

    /** @return array{0:int,1:int,2:\stdClass} */
    private function create_task(): array {
        global $DB;

        $owner = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Kanban test company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$owner->id, 'employee');

        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid,
            'toolid' => $toolid,
            'title' => 'Kanban baseline',
            'audittype' => 'baseline',
            'auditdate' => time(),
        ], (int)get_admin()->id);
        $audititems = implementation_audits::get_items($auditid);
        $item = reset($audititems);
        $DB->update_record('local_kbacorp_impl_item', (object)[
            'id' => (int)$item->id,
            'score' => 2,
            'evidencestatus' => implementation_audits::EVIDENCE_SUBMITTED,
        ]);
        $DB->set_field('local_kbacorp_impl_audit', 'status',
            implementation_audits::STATUS_CONFIRMED, ['id' => $auditid]);
        $DB->insert_record('local_kbacorp_impl_program', (object)[
            'companyid' => $companyid,
            'baselineauditid' => $auditid,
            'code' => 'BMS-KANBAN-' . $companyid . '-' . $auditid,
            'title' => 'Kanban programme',
            'package' => 'start',
            'commercialstatus' => 'paid',
            'status' => 'active',
            'startdate' => time(),
            'requestedby' => (int)get_admin()->id,
            'activatedby' => (int)get_admin()->id,
            'activatedat' => time(),
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $result = implementation_actions::create_selected(
            $companyid,
            $auditid,
            [(int)$item->id],
            [],
            (int)$owner->id,
            (int)get_admin()->id,
            1704067200
        );
        $taskid = (int)reset($result->taskids);
        return [$taskid, $companyid, $owner];
    }
}
