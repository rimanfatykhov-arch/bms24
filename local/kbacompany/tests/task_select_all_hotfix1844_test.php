<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

/** Hotfix 1844: compact bulk select-all with accessible tooltip. */
final class task_select_all_hotfix1844_test extends \advanced_testcase {
    public function test_select_all_is_icon_only_and_retains_accessible_tooltip(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $task = [
            'uuid'=>'11111111-1111-4111-8111-111111111111',
            'title'=>'Test task', 'revision'=>1,
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
            'list_preferences'=>['columns'=>['title','status'],
                'sort'=>'title', 'dir'=>'asc'],
        ];
        $filters = [
            'scope'=>'roles', 'q'=>'', 'status'=>'', 'stream'=>'',
            'sourcekind'=>'', 'battleplan'=>'', 'signal'=>'',
            'assignee'=>0, 'project'=>'', 'sort'=>'title', 'dir'=>'asc',
        ];
        $html = ui::listing('test-company', $model, $filters, 'list');
        $tooltip = ui::tr('bms_task1842_select_all');
        $this->assertSame(1, preg_match(
            '/<label\b[^>]*class="bms-task1842-select-all"[^>]*>(.*?)<\/label>/s',
            $html, $matches));
        // The label contains only the checkbox: no visible text may widen the row.
        $this->assertSame('', trim(strip_tags($matches[1])));
        $this->assertStringContainsString('data-bms-task1842-select-all="1"', $matches[1]);
        $this->assertStringContainsString('title="'.s($tooltip).'"', $matches[0]);
        $this->assertStringContainsString('aria-label="'.s($tooltip).'"', $matches[1]);
        $this->assertStringContainsString('data-bms-task1842-count="1"', $html);
        $this->assertStringContainsString('name="selected[11111111-1111-4111-8111-111111111111]"', $html);
    }
}
