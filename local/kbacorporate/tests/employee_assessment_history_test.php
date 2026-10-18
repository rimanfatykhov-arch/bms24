<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\employee_assessment_history;
use local_kbacorporate\local\employee_competency_profile;

/** Regression guards for employee assessment history and dynamics. */
final class employee_assessment_history_test extends \advanced_testcase {
    /** Prepare a normalised test event. */
    private function event(array $values): \stdClass {
        $defaults = [
            'id' => 'event-' . uniqid('', true),
            'competencyid' => 1,
            'kind' => 'assessment',
            'source' => employee_competency_profile::SOURCE_AUDIT,
            'title' => 'Assessment',
            'details' => '',
            'level' => 1,
            'status' => 'confirmed',
            'timecreated' => 1000,
            'actorid' => 1,
            'actorname' => 'Assessor One',
            'competencyshortname' => 'Strategic thinking',
            'competencyidnumber' => 'BMS-C04-01',
            'frameworkname' => 'BMS Competency Framework',
            'targeted' => true,
            'targetlevel' => 4,
            'currentlevel' => 2,
            'gap' => 2,
            'required' => true,
            'priority' => 'critical',
            'roles' => [],
            'scaleid' => 0,
            'trend' => employee_assessment_history::TREND_NO_COMPARISON,
        ];
        return (object)array_merge($defaults, $values);
    }

    public function test_deduplication_prefers_explicit_assessment_over_synthetic_current_grade(): void {
        $events = [
            $this->event([
                'id' => 'moodle-current-10',
                'source' => employee_competency_profile::SOURCE_MOODLE,
                'level' => 3,
                'timecreated' => 1200,
            ]),
            $this->event([
                'id' => 'kba-audit-20',
                'source' => employee_competency_profile::SOURCE_AUDIT,
                'level' => 3,
                'timecreated' => 1100,
            ]),
            $this->event([
                'id' => 'moodle-current-11',
                'competencyid' => 2,
                'level' => 2,
                'timecreated' => 1200,
            ]),
        ];

        $result = employee_assessment_history::deduplicate_assessments($events);
        $this->assertCount(2, $result);
        $this->assertSame('moodle-current-11', $result[0]->id);
        $this->assertSame('kba-audit-20', $result[1]->id);
    }

    public function test_filter_honours_scope_source_status_period_trend_and_query(): void {
        $events = [
            $this->event([
                'id' => 'audit-1',
                'source' => employee_competency_profile::SOURCE_AUDIT,
                'status' => 'confirmed',
                'timecreated' => 1000,
                'trend' => employee_assessment_history::TREND_IMPROVED,
            ]),
            $this->event([
                'id' => 'ai-1',
                'competencyid' => 2,
                'competencyshortname' => 'Mission',
                'competencyidnumber' => 'BMS-C01-01',
                'source' => employee_competency_profile::SOURCE_AI,
                'status' => 'pending',
                'timecreated' => 2000,
                'actorname' => 'AI reviewer',
                'trend' => employee_assessment_history::TREND_NO_COMPARISON,
            ]),
            $this->event([
                'id' => 'moodle-1',
                'competencyid' => 3,
                'competencyshortname' => 'External competency',
                'competencyidnumber' => 'EXT-01',
                'source' => employee_competency_profile::SOURCE_MOODLE,
                'status' => 'recorded',
                'timecreated' => 3000,
                'targeted' => false,
                'trend' => employee_assessment_history::TREND_SINGLE,
            ]),
        ];

        $this->assertCount(2, employee_assessment_history::filter($events));
        $this->assertCount(1, employee_assessment_history::filter(
            $events, employee_assessment_history::SCOPE_PROFILE,
            employee_competency_profile::SOURCE_AUDIT, 'confirmed',
            employee_assessment_history::TREND_IMPROVED, 900, 1500, 'strategic'
        ));
        $this->assertCount(1, employee_assessment_history::filter(
            $events, employee_assessment_history::SCOPE_ALL,
            employee_competency_profile::SOURCE_MOODLE, 'recorded',
            employee_assessment_history::TREND_SINGLE, 2500, 3500, 'EXT-01'
        ));
        $this->assertCount(3, $events);
    }

    public function test_trends_ignore_pending_rejected_and_missing_levels(): void {
        $events = [
            $this->event(['id' => 'c1-a', 'competencyid' => 1, 'level' => 1, 'timecreated' => 1000]),
            $this->event(['id' => 'c1-b', 'competencyid' => 1, 'level' => 3, 'timecreated' => 2000]),
            $this->event(['id' => 'c1-pending', 'competencyid' => 1, 'level' => 5,
                'status' => 'pending', 'timecreated' => 3000]),
            $this->event(['id' => 'c2-a', 'competencyid' => 2, 'level' => 2, 'timecreated' => 1000]),
            $this->event(['id' => 'c2-b', 'competencyid' => 2, 'level' => 2,
                'status' => 'recorded', 'timecreated' => 2000]),
            $this->event(['id' => 'c3-a', 'competencyid' => 3, 'level' => 4, 'timecreated' => 1000]),
            $this->event(['id' => 'c3-b', 'competencyid' => 3, 'level' => 2, 'timecreated' => 2000]),
            $this->event(['id' => 'c4-pending', 'competencyid' => 4, 'level' => 3,
                'status' => 'pending', 'timecreated' => 1000]),
        ];

        $trends = employee_assessment_history::build_trends($events);
        $map = [];
        foreach ($trends as $row) {
            $map[$row->competencyid] = $row;
        }

        $this->assertSame(employee_assessment_history::TREND_IMPROVED, $map[1]->trend);
        $this->assertSame(2, $map[1]->delta);
        $this->assertSame(3, $map[1]->latestlevel);
        $this->assertSame(employee_assessment_history::TREND_UNCHANGED, $map[2]->trend);
        $this->assertSame(0, $map[2]->delta);
        $this->assertSame(employee_assessment_history::TREND_DECLINED, $map[3]->trend);
        $this->assertSame(-2, $map[3]->delta);
        $this->assertSame(employee_assessment_history::TREND_NO_COMPARISON, $map[4]->trend);
        $this->assertNull($map[4]->delta);
    }

    public function test_summary_counts_events_competencies_assessors_and_trends(): void {
        $events = [
            $this->event(['id' => 'e1', 'competencyid' => 1, 'status' => 'confirmed',
                'actorid' => 10, 'timecreated' => 1000]),
            $this->event(['id' => 'e2', 'competencyid' => 1, 'status' => 'recorded',
                'actorid' => 10, 'timecreated' => 2000]),
            $this->event(['id' => 'e3', 'competencyid' => 2, 'status' => 'pending',
                'actorid' => 0, 'actorname' => 'Reviewer Two', 'timecreated' => 3000]),
            $this->event(['id' => 'e4', 'competencyid' => 3, 'status' => 'rejected',
                'actorid' => 0, 'actorname' => '', 'timecreated' => 4000]),
        ];
        $trends = [
            (object)['trend' => employee_assessment_history::TREND_IMPROVED],
            (object)['trend' => employee_assessment_history::TREND_SINGLE],
            (object)['trend' => employee_assessment_history::TREND_NO_COMPARISON],
        ];

        $summary = employee_assessment_history::summarise($events, $trends);
        $this->assertSame(4, $summary->events);
        $this->assertSame(3, $summary->competencies);
        $this->assertSame(1, $summary->confirmed);
        $this->assertSame(1, $summary->recorded);
        $this->assertSame(1, $summary->pending);
        $this->assertSame(1, $summary->rejected);
        $this->assertSame(2, $summary->assessors);
        $this->assertSame(1000, $summary->firsttime);
        $this->assertSame(4000, $summary->latesttime);
        $this->assertSame(1, $summary->improved);
        $this->assertSame(1, $summary->single);
        $this->assertSame(1, $summary->nocomparison);
    }

    public function test_date_parser_accepts_valid_date_and_rejects_invalid_values(): void {
        $this->assertGreaterThan(0, employee_assessment_history::parse_date('2026-09-08'));
        $this->assertGreaterThan(
            employee_assessment_history::parse_date('2026-09-08'),
            employee_assessment_history::parse_date('2026-09-08', true)
        );
        $this->assertSame(0, employee_assessment_history::parse_date('2026-02-31'));
        $this->assertSame(0, employee_assessment_history::parse_date('not-a-date'));
    }
}
