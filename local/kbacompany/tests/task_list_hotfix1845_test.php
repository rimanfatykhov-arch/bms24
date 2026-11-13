<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

/** Hotfix 1845: compact table settings under a gear control and tighter bulk toolbar. */
final class task_list_hotfix1845_test extends \advanced_testcase {
    public function test_table_preferences_move_under_gear_button_inside_view_toolbar(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $task = [
            'uuid'=>'22222222-2222-4222-8222-222222222222',
            'title'=>'Compact list task', 'revision'=>1,
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
            'list_preferences'=>['columns'=>['title','status','duedate'],
                'sort'=>'duedate', 'dir'=>'asc'],
        ];
        $filters = [
            'scope'=>'roles', 'q'=>'', 'status'=>'', 'stream'=>'',
            'sourcekind'=>'', 'battleplan'=>'', 'signal'=>'',
            'assignee'=>0, 'project'=>'', 'sort'=>'duedate', 'dir'=>'asc',
        ];
        $html = ui::listing('test-company', $model, $filters, 'list');
        $this->assertStringContainsString('bms24-task-viewtools', $html);
        $this->assertStringContainsString('bms24-task-preferences-toggle', $html);
        $this->assertStringContainsString('bms24-task-preferences-icon', $html);
        $this->assertStringContainsString('sr-only', $html);
        $this->assertStringContainsString('task_list_preferences.php', $html);
        $this->assertSame(1, substr_count($html, 'class="bms24-task-preferences"'));
        $this->assertStringContainsString('class="bms-task1842-selection-count"', $html);
        $this->assertStringContainsString('0 / 1', $html);
    }
}
