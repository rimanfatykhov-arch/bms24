<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

/** Manager-only Task Execution Workspace must reuse the permission-scoped execution report. */
final class task_manager_workspace1853_test extends \advanced_testcase {
    private function model(bool $manager): array {
        return [
            'can_manage'=>$manager, 'total'=>3, 'page'=>0, 'pagesize'=>25,
            'tasks'=>[], 'counts'=>['total'=>3, 'open'=>2, 'review'=>1, 'overdue'=>1,
                'done'=>1, 'cancelled'=>0],
            'scope_counts'=>['roles'=>2, 'all'=>3], 'actor'=>42,
            'filter_plans'=>[], 'plan_memberships'=>[],
            'execution_report'=>[
                'summary'=>['returned'=>1, 'soon'=>1, 'undated'=>0],
                'assignees'=>[['userid'=>77, 'name'=>'Иван <script>bad()</script>',
                    'total'=>3, 'active'=>2, 'review'=>1, 'returned'=>1,
                    'done'=>1, 'overdue'=>1]],
                'projects'=>[['uuid'=>'11111111-1111-4111-8111-111111111111',
                    'name'=>'Project <unsafe>', 'total'=>2, 'active'=>1,
                    'done'=>1, 'overdue'=>1]],
                'risks'=>[['uuid'=>'22222222-2222-4222-8222-222222222222',
                    'title'=>'Issue <img src=x onerror=alert(1)>', 'subtask'=>false,
                    'assignee'=>'<script>alert(1)</script>', 'status'=>'returned',
                    'duedate'=>'2026-10-01']],
            ],
        ];
    }
    private function filters(): array {
        return ['scope'=>'all', 'q'=>'', 'status'=>'', 'stream'=>'',
            'sourcekind'=>'', 'battleplan'=>'', 'signal'=>'',
            'assignee'=>0, 'project'=>'', 'sort'=>'duedate', 'dir'=>'asc'];
    }
    public function test_manager_workspace_shows_scoped_metrics_links_and_escaped_risks(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $html = ui::listing('test-company', $this->model(true), $this->filters(), 'manager');
        $this->assertStringContainsString('bms-task1853-workspace', $html);
        $this->assertStringContainsString('bms-task1853-metrics', $html);
        $this->assertStringContainsString('display=manager', $html);
        $this->assertStringContainsString('signal=overdue', $html);
        $this->assertStringContainsString('signal=review', $html);
        $this->assertStringContainsString('scope=all', $html);
        $this->assertStringContainsString('bms-task1853-risk-list', $html);
        $this->assertStringNotContainsString('<script>bad()</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('Project <unsafe>', $html);
    }
    public function test_non_manager_never_receives_manager_workspace(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $html = ui::listing('test-company', $this->model(false), $this->filters(), 'manager');
        $this->assertStringNotContainsString('bms-task1853-workspace', $html);
        $this->assertStringNotContainsString('bms-task1853-metrics', $html);
        $this->assertStringNotContainsString('display=manager', $html);
    }

    public static function shell_modes(): array {
        return ['owned'=>['owned'], 'legacy'=>['legacy']];
    }

    /** @dataProvider shell_modes */
    public function test_denied_manager_state_is_removed_before_links_and_forms(string $mode): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', $mode, 'local_kbacompany');
        $filters = $this->filters() + ['display'=>'manager'];
        $html = ui::listing('test-company', $this->model(false), $filters, 'manager');
        $this->assertStringNotContainsString('display=manager', $html);
        $this->assertStringNotContainsString('value="manager"', $html);
        $this->assertStringNotContainsString('scope=all', $html);
        $this->assertStringNotContainsString('bms-task1853-workspace', $html);
        $this->assertStringNotContainsString('bms-task1853-metrics', $html);
        $this->assertStringNotContainsString('11111111-1111-4111-8111-111111111111', $html);
        $this->assertStringNotContainsString('22222222-2222-4222-8222-222222222222', $html);
        $this->assertStringNotContainsString('bad()', $html);
        $this->assertStringContainsString('display=list', $html);
        $this->assertStringContainsString('scope=roles', $html);
    }
}
