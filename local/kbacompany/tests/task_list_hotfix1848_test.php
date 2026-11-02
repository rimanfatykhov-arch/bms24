<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

final class task_list_hotfix1848_test extends \advanced_testcase {
    public function test_list_workspace_renders_scroll_shell_and_arrows(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $task = [
            'uuid'=>'33333333-3333-4333-8333-333333333333', 'title'=>'Wide task', 'revision'=>1,
            'product'=>'Result', 'status'=>'open', 'stream'=>'operate', 'due_date'=>'',
            'assignee_id'=>1, 'assignee_name'=>'A', 'effective_assignee_id'=>1, 'effective_assignee_name'=>'A',
            'creator_id'=>2, 'creator_name'=>'B', 'reviewer_id'=>0, 'reviewer_name'=>'',
            'observer_id'=>0, 'observer_name'=>'', 'priority'=>'normal', 'source_kind'=>'manual',
            'time_modified'=>0, 'time_created'=>0,
        ];
        $model = [
            'can_manage'=>true, 'total'=>1, 'page'=>0, 'pagesize'=>25, 'actor'=>1,
            'tasks'=>[$task], 'scope_counts'=>['roles'=>1], 'plan_memberships'=>[], 'employees'=>[1=>'A'],
            'filter_plans'=>[], 'list_preferences'=>['columns'=>['title','assignee','creator','status','duedate','priority','sourcekind','stream','updated','created'], 'sort'=>'duedate', 'dir'=>'asc'],
        ];
        $filters = ['scope'=>'roles','q'=>'','status'=>'','stream'=>'','sourcekind'=>'','battleplan'=>'','signal'=>'','assignee'=>0,'project'=>'','sort'=>'duedate','dir'=>'asc'];
        $html = ui::listing('test-company', $model, $filters, 'list');
        $this->assertStringContainsString('bms-task1848-shell', $html);
        $this->assertStringContainsString('data-bms-task1848-scroller', $html);
        $this->assertStringContainsString('bms-task1848-scrollbtn--left', $html);
        $this->assertStringContainsString('bms-task1848-scrollbtn--right', $html);
    }
}
