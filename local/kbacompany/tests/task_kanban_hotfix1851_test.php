<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;
use local_kbacompany\local\task_kanban_preferences_service as prefs;

final class task_kanban_hotfix1851_test extends \advanced_testcase {
    public function test_owned_kanban_renders_horizontal_scroll_shell_and_arrows(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $task = [
            'uuid'=>'44444444-4444-4444-8444-444444444444','title'=>'Kanban task','revision'=>1,
            'product'=>'Done','status'=>'new','stream'=>'operate','due_date'=>'2026-10-18',
            'assignee_id'=>1,'assignee_name'=>'User A','effective_assignee_id'=>1,'effective_assignee_name'=>'User A',
            'creator_id'=>1,'creator_name'=>'User A','reviewer_id'=>2,'reviewer_name'=>'User B','observer_id'=>0,'observer_name'=>'',
            'priority'=>'normal','source_kind'=>'manual','time_modified'=>0,'time_created'=>0,
        ];
        $model = [
            'can_manage'=>true,'actor'=>1,'total'=>1,'page'=>0,'pagesize'=>25,'tasks'=>[$task],
            'scope_counts'=>['roles'=>1],'plan_memberships'=>[],'kanban_status_counts'=>['new'=>1],
            'kanban_preferences'=>prefs::defaults(),
        ];
        $filters = ['scope'=>'roles','q'=>'','status'=>'','stream'=>'','sourcekind'=>'','battleplan'=>'','signal'=>'','assignee'=>0,'project'=>'','sort'=>'duedate','dir'=>'asc'];
        $html = ui::listing('test-company', $model, $filters, 'kanban');
        $this->assertStringContainsString('bms-task1851-board-stage', $html);
        $this->assertStringContainsString('bms-task1851-board-scroll', $html);
        $this->assertStringContainsString('data-bms-task1848-scroller', $html);
        $this->assertStringContainsString('bms-task1848-scrollbtn--left', $html);
        $this->assertStringContainsString('bms-task1848-scrollbtn--right', $html);
    }
}
