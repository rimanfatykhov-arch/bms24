<?php
namespace local_bitrixsync;

defined('MOODLE_INTERNAL') || die();

/** Source-level contract checks for the v5.0.36.1.21 BMS bridge. */
class bms_bridge_service_test extends \advanced_testcase {
    public function test_contract_preserves_deal_driven_enrolment(): void {
        $service = new \local_bitrixsync\services\bms_bridge_service();
        $contract = $service->contract();
        $this->assertSame('5.0.36.1.23', $contract['version']);
        $this->assertSame('unchanged_deal_driven', $contract['course_enrolment']);
        $this->assertContains('employee_upsert', $contract['outbox_commands']);
    }
}
