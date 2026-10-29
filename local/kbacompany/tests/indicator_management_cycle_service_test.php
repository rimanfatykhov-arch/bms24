<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\indicator_management_cycle_service;

/** Contract-level regression guard for v0.5.9 management-cycle read model. */
final class indicator_management_cycle_service_test extends \advanced_testcase {
    public function test_contract_keeps_cycle_schema_neutral_and_noncausal(): void {
        $contract = indicator_management_cycle_service::contract();
        $this->assertSame('0.5.9', $contract['version']);
        $this->assertSame('none', $contract['storage']);
        $this->assertContains('followup_compares_exact_next_week_fact_without_claiming_causality', $contract['rules']);
        $this->assertContains('management_cycle_is_read_model_not_incident_storage', $contract['rules']);
    }
}
