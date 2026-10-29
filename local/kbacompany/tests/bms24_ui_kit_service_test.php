<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms24_ui_kit_service as kit;

/** W2 UI Kit: no business access or persistence. */
class bms24_ui_kit_service_test extends \advanced_testcase {
    public function test_rejects_unknown_fragment_and_variants(): void {
        $this->resetAfterTest();
        try {
            kit::fragment('other_plugin', []);
            $this->fail('Expected coding_exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Unsupported', $e->getMessage());
        }
        try {
            kit::status('Test', 'invalid-css-class');
            $this->fail('Expected coding_exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Unsupported', $e->getMessage());
        }
    }

    public function test_escaped_label_in_button_and_cell(): void {
        $this->resetAfterTest();
        $html = kit::button(new \moodle_url('/local/kbacompany/bms24_styleguide.php'), '<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $table = kit::table('Sample', ['Title'], [['<svg/onload=alert(1)>']]);
        $this->assertStringNotContainsString('<svg', $table);
    }

    public function test_recurring_series_statuses_are_typed_variants(): void {
        $this->resetAfterTest();
        $this->assertStringContainsString('bms24-ui-status--draft', kit::status('Draft', 'draft'));
        $this->assertStringContainsString('bms24-ui-status--active', kit::status('Active', 'active'));
        $this->assertStringContainsString('bms24-ui-status--archived', kit::status('Archived', 'archived'));
    }

    public function test_filter_field_name_validation(): void {
        $this->resetAfterTest();
        try {
            kit::field(['type' => 'text', 'name' => 'evil"onfocus', 'id' => 'valid']);
            $this->fail('Expected coding_exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }
        try {
            kit::filterbar(new \moodle_url('/'), 'Filter', [], ['bad name' => 'x'],
                new \moodle_url('/'), 'Apply', 'Reset');
            $this->fail('Expected coding_exception');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Invalid', $e->getMessage());
        }
    }
    public function test_filterbar_uses_canonical_responsive_grid(): void {
        $this->resetAfterTest();
        $html = kit::filterbar(
            new \moodle_url('/local/kbacompany/battle_plan_overview.php'),
            'Battle Plan filters',
            [
                ['id'=>'week','name'=>'week','label'=>'Week','type'=>'date','value'=>'2026-10-19'],
                ['id'=>'unit','name'=>'orgnode','label'=>'Unit','type'=>'select','value'=>'','options'=>[''=>'All']],
            ],
            ['uuid'=>'company'],
            new \moodle_url('/local/kbacompany/battle_plan_overview.php', ['uuid'=>'company']),
            'Apply',
            'Reset'
        );
        $this->assertStringContainsString('bms24-ui-filterbar', $html);
        $this->assertStringContainsString('bms24-ui-fieldgrid', $html);
        $this->assertStringContainsString('bms24-ui-filter-actions', $html);
        $this->assertStringContainsString('name="uuid"', $html);
    }

    public function test_fieldgrid_uses_typed_escaped_fields(): void {
        $this->resetAfterTest();
        $html = kit::fieldgrid([
            ['id'=>'query','name'=>'q','label'=>'<Search>','type'=>'search','value'=>'<script>'],
        ]);
        $this->assertStringContainsString('bms24-ui-fieldgrid', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

}
