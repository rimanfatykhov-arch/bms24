<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_actions;
use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\implementation_task_discussion;
use local_kbacorporate\local\point_a_template;
use local_kbacorporate\local\repository;

/** Curator workspace: server paging, unread and company isolation. */
final class implementation_curator_paging_test extends \advanced_testcase {
    public function test_paged_results_keep_global_counters_and_unread_filters(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $curator = $this->getDataGenerator()->create_user();
        $owner = $this->getDataGenerator()->create_user();
        [$companyid, $auditid, $taskids] = $this->make_tasks($curator, $owner);
        $this->assertGreaterThanOrEqual(2, count($taskids));

        $firstid = $taskids[0];
        $secondid = $taskids[1];
        $DB->set_field('local_kbacorp_impl_task', 'status', 'inprogress', ['id' => $secondid]);
        $DB->insert_record('local_kbacorp_impl_comment', (object)[
            'taskid' => $firstid, 'userid' => (int)$owner->id,
            'message' => 'Question for curator', 'messageformat' => FORMAT_PLAIN,
            'timecreated' => time() + 5, 'timemodified' => time() + 5,
        ]);
        $DB->insert_record('local_kbacorp_impl_comment', (object)[
            'taskid' => $firstid, 'userid' => (int)$owner->id,
            'message' => 'Latest message', 'messageformat' => FORMAT_PLAIN,
            'timecreated' => time() + 6, 'timemodified' => time() + 6,
        ]);

        $overview = implementation_task_discussion::get_curator_overview((int)$curator->id);
        $this->assertSame(count($taskids), $overview->taskcount);
        $this->assertSame(2, $overview->unread);
        $this->assertArrayHasKey((int)$owner->id, $overview->students);

        $page0 = implementation_task_discussion::get_curator_page((int)$curator->id, '', '', '', false, 0, 1);
        $page1 = implementation_task_discussion::get_curator_page((int)$curator->id, '', '', '', false, 1, 1);
        $this->assertSame(count($taskids), $page0->total);
        $this->assertCount(1, $page0->threads);
        $this->assertCount(1, $page1->threads);
        $this->assertNotSame((int)$page0->threads[0]->id, (int)$page1->threads[0]->id);

        $unread = implementation_task_discussion::get_curator_page((int)$curator->id,
            '', '', 'unread', false, 0, 20);
        $this->assertSame(1, $unread->total);
        $this->assertSame($firstid, (int)$unread->threads[0]->id);
        $this->assertSame(2, (int)$unread->threads[0]->unreadcount);
        $this->assertSame('Latest message', $unread->threads[0]->lastcomment->message);
        $status = implementation_task_discussion::get_curator_page((int)$curator->id,
            'inprogress', '', '', false, 0, 20);
        $this->assertSame(1, $status->total);
        $this->assertSame($secondid, (int)$status->threads[0]->id);
    }

    public function test_cross_company_curator_isolation(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $curatora = $this->getDataGenerator()->create_user();
        $curatorb = $this->getDataGenerator()->create_user();
        $owner = $this->getDataGenerator()->create_user();
        [,, $tasksfirst] = $this->make_tasks($curatora, $owner);
        [,, $taskssecond] = $this->make_tasks($curatorb, $owner);
        $page = implementation_task_discussion::get_curator_page((int)$curatora->id);
        $this->assertCount(count($tasksfirst), $page->threads);
        $this->assertSame(count($tasksfirst), $page->total);
        $foreignids = array_map('intval', $taskssecond);
        foreach ($page->threads as $thread) {
            $this->assertNotContains((int)$thread->id, $foreignids);
        }
        try {
            implementation_task_discussion::get_curator_page((int)$curatora->id, '', '', '', true);
            $this->fail('A normal curator must never request the global task scope.');
        } catch (\moodle_exception $e) {
            $this->assertSame('companynotallowed', $e->errorcode);
        }
        $all = implementation_task_discussion::get_curator_page((int)get_admin()->id, '', '', '', true);
        $this->assertSame(count($tasksfirst) + count($taskssecond), $all->total);
    }

    /** @return array{0:int,1:int,2:array<int,int>} */
    private function make_tasks(\stdClass $curator, \stdClass $owner): array {
        global $DB;
        $companyid = (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Curator paging ' . uniqid(), 'active' => 1,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        repository::add_member($companyid, (int)$owner->id, 'employee');
        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid, 'toolid' => $toolid,
            'title' => 'Curator paging audit', 'audittype' => 'baseline', 'auditdate' => time(),
        ], (int)get_admin()->id);
        $items = array_slice(array_values(implementation_audits::get_items($auditid)), 0, 3);
        $itemids = [];
        foreach ($items as $item) {
            $itemids[] = (int)$item->id;
            $DB->update_record('local_kbacorp_impl_item', (object)[
                'id' => (int)$item->id, 'score' => 2,
                'evidencestatus' => implementation_audits::EVIDENCE_SUBMITTED,
            ]);
        }
        $DB->set_field('local_kbacorp_impl_audit', 'status',
            implementation_audits::STATUS_CONFIRMED, ['id' => $auditid]);
        $DB->insert_record('local_kbacorp_impl_program', (object)[
            'companyid' => $companyid, 'baselineauditid' => $auditid,
            'code' => 'PAGE-' . $companyid . '-' . $auditid,
            'title' => 'Curator programme', 'package' => 'start',
            'commercialstatus' => 'paid', 'status' => 'active',
            'curatorid' => (int)$curator->id,
            'timecreated' => time(), 'timemodified' => time(),
        ]);
        $created = implementation_actions::create_selected($companyid, $auditid, $itemids,
            [], (int)$owner->id, (int)get_admin()->id, time());
        return [$companyid, $auditid, array_values(array_map('intval', $created->taskids))];
    }
}
