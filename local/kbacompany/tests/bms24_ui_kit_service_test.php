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
}
