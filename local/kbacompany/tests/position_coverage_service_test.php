<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\position_coverage_service as coverage;

/** Coverage calendar and lifecycle regression checks (pure, no generated tasks). */
final class position_coverage_service_test extends \advanced_testcase {
    public function test_calendar_dates_are_strict_and_inclusive(): void {
        [$start, $end] = coverage::valid_range('2026-10-16', '2026-10-23');
        $this->assertSame(7 * 86400, $end - $start);
        $this->assertSame('2026-10-16', gmdate('Y-m-d', $start));
        $this->expectException(\moodle_exception::class);
        coverage::valid_range('2026-02-30', '2026-03-01');
    }

    public function test_reversed_dates_are_rejected(): void {
        $this->expectException(\moodle_exception::class);
        coverage::valid_range('2026-10-23', '2026-10-16');
    }

    public function test_period_over_one_year_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        coverage::valid_range('2026-01-01', '2027-12-31');
    }

    public function test_approved_state_is_derived_without_mutating_a_row(): void {
        [$start, $end] = coverage::valid_range('2026-10-16', '2026-10-23');
        $row = (object)['status' => 'approved', 'startdate' => $start, 'enddate' => $end];
        $this->assertSame('scheduled', coverage::effective_state($row, $start - 86400));
        $this->assertSame('effective', coverage::effective_state($row, $start));
        $this->assertSame('effective', coverage::effective_state($row, $end));
        $this->assertSame('expired', coverage::effective_state($row, $end + 86400));
        $this->assertSame('approved', $row->status);
        $row->status = 'cancelled';
        $this->assertSame('cancelled', coverage::effective_state($row, $start));
    }
}
