<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\administrative_cycle_service;
use local_kbacompany\local\meeting_service;

final class administrative_cycle_service_test extends \advanced_testcase {
    public function test_cycle_uses_planning_objects_but_not_duplicate_decision_or_task_definitions(): void {
        $this->assertSame(['goal', 'policy', 'plan', 'program', 'project'], administrative_cycle_service::PLANNING_KINDS);
        $contract = administrative_cycle_service::contract();
        $this->assertContains('Decision', $contract['execution_objects']);
        $this->assertContains('Task', $contract['execution_objects']);
        $this->assertContains('decision_is_canonical_meeting_decision_not_cycle_copy', $contract['rules']);
        $this->assertContains('task_is_canonical_task_not_cycle_copy', $contract['rules']);
    }

    public function test_meeting_contract_accepts_exact_project_source(): void {
        $contract = meeting_service::contract();
        $this->assertContains('admin_project', $contract['source_kinds']);
        $this->assertContains('meeting_may_pin_exact_published_administrative_project_revision', $contract['rules']);
    }

    public function test_cycle_route_is_compatibility_only_and_scale_is_canonical(): void {
        $source = file_get_contents(__DIR__ . '/../classes/local/bms_ui_service.php');
        $this->assertStringContainsString("'admincycle' => [", $source);
        $this->assertStringContainsString("'url' => \$url('administrative_cycle.php')", $source);
        $this->assertStringContainsString("'adminscale' => [", $source);
        $this->assertStringContainsString("'group' => 'legacy', 'sidebar' => false, 'tree' => false", $source);
    }

    public function test_meeting_schema_contains_cycle_source_pin(): void {
        $xml = file_get_contents(__DIR__ . '/../db/install.xml');
        $this->assertStringContainsString('FIELD NAME="sourcekind" TYPE="char" LENGTH="20"', $xml);
        $this->assertStringContainsString('FIELD NAME="sourceuuid" TYPE="char" LENGTH="36"', $xml);
        $this->assertStringContainsString('FIELD NAME="sourcerevision" TYPE="int" LENGTH="10"', $xml);
        $this->assertStringContainsString('INDEX NAME="source_ix" UNIQUE="false" FIELDS="companyuuid, sourcekind, sourceuuid, sourcerevision"', $xml);
    }
}
