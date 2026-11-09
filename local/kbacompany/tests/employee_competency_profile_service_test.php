<?php

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\employee_competency_profile_service;

/** Contract regression checks for Employee Competency Profile. */
class employee_competency_profile_service_test extends \advanced_testcase {
    public function test_contract_keeps_profile_as_evidence_comparison_not_assessment_engine(): void {
        $contract = employee_competency_profile_service::contract();
        $this->assertSame('0.7.2', $contract['version']);
        $this->assertContains('employee_profile_compares_evidence_with_published_position_profile', $contract['rules']);
        $this->assertContains('course_completion_does_not_confirm_competency_attainment', $contract['rules']);
        $this->assertContains('missing_evidence_is_unknown_not_zero_competence', $contract['rules']);
        $this->assertContains('formal_assessment_projection_is_owned_by_assessment_engine_0_7_3', $contract['rules']);
    }
}
