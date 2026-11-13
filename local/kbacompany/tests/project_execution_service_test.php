<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\administrative_cycle_service;
use local_kbacompany\local\administrative_scale_ui_service;
use local_kbacompany\local\bms_workspace_ux_service;
use local_kbacompany\local\meeting_service;
use local_kbacompany\local\task_service;

/** Source/UI regression contracts; full database scenarios require the Moodle test harness. */
final class project_execution_service_test extends \advanced_testcase {
    private function method_source(string $class, string $method): string {
        $reflection = new \ReflectionMethod($class, $method);
        return implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    public function test_execution_is_an_explicit_project_card_tab(): void {
        $html = administrative_scale_ui_service::project_tabs('company-a', 'project-a', 'execution');
        $this->assertStringContainsString('recordtab=execution', $html);
        $this->assertStringContainsString('detailuuid=project-a', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertStringNotContainsString('administrative_cycle.php', $html);
    }

    public function test_legacy_editor_cannot_save_or_publish(): void {
        $source = file_get_contents(__DIR__ . '/../administrative_cycle.php');
        $this->assertStringContainsString('projectexec_legacy_post', $source);
        $this->assertStringContainsString('legacy_destination', $source);
        $this->assertStringNotContainsString('::save(', $source);
        $this->assertStringNotContainsString('::publish(', $source);
        $this->assertStringNotContainsString('::archive(', $source);
    }

    public function test_execution_project_and_task_queries_are_read_only(): void {
        foreach ([[administrative_cycle_service::class, 'project_execution'],
                [task_service::class, 'for_project'], [meeting_service::class, 'for_project']] as $pair) {
            $source = $this->method_source($pair[0], $pair[1]);
            foreach (['insert_record', 'update_record', 'delete_records', 'set_field', 'create_generated'] as $write) {
                $this->assertStringNotContainsString($write, $source);
            }
        }
        $tasks = $this->method_source(task_service::class, 'for_project');
        $this->assertStringContainsString('t.companyuuid = :company', $tasks);
        $this->assertStringContainsString('a.revision = t.sourcerevision', $tasks);
        $this->assertStringContainsString('bp.reviewerid = :planreviewer', $tasks);
        $meetings = $this->method_source(meeting_service::class, 'for_project');
        $this->assertStringContainsString('p.userid = :actor AND p.active = 1', $meetings);
    }

    public function test_management_planning_does_not_link_to_retired_editor(): void {
        $model = bms_workspace_ux_service::management_nav_model('company-a', 'cycles', true);
        $this->assertSame(['coordweek', 'decisionsregistry', 'week', 'battleplan', 'cycles'], array_column($model['items'], 'key'));
        foreach ($model['items'] as $item) {
            $this->assertStringNotContainsString('administrative_cycle.php', $item['url']);
        }
    }
}
