<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_audits;
use local_kbacorporate\local\implementation_evidence;
use local_kbacorporate\local\implementation_section_progress;

/** Regression tests for the BMS section implementation-progress projection. */
final class implementation_section_progress_test extends \advanced_testcase {
    public function test_aggregate_separates_maturity_actions_evidence_and_risk(): void {
        $today = usergetmidnight(time());
        $audit = (object)[
            'attentionthreshold' => 3,
            'scalemax' => 9,
        ];
        $items = [
            $this->item(1, 'A', 'Section A', 2, 7, implementation_audits::EVIDENCE_SUBMITTED),
            $this->item(2, 'A', 'Section A', 7, 7, implementation_audits::EVIDENCE_SUBMITTED),
            $this->item(3, 'A', 'Section A', -1, 7, implementation_audits::EVIDENCE_MISSING),
            $this->item(4, 'B', 'Section B', 4, 7, implementation_audits::EVIDENCE_SUBMITTED),
            $this->item(5, 'B', 'Section B', -1, 7, implementation_audits::EVIDENCE_NOT_APPLICABLE),
        ];
        $tasks = [
            $this->task(10, 1, 'planned', 0, $today - DAYSECS),
            $this->task(11, 4, 'completed', 2, $today - DAYSECS),
        ];
        $evidence = [
            10 => (object)['status' => implementation_evidence::STATUS_SUBMITTED],
            11 => (object)['status' => implementation_evidence::STATUS_ACCEPTED],
        ];

        $model = implementation_section_progress::aggregate_records(
            $audit,
            $items,
            $tasks,
            $evidence,
            ['A' => 1.5, 'B' => 3.0]
        );

        $this->assertCount(2, $model->sections);
        $a = $model->sections[0];
        $b = $model->sections[1];

        $this->assertSame('A', $a->code);
        $this->assertSame(3, $a->totalcriteria);
        $this->assertSame(2, $a->assessedcriteria);
        $this->assertSame(1, $a->unassessedcriteria);
        $this->assertSame(1, $a->gapcriteria);
        $this->assertSame(1, $a->achievedcriteria);
        $this->assertSame(1, $a->attentioncriteria);
        $this->assertSame(4.5, $a->currentaverage);
        $this->assertSame(3.0, $a->scorechange);
        $this->assertSame(1, $a->coveredcriteria);
        $this->assertSame(100.0, $a->actioncoverage);
        $this->assertSame(1, $a->overduetasks);
        $this->assertSame(1, $a->unassignedtasks);
        $this->assertSame(0.0, $a->workflowprogress);
        $this->assertSame('risk', $a->status);

        $this->assertSame('B', $b->code);
        $this->assertSame(1, $b->totalcriteria);
        $this->assertSame(1, $b->notapplicable);
        $this->assertSame(1, $b->gapcriteria);
        $this->assertSame(1, $b->completedcriteria);
        $this->assertSame(1, $b->acceptedcriteria);
        $this->assertSame(100.0, $b->workflowprogress);
        $this->assertSame('complete', $b->status);

        $this->assertSame(2, $model->summary->sectioncount);
        $this->assertSame(2, $model->summary->sectionswithgaps);
        $this->assertSame(1, $model->summary->sectionscomplete);
        $this->assertSame(1, $model->summary->sectionsrisk);
        $this->assertSame(2, $model->summary->gapcriteria);
        $this->assertSame(2, $model->summary->coveredcriteria);
        $this->assertSame(1, $model->summary->completedcriteria);
        $this->assertSame(1, $model->summary->acceptedcriteria);
        $this->assertSame(100.0, $model->summary->actioncoverage);
        $this->assertSame(50.0, $model->summary->workflowprogress);
        $this->assertSame(1, $model->summary->overduetasks);
    }

    public function test_cancelled_action_does_not_cover_a_gap(): void {
        $audit = (object)['attentionthreshold' => 3, 'scalemax' => 9];
        $items = [
            $this->item(1, 'A', 'Section A', 2, 7, implementation_audits::EVIDENCE_SUBMITTED),
        ];
        $tasks = [$this->task(10, 1, 'cancelled', 2, 0)];

        $model = implementation_section_progress::aggregate_records($audit, $items, $tasks);
        $section = $model->sections[0];

        $this->assertSame(0, $section->coveredcriteria);
        $this->assertSame(0, $section->tasktotal);
        $this->assertSame(1, $section->cancelledtasks);
        $this->assertSame(0.0, $section->actioncoverage);
        $this->assertSame('noactions', $section->status);
    }

    public function test_unassessed_section_is_not_reported_as_achieved(): void {
        $audit = (object)['attentionthreshold' => 3, 'scalemax' => 9];
        $items = [
            $this->item(1, 'A', 'Section A', -1, 7, implementation_audits::EVIDENCE_MISSING),
        ];

        $model = implementation_section_progress::aggregate_records($audit, $items, []);
        $section = $model->sections[0];

        $this->assertSame('unassessed', $section->status);
        $this->assertSame(0, $model->summary->sectionscomplete);
    }

    private function item(int $id, string $sectioncode, string $sectionname, int $score,
            int $targetscore, string $evidencestatus): \stdClass {
        return (object)[
            'id' => $id,
            'criterionid' => $id,
            'criterioncode' => $sectioncode . '.' . $id,
            'criterionname' => 'Criterion ' . $id,
            'sectioncode' => $sectioncode,
            'sectionname' => $sectionname,
            'score' => $score,
            'targetscore' => $targetscore,
            'evidencestatus' => $evidencestatus,
        ];
    }

    private function task(int $id, int $itemid, string $status, int $ownerid,
            int $duedate): \stdClass {
        return (object)[
            'id' => $id,
            'itemid' => $itemid,
            'status' => $status,
            'ownerid' => $ownerid,
            'duedate' => $duedate,
            'title' => 'Task ' . $id,
        ];
    }
}
