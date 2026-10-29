<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\competency_360_service;
use local_kbacompany\local\competency_assessment_service;

/** Contract regression tests for 360 competency assessment. */
class competency_360_service_test extends \advanced_testcase {
    public function test_contract_keeps_aggregate_and_formal_approval_gate(): void {
        $contract = competency_360_service::contract();
        $this->assertSame('0.7.4', $contract['version']);
        $this->assertContains('individual_360_response_never_changes_employee_competency_fact', $contract['rules']);
        $this->assertContains('aggregate_is_transferred_to_assessment_engine_as_draft_evidence', $contract['rules']);
        $this->assertContains('formal_assessment_approval_is_required_before_employee_profile_projection', $contract['rules']);
        $this->assertContains('respondent_identity_is_not_exposed_in_subject_aggregate', $contract['rules']);
    }

    public function test_relationships_and_assessment_method_are_explicit(): void {
        $this->assertSame(['self', 'manager', 'peer', 'direct_report', 'other'], competency_360_service::relationships());
        $this->assertContains('feedback_360', competency_assessment_service::methods());
    }
}
