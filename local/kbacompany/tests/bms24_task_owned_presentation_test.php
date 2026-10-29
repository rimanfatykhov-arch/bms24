<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

/** W3: Task Center presentation-only component migration; no data writes. */
class bms24_task_owned_presentation_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_owned_mode_has_local_task_fragments_and_escapes_title(): void {
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $panel = ui::panel('<img src=x onerror=alert(1)>', '<p>server-rendered slot</p>');
        $this->assertStringContainsString('bms24-task-panel', $panel);
        $this->assertStringNotContainsString('<img src=x', $panel);
        $this->assertStringContainsString('&lt;img', $panel);
        $this->assertStringContainsString('server-rendered slot', $panel);

        $status = ui::badge('open');
        $this->assertStringContainsString('bms24-ui-status--open', $status);
        $this->assertStringContainsString('bms24-ui-status--cancelled', ui::badge('cancelled'));
        $this->assertStringContainsString('bms24-ui-btn--primary',
            ui::link(new \moodle_url('/local/kbacompany/tasks.php'), 'Test', true));
    }

    public function test_owned_list_uses_typed_get_filters_and_task_tabs(): void {
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $html = ui::listing('company-test', ['can_manage'=>true,'total'=>0], [
            'scope'=>'my', 'q'=>'<script>alert(1)</script>', 'status'=>'',
            'stream'=>'', 'sourcekind'=>'', 'battleplan'=>'plan-1',
        ], 'list');
        $this->assertStringContainsString('bms24-task-header', $html);
        $this->assertStringContainsString('bms24-task-tabs', $html);
        $this->assertStringContainsString('bms24-ui-filterbar', $html);
        $this->assertStringContainsString('name="scope"', $html);
        $this->assertStringContainsString('name="display"', $html);
        $this->assertStringContainsString('name="battleplan"', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_owned_inputs_keep_stable_ids_and_error_attributes(): void {
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $html = ui::control('title', ['label'=>'taskcenter_task', 'required'=>true,
            'maxlength'=>255], 'User', 'Invalid data');
        $this->assertStringContainsString('id="tui_title"', $html);
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('required="required"', $html);
        $this->assertStringContainsString('bms24-ui-input', $html);
        $this->assertStringContainsString('bms24-task-control', $html);
    }

    public function test_legacy_mode_retains_original_task_dom(): void {
        set_config('bms24_shell_mode', 'legacy', 'local_kbacompany');
        $this->assertStringContainsString('bms-crm-section bms-tui-panel', ui::panel('Title','Body'));
        $this->assertStringContainsString('bms-tui-badge', ui::badge('open'));
        $this->assertStringContainsString('btn btn-primary',
            ui::link(new \moodle_url('/local/kbacompany/tasks.php'), 'Test', true));
        $this->assertStringContainsString('form-control', ui::control('title', ['label'=>'taskcenter_task']));
    }

    public function test_owned_detail_header_links_assignee_to_employee_card_safely(): void {
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $html = ui::detail('company-test', $this->task_detail_model([
            'title' => '<script>alert(1)</script>',
            'product' => '<b>unsafe product</b>',
            'description' => '<img src=x onerror=alert(1)>',
            'assignee_id' => 42,
            'assignee_name' => 'Alice <img src=x onerror=alert(1)>',
        ]), 'overview', []);

        $this->assertStringContainsString('bms24-task-header', $html);
        $this->assertStringContainsString('/local/kbacompany/employee_view.php?uuid=company-test&amp;userid=42', $html);
        $this->assertStringContainsString('Alice &lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('Alice <img', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>unsafe product</b>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    public function test_legacy_detail_header_links_assignee_to_employee_card_safely(): void {
        set_config('bms24_shell_mode', 'legacy', 'local_kbacompany');
        $html = ui::detail('company-test', $this->task_detail_model([
            'title' => '<script>alert(1)</script>',
            'assignee_id' => 42,
            'assignee_name' => 'Alice <img src=x onerror=alert(1)>',
        ]), 'overview', []);

        $this->assertStringContainsString('bms-workspace-header', $html);
        $this->assertStringContainsString('/local/kbacompany/employee_view.php?uuid=company-test&amp;userid=42', $html);
        $this->assertStringContainsString('Alice &lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('Alice <img', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_detail_header_without_assignee_has_no_employee_card_link(): void {
        foreach (['owned' => 'bms24-task-header', 'legacy' => 'bms-workspace-header'] as $mode => $headerclass) {
            set_config('bms24_shell_mode', $mode, 'local_kbacompany');
            $html = ui::detail('company-test', $this->task_detail_model([
                'assignee_id' => 0,
                'assignee_name' => '',
                'effective_assignee_id' => 0,
                'effective_assignee_name' => '',
            ]), 'overview', []);
            $header = $this->task_detail_header_html($html);

            $this->assertStringContainsString($headerclass, $header);
            $this->assertStringNotContainsString('employee_view.php', $header);
            $this->assertStringNotContainsString('userid=0', $header);
            $this->assertStringContainsString(get_string('tui_unavailable', 'local_kbacompany'), $header);
        }
    }

    private function task_detail_model(array $overrides = []): array {
        $task = array_merge([
            'uuid' => 'task-test',
            'parent_uuid' => '',
            'context_kind' => '',
            'context_uuid' => '',
            'context_revision' => 0,
            'context_label' => '',
            'source_kind' => 'manual',
            'source_label' => '',
            'source_meeting_uuid' => '',
            'source_recurrence_uuid' => '',
            'source_template_key' => '',
            'source_condition_event_uuid' => '',
            'source_indicator_uuid' => '',
            'stream' => 'operate',
            'title' => 'Task title',
            'description' => 'Description',
            'product' => 'Product',
            'assignee_id' => 42,
            'assignee_name' => 'Alice Employee',
            'duty_position_uuid' => '',
            'effective_assignee_id' => 42,
            'effective_assignee_name' => 'Alice Employee',
            'creator_id' => 7,
            'creator_name' => 'Creator',
            'reviewer_id' => 0,
            'reviewer_name' => '',
            'observer_id' => 0,
            'observer_name' => '',
            'priority' => 'normal',
            'status' => 'open',
            'start_date' => '',
            'start_time' => '',
            'due_date' => '',
            'due_time' => '',
            'plan_minutes' => 0,
            'fact_minutes' => 0,
            'result' => '',
            'revision' => 1,
            'started_at' => 0,
            'completed_at' => 0,
            'time_created' => 0,
            'time_modified' => 0,
        ], $overrides);

        return [
            'selected' => $task,
            'plans' => ['eligible' => [], 'included' => []],
            'parent' => null,
            'discussion' => ['thread_uuid' => '', 'messages' => [], 'restricted' => true, 'can_post' => false],
            'checklist' => [],
            'attachments' => [],
            'result_attachments' => [],
            'history' => [],
            'employees' => [],
        ];
    }

    private function task_detail_header_html(string $html): string {
        foreach (['<section class="bms24-task-toolbar"', '<div class="bms-workspace-actionbar"'] as $marker) {
            $pos = strpos($html, $marker);
            if ($pos !== false) {
                return substr($html, 0, $pos);
            }
        }
        $this->fail('Task detail header boundary was not found.');
    }
}
