<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\implementation_task_checklist;
use local_kbacorporate\local\implementation_task_discussion;
use local_kbacorporate\local\implementation_task_lifecycle;
use local_kbacorporate\local\point_a_template;

/** Tests for the bundled Point A implementation-audit template. */
final class implementation_audits_test extends \advanced_testcase {
    public function test_point_a_installs_15_areas_and_137_criteria_once(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company();

        $toolid = point_a_template::install($companyid);
        $this->assertSame($toolid, point_a_template::install($companyid));
        $tool = $DB->get_record('local_kbacorp_bms_tool', ['id' => $toolid], '*', MUST_EXIST);
        $this->assertSame(9, (int)$tool->scalemax);
        $this->assertSame(7, (int)$tool->attentionthreshold);
        $this->assertSame(implementation_audits::INDEX_AREA_AVERAGE, $tool->indexmethod);
        $criteria = implementation_audits::get_criteria($toolid);
        $this->assertCount(137, $criteria);
        $this->assertCount(15, array_unique(array_column($criteria, 'sectioncode')));
        $this->assertSame([7], array_values(array_unique(array_map(static function($criterion) {
            return (int)$criterion->targetscore;
        }, $criteria))));
    }

    public function test_audit_selector_contains_company_and_global_tools_only(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company();
        $othercompanyid = $this->create_company();
        $companytool = point_a_template::install($companyid);
        $othertool = point_a_template::install($othercompanyid);
        $globaltool = implementation_audits::save_tool((object)[
            'companyid' => 0, 'code' => 'GLOBAL-TOOL', 'name' => 'Global tool',
            'area' => 'management', 'scalemax' => 5, 'attentionthreshold' => 3,
            'indexmethod' => implementation_audits::INDEX_WEIGHTED_VERIFIED,
            'description' => '', 'active' => 1,
        ]);

        $ids = array_map(static function($tool) {
            return (int)$tool->id;
        }, implementation_audits::get_selectable_tools($companyid, true));
        $this->assertContains($companytool, $ids);
        $this->assertContains($globaltool, $ids);
        $this->assertNotContains($othertool, $ids);

        $DB->set_field('local_kbacorp_bms_tool', 'active', 0, ['id' => $companytool]);
        $activeids = array_map(static function($tool) {
            return (int)$tool->id;
        }, implementation_audits::get_selectable_tools($companyid, true));
        $this->assertNotContains($companytool, $activeids);
    }

    public function test_tool_overview_is_company_scoped_and_contains_usage_counts(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company();
        $othercompanyid = $this->create_company();
        $toolid = point_a_template::install($companyid);
        $othertool = point_a_template::install($othercompanyid);

        $rows = implementation_audits::get_tools_overview($companyid);
        $byid = [];
        foreach ($rows as $row) { $byid[(int)$row->id] = $row; }
        $this->assertArrayHasKey($toolid, $byid);
        $this->assertArrayNotHasKey($othertool, $byid);
        $this->assertSame(137, (int)$byid[$toolid]->criteriacount);
        $this->assertSame(137, (int)$byid[$toolid]->activecriteriacount);
        $this->assertSame(0, (int)$byid[$toolid]->auditcount);

        implementation_audits::create_audit((object)[
            'companyid' => $companyid, 'toolid' => $toolid, 'title' => 'Library count',
            'audittype' => 'baseline', 'auditdate' => time(),
        ], 2);
        $updated = implementation_audits::get_tools_overview($companyid);
        foreach ($updated as $row) {
            if ((int)$row->id === $toolid) {
                $this->assertSame(1, (int)$row->auditcount);
            }
        }
        $this->assertTrue(implementation_audits::tool_has_audits($toolid));
    }

    public function test_audit_snapshots_targets_and_calculates_gaps(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company();
        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid, 'toolid' => $toolid, 'title' => 'Gap test',
            'audittype' => 'baseline', 'auditdate' => time(),
        ], 2);
        $audititems = implementation_audits::get_items($auditid);
        $item = reset($audititems);
        $this->assertSame(7, (int)$item->targetscore);
        $DB->set_field('local_kbacorp_impl_item', 'score', 2, ['id' => $item->id]);
        $DB->set_field('local_kbacorp_impl_item', 'evidencestatus',
            implementation_audits::EVIDENCE_SUBMITTED, ['id' => $item->id]);
        $rows = implementation_audits::get_gap_rows($auditid);
        $row = reset($rows);
        $this->assertSame(5, $row->gap);
        $this->assertSame('critical', $row->gappriority);
        $DB->set_field('local_kbacorp_bms_crit', 'targetscore', 9, ['id' => $item->criterionid]);
        $this->assertSame(7, (int)$DB->get_field('local_kbacorp_impl_item', 'targetscore', ['id' => $item->id]));
    }

    public function test_published_tool_version_is_immutable_and_pins_new_audits(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company();
        $toolid = point_a_template::install($companyid);
        $version1 = implementation_audits::ensure_versioned($toolid, 2);
        $this->assertSame('v1', $version1->versionlabel);

        $criteria = implementation_audits::get_criteria($toolid);
        $criterion = reset($criteria);
        $oldname = $criterion->name;
        $DB->set_field('local_kbacorp_bms_crit', 'name', 'Updated criterion', ['id' => $criterion->id]);
        implementation_audits::mark_tool_draft($toolid);
        $this->assertEquals(1, $DB->get_field('local_kbacorp_bms_tool', 'hasdraft', ['id' => $toolid]));
        $comparison = implementation_audits::compare_current_to_draft($toolid);
        $this->assertCount(1, $comparison->criteriachanged);

        $audit1 = implementation_audits::create_audit((object)[
            'companyid' => $companyid, 'toolid' => $toolid, 'title' => 'Before publication',
            'audittype' => 'baseline', 'auditdate' => time(),
        ], 2);
        $auditrecord1 = implementation_audits::get_audit($audit1);
        $this->assertSame('v1', $auditrecord1->toolversionlabel);
        $item1 = $DB->get_record('local_kbacorp_impl_item', [
            'auditid' => $audit1, 'criterionid' => $criterion->id,
        ], '*', MUST_EXIST);
        $this->assertSame($oldname, $item1->criterionname);

        $version2 = implementation_audits::publish_version($toolid, 2, 'Criterion wording updated');
        $this->assertSame('v2', $version2->versionlabel);
        $this->assertNotSame($version1->contenthash, $version2->contenthash);
        $this->assertCount(2, implementation_audits::get_versions($toolid));
        $this->assertSame(implementation_audits::VERSION_ARCHIVED,
            $DB->get_field('local_kbacorp_bms_toolver', 'status', ['id' => $version1->id]));

        $audit2 = implementation_audits::create_audit((object)[
            'companyid' => $companyid, 'toolid' => $toolid, 'title' => 'After publication',
            'audittype' => 'followup', 'auditdate' => time(),
        ], 2);
        $auditrecord2 = implementation_audits::get_audit($audit2);
        $this->assertSame('v2', $auditrecord2->toolversionlabel);
        $item2 = $DB->get_record('local_kbacorp_impl_item', [
            'auditid' => $audit2, 'criterionid' => $criterion->id,
        ], '*', MUST_EXIST);
        $this->assertSame('Updated criterion', $item2->criterionname);
        $this->assertSame($oldname, $DB->get_field('local_kbacorp_impl_item', 'criterionname', ['id' => $item1->id]));
    }

    public function test_criterion_competencies_are_versioned_and_pinned_to_audit_items(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company();
        $toolid = point_a_template::install($companyid);
        $criteria = implementation_audits::get_criteria($toolid);
        $criterion = reset($criteria);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework();
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'shortname' => 'Strategic thinking',
        ]);

        implementation_audits::save_criterion_competency((object)[
            'companyid' => $companyid,
            'criterionid' => $criterion->id,
            'competencyid' => $competency->get('id'),
            'targetlevel' => 4,
            'priority' => 'critical',
            'required' => 1,
        ]);
        $mappings = implementation_audits::get_criterion_competencies((int)$criterion->id);
        $this->assertCount(1, $mappings);
        $this->assertSame(4, (int)$mappings[0]->targetlevel);

        $version = implementation_audits::publish_version($toolid, 2, 'Competency linked');
        $snapshot = implementation_audits::get_version_snapshot($version);
        $snapshotcriterion = array_values(array_filter($snapshot['criteria'], static function($row) use ($criterion) {
            return (int)$row['id'] === (int)$criterion->id;
        }))[0];
        $this->assertSame('Strategic thinking', $snapshotcriterion['competencies'][0]['shortname']);

        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid, 'toolid' => $toolid, 'title' => 'Competency snapshot',
            'audittype' => 'baseline', 'auditdate' => time(),
        ], 2);
        $item = $DB->get_record('local_kbacorp_impl_item', [
            'auditid' => $auditid, 'criterionid' => $criterion->id,
        ], '*', MUST_EXIST);
        $itemcompetencies = implementation_audits::get_item_competencies($item);
        $this->assertSame(4, (int)$itemcompetencies[0]['targetlevel']);

        $mapping = reset($mappings);
        implementation_audits::remove_criterion_competency((int)$criterion->id, (int)$mapping->id, $companyid);
        $this->assertCount(0, implementation_audits::get_criterion_competencies((int)$criterion->id));
        $this->assertSame(4, (int)implementation_audits::get_item_competencies($item)[0]['targetlevel']);
    }

    public function test_gap_becomes_managed_implementation_task(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company();
        $owner = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)['companyid' => $companyid,
            'userid' => $owner->id, 'membertype' => 'employee', 'isemployee' => 1, 'ismanager' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)['companyid' => $companyid,
            'toolid' => $toolid, 'title' => 'Task test', 'audittype' => 'baseline', 'auditdate' => time()], 2);
        $audititems = implementation_audits::get_items($auditid);
        $item = reset($audititems);
        $DB->set_field('local_kbacorp_impl_item', 'score', 2, ['id' => $item->id]);
        $DB->set_field('local_kbacorp_impl_item', 'evidencestatus', implementation_audits::EVIDENCE_SUBMITTED,
            ['id' => $item->id]);
        $taskid = implementation_audits::save_task((object)['companyid' => $companyid,
            'auditid' => $auditid, 'itemid' => $item->id, 'routeid' => 0, 'title' => 'Implement criterion',
            'ownerid' => $owner->id, 'priority' => 'critical', 'status' => 'planned',
            'expectedresult' => 'Reach level 7', 'completioncomment' => '', 'startdate' => time(),
            'duedate' => time() + WEEKSECS], 2);
        $task = $DB->get_record('local_kbacorp_impl_task', ['id' => $taskid], '*', MUST_EXIST);
        $this->assertSame(5, (int)$task->gapvalue);
        $this->assertSame((int)$owner->id, (int)$task->ownerid);
        $this->assertSame('planned', $task->status);

        $task = implementation_task_discussion::get_task($taskid);
        $checkid = implementation_task_checklist::add($task, (int)$owner->id, 'Collect evidence');
        $progress = implementation_task_checklist::progress($taskid);
        $this->assertSame(1, $progress->total);
        $this->assertSame(0, $progress->done);
        implementation_task_checklist::toggle($task, $checkid, (int)$owner->id);
        $progress = implementation_task_checklist::progress($taskid);
        $this->assertSame(1, $progress->done);
        $this->assertSame(100, $progress->percent);
        implementation_task_checklist::delete($task, $checkid, (int)$owner->id);
        $this->assertSame(0, implementation_task_checklist::progress($taskid)->total);
    }


    public function test_implementation_task_soft_delete_hides_record_and_frees_active_slot(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $adminid = (int)get_admin()->id;
        $companyid = $this->create_company();
        $owner = $this->getDataGenerator()->create_user();
        $DB->insert_record('local_kbacorp_member', (object)[
            'companyid' => $companyid,
            'userid' => $owner->id,
            'membertype' => 'employee',
            'isemployee' => 1,
            'ismanager' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid,
            'toolid' => $toolid,
            'title' => 'Delete task test',
            'audittype' => 'baseline',
            'auditdate' => time(),
        ], $adminid);
        $audititems = implementation_audits::get_items($auditid);
        $item = reset($audititems);
        $taskdata = (object)[
            'companyid' => $companyid,
            'auditid' => $auditid,
            'itemid' => $item->id,
            'routeid' => 0,
            'title' => 'Temporary implementation task',
            'ownerid' => $owner->id,
            'priority' => 'core',
            'status' => 'completed',
            'expectedresult' => 'Expected result',
            'completioncomment' => 'Completed result',
            'startdate' => time() - DAYSECS,
            'duedate' => time(),
        ];
        $taskid = implementation_audits::save_task($taskdata, $adminid);
        $task = implementation_task_discussion::get_task($taskid);
        implementation_task_discussion::add_comment($task, $adminid, 'Audit trail message');
        implementation_task_lifecycle::delete_task($task, $adminid);

        $deleted = $DB->get_record('local_kbacorp_impl_task', ['id' => $taskid], '*', MUST_EXIST);
        $this->assertSame(1, (int)$deleted->deleted);
        $this->assertSame($taskid, (int)$deleted->deletionkey);
        $this->assertGreaterThan(0, (int)$deleted->deletedat);
        $this->assertCount(0, implementation_audits::get_tasks($companyid, ['auditid' => $auditid]));
        $this->assertTrue($DB->record_exists('local_kbacorp_impl_comment', [
            'taskid' => $taskid,
            'message' => 'Audit trail message',
        ]));
        $history = implementation_task_lifecycle::get_history($taskid);
        $this->assertSame('delete', (string)$history[0]->eventtype);

        $replacementid = implementation_audits::save_task($taskdata, $adminid);
        $this->assertNotSame($taskid, $replacementid);
        $this->assertSame(0, (int)$DB->get_field('local_kbacorp_impl_task', 'deleted',
            ['id' => $replacementid]));
        $this->assertCount(1, implementation_audits::get_tasks($companyid, ['auditid' => $auditid]));
    }

    public function test_point_a_index_gives_every_assessed_area_equal_weight(): void {
        global $DB;
        $this->resetAfterTest();
        $companyid = $this->create_company();
        $toolid = point_a_template::install($companyid);
        $auditid = implementation_audits::create_audit((object)[
            'companyid' => $companyid,
            'toolid' => $toolid,
            'title' => 'Point A test',
            'audittype' => 'baseline',
            'auditdate' => time(),
        ], 2);

        foreach (implementation_audits::get_items($auditid) as $item) {
            if ($item->sectioncode === '0') {
                $score = 0;
                $status = implementation_audits::EVIDENCE_SUBMITTED;
            } else if ($item->sectioncode === '1') {
                $score = 9;
                $status = implementation_audits::EVIDENCE_SUBMITTED;
            } else {
                $score = -1;
                $status = implementation_audits::EVIDENCE_NOT_APPLICABLE;
            }
            $DB->set_field('local_kbacorp_impl_item', 'score', $score, ['id' => $item->id]);
            $DB->set_field('local_kbacorp_impl_item', 'evidencestatus', $status, ['id' => $item->id]);
        }

        $metrics = implementation_audits::calculate_metrics($auditid);
        $this->assertSame(50.0, $metrics->indexvalue);
        $this->assertSame(100.0, $metrics->coverage);
        implementation_audits::confirm_audit($auditid, 2);
        $this->assertSame(implementation_audits::STATUS_CONFIRMED,
            $DB->get_field('local_kbacorp_impl_audit', 'status', ['id' => $auditid]));
    }

    public function test_economic_snapshot_calculates_company_performance_ratios(): void {
        $record = (object)[
            'revenue' => 1000000.0, 'grossprofit' => 400000.0,
            'operatingprofit' => 200000.0, 'netprofit' => 120000.0,
            'profittax' => 30000.0, 'vat' => 50000.0, 'socialfunds' => 20000.0,
            'othertaxes' => 0.0, 'payroll' => 250000.0, 'employees' => 10,
        ];
        $metrics = implementation_audits::calculate_economic_metrics($record);
        $this->assertSame(40.0, $metrics->grossmargin);
        $this->assertSame(20.0, $metrics->operatingmargin);
        $this->assertSame(12.0, $metrics->netmargin);
        $this->assertSame(100000.0, $metrics->totaltaxes);
        $this->assertSame(10.0, $metrics->taxburden);
        $this->assertSame(25.0, $metrics->payrollshare);
        $this->assertSame(100000.0, $metrics->revenueperemployee);
        $this->assertSame(20000.0, $metrics->operatingprofitperemployee);
    }

    public function test_decimal_parser_accepts_russian_input_and_negative_profit(): void {
        $this->assertSame(1250000.5, implementation_audits::parse_decimal('1 250 000,50'));
        $this->assertSame(-15000.0, implementation_audits::parse_decimal('-15000'));
        $this->assertNull(implementation_audits::parse_decimal('not a number'));
    }

    private function create_company(): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('local_kbacorp_company', (object)[
            'name' => 'Point A Company',
            'active' => 1,
            'trainingminutesday' => 90,
            'trainingweekdays' => '1,2,3,4,5',
            'alertsenabled' => 0,
            'alertfrequency' => 'weekly',
            'lastalertsent' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
