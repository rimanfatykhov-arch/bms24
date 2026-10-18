<?php
// This file is part of Moodle - http://moodle.org/

namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

/** Tests for read-only management dashboard metric contracts. */
class management_dashboards_test extends \advanced_testcase {
    public function test_task_summary_excludes_cancelled_and_counts_overdue(): void {
        $now = 2000;
        $tasks = [
            (object)['status' => 'planned', 'duedate' => 1000],
            (object)['status' => 'inprogress', 'duedate' => 3000],
            (object)['status' => 'review', 'duedate' => 0],
            (object)['status' => 'completed', 'duedate' => 500],
            (object)['status' => 'cancelled', 'duedate' => 500],
        ];

        $summary = \local_kbacorporate\local\management_dashboards::summarize_task_records($tasks, $now);

        $this->assertSame(4, $summary->total);
        $this->assertSame(1, $summary->completed);
        $this->assertSame(3, $summary->active);
        $this->assertSame(1, $summary->overdue);
        $this->assertSame(53, $summary->progress);
    }

    public function test_gap_summary_keeps_unknown_target_separate(): void {
        $items = [
            (object)['proposedlevel' => 0, 'targetlevel' => null],
            (object)['proposedlevel' => 1, 'targetlevel' => 3],
            (object)['proposedlevel' => 2, 'targetlevel' => 3],
            (object)['proposedlevel' => 3, 'targetlevel' => 3],
        ];

        $summary = \local_kbacorporate\local\management_dashboards::summarize_gap_records($items);

        $this->assertSame(1, $summary->unknown);
        $this->assertSame(3, $summary->assessed);
        $this->assertSame(2, $summary->gaps);
        $this->assertSame(1, $summary->critical);
    }

    public function test_index_delta_is_unknown_when_a_value_is_missing(): void {
        $latest = (object)['indexvalue' => null];
        $previous = (object)['indexvalue' => 45.5];
        $this->assertNull(\local_kbacorporate\local\management_dashboards::index_delta($latest, $previous));

        $latest->indexvalue = 53.25;
        $this->assertSame(7.75,
            \local_kbacorporate\local\management_dashboards::index_delta($latest, $previous));
    }
}
