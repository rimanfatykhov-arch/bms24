<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\recurring_task_library_service;
use local_kbacompany\local\recurring_task_service;

/** Recurring Task scheduler and built-in library regression tests. */
final class recurring_task_service_test extends \advanced_testcase {
    private function row(string $cadence, string $start, string $end = '', int $weekdaymask = 0,
            int $monthweek = 0): \stdClass {
        return (object)['status' => 'active', 'cadence' => $cadence, 'startdate' => $start, 'enddate' => $end,
            'weekdaymask' => $weekdaymask, 'monthweek' => $monthweek];
    }

    public function test_library_snapshot_contains_enriched_source_templates(): void {
        $templates = recurring_task_library_service::templates();
        $this->assertCount(169, $templates);
        $this->assertSame('1c0kbItTDOZOV5PjzQ8anS5Nd9VTsEkNRZPRYRT0kcS4',
            recurring_task_library_service::SOURCE_FILE_ID);
        $this->assertTrue($templates['rtpl_owner_06']['requires_review']);
        $this->assertSame('twiceweekly', $templates['rtpl_sales_03']['cadence']);

        // Existing keys remain stable; appended executive and finance templates use exact workbook schedules.
        $this->assertSame('Проведение утренней координации с расстановкой задач на день всем директорам (согласно плана)',
            $templates['rtpl_executive_10']['title']);
        $this->assertSame(31, $templates['rtpl_executive_10']['weekdaymask']);
        $this->assertSame('09:00', $templates['rtpl_executive_10']['source_time']);
        $this->assertSame('65d9e6b56f2324063f3b26d26e2da592b9fc2c86b7b1f61de2606f7c0f83cc3a',
            $templates['rtpl_executive_10']['source_hash']);

        $this->assertSame('Разработка финансовой модели распределения денег, соответсвующей операционной деятельности и планируемой прибыли',
            $templates['rtpl_finance_09']['title']);
        $this->assertSame(8, $templates['rtpl_finance_09']['weekdaymask']); // Thursday.
        $this->assertSame('monthly', $templates['rtpl_finance_42']['cadence']);
        $this->assertSame(1, $templates['rtpl_finance_42']['monthweek']);
        $this->assertTrue($templates['rtpl_finance_46']['requires_review']);
    }

    public function test_template_source_uuid_is_stable_and_reversible(): void {
        $template = recurring_task_library_service::template('rtpl_executive_10');
        $this->assertNotNull($template);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $template['source_uuid']);
        $this->assertSame($template['source_uuid'],
            recurring_task_library_service::template_source_uuid('rtpl_executive_10'));
        $resolved = recurring_task_library_service::template_by_source_uuid($template['source_uuid']);
        $this->assertSame('rtpl_executive_10', $resolved['key']);
    }

    public function test_standard_cadences_are_deterministic(): void {
        $this->assertTrue(recurring_task_service::is_due_on($this->row('daily', '2026-09-01'), '2026-09-13'));
        $this->assertTrue(recurring_task_service::is_due_on($this->row('weekly', '2026-09-07'), '2026-09-14'));
        $this->assertFalse(recurring_task_service::is_due_on($this->row('weekly', '2026-09-07'), '2026-09-15'));
        $this->assertTrue(recurring_task_service::is_due_on($this->row('biweekly', '2026-09-07'), '2026-09-21'));
        $this->assertTrue(recurring_task_service::is_due_on($this->row('monthly', '2026-01-31'), '2026-02-28'));
        $this->assertTrue(recurring_task_service::is_due_on($this->row('quarterly', '2026-01-15'), '2026-04-15'));
        $this->assertTrue(recurring_task_service::is_due_on($this->row('semiannual', '2026-01-15'), '2026-07-15'));
        $this->assertTrue(recurring_task_service::is_due_on($this->row('annual', '2026-02-28'), '2027-02-28'));
    }

    public function test_exact_weekday_masks_are_deterministic(): void {
        $this->assertFalse(recurring_task_service::is_due_on($this->row('daily', '2026-09-01', '', 31), '2026-09-13')); // Sunday.
        $this->assertTrue(recurring_task_service::is_due_on($this->row('daily', '2026-09-01', '', 31), '2026-09-14')); // Monday.
        $this->assertTrue(recurring_task_service::is_due_on($this->row('weekly', '2026-09-07', '', 21), '2026-09-09')); // Wed.
        $this->assertFalse(recurring_task_service::is_due_on($this->row('weekly', '2026-09-07', '', 21), '2026-09-10')); // Thu.
    }

    public function test_first_week_of_month_schedule_is_supported(): void {
        $row = $this->row('monthly', '2026-09-07', '', 1, 1); // Monday in first week.
        $this->assertTrue(recurring_task_service::is_due_on($row, '2026-10-05'));
        $this->assertFalse(recurring_task_service::is_due_on($row, '2026-10-12'));
    }

    public function test_review_cadence_never_generates(): void {
        $this->assertFalse(recurring_task_service::is_due_on($this->row('review', '2026-09-01'), '2026-09-13'));
    }

    public function test_immutable_revision_comparison_reports_actual_field_changes(): void {
        $before = (object)[
            'title' => 'Проверка продаж', 'status' => 'draft', 'cadence' => 'weekly',
            'assigneeid' => 15, 'product' => 'Отчёт', 'description' => 'Описание',
        ];
        $after = clone $before;
        $after->status = 'active';
        $after->cadence = 'daily';
        $after->assigneeid = 18;
        $after->description = 'Описание изменено с импортным SHA256/ABCDEF';
        $changes = recurring_task_service::compare_revisions($before, $after);
        $this->assertSame(['description', 'assigneeid', 'cadence', 'status'],
            array_column($changes, 'field'));
        $this->assertTrue($changes[0]['redacted']);
        $this->assertSame('', $changes[0]['before']);
        $this->assertSame('15', $changes[1]['before']);
        $this->assertSame('18', $changes[1]['after']);
        $this->assertSame([], recurring_task_service::compare_revisions($after, clone $after));
        $this->assertSame([], recurring_task_service::compare_revisions(null, $after));
    }

    public function test_position_responsibility_is_audited_as_a_definition_change(): void {
        $before = (object)['title' => 'Weekly position report', 'status' => 'active',
            'assigneeid' => 15, 'dutypositionuuid' => ''];
        $after = clone $before;
        $after->dutypositionuuid = '123e4567-e89b-42d3-a456-426614174000';
        $changes = recurring_task_service::compare_revisions($before, $after);
        $this->assertSame(['dutypositionuuid'], array_column($changes, 'field'));
        $this->assertSame('', $changes[0]['before']);
        $this->assertSame($after->dutypositionuuid, $changes[0]['after']);
        $this->assertContains('one_occurrence_per_company_series_period_independent_of_assignee',
            recurring_task_service::contract()['rules']);
    }

    public function test_upcoming_dates_reuses_canonical_schedule_without_generating_tasks(): void {
        $series = ['cadence' => 'weekly', 'start_date' => '2026-10-12', 'end_date' => '',
            'weekday_mask' => 1, 'month_week' => 0];
        $this->assertSame(['2026-10-19', '2026-10-26', '2026-11-02'],
            recurring_task_service::upcoming_dates($series, '2026-10-13', 3));
        $series['end_date'] = '2026-10-25';
        $this->assertSame(['2026-10-19'],
            recurring_task_service::upcoming_dates($series, '2026-10-13'));
        $series['cadence'] = 'review';
        $this->assertSame([], recurring_task_service::upcoming_dates($series, '2026-10-13'));
        $series['cadence'] = 'annual';
        $series['weekday_mask'] = 0;
        $series['end_date'] = '';
        $this->assertSame('2027-10-12', recurring_task_service::upcoming_dates($series,
            '2026-10-13', 5)[0]);
    }
}
