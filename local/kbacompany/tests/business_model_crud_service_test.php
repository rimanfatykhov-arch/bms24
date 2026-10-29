<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\business_model_crud_service;
use local_kbacompany\local\business_model_ui_service;

/** Business Model CRUD contract regression tests. */
final class business_model_crud_service_test extends \advanced_testcase {
    public function test_contract_keeps_snapshot_and_gap_boundaries(): void {
        $contract = business_model_crud_service::contract();
        $this->assertSame('bms_business_model_crud', $contract['contract']);
        $this->assertContains('canvas_revision_is_snapshot_boundary', $contract['rules']);
        $this->assertContains('asis_and_target_are_separate_records', $contract['rules']);
        $this->assertContains('gap_is_not_created_implicitly', $contract['rules']);
        $this->assertContains('no_parallel_kpi_or_project_engine', $contract['rules']);
    }

    public function test_all_nine_methodology_blocks_have_ui_schema(): void {
        $schema = business_model_ui_service::block_schema();
        $this->assertSame(business_model_crud_service::BLOCKS, array_keys($schema));
        foreach ($schema as $block) {
            $this->assertNotEmpty($block['label']);
            $this->assertNotEmpty($block['purpose']);
            $this->assertNotEmpty($block['questions']);
            $this->assertNotEmpty($block['mistake']);
        }
    }

    public function test_state_markers_keep_fact_and_target_separate(): void {
        $this->assertContains('fact', business_model_crud_service::ASIS_MARKERS);
        $this->assertContains('data', business_model_crud_service::ASIS_MARKERS);
        $this->assertContains('hyp', business_model_crud_service::ASIS_MARKERS);
        $this->assertNotContains('target', business_model_crud_service::ASIS_MARKERS);
        $this->assertContains('target', business_model_crud_service::TARGET_MARKERS);
        $this->assertNotContains('fact', business_model_crud_service::TARGET_MARKERS);
    }
}
