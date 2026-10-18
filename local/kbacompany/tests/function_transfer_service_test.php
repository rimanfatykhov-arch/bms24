<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\function_transfer_service;

class function_transfer_service_test extends \advanced_testcase {
    public function test_contract_preserves_authorization_boundary(): void {
        $contract = function_transfer_service::contract();
        $this->assertSame('0.7.7', $contract['version']);
        $this->assertContains('authorization_record_does_not_grant_moodle_capabilities', $contract['rules']);
        $this->assertContains('transfer_does_not_rewrite_organization_or_staffing', $contract['rules']);
        $this->assertContains('target_acceptance_is_explicit', $contract['rules']);
    }
}
