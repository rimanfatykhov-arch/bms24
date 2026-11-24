<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_workspace_focus_service;

/**
 * The top bar "Needs attention" flag must open the most urgent part of what its badge counts,
 * not the Communication Center start page.
 *
 * @covers \local_kbacompany\local\bms_workspace_focus_service::attention_target
 */
final class bms_workspace_attention_target_test extends \basic_testcase {
    public function test_overdue_wins_over_review_and_inbox(): void {
        $this->assertSame('overdue',
            bms_workspace_focus_service::attention_target(['overdue' => 1, 'control' => 4, 'incoming' => 7]));
    }

    public function test_review_wins_over_inbox(): void {
        $this->assertSame('control',
            bms_workspace_focus_service::attention_target(['overdue' => 0, 'control' => 2, 'incoming' => 5]));
    }

    public function test_inbox_only(): void {
        $this->assertSame('incoming',
            bms_workspace_focus_service::attention_target(['overdue' => 0, 'control' => 0, 'incoming' => 7]));
    }

    public function test_nothing_or_missing_counts(): void {
        $this->assertSame('', bms_workspace_focus_service::attention_target(['overdue' => 0, 'control' => 0, 'incoming' => 0]));
        $this->assertSame('', bms_workspace_focus_service::attention_target([]));
        $this->assertSame('', bms_workspace_focus_service::attention_target(['overdue' => -3]));
    }

    public function test_page_uses_target_not_center_start(): void {
        $source = file_get_contents(__DIR__ . '/../bms_workspace.php');
        $this->assertStringContainsString('\'url\' => $attentionurl->out(false),', $source);
        $this->assertStringContainsString('$attentionhtml = html_writer::link($attentionurl,', $source);
        $this->assertStringNotContainsString('$attentionhtml = html_writer::link($communicationurl,', $source);
    }

    public function test_owned_flag_template_is_a_bell_sized_button(): void {
        $template = file_get_contents(__DIR__ . '/../templates/bms24_attention_flag.mustache');
        $this->assertStringContainsString('class="bms24-attention"', $template);
        $this->assertStringContainsString('aria-label="{{label}}"', $template);
        $this->assertStringContainsString('aria-hidden="true">{{count}}</span>', $template);
        $css = file_get_contents(__DIR__ . '/../bms_ui/notification_bell.css');
        $this->assertStringContainsString('.bms24-app .bms24-attention {', $css);
        $this->assertStringContainsString('.bms24-app .bms24-attention-badge {', $css);
    }
}
