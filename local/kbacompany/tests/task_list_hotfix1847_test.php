<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;
use local_kbacompany\local\task_list_preferences_service as prefs;

/** UI hotfix 1847 — real Moodle HTML rendering, no changes to task ACL. */
final class task_list_hotfix1847_test extends \advanced_testcase {
    private function render_list(array $columns): string {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $task = [
            'uuid'=>'22222222-2222-4222-8222-222222222222',
            'title'=>'A task', 'revision'=>1,
            'product'=>'Expected result', 'status'=>'open', 'stream'=>'operate',
            'due_date'=>'', 'assignee_id'=>0, 'assignee_name'=>'',
            'effective_assignee_id'=>0, 'effective_assignee_name'=>'',
            'creator_id'=>0, 'creator_name'=>'', 'reviewer_id'=>0,
            'reviewer_name'=>'', 'observer_id'=>0, 'observer_name'=>'',
            'priority'=>'normal', 'source_kind'=>'manual',
            'time_modified'=>0, 'time_created'=>0,
        ];
        $model = [
            'can_manage'=>false, 'total'=>1, 'page'=>0, 'pagesize'=>25,
            'actor'=>10, 'tasks'=>[$task], 'scope_counts'=>[],
            'plan_memberships'=>[], 'employees'=>[],
            'list_preferences'=>['columns'=>prefs::columns($columns),
                'sort'=>'duedate', 'dir'=>'asc'],
        ];
        $filters = [
            'scope'=>'roles', 'q'=>'', 'status'=>'', 'stream'=>'',
            'sourcekind'=>'', 'battleplan'=>'', 'signal'=>'',
            'assignee'=>0, 'project'=>'', 'sort'=>'duedate', 'dir'=>'asc',
        ];
        return ui::listing('test-company', $model, $filters, 'list');
    }

    private function column_input(string $html, string $column): string {
        $pattern = '/<input\\b[^>]*name="columns\\['.preg_quote($column, '/').'\\]"[^>]*>/';
        $this->assertSame(1, preg_match($pattern, $html, $matches), 'Missing: '.$column);
        return $matches[0];
    }

    public function test_only_selected_columns_have_checked_and_only_title_is_disabled(): void {
        $html = $this->render_list(['title', 'status']);
        foreach (array_keys(prefs::COLUMNS) as $column) {
            $input = $this->column_input($html, $column);
            if (in_array($column, ['title', 'status'], true)) {
                $this->assertMatchesRegularExpression('/\\schecked(?:=|\\s|>)/', $input);
            } else {
                $this->assertDoesNotMatchRegularExpression('/\\schecked(?:=|\\s|>)/', $input);
            }
            if ($column === 'title') {
                $this->assertMatchesRegularExpression('/\\sdisabled(?:=|\\s|>)/', $input);
            } else {
                $this->assertDoesNotMatchRegularExpression('/\\sdisabled(?:=|\\s|>)/', $input);
            }
        }
    }

    public function test_reset_button_and_compact_checkbox_column_render(): void {
        $html = $this->render_list(['title', 'status']);
        $this->assertStringContainsString('name="resetcolumns"', $html);
        $this->assertStringContainsString('value="1"', $html);
        $this->assertStringContainsString('bms24-task-sort-field', $html);
        $this->assertStringContainsString('bms-task1847-compact-table', $html);
        $this->assertStringContainsString('bms-task1847-select-col', $html);
        $this->assertStringContainsString('<colgroup>', $html);
        $this->assertSame(1, substr_count($html, 'class="bms24-task-preferences"'));
        $this->assertStringContainsString('data-bms-task1842-select-all="1"', $html);
    }
}
