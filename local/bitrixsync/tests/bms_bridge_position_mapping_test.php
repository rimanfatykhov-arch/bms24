<?php
namespace local_bitrixsync;

defined('MOODLE_INTERNAL') || die();

/** Regression source for v5.0.36.1.23 Bitrix Contact POST handoff. */
final class bms_bridge_position_mapping_test extends \advanced_testcase {
    public function test_contract_requires_bms_position_projection_hotfix(): void {
        $service = new \local_bitrixsync\services\bms_bridge_service();
        $contract = $service->contract();
        $this->assertSame('5.0.36.1.23', $contract['version']);
        $this->assertSame('0.6.9.2', $contract['bms_required']);
    }
}
