<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_actions;
use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\implementation_kanban;
use local_kbacorporate\local\point_a_template;
use local_kbacorporate\local\repository;

/** Regression tests for the audit-action Kanban projection and controlled card updates. */
final class implementation_kanban_test extends \advanced_testcase {
    public function test_manager_updates_owner_due_date_and_priority_without_changing_status(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$taskid, $companyid, $ownerone] = $this->create_task();
        $ownertwo = $this->getDataGenerator()->create_user();
        repository::add_member($companyid, (int)$ownertwo->id, 'employee');
        $duedate = implementation_kanban::parse_due_date('2030-05-20');

        $result = implementation_kanban::update_card(
            $taskid,
            (int)get_admin()->id,
            (int)$ownertwo->id,
            $duedate,
            'additional'
        );

        $this->assertTrue($result->changed);
        $task = $DB->get_record('local_kbacorp_impl_task', ['id' => $taskid], '*', MUST_EXIST);
        $this->assertSame((int)$ownertwo->id, (int)$task->ownerid);
        $this->assertSame($duedate, (int)$task->duedate);
        $this->assertSame('additional', (string)$task->priority);
        $this->assertSame('planned', (string)$task->status);
        $this->assertSame(0, (int)$task->lastremindersent);

        $log = $DB->get_record('local_kbacorp_impl_tasklog', [
            'taskid' => $taskid,
            'eventtype' => 'kanban',
        ], '*', MUST_EXIST);
        $details = json_decode((string)$log->detailsjson, true);
        $this->assertSame((int)$ownerone->id, (int)$details['ownerfrom']);
        $this->assertSame((int)$ownertwo->id, (int)$details['ownerto']);
        $this->assertSame('critical', (string)$details['priorityfrom']);
        $this->assertSame('additional', (string)$details['priorityto']);
        $this->assertSame('planned', (string)$log->fromstatus);
        $this->assertSame('planned', (string)$log->tostatus);
    }

    public function test_idempotent_card_update_does_not_create_history_row(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$taskid, $companyid, $owner] = $this->create_task();
        $task = $DB->get_record('local_kbacorp_impl_task', ['id' => $taskid], '*', MUST_EXIST);
        $before = $DB->count_records('local_kbacorp_impl_tasklog', ['taskid' => $taskid]);

        $result = implementation_kanban::update_card(
            $taskid,
            (int)get_admin()->id,
            (int)$owner->id,
            (int)$task->duedate,
            (string)$task->priority
        );

        $this->assertFalse($result->changed);
        $this->assertSame($before,
            $DB->count_records('local_kbacorp_impl_tasklog', ['taskid' => $taskid]));
    }

    public function test_owner_from_another_company_is_rejected(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$taskid] = $this->create_task();
        $outsider = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        implementation_kanban::update_card(
            $taskid,
            (int)get_admin()->id,
            (int)$outsider->id,
            0,
            'core'
        );
    }

    public function test_completed_card_cannot_be_changed_from_kanban(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        [$taskid, $companyid, $owner] = $this->create_task();
        $DB->set_field('local_kbacorp_impl_task', 'status', 'completed', ['id' => $taskid]);

        try {
            implementation_kanban::update_card(
                $taskid,
                (int)get_admin()->id,
                (int)$owner->id,
                0,
                'core'
            );
            $this->fail('Completed Kanban cards must be locked.');
        } catch (\moodle_exception $exception) {
            $this->assertSame('kanbancardlocked', $exception->errorcode);
        }
    }

    public function test_due_date_parser_rejects_invalid_calendar_date(): void {
        $this->expectException(\invalid_parameter_exception::class);
        implementation_kanban::parse_due_date('2029-02-31');
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
        $item = reset(implementation_audits::get_items($auditid));
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
