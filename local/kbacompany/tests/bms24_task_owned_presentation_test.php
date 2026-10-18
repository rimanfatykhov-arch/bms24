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
            'stream'=>'', 'sourcekind'=>'',
        ], 'list');
        $this->assertStringContainsString('bms24-task-header', $html);
        $this->assertStringContainsString('bms24-task-tabs', $html);
        $this->assertStringContainsString('bms24-ui-filterbar', $html);
        $this->assertStringContainsString('name="scope"', $html);
        $this->assertStringContainsString('name="display"', $html);
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
}
