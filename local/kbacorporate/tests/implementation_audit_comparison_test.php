<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\economic_indicators;
use local_kbacorporate\local\implementation_audit_comparison;
use local_kbacorporate\local\implementation_audits;

/** Regression tests for confirmed BMS audit comparison. */
final class implementation_audit_comparison_test extends \advanced_testcase {
    public function test_aggregate_separates_change_risk_actions_and_evidence(): void {
        $beforedate = make_timestamp(2035, 1, 31);
        $afterdate = make_timestamp(2035, 4, 30);
        $beforeaudit = $this->audit($beforedate, 9, 3, 42.0, 100.0);
        $afteraudit = $this->audit($afterdate, 9, 3, 55.0, 100.0);

        $beforeitems = [
            $this->item('A.1', 'A', 2, 7),
            $this->item('A.2', 'A', 4, 7),
            $this->item('B.1', 'B', 7, 7),
            $this->item('B.2', 'B', -1, 7, implementation_audits::EVIDENCE_MISSING),
            $this->item('C.1', 'C', 1, 7),
        ];
        $afteritems = [
            $this->item('A.1', 'A', 5, 7),
            $this->item('A.2', 'A', 2, 7),
            $this->item('B.1', 'B', 7, 7),
            $this->item('B.2', 'B', 4, 7),
            $this->item('D.1', 'D', 3, 7),
        ];
        $tasks = [
            $this->task(10, 'A.1', implementation_audits::TASK_COMPLETED,
                $beforedate + 10 * DAYSECS, $beforedate + 40 * DAYSECS),
            $this->task(11, 'A.2', implementation_audits::TASK_IN_PROGRESS,
                $beforedate - DAYSECS, 0),
            $this->task(12, 'C.1', implementation_audits::TASK_CANCELLED,
                $beforedate + DAYSECS, 0),
        ];
        $evidence = [
            (object)['id' => 100, 'taskid' => 10, 'status' => 'accepted'],
        ];

        $model = implementation_audit_comparison::aggregate_records(
            $beforeaudit,
            $afteraudit,
            $beforeitems,
            $afteritems,
            $tasks,
            $evidence
        );

        $this->assertSame(6, $model->summary->totalcriteria);
        $this->assertSame(4, $model->summary->matchedcriteria);
        $this->assertSame(3, $model->summary->comparablecriteria);
        $this->assertSame(1, $model->summary->improved);
        $this->assertSame(1, $model->summary->worsened);
        $this->assertSame(1, $model->summary->unchanged);
        $this->assertSame(1, $model->summary->newlyassessed);
        $this->assertSame(1, $model->summary->added);
        $this->assertSame(1, $model->summary->removed);
        $this->assertSame(1, $model->summary->resolvedrisks);
        $this->assertSame(1, $model->summary->newrisks);
        $this->assertSame(0, $model->summary->persistentrisks);
        $this->assertSame(2, $model->summary->actions);
        $this->assertSame(1, $model->summary->actionscancelled);
        $this->assertSame(1, $model->summary->actionscreatedbetween);
        $this->assertSame(1, $model->summary->actionscompletedbetween);
        $this->assertSame(1, $model->summary->actionsopen);
        $this->assertSame(1, $model->summary->acceptedevidence);
        $this->assertEquals(13.0, $model->indexdelta);

        $rows = [];
        foreach ($model->criteria as $row) {
            $rows[$row->criterioncode] = $row;
        }
        $this->assertSame(implementation_audit_comparison::STATUS_IMPROVED, $rows['A.1']->status);
        $this->assertSame(implementation_audit_comparison::RISK_RESOLVED, $rows['A.1']->riskstatus);
        $this->assertSame(-3, $rows['A.1']->gapchange);
        $this->assertSame(1, $rows['A.1']->acceptedevidence);
        $this->assertSame(implementation_audit_comparison::STATUS_WORSENED, $rows['A.2']->status);
        $this->assertSame(implementation_audit_comparison::RISK_NEW, $rows['A.2']->riskstatus);
        $this->assertSame(implementation_audit_comparison::STATUS_NEWLY_ASSESSED, $rows['B.2']->status);
        $this->assertSame(implementation_audit_comparison::STATUS_REMOVED, $rows['C.1']->status);
        $this->assertSame(implementation_audit_comparison::RISK_UNKNOWN, $rows['C.1']->riskstatus);
        $this->assertSame(implementation_audit_comparison::STATUS_ADDED, $rows['D.1']->status);
    }

    public function test_different_scales_are_compared_by_normalised_percentage(): void {
        $beforeaudit = $this->audit(100, 5, 2, 40.0, 100.0);
        $afteraudit = $this->audit(200, 10, 4, 40.0, 100.0);
        $before = [$this->item('A.1', 'A', 2, 4)];
        $after = [$this->item('A.1', 'A', 4, 8)];

        $model = implementation_audit_comparison::aggregate_records($beforeaudit, $afteraudit, $before, $after);
        $row = $model->criteria[0];

        $this->assertEquals(40.0, $row->beforenormalized);
        $this->assertEquals(40.0, $row->afternormalized);
        $this->assertEquals(0.0, $row->normalizeddelta);
        $this->assertNull($row->rawdelta);
        $this->assertSame(implementation_audit_comparison::STATUS_UNCHANGED, $row->status);
    }

    public function test_economic_comparison_respects_indicator_direction_and_currency(): void {
        $before = [
            $this->economic(1, 'REV', 100, economic_indicators::TYPE_MONEY,
                economic_indicators::DIRECTION_UP, 'RUB'),
            $this->economic(2, 'DEBT', 100, economic_indicators::TYPE_MONEY,
                economic_indicators::DIRECTION_DOWN, 'RUB'),
            $this->economic(3, 'TURNOVER', 10, economic_indicators::TYPE_PERCENT,
                economic_indicators::DIRECTION_DOWN, 'RUB'),
            $this->economic(4, 'CASH', 100, economic_indicators::TYPE_MONEY,
                economic_indicators::DIRECTION_UP, 'RUB'),
        ];
        $after = [
            $this->economic(11, 'REV', 120, economic_indicators::TYPE_MONEY,
                economic_indicators::DIRECTION_UP, 'RUB'),
            $this->economic(12, 'DEBT', 80, economic_indicators::TYPE_MONEY,
                economic_indicators::DIRECTION_DOWN, 'RUB'),
            $this->economic(13, 'TURNOVER', 12, economic_indicators::TYPE_PERCENT,
                economic_indicators::DIRECTION_DOWN, 'RUB'),
            $this->economic(14, 'CASH', 110, economic_indicators::TYPE_MONEY,
                economic_indicators::DIRECTION_UP, 'USD'),
        ];

        $result = implementation_audit_comparison::compare_economic_records($before, $after);
        $rows = [];
        foreach ($result->rows as $row) {
            $rows[$row->indicatorcode] = $row;
        }

        $this->assertSame('positive', $rows['REV']->outcome);
        $this->assertSame('positive', $rows['DEBT']->outcome);
        $this->assertSame('negative', $rows['TURNOVER']->outcome);
        $this->assertSame('incomparable', $rows['CASH']->outcome);
        $this->assertSame(3, $result->summary->comparable);
        $this->assertSame(2, $result->summary->positive);
        $this->assertSame(1, $result->summary->negative);
        $this->assertSame(1, $result->summary->incomparable);
    }

    public function test_filter_criteria_combines_status_risk_section_and_query(): void {
        $criteria = [
            (object)['status' => 'improved', 'riskstatus' => 'resolved', 'sectioncode' => 'A',
                'criterioncode' => 'A.1', 'criterionname' => 'Planning', 'sectionname' => 'Strategy'],
            (object)['status' => 'worsened', 'riskstatus' => 'new', 'sectioncode' => 'B',
                'criterioncode' => 'B.1', 'criterionname' => 'Cash flow', 'sectionname' => 'Finance'],
        ];
        $filtered = implementation_audit_comparison::filter_criteria($criteria, [
            'status' => 'improved',
            'risk' => 'resolved',
            'section' => 'A',
            'query' => 'plan',
        ]);

        $this->assertCount(1, $filtered);
        $this->assertSame('A.1', $filtered[0]->criterioncode);
    }

    private function audit(int $date, int $scalemax, int $threshold, float $index,
            float $coverage): \stdClass {
        return (object)[
            'auditdate' => $date,
            'scalemax' => $scalemax,
            'attentionthreshold' => $threshold,
            'indexmethod' => implementation_audits::INDEX_AREA_AVERAGE,
            'indexvalue' => $index,
            'coverage' => $coverage,
        ];
    }

    private function item(string $code, string $section, int $score, int $target,
            string $evidence = implementation_audits::EVIDENCE_SUBMITTED): \stdClass {
        return (object)[
            'criterioncode' => $code,
            'criterionname' => 'Criterion ' . $code,
            'sectioncode' => $section,
            'sectionname' => 'Section ' . $section,
            'score' => $score,
            'targetscore' => $target,
            'evidencestatus' => $evidence,
        ];
    }

    private function task(int $id, string $code, string $status, int $created,
            int $completed): \stdClass {
        return (object)[
            'id' => $id,
            'criterioncode' => $code,
            'criterionname' => 'Criterion ' . $code,
            'status' => $status,
            'timecreated' => $created,
            'completedat' => $completed,
        ];
    }

    private function economic(int $id, string $code, float $value, string $type,
            string $direction, string $currency): \stdClass {
        return (object)[
            'id' => $id,
            'indicatorcode' => $code,
            'indicatorname' => $code,
            'category' => 'finance',
            'valuetype' => $type,
            'unit' => $type === economic_indicators::TYPE_MONEY ? 'currency' : '%',
            'direction' => $direction,
            'currency' => $currency,
            'value' => $value,
            'periodend' => $id,
        ];
    }
}
