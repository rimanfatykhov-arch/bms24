<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\battle_plan_week_transition_service;

final class battle_plan_week_transition_service_test extends \advanced_testcase {
    public function test_contract_uses_canonical_transition_without_storage(): void {
        $contract = battle_plan_week_transition_service::contract();
        $this->assertSame('0.5.14', $contract['version']);
        $this->assertSame('none', $contract['storage']);
        $this->assertContains('each_plan_uses_canonical_transition_individually', $contract['rules']);
        $this->assertContains('no_direct_bulk_status_update', $contract['rules']);
    }
}
