<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\development_plans;
use local_kbacorporate\local\study_plan_progress;

/** Regression guards for study-plan progress summaries and filters. */
final class study_plan_progress_test extends \advanced_testcase {
    private function row(array $values): \stdClass {
        $defaults = [
            'itemstatus' => development_plans::ITEM_PLANNED,
            'deadline' => 'ontime',
            'courseid' => 0,
            'coursename' => '',
            'course' => (object)[
                'enrolled' => false,
                'status' => 'nocourse',
                'progress' => null,
            ],
            'current' => null,
            'delta' => null,
            'reached' => false,
            'reassessment' => 'notready',
            'needsattention' => false,
            'competencyname' => 'Test competency',
            'idnumber' => 'TEST-01',
        ];
        return (object)array_merge($defaults, $values);
    }

    public function test_summary_keeps_unassessed_separate_from_zero(): void {
        $summary = study_plan_progress::summarise([
            $this->row([]),
            $this->row([
                'itemstatus' => development_plans::ITEM_COMPLETED,
                'courseid' => 5,
                'course' => (object)['enrolled' => true, 'status' => 'completed', 'progress' => 100],
                'current' => 4,
                'delta' => 2,
                'reached' => true,
                'reassessment' => 'closed',
            ]),
        ]);

        $this->assertSame(2, $summary->total);
        $this->assertSame(1, $summary->unassessed);
        $this->assertSame(1, $summary->assessed);
        $this->assertSame(1, $summary->targetsreached);
        $this->assertSame(50, $summary->actionprogress);
        $this->assertSame(50, $summary->targetprogress);
        $this->assertSame(50, $summary->assessmentcoverage);
    }

    public function test_summary_calculates_course_progress_and_attention(): void {
        $summary = study_plan_progress::summarise([
            $this->row([
                'itemstatus' => development_plans::ITEM_INPROGRESS,
                'deadline' => 'overdue',
                'courseid' => 5,
                'course' => (object)['enrolled' => true, 'status' => 'inprogress', 'progress' => 40],
                'current' => 2,
                'delta' => 1,
                'reassessment' => 'aftercourse',
                'needsattention' => true,
            ]),
            $this->row([
                'itemstatus' => development_plans::ITEM_COMPLETED,
                'deadline' => 'completed',
                'courseid' => 6,
                'course' => (object)['enrolled' => true, 'status' => 'completed', 'progress' => 100],
                'current' => 3,
                'delta' => 1,
                'reassessment' => 'required',
                'needsattention' => true,
            ]),
        ]);

        $this->assertSame(70.0, $summary->averagecourseprogress);
        $this->assertSame(1, $summary->coursescompleted);
        $this->assertSame(1, $summary->overdue);
        $this->assertSame(1, $summary->needsreassessment);
        $this->assertSame(2, $summary->attention);
        $this->assertSame(2, $summary->improved);
    }

    public function test_filters_are_non_destructive_and_searchable(): void {
        $rows = [
            $this->row(['deadline' => 'overdue', 'needsattention' => true,
                'competencyname' => 'Strategic thinking']),
            $this->row(['itemstatus' => development_plans::ITEM_COMPLETED,
                'competencyname' => 'Financial planning']),
            $this->row(['reassessment' => 'required', 'needsattention' => true,
                'competencyname' => 'Mission']),
        ];

        $this->assertCount(1, study_plan_progress::filter($rows,
            study_plan_progress::FILTER_OVERDUE));
        $this->assertCount(2, study_plan_progress::filter($rows,
            study_plan_progress::FILTER_ATTENTION));
        $this->assertCount(1, study_plan_progress::filter($rows,
            study_plan_progress::FILTER_REASSESSMENT));
        $this->assertCount(1, study_plan_progress::filter($rows,
            study_plan_progress::FILTER_ALL, 'financial'));
        $this->assertCount(3, $rows);
    }
}
