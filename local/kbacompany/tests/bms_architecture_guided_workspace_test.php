<?php
namespace local_kbacompany;

use local_kbacompany\local\bms_ui_service;

defined('MOODLE_INTERNAL') || die();

/** Guided Business Architecture semantics regression coverage. */
class bms_architecture_guided_workspace_test extends \advanced_testcase {
    public function test_design_sequence_is_product_first_and_stable(): void {
        $semantics = bms_ui_service::architecture_semantics();
        $keys = array_map(static function(array $stage): string {
            return $stage['key'];
        }, $semantics['flow']);
        $this->assertSame([
            'owner',
            'philosophy',
            'market',
            'business_model',
            'financial_model',
            'gaps_strategy',
            'organization',
            'process_architecture',
            'process_design_stp',
            'functions_positions_roles',
            'documents_metrics_improvement',
        ], $keys);
    }

    public function test_navigator_items_are_bound_to_guided_stages(): void {
        $semantics = bms_ui_service::architecture_semantics();
        foreach ($semantics['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $this->assertNotEmpty($item['route']);
                $this->assertNotEmpty($item['stage']);
            }
        }
    }
}
