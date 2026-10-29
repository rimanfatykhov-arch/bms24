<?php

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\development_plan_service;

/** Contract regression checks for Development Plan and Learning Linkage. */
class development_plan_service_test extends \advanced_testcase {
    public function test_contract_keeps_learning_execution_outside_bms(): void {
        $contract = development_plan_service::contract();
        $this->assertSame('0.7.6', $contract['version']);
        $this->assertContains('learning_execution_remains_owned_by_moodle_and_kba_corporate', $contract['rules']);
        $this->assertContains('company_course_linkage_is_validated_against_learning_contour_catalogue', $contract['rules']);
        $this->assertContains('moodle_learning_status_is_read_live_and_never_written_as_competency_fact', $contract['rules']);
        $this->assertContains('competency_attainment_requires_assessment_engine_approval', $contract['rules']);
    }
}
