<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

/** Contract regression coverage for schema-neutral Battle Plan coverage read-model. */
class battle_plan_coverage_service_test extends \advanced_testcase {
    public function test_contract_preserves_canonical_sources(): void {
        $contract = \local_kbacompany\local\battle_plan_coverage_service::contract();
        $this->assertSame('0.5.11', $contract['version']);
        $this->assertSame('none', $contract['storage']);
        $this->assertContains('one_expected_plan_per_employee_position_week', $contract['rules']);
        $this->assertContains('historical_assignment_uses_effectivefrom_effectiveto', $contract['rules']);
        $this->assertContains('norm_gap_uses_effective_norm_revision_for_selected_week', $contract['rules']);
        $this->assertContains('no_hidden_thresholds', $contract['rules']);
    }
}
