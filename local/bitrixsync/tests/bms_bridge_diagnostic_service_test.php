<?php
namespace local_bitrixsync;

defined('MOODLE_INTERNAL') || die();

/** Source-level contract checks for v5.0.36.1.18 BMS bridge diagnostics. */
class bms_bridge_diagnostic_service_test extends \advanced_testcase {
    public function test_contract_is_read_only_and_schema_neutral(): void {
        $service = new \local_bitrixsync\services\bms_bridge_diagnostic_service();
        $contract = $service->contract();
        $this->assertSame('5.0.36.1.18', $contract['version']);
        $this->assertSame('none', $contract['storage']);
        $this->assertTrue($contract['read_only']);
        $this->assertSame('bitrix_company_id', $contract['company_lookup']);
    }
}
