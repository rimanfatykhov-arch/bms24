<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

/** Task Card 3.0 view-only regression checks. */
class task_card30_presentation_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    private function fixture(): array {
        return [
            'selected' => [
                'uuid' => 'task-123', 'title' => 'Test task', 'parent_uuid' => '',
                'product' => 'Expected result', 'description' => 'Work description',
                'assignee_id' => 0, 'assignee_name' => '',
                'duty_position_uuid' => '', 'effective_assignee_id' => 0,
                'effective_assignee_name' => '', 'due_date' => '', 'due_time' => '',
                'priority' => 'normal', 'creator_name' => 'Manager',
                'reviewer_name' => '', 'observer_name' => '', 'start_date' => '',
                'start_time' => '', 'plan_minutes' => 0, 'fact_minutes' => 0,
                'stream' => 'operate', 'source_kind' => 'manual', 'context_uuid' => '',
                'context_kind' => '', 'context_revision' => 0, 'context_label' => '',
                'status' => 'open', 'revision' => 1, 'result' => '',
                'reviewer_id' => 0,
            ],
            'parent' => null, 'employees' => [], 'checklist' => [],
            'subtasks' => ['items'=>[], 'total'=>0, 'page'=>0, 'pagesize'=>20],
            'plans' => ['eligible'=>[], 'included'=>[]],
            'can_add_subtasks' => false,
            'discussion' => ['restricted'=>true, 'can_post'=>false, 'messages'=>[], 'thread_uuid'=>''],
            'result_attachments' => [], 'attachments' => [], 'history' => [],
        ];
    }

    public function test_owned_overview_groups_result_checklist_subtasks_and_chat(): void {
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $html = ui::detail('company-test', $this->fixture(), 'overview', []);
        $this->assertStringContainsString('bms-task3-outcome', $html);
        $this->assertStringContainsString('Expected result', $html);
        $this->assertStringContainsString('bms-task3-jump-nav', $html);
        $this->assertStringContainsString('id="bms-task3-subtasks"', $html);
        $this->assertStringContainsString('id="bms-task3-checklist"', $html);
        $this->assertStringContainsString('id="bms-task3-discussion"', $html);
        $this->assertStringContainsString('bms-task3-layout', $html);
        $this->assertStringNotContainsString('communication_center.php', $html);
    }

    public function test_owned_overview_escapes_untrusted_result(): void {
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $model = $this->fixture();
        $model['selected']['product'] = '<img src=x onerror=alert(1)>';
        $model['selected']['description'] = '<script>alert(1)</script>';
        $html = ui::detail('company-test', $model, 'overview', []);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }

    public function test_legacy_mode_keeps_expected_result_within_content_panel(): void {
        set_config('bms24_shell_mode', 'legacy', 'local_kbacompany');
        $html = ui::detail('company-test', $this->fixture(), 'overview', []);
        $this->assertStringNotContainsString('bms-task3-intro', $html);
        $this->assertStringContainsString('Expected result', $html);
    }
}
