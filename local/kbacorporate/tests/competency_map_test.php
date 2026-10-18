<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

/** Unit tests for company competency-map filtering and counters. */
class competency_map_test extends \advanced_testcase {
    public function test_filter_preserves_not_assessed_as_distinct_from_zero(): void {
        $rows = [
            (object)['userid' => 1, 'employee' => 'A', 'departments' => ['Sales'], 'roleids' => [10],
                'roles' => ['Owner'], 'competency' => 'Planning', 'framework' => 'BMS', 'frameworkid' => 5,
                'currentlevel' => null, 'targetlevel' => 2, 'gap' => null, 'attention' => true],
            (object)['userid' => 2, 'employee' => 'B', 'departments' => ['Sales'], 'roleids' => [10],
                'roles' => ['Owner'], 'competency' => 'Planning', 'framework' => 'BMS', 'frameworkid' => 5,
                'currentlevel' => 0, 'targetlevel' => 2, 'gap' => 2, 'attention' => true],
        ];
        $filtered = \local_kbacorporate\local\competency_map::filter($rows, 0, 0, 'notassessed', '');
        $this->assertCount(1, $filtered);
        $this->assertSame(1, $filtered[0]->userid);

        $confirmedgaps = \local_kbacorporate\local\competency_map::filter($rows, 0, 0, 'gap', '');
        $this->assertCount(1, $confirmedgaps);
        $this->assertSame(2, $confirmedgaps[0]->userid);

        $attention = \local_kbacorporate\local\competency_map::filter($rows, 0, 0, 'attention', '');
        $this->assertCount(2, $attention);
    }

    public function test_summary_counts_requirements_and_unique_employees(): void {
        $rows = [
            (object)['userid' => 1, 'currentlevel' => null, 'gap' => null, 'attention' => true],
            (object)['userid' => 1, 'currentlevel' => 1, 'gap' => 2, 'attention' => false],
            (object)['userid' => 2, 'currentlevel' => 3, 'gap' => 0, 'attention' => false],
        ];
        $summary = \local_kbacorporate\local\competency_map::summary($rows);
        $this->assertSame(2, $summary->employees);
        $this->assertSame(3, $summary->requirements);
        $this->assertSame(1, $summary->notassessed);
        $this->assertSame(1, $summary->gaps);
        $this->assertSame(1, $summary->reached);
        $this->assertSame(67, $summary->assessmentcoverage);
        $this->assertSame(33, $summary->targetreadiness);
    }

    public function test_csv_value_prevents_formula_injection(): void {
        $this->assertSame("'=2+2", \local_kbacorporate\local\competency_map::csv_value('=2+2'));
        $this->assertSame("'  @SUM(A1:A2)", \local_kbacorporate\local\competency_map::csv_value('  @SUM(A1:A2)'));
        $this->assertSame('Planning', \local_kbacorporate\local\competency_map::csv_value('Planning'));
    }
}
