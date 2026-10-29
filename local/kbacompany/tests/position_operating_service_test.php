<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\position_operating_service;

/** Static contract tests for Position Operating System. Runtime scenarios are covered on Moodle test stand. */
class position_operating_service_test extends \advanced_testcase {
    public function test_contract_is_position_os_034(): void {
        $contract = position_operating_service::contract();
        $this->assertSame('bms_position_operating_system', $contract['contract']);
        $this->assertSame('0.3.4', $contract['version']);
        $this->assertContains('job_description_is_generated_from_bms_model', $contract['rules']);
        $this->assertContains('published_work_item_is_immutable', $contract['rules']);
        $this->assertSame(['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'event'], $contract['frequencies']);
    }

    public function test_contract_hash_is_stable_sha256_shape(): void {
        $contract = position_operating_service::contract();
        $this->assertSame('sha256', $contract['schema_hash_algorithm']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $contract['schema_hash']);
    }
}
