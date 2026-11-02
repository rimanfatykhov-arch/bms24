<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

/** build2026101838: filtered task list must not pass string attributes to html_writer::tag(). */
final class task_summary_filter_render_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    private function filters(array $overrides = []): array {
        return array_merge([
            'scope' => 'all', 'q' => '', 'status' => '', 'stream' => '',
            'sourcekind' => '', 'battleplan' => '', 'signal' => '',
            'assignee' => 0, 'project' => '',
        ], $overrides);
    }

    private function render(array $filters, string $mode): string {
        set_config('bms24_shell_mode', $mode, 'local_kbacompany');
        return ui::listing('test-company', [
            'can_manage' => true, 'total' => 0, 'filter_plans' => [],
        ], $filters, 'list');
    }

    public function test_all_active_summary_signals_render_without_typeerror(): void {
        foreach (['open', 'done', 'overdue', 'review', 'returned',
                'soon', 'undated', 'subtaskoverdue'] as $signal) {
            foreach (['owned', 'legacy'] as $mode) {
                $html = $this->render($this->filters(['signal' => $signal]), $mode);
                $this->assertStringContainsString('class="bms24-task-signal-chip"', $html,
                    'Active signal must have HTML class attributes in '.$mode.': '.$signal);
                $this->assertStringContainsString('tasks.php', $html);
            }
        }
    }

    public function test_employee_and_project_drilldowns_render_without_typeerror(): void {
        foreach (['owned', 'legacy'] as $mode) {
            foreach ([['assignee' => 42], ['project' => 'test-project'],
                    ['assignee' => 42, 'project' => 'test-project']] as $scope) {
                $html = $this->render($this->filters($scope), $mode);
                $this->assertStringContainsString('class="bms24-task-signal-chip"', $html);
                $this->assertStringContainsString('tasks.php', $html);
            }
        }
    }

    public function test_combined_signal_and_employee_scope_renders_both_filter_chips(): void {
        $html = $this->render($this->filters([
            'signal' => 'overdue', 'assignee' => 42,
        ]), 'owned');
        $this->assertSame(2, substr_count($html, 'class="bms24-task-signal-chip"'));
    }
}
