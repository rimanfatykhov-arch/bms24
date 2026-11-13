<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

/** build1858: empty filtered lists keep controls and offer a safe recovery route. */
final class task_empty_workspace1858_test extends \advanced_testcase {
    private static function empty_model(): array {
        return [
            'can_manage'=>false, 'total'=>0, 'page'=>0, 'pagesize'=>25,
            'actor'=>1, 'tasks'=>[], 'scope_counts'=>[],
            'plan_memberships'=>[], 'employees'=>[],
            'list_preferences'=>[
                'columns'=>['title','assignee','status','duedate'],
                'sort'=>'duedate', 'dir'=>'asc',
            ],
        ];
    }

    private static function filters(): array {
        return [
            'scope'=>'roles', 'q'=>'', 'status'=>'', 'stream'=>'',
            'sourcekind'=>'', 'battleplan'=>'', 'signal'=>'',
            'assignee'=>0, 'project'=>'', 'sort'=>'duedate', 'dir'=>'asc',
        ];
    }

    private static function xpath(string $html): \DOMXPath {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new \DOMXPath($dom);
    }

    public function test_empty_owned_list_keeps_view_tabs_and_column_preferences(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $filters = self::filters();
        $filters['q'] = 'nonexistent-1858';
        $filters['status'] = 'review';
        $html = ui::listing('company-test', self::empty_model(), $filters, 'list');
        $xpath = self::xpath($html);
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," bms-task1849-shell ")]')->length);
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," bms-task1849-topbar ")]')->length);
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," bms24-task-preferences ")]')->length);
        $this->assertSame(1, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," bms-task1858-empty-actions ")]')->length);
        $this->assertStringContainsString(get_string('bms_task1858_clear_filters', 'local_kbacompany'), $html);
        $this->assertStringContainsString(get_string('tui_view_kanban', 'local_kbacompany'), $html);
        $this->assertSame(0, $xpath->query('//form[contains(@class,"bms-task1842-batch-form")]')->length);
        // Reset removes filters, preserves current scope and presentation, and never
        // offers a client-supplied redirect.
        $recovery = null;
        foreach ($xpath->query('//a') as $link) {
            if (trim($link->textContent) === get_string('bms_task1858_clear_filters', 'local_kbacompany')) {
                $recovery = $link->getAttribute('href'); break;
            }
        }
        $this->assertNotNull($recovery);
        $this->assertStringContainsString('scope=roles', $recovery);
        $this->assertStringContainsString('display=list', $recovery);
        $this->assertStringNotContainsString('nonexistent-1858', $recovery);
        $this->assertStringNotContainsString('status=review', $recovery);
    }

    public function test_empty_unfiltered_list_does_not_offer_misleading_reset(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $html = ui::listing('company-test', self::empty_model(), self::filters(), 'list');
        $this->assertStringContainsString('bms-task1849-topbar', $html);
        $this->assertStringNotContainsString(get_string('bms_task1858_clear_filters', 'local_kbacompany'), $html);
    }

    public function test_empty_kanban_keeps_navigation_and_clear_filters(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $filters = self::filters();
        $filters['signal'] = 'overdue';
        $model = self::empty_model();
        $html = ui::listing('company-test', $model, $filters, 'kanban');
        $this->assertStringContainsString('bms-task1846-settings', $html);
        $this->assertStringContainsString(get_string('bms_task1858_clear_filters', 'local_kbacompany'), $html);
        $this->assertStringNotContainsString('bms-task1849-shell', $html);
    }
}
