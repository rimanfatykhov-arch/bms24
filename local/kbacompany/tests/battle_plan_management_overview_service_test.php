<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_management_overview_service;

class battle_plan_management_overview_service_test extends \advanced_testcase {
    public function test_contract_is_schema_neutral_read_model(): void {
        $contract = battle_plan_management_overview_service::contract();
        $this->assertSame('0.5.11', $contract['version']);
        $this->assertSame('none', $contract['storage']);
        $this->assertContains('overview_is_read_model_not_persisted_summary', $contract['rules']);
        $this->assertContains('canonical_task_acl_is_not_bypassed', $contract['rules']);
        $this->assertContains('only_accessible_battle_plans_are_aggregated', $contract['rules']);
        $this->assertSame('local_kbacompany_task', $contract['sources']['canonical_tasks']);
    }
}
