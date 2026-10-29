<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\learning_contour_service;
use local_kbacompany\local\bms_ui_service;

class learning_contour_service_test extends \advanced_testcase {
    public function test_learning_contract_keeps_ownership_boundaries(): void {
        $contract = learning_contour_service::contract();
        $this->assertSame('0.7.6', $contract['version']);
        $this->assertSame('local_kbacorporate_public_bridge', $contract['sources']['corporate_learning']);
        $this->assertSame('moodle_core', $contract['sources']['enrolment_completion']);
        $this->assertContains('read_only_aggregation', $contract['rules']);
        $this->assertContains('course_completion_is_not_competency_attainment', $contract['rules']);
        $this->assertContains('bms_does_not_write_local_kbacorp_tables', $contract['rules']);
        $this->assertContains('corporate_drilldowns_keep_corporate_acl', $contract['rules']);
        $this->assertContains('learning_status_is_read_live_and_not_persisted_as_competency_fact', $contract['rules']);
    }

    public function test_ui_contract_has_people_and_development_group(): void {
        $contract = bms_ui_service::contract();
        $this->assertContains('people', $contract['workspace']['navigation_groups']);
    }
}
