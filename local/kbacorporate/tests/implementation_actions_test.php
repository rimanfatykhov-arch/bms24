<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_actions;
use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\point_a_template;
use local_kbacorporate\local\repository;

/** Regression tests for the confirmed-audit to implementation-action workflow. */
final class implementation_actions_test extends \advanced_testcase {
    public function test_default_due_dates_follow_priority_windows(): void {
        $start = 1704067200;
        $this->assertSame($start + 30 * DAYSECS,
            implementation_actions::default_due_date('critical', $start));
        $this->assertSame($start + 60 * DAYSECS,
            implementation_actions::default_due_date('core', $start));
        $this->assertSame($start + 90 * DAYSECS,
            implementation_actions::default_due_date('additional', $start));
        $this->assertSame($start + 60 * DAYSECS,
            implementation_actions::default_due_date('unknown', $start));
    }

    public function test_preferred_route_requires_implementation_tool(): void {
        $courseonly = (object)['id' => 1, 'actiontoolid' => 0, 'courseid' => 17, 'priority' => 'critical'];
        $toolroute = (object)['id' => 2, 'actiontoolid' => 9, 'courseid' => 0, 'priority' => 'core'];

        $this->assertSame($toolroute, implementation_actions::preferred_route([$courseonly, $toolroute]));
        $this->assertNull(implementation_actions::preferred_route([$courseonly]));
    }

    public function test_route_outcome_and_evidence_are_carried_into_task_result(): void {
        $item = (object)[
            'criterionname' => 'Регулярный управленческий цикл',
            'targetscore' => 4,
            'evidencehint' => '',
        ];
        $route = (object)[
            'expectedresult' => 'Проводится еженедельное совещание.',
            'evidencecriteria' => 'Протоколы за четыре недели.',
            'note' => '',
        ];

        $result = implementation_actions::default_expected_result($item, $route);
        $this->assertStringContainsString($route->expectedresult, $result);
        $this->assertStringContainsString($route->evidencecriteria, $result);
    }

    public function test_confirmed_gap_creates_one_idempotent_action(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Audit to action company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$user->id, 'employee');

        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid,
            'toolid' => $toolid,
            'title' => 'Confirmed baseline',
            'audittype' => 'baseline',
            'auditdate' => time(),
        ], 2);
        $audititems = implementation_audits::get_items($auditid);
        $item = reset($audititems);
        $DB->update_record('local_kbacorp_impl_item', (object)[
            'id' => (int)$item->id,
            'score' => 2,
            'evidencestatus' => implementation_audits::EVIDENCE_SUBMITTED,
        ]);
        $DB->set_field('local_kbacorp_impl_audit', 'status',
            implementation_audits::STATUS_CONFIRMED, ['id' => $auditid]);

        $now = 1704067200;
        $DB->insert_record('local_kbacorp_impl_program', (object)[
            'companyid' => $companyid,
            'baselineauditid' => $auditid,
            'code' => 'BMS-PROG-' . $companyid . '-' . $auditid,
            'title' => 'Active implementation programme',
            'package' => 'start',
            'commercialstatus' => 'paid',
            'status' => 'active',
            'startdate' => $now,
            'requestedby' => 2,
            'activatedby' => 2,
            'activatedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $preview = implementation_actions::preview($companyid, $auditid, $now);
        $this->assertSame(1, $preview->counts->gaps);
        $this->assertSame(1, $preview->counts->critical);
        $this->assertSame(1, $preview->counts->withoutactions);
        $this->assertCount(1, $preview->rows);
        $this->assertSame(5, (int)$preview->rows[0]->gap);
        $this->assertSame('critical', $preview->rows[0]->priority);

        $first = implementation_actions::create_selected($companyid, $auditid,
            [(int)$item->id], [], (int)$user->id, 2, $now);
        $this->assertSame(1, $first->created);
        $this->assertSame(0, $first->existing);
        $this->assertCount(1, $first->taskids);

        $task = $DB->get_record('local_kbacorp_impl_task', [
            'id' => (int)$first->taskids[0],
        ], '*', MUST_EXIST);
        $this->assertSame((int)$item->id, (int)$task->itemid);
        $this->assertSame((int)$user->id, (int)$task->ownerid);
        $this->assertSame('critical', $task->priority);
        $this->assertSame(5, (int)$task->gapvalue);
        $this->assertSame(usergetmidnight($now) + 30 * DAYSECS, (int)$task->duedate);

        $second = implementation_actions::create_selected($companyid, $auditid,
            [(int)$item->id], [], (int)$user->id, 2, $now);
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->existing);
        $this->assertSame(1, $DB->count_records('local_kbacorp_impl_task', [
            'companyid' => $companyid,
            'auditid' => $auditid,
            'itemid' => (int)$item->id,
        ]));
    }

    public function test_draft_audit_cannot_create_actions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Draft audit company',
            'active' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid,
            'toolid' => $toolid,
            'title' => 'Draft baseline',
            'audittype' => 'baseline',
            'auditdate' => time(),
        ], 2);
        $audititems = implementation_audits::get_items($auditid);
        $item = reset($audititems);

        $this->expectException(\moodle_exception::class);
        implementation_actions::create_selected($companyid, $auditid,
            [(int)$item->id], [], 0, 2);
    }
}
