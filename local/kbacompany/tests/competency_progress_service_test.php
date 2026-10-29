<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\competency_progress_service;

class competency_progress_service_test extends \advanced_testcase {
    public function test_contract_keeps_reassessment_approval_boundary(): void {
        $contract = competency_progress_service::contract();
        $this->assertSame('0.7.8', $contract['version']);
        $this->assertContains('progress_uses_approved_assessments_only', $contract['rules']);
        $this->assertContains('development_completion_may_recommend_reassessment_but_never_changes_competency_fact', $contract['rules']);
        $this->assertContains('reassessment_is_a_new_formal_assessment_and_requires_normal_approval', $contract['rules']);
    }
}
