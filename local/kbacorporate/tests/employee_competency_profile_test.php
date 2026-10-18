<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\employee_competency_profile;

/** Regression guards for the unified employee competency profile. */
final class employee_competency_profile_test extends \advanced_testcase {
    private function row(array $values): \stdClass {
        $defaults = [
            'targeted' => true,
            'required' => true,
            'status' => employee_competency_profile::STATUS_UNASSESSED,
            'priority' => 'core',
            'coursecovered' => false,
            'historycount' => 0,
            'assessment_source' => employee_competency_profile::SOURCE_NONE,
            'shortname' => 'Test competency',
            'idnumber' => 'TEST-01',
            'frameworkname' => 'Test framework',
            'roles' => [],
        ];
        return (object)array_merge($defaults, $values);
    }

    public function test_summary_separates_profile_assessment_and_history(): void {
        $summary = employee_competency_profile::summarise([
            $this->row([
                'status' => employee_competency_profile::STATUS_GAP,
                'coursecovered' => true,
                'historycount' => 2,
            ]),
            $this->row([
                'required' => false,
                'status' => employee_competency_profile::STATUS_PENDING,
                'historycount' => 1,
            ]),
            $this->row([
                'status' => employee_competency_profile::STATUS_MET,
                'coursecovered' => true,
                'historycount' => 1,
            ]),
            $this->row([
                'targeted' => false,
                'required' => false,
                'status' => employee_competency_profile::STATUS_OUTSIDE,
            ]),
        ]);

        $this->assertSame(4, $summary->total);
        $this->assertSame(3, $summary->profile);
        $this->assertSame(1, $summary->outsideprofile);
        $this->assertSame(2, $summary->mandatory);
        $this->assertSame(1, $summary->supporting);
        $this->assertSame(2, $summary->assessed);
        $this->assertSame(1, $summary->unassessed);
        $this->assertSame(1, $summary->pending);
        $this->assertSame(1, $summary->gaps);
        $this->assertSame(1, $summary->met);
        $this->assertSame(2, $summary->withcourses);
        $this->assertSame(1, $summary->withoutcourses);
        $this->assertSame(3, $summary->withhistory);
        $this->assertSame(4, $summary->historyevents);
        $this->assertSame(67, $summary->assessmentcoverage);
        $this->assertSame(33, $summary->targetreadiness);
    }

    public function test_filters_preserve_source_scope_and_requirement_semantics(): void {
        $rows = [
            $this->row([
                'shortname' => 'Strategic thinking',
                'idnumber' => 'BMS-C04-01',
                'status' => employee_competency_profile::STATUS_GAP,
                'assessment_source' => employee_competency_profile::SOURCE_AUDIT,
            ]),
            $this->row([
                'shortname' => 'Mission',
                'idnumber' => 'BMS-C01-01',
                'required' => false,
                'status' => employee_competency_profile::STATUS_MET,
                'assessment_source' => employee_competency_profile::SOURCE_MOODLE,
            ]),
            $this->row([
                'shortname' => 'Outside knowledge',
                'idnumber' => 'OTHER-01',
                'targeted' => false,
                'required' => false,
                'status' => employee_competency_profile::STATUS_OUTSIDE,
                'assessment_source' => employee_competency_profile::SOURCE_COURSE,
            ]),
        ];

        $this->assertCount(2, employee_competency_profile::filter($rows));
        $this->assertCount(1, employee_competency_profile::filter(
            $rows, employee_competency_profile::SCOPE_PROFILE,
            employee_competency_profile::STATUS_GAP,
            employee_competency_profile::SOURCE_AUDIT,
            'mandatory'
        ));
        $this->assertCount(1, employee_competency_profile::filter(
            $rows, employee_competency_profile::SCOPE_ALL,
            employee_competency_profile::STATUS_OUTSIDE,
            employee_competency_profile::SOURCE_COURSE,
            'outsideprofile'
        ));
        $this->assertCount(1, employee_competency_profile::filter(
            $rows, employee_competency_profile::SCOPE_ALL, 'all', 'all', 'all', 'mission'
        ));
        $this->assertCount(3, $rows);
    }

    public function test_history_deduplication_prefers_local_audit_event(): void {
        $events = [
            (object)[
                'id' => 'audit-1',
                'kind' => 'assessment',
                'source' => employee_competency_profile::SOURCE_AUDIT,
                'level' => 3,
                'timecreated' => 1000,
            ],
            (object)[
                'id' => 'evidence-1',
                'kind' => 'evidence',
                'source' => employee_competency_profile::SOURCE_AUDIT,
                'level' => 3,
                'timecreated' => 1100,
            ],
            (object)[
                'id' => 'course-1',
                'kind' => 'course',
                'source' => employee_competency_profile::SOURCE_COURSE,
                'level' => 2,
                'timecreated' => 900,
            ],
            (object)[
                'id' => 'course-1',
                'kind' => 'course',
                'source' => employee_competency_profile::SOURCE_COURSE,
                'level' => 2,
                'timecreated' => 900,
            ],
        ];

        $result = employee_competency_profile::deduplicate_events($events);
        $this->assertCount(2, $result);
        $this->assertSame('audit-1', $result[0]->id);
        $this->assertSame('course-1', $result[1]->id);
    }
}
