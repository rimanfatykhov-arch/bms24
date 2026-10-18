<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\identity_registry;
use local_kbacompany\local\task_service;
use local_kbacorporate\local\repository;

/** Canonical Task Center regression tests. */
final class task_service_test extends \advanced_testcase {
    private function fixture(): array {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $admin = (int)$USER->id;
        $companyid = repository::save_company((object)['name' => 'Task Center', 'active' => 1]);
        $company = identity_registry::company_uuid($companyid);
        $employee = $this->getDataGenerator()->create_user();
        $reviewer = $this->getDataGenerator()->create_user();
        $now = time();
        foreach ([$employee, $reviewer] as $user) {
            $DB->insert_record('local_kbacorp_member', (object)[
                'companyid' => $companyid, 'userid' => $user->id, 'membertype' => 'employee',
                'isemployee' => 1, 'ismanager' => 0, 'isdepartmentmanager' => 0, 'ishr' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
        }
        return compact('admin', 'companyid', 'company', 'employee', 'reviewer');
    }

    private function create(array $f, array $overrides = []): string {
        return task_service::create($f['company'], $f['admin'], array_merge([
            'title' => 'Prepare weekly management report',
            'description' => 'Collect and verify source facts.',
            'product' => 'Approved weekly management report',
            'assigneeid' => (int)$f['employee']->id,
            'reviewerid' => (int)$f['reviewer']->id,
            'priority' => 'normal', 'stream' => 'operate',
            'startdate' => '2026-09-14', 'duedate' => '2026-09-18',
            'planminutes' => 90, 'parentuuid' => '',
        ], $overrides));
    }

    public function test_contract_declares_canonical_task_center(): void {
        $contract = task_service::contract();
        $this->assertSame('bms_task_center', $contract['contract']);
        $this->assertSame('0.5.6', $contract['version']);
        $this->assertContains('task_is_canonical_execution_object', $contract['rules']);
        $this->assertContains('battle_plan_references_task_instead_of_copying_it', $contract['rules']);
        $this->assertSame(['change', 'run', 'operate', 'develop', 'unplanned'], $contract['streams']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }

    public function test_task_requires_product_and_runs_through_review(): void {
        global $DB;
        $f = $this->fixture();
        $taskuuid = $this->create($f);
        $task = $DB->get_record('local_kbacompany_task', ['uuid' => $taskuuid], '*', MUST_EXIST);
        $this->assertSame('manual', $task->sourcekind);
        $this->assertSame('Approved weekly management report', $task->product);
        $this->assertSame('open', $task->status);
        $this->assertSame(1, (int)$task->revision);

        $this->setUser($f['employee']);
        task_service::transition($f['company'], (int)$f['employee']->id, $taskuuid, 1, 'start');
        $check = task_service::add_checklist($f['company'], (int)$f['employee']->id, $taskuuid,
            'Verify source figures');
        task_service::toggle_checklist($f['company'], (int)$f['employee']->id, $taskuuid, $check);
        task_service::transition($f['company'], (int)$f['employee']->id, $taskuuid, 2, 'submit', [
            'result' => 'Report approved for review', 'factminutes' => 80,
        ]);
        $this->assertSame('review', $DB->get_field('local_kbacompany_task', 'status', ['uuid' => $taskuuid]));

        $this->setUser($f['reviewer']);
        task_service::transition($f['company'], (int)$f['reviewer']->id, $taskuuid, 3, 'accept');
        $done = $DB->get_record('local_kbacompany_task', ['uuid' => $taskuuid], '*', MUST_EXIST);
        $this->assertSame('done', $done->status);
        $this->assertSame('Report approved for review', $done->result);
        $this->assertSame(80, (int)$done->factminutes);
        $this->assertGreaterThan(0, (int)$done->completedat);
        $this->assertGreaterThanOrEqual(5, $DB->count_records('local_kbacompany_tasklog', ['taskuuid' => $taskuuid]));
    }

    public function test_unfinished_checklist_blocks_submit(): void {
        $f = $this->fixture();
        $taskuuid = $this->create($f, ['reviewerid' => 0]);
        $this->setUser($f['employee']);
        task_service::add_checklist($f['company'], (int)$f['employee']->id, $taskuuid, 'Required evidence');
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('taskcenter_checklisterror', 'local_kbacompany'));
        task_service::transition($f['company'], (int)$f['employee']->id, $taskuuid, 1, 'submit', [
            'result' => 'Result without checklist completion',
        ]);
    }

    public function test_reviewer_cannot_complete_unassigned_execution_checklist(): void {
        $f = $this->fixture();
        $taskuuid = $this->create($f);
        $check = task_service::add_checklist($f['company'], $f['admin'], $taskuuid, 'Executor step');
        $this->setUser($f['reviewer']);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('taskcenter_permission', 'local_kbacompany'));
        task_service::toggle_checklist($f['company'], (int)$f['reviewer']->id, $taskuuid, $check);
    }

    public function test_optimistic_revision_and_parent_cycle_are_enforced(): void {
        $f = $this->fixture();
        $a = $this->create($f, ['title' => 'Parent A', 'product' => 'A product', 'reviewerid' => 0]);
        $b = $this->create($f, ['title' => 'Child B', 'product' => 'B product', 'reviewerid' => 0, 'parentuuid' => $a]);
        task_service::save($f['company'], $f['admin'], $a, 1, [
            'title' => 'Parent A revised', 'description' => '', 'product' => 'A product',
            'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => '',
        ]);
        try {
            task_service::save($f['company'], $f['admin'], $a, 1, [
                'title' => 'Stale', 'description' => '', 'product' => 'A product',
                'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0,
                'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
                'planminutes' => 0, 'parentuuid' => '',
            ]);
            $this->fail('Stale revision must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('taskcenter_conflict', 'local_kbacompany'), $e->getMessage());
        }
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('taskcenter_parent', 'local_kbacompany'));
        task_service::save($f['company'], $f['admin'], $a, 2, [
            'title' => 'Cycle', 'description' => '', 'product' => 'A product',
            'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => $b,
        ]);
    }

    public function test_employee_can_assign_task_to_another_employee_or_self(): void {
        global $DB;
        $f = $this->fixture();
        $this->setUser($f['employee']);
        $delegated = task_service::create($f['company'], (int)$f['employee']->id, [
            'title' => 'Delegate peer task', 'description' => '', 'product' => 'Peer result',
            'assigneeid' => (int)$f['reviewer']->id, 'reviewerid' => 0, 'priority' => 'normal', 'stream' => 'operate',
            'startdate' => '', 'duedate' => '', 'planminutes' => 0, 'parentuuid' => '',
        ]);
        $self = task_service::create($f['company'], (int)$f['employee']->id, [
            'title' => 'Self task', 'description' => '', 'product' => 'Self result',
            'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0, 'priority' => 'normal', 'stream' => 'operate',
            'startdate' => '', 'duedate' => '', 'planminutes' => 0, 'parentuuid' => '',
        ]);
        $this->assertSame((int)$f['reviewer']->id, (int)$DB->get_field('local_kbacompany_task', 'assigneeid', ['uuid' => $delegated]));
        $this->assertSame((int)$f['employee']->id, (int)$DB->get_field('local_kbacompany_task', 'assigneeid', ['uuid' => $self]));
    }


    public function test_assignee_may_review_own_task_and_it_remains_in_my_tasks(): void {
        global $DB;
        $f = $this->fixture();
        $userid = (int)$f['employee']->id;
        $this->setUser($f['employee']);
        $taskuuid = task_service::create($f['company'], $userid, [
            'title' => 'Self reviewed task', 'description' => '', 'product' => 'Accepted self result',
            'assigneeid' => $userid, 'reviewerid' => $userid, 'observerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '', 'duedate' => '',
            'planminutes' => 0, 'parentuuid' => '',
        ]);
        $task = $DB->get_record('local_kbacompany_task', ['uuid' => $taskuuid], '*', MUST_EXIST);
        $this->assertSame($userid, (int)$task->assigneeid);
        $this->assertSame($userid, (int)$task->reviewerid);

        $page = task_service::list_page($f['company'], $userid, ['scope' => 'my']);
        $this->assertContains($taskuuid, array_column($page['tasks'], 'uuid'));

        task_service::transition($f['company'], $userid, $taskuuid, 1, 'submit', [
            'result' => 'Self checked result', 'factminutes' => 15,
        ]);
        $this->assertSame('review', $DB->get_field('local_kbacompany_task', 'status', ['uuid' => $taskuuid]));
        task_service::transition($f['company'], $userid, $taskuuid, 2, 'accept');
        $this->assertSame('done', $DB->get_field('local_kbacompany_task', 'status', ['uuid' => $taskuuid]));
    }

    /** RC25: Workspace and Task Center must project one canonical user-scoped task set. */
    public function test_workspace_and_tasks_page_agree_on_open_and_overdue_tasks(): void {
        $f = $this->fixture();
        $uuid = $this->create($f, [
            'title' => 'Launch sales battle plan',
            'duedate' => '2026-01-01',
            'reviewerid' => 0,
        ]);
        $this->setUser($f['employee']);
        $actor = (int)$f['employee']->id;
        $page = task_service::list_page($f['company'], $actor, ['scope' => 'my']);
        $workspace = \local_kbacompany\local\bms_ui_service::workspace_model($f['company'], $actor);
        $this->assertSame(1, (int)$page['counts']['open']);
        $this->assertSame(1, (int)$page['counts']['overdue']);
        $this->assertSame((int)$page['counts']['open'], (int)$workspace['workspace_counters']['tasks']);
        $this->assertSame((int)$page['counts']['overdue'], (int)$workspace['workspace_counters']['overdue']);
        $visibleuuids = array_column($workspace['communication_context']['task_center']['tasks'], 'uuid');
        $this->assertContains($uuid, $visibleuuids);
    }

    /** RC26: a completed task must not displace an open task on the dashboard page. */
    public function test_workspace_open_tasks_remain_visible_after_completed_history(): void {
        global $DB;
        $f = $this->fixture();
        for ($i = 0; $i < 28; $i++) {
            $doneuuid = $this->create($f, [
                'title' => 'Completed historical work ' . $i,
                'duedate' => '2026-01-01', 'reviewerid' => 0,
            ]);
            $DB->set_field('local_kbacompany_task', 'status', 'done', ['uuid' => $doneuuid]);
        }
        $openuuid = $this->create($f, [
            'title' => 'Current overdue action', 'duedate' => '2026-01-01', 'reviewerid' => 0,
        ]);
        $this->setUser($f['employee']);
        $actor = (int)$f['employee']->id;
        $page = task_service::list_page($f['company'], $actor, ['scope' => 'my']);
        $workspace = \local_kbacompany\local\bms_ui_service::workspace_model($f['company'], $actor);
        $this->assertSame(1, (int)$page['counts']['open']);
        $this->assertSame(1, (int)$page['counts']['overdue']);
        $this->assertSame(1, (int)$workspace['workspace_counters']['tasks']);
        $this->assertSame(1, (int)$workspace['workspace_counters']['overdue']);
        $this->assertContains($openuuid, array_column($workspace['communication_context']['task_center']['tasks'], 'uuid'));
    }

    /** RC26: company-scoped task listing and dashboard must not expose tasks from another company. */
    public function test_workspace_task_list_does_not_include_second_company(): void {
        global $DB;
        $f = $this->fixture();
        $ownuuid = $this->create($f, ['title' => 'Our work', 'reviewerid' => 0]);
        $secondid = repository::save_company((object)['name' => 'Unrelated tenant', 'active' => 1]);
        $second = identity_registry::company_uuid($secondid);
        $now = time();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $secondid, 'userid' => (int)$f['employee']->id,
            'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0,
            'isdepartmentmanager' => 0, 'ishr' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $otheruuid = task_service::create($second, $f['admin'], [
            'title' => 'Other company task', 'description' => '', 'product' => 'Result',
            'assigneeid' => (int)$f['employee']->id, 'reviewerid' => 0,
            'priority' => 'normal', 'stream' => 'operate', 'startdate' => '',
            'duedate' => '2026-01-01', 'planminutes' => 0, 'parentuuid' => '',
        ]);
        $this->setUser($f['employee']);
        $actor = (int)$f['employee']->id;
        $workspace = \local_kbacompany\local\bms_ui_service::workspace_model($f['company'], $actor);
        $visible = array_column($workspace['communication_context']['task_center']['tasks'], 'uuid');
        $this->assertContains($ownuuid, $visible);
        $this->assertNotContains($otheruuid, $visible);
        $this->assertSame(1, (int)$workspace['workspace_counters']['tasks']);
    }

}
