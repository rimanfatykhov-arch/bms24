<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\study_plan_provisioning;

/** Regression guards for explicit KBA -> Moodle study-plan provisioning. */
final class study_plan_provisioning_test extends \advanced_testcase {
    public function test_normalise_selected_ids_removes_invalid_values_and_duplicates(): void {
        $this->assertSame(
            [7, 3, 12],
            study_plan_provisioning::normalise_selected_ids([7, '3', 0, -1, 7, '12', 'bad', 3])
        );
    }

    public function test_compare_competency_sets_reports_exact_match(): void {
        $result = study_plan_provisioning::compare_competency_sets([4, 2, 2, 9], [9, 4, 2]);
        $this->assertTrue($result->ok);
        $this->assertSame(3, $result->expected);
        $this->assertSame(3, $result->actual);
        $this->assertSame(3, $result->matched);
        $this->assertSame([], $result->missing);
        $this->assertSame([], $result->extra);
    }

    public function test_compare_competency_sets_reports_missing_and_extra_ids(): void {
        $result = study_plan_provisioning::compare_competency_sets([2, 4, 8], [2, 6, 8]);
        $this->assertFalse($result->ok);
        $this->assertSame([4], $result->missing);
        $this->assertSame([6], $result->extra);
        $this->assertSame(2, $result->matched);
    }
}
