<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\position_competency_profile_service;

class position_competency_profile_service_test extends \advanced_testcase {
    public function test_contract_preserves_competency_boundaries(): void {
        $contract = position_competency_profile_service::contract();
        $this->assertSame('0.7.1', $contract['version']);
        $this->assertSame('RoleDNA', $contract['root']);
        $this->assertSame('moodle_core_competencies', $contract['competency_engine']);
        $this->assertTrue($contract['published_immutable']);
        $this->assertContains('target_requirement_does_not_change_employee_assessment', $contract['rules']);
        $this->assertContains('course_completion_does_not_confirm_competency_attainment', $contract['rules']);
    }
}
