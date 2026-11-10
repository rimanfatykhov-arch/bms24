<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_actions;
use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\implementation_board_query;
use local_kbacorporate\local\point_a_template;
use local_kbacorporate\local\repository;

/** SQL paging and ACL regression for Corporate Kanban. */
final class implementation_board_query_test extends \advanced_testcase {
    public function test_column_pagination_and_full_counts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$taskid, $companyid, $auditid] = $this->fixture();
        $task = $DB->get_record('local_kbacorp_impl_task', ['id' => $taskid], '*', MUST_EXIST);
        for ($i = 1; $i <= 22; $i++) {
            $copy = clone $task;
            unset($copy->id);
            $copy->title = 'Implementation batch ' . $i;
            $copy->status = $i > 18 ? 'review' : 'planned';
            $copy->timecreated += $i;
            $copy->timemodified += $i;
            $DB->insert_record('local_kbacorp_impl_task', $copy);
        }
        $filter = ['companyid' => $companyid];
        $total = implementation_board_query::summary((int)get_admin()->id, false, $filter);
        $this->assertSame(23, $total['total']);
        $this->assertSame(19, $total['counts']['planned']);
        $this->assertSame(4, $total['counts']['review']);
        $first = implementation_board_query::column((int)get_admin()->id, false,
            $filter, 'planned', 0);
        $second = implementation_board_query::column((int)get_admin()->id, false,
            $filter, 'planned', 1);
        $this->assertCount(15, $first);
        $this->assertCount(4, $second);
        $firstids = array_map(static fn($task): int => (int)$task->id, $first);
        $secondids = array_map(static fn($task): int => (int)$task->id, $second);
        $this->assertEmpty(array_intersect($firstids, $secondids));
        $this->assertSame(1, implementation_board_query::page(100, 19));
        $this->assertSame(0, implementation_board_query::page(-1, 19));
        $this->assertSame(0, implementation_board_query::page(0, 0));
        $this->assertCount(0, implementation_board_query::column((int)get_admin()->id,
            false, ['companyid' => $companyid, 'status' => 'review'], 'planned'));
    }

    public function test_restricted_actor_cannot_read_all_curators(): void {
        $this->resetAfterTest();
        $actor = $this->getDataGenerator()->create_user();
        $this->expectException(\moodle_exception::class);
        implementation_board_query::summary((int)$actor->id, true, []);
    }

    private function fixture(): array {
        global $DB;
        $owner = $this->getDataGenerator()->create_user();
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Board query fixture', 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$owner->id, 'employee');
        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid, 'toolid' => $toolid,
            'title' => 'Board query audit', 'audittype' => 'baseline',
            'auditdate' => time(),
        ], (int)get_admin()->id);
        $item = reset(implementation_audits::get_items($auditid));
        $DB->update_record('local_kbacorp_impl_item', (object)[
            'id' => (int)$item->id, 'score' => 2,
            'evidencestatus' => implementation_audits::EVIDENCE_SUBMITTED,
        ]);
        $DB->set_field('local_kbacorp_impl_audit', 'status',
            implementation_audits::STATUS_CONFIRMED, ['id' => $auditid]);
        $DB->insert_record('local_kbacorp_impl_program', (object)[
            'companyid' => $companyid, 'baselineauditid' => $auditid,
            'curatorid' => (int)get_admin()->id, 'code' => 'BOARD-' . $companyid,
            'title' => 'Board query programme', 'package' => 'start',
            'commercialstatus' => 'paid', 'status' => 'active',
            'startdate' => time(), 'requestedby' => (int)get_admin()->id,
            'activatedby' => (int)get_admin()->id, 'activatedat' => time(),
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $result = implementation_actions::create_selected($companyid, $auditid,
            [(int)$item->id], [], (int)$owner->id, (int)get_admin()->id, 1704067200);
        return [(int)reset($result->taskids), $companyid, $auditid];
    }
}
