<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\competency_assessment_service;

/** Contract regression tests for the formal competency Assessment Engine. */
class competency_assessment_service_test extends \advanced_testcase {
    public function test_contract_keeps_approval_gate_and_no_automatic_learning_attainment(): void {
        $contract = competency_assessment_service::contract();
        $this->assertSame('0.7.3', $contract['version']);
        $this->assertContains('draft_and_submitted_assessments_do_not_change_employee_competency_fact', $contract['rules']);
        $this->assertContains('approved_assessment_projects_decided_levels_to_employee_competency_profile', $contract['rules']);
        $this->assertContains('course_completion_is_evidence_only_not_automatic_attainment', $contract['rules']);
        $this->assertContains('ai_may_propose_but_cannot_approve_or_write_assessment_decisions', $contract['rules']);
    }

    public function test_supported_methods_are_explicit(): void {
        $this->assertSame([
            'manager_observation', 'interview', 'test', 'case_task', 'evidence_review', 'self_assessment', 'feedback_360',
        ], competency_assessment_service::methods());
    }
}
