<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_ui_service as ui;

/** Hotfix 1849: horizontal overflow is confined to the task table. */
final class task_list_hotfix1849_test extends \advanced_testcase {
    public function test_fixed_controls_stay_outside_horizontal_scroll_viewport(): void {
        $this->resetAfterTest();
        set_config('bms24_shell_mode', 'owned', 'local_kbacompany');
        $task = [
            'uuid'=>'44444444-4444-4444-8444-444444444444',
            'title'=>'Wide list regression', 'revision'=>1,
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
            'actor'=>1, 'tasks'=>[$task], 'scope_counts'=>[],
            'plan_memberships'=>[], 'employees'=>[],
            'list_preferences'=>[
                'columns'=>['title','assignee','creator','status','duedate','priority',
                    'sourcekind','reviewer','observer','stream','updated','created'],
                'sort'=>'duedate', 'dir'=>'asc',
            ],
        ];
        $filters = [
            'scope'=>'roles','q'=>'','status'=>'','stream'=>'','sourcekind'=>'',
            'battleplan'=>'','signal'=>'','assignee'=>0,'project'=>'',
            'sort'=>'duedate','dir'=>'asc',
        ];
        $html = ui::listing('company-test', $model, $filters, 'list');
        $dom = new \DOMDocument();
        $old = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($old);
        $xpath = new \DOMXPath($dom);
        $hasclass = static function(string $name): string {
            return "contains(concat(' ', normalize-space(@class), ' '), ' ".$name." ')";
        };
        $shell = $xpath->query('//*['.$hasclass('bms-task1849-shell').']')->item(0);
        $this->assertNotNull($shell);
        $topbar = $xpath->query('.//*['.$hasclass('bms-task1849-topbar').']', $shell)->item(0);
        $this->assertNotNull($topbar);
        $batch = $xpath->query('.//form['.$hasclass('bms-task1842-batch-form').']', $shell)->item(0);
        $this->assertNotNull($batch);
        // The preferences and view tabs stay OUTSIDE the scrolling table.
        $this->assertSame(0, $xpath->query('.//*['.$hasclass('bms-task1849-table-scroll').']', $topbar)->length);
        $this->assertSame(1, $xpath->query('.//*['.$hasclass('bms24-task-preferences').']', $topbar)->length);
        $viewport = $xpath->query('.//*['.$hasclass('bms-task1849-table-scroll').']', $batch)->item(0);
        $this->assertNotNull($viewport);
        $this->assertSame(1, $xpath->query('.//table', $viewport)->length);
        $this->assertSame(0, $xpath->query('.//*['.$hasclass('bms-task1842-toolbar').']', $viewport)->length);
        $this->assertSame(1, $xpath->query('.//*['.$hasclass('bms-task1842-toolbar').']', $batch)->length);
        $this->assertSame(2, $xpath->query('.//button[contains(@class,"bms-task1848-scrollbtn")]', $batch)->length);
        $this->assertSame(0, $xpath->query('.//button[contains(@class,"bms-task1848-scrollbtn")]', $viewport)->length);
        $this->assertSame(1, $xpath->query('.//input[contains(@class,"bms-task1842-row-select")]', $viewport)->length);
    }
}
