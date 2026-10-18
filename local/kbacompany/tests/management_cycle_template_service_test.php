<?php
namespace local_kbacompany;
defined('MOODLE_INTERNAL') || die();
use local_kbacompany\local\management_cycle_template_service;
class management_cycle_template_service_test extends \advanced_testcase {
    public function test_standard_templates_cover_management_cadences(): void {
        $templates = management_cycle_template_service::templates();
        foreach (['week','month','quarter','year','project','advisory4x90'] as $code) {
            $this->assertArrayHasKey($code, $templates);
            $this->assertNotEmpty($templates[$code]['checkpointkeys']);
        }
        $this->assertSame(3, $templates['advisory4x90']['indicator_max']);
        $this->assertTrue($templates['month']['battleplan']);
    }
    public function test_contract_preserves_canonical_execution_boundaries(): void {
        $contract = management_cycle_template_service::contract();
        $this->assertContains('indicators_remain_canonical_indicator_registry_objects', $contract['rules']);
        $this->assertContains('execution_remains_canonical_task_and_battle_plan_objects', $contract['rules']);
    }
}
