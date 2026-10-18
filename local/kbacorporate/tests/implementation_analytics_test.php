<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\implementation_analytics;

/** Tests for implementation effectiveness calculations. */
final class implementation_analytics_test extends \advanced_testcase {
    public function test_summary_calculates_delivery_cycle_overdue_and_checklists(): void {
        $today = usergetmidnight(time());
        $tasks = [
            (object)['status' => 'completed', 'startdate' => $today - (4 * DAYSECS),
                'timecreated' => $today - (5 * DAYSECS), 'completedat' => $today - (2 * DAYSECS),
                'duedate' => $today - DAYSECS, 'checklisttotal' => 2, 'checklistdone' => 2],
            (object)['status' => 'review', 'startdate' => $today - DAYSECS,
                'timecreated' => $today - (2 * DAYSECS), 'completedat' => 0,
                'duedate' => $today + DAYSECS, 'checklisttotal' => 2, 'checklistdone' => 1],
            (object)['status' => 'inprogress', 'startdate' => $today - (3 * DAYSECS),
                'timecreated' => $today - (3 * DAYSECS), 'completedat' => 0,
                'duedate' => $today - DAYSECS, 'checklisttotal' => 0, 'checklistdone' => 0],
            (object)['status' => 'cancelled', 'startdate' => 0,
                'timecreated' => $today - DAYSECS, 'completedat' => 0,
                'duedate' => $today - DAYSECS, 'checklisttotal' => 0, 'checklistdone' => 0],
        ];
        $summary = implementation_analytics::summarize($tasks);
        $this->assertSame(4, $summary->total);
        $this->assertSame(1, $summary->active);
        $this->assertSame(1, $summary->review);
        $this->assertSame(1, $summary->completed);
        $this->assertSame(1, $summary->overdue);
        $this->assertSame(25.0, $summary->completionrate);
        $this->assertSame(2.0, $summary->averagecycledays);
        $this->assertSame(75.0, $summary->checklistrate);
    }

    public function test_monthly_trend_counts_created_completed_and_cycle(): void {
        $firstofmonth = strtotime(userdate(time(), '%Y-%m-01 00:00:00'));
        $previousmonth = strtotime('-1 month', $firstofmonth);
        $tasks = [
            (object)['status' => 'completed', 'timecreated' => $previousmonth + DAYSECS,
                'startdate' => $previousmonth + (2 * DAYSECS),
                'completedat' => $previousmonth + (5 * DAYSECS)],
            (object)['status' => 'inprogress', 'timecreated' => $previousmonth + (2 * DAYSECS),
                'startdate' => $previousmonth + (2 * DAYSECS), 'completedat' => 0],
        ];
        $trend = implementation_analytics::monthly_trend($tasks, 2);
        $this->assertCount(2, $trend);
        $this->assertSame(2, $trend[0]->created);
        $this->assertSame(1, $trend[0]->completed);
        $this->assertSame(3.0, $trend[0]->averagecycledays);
    }
}
