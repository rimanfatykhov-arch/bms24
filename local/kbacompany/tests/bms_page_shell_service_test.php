<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\bms_ui_service;

/** Contract checks for the shared BMS page shell adapter. */
class bms_page_shell_service_test extends \advanced_testcase {
    public function test_contract_versions_are_aligned(): void {
        // Adapter and presentation model have independently versioned contracts.
        $this->assertSame('0.6.0', bms_page_shell_service::CONTRACT_VERSION);
        $this->assertSame('0.7.0.17', bms_ui_service::CONTRACT_VERSION);
    }
    public function test_business_architecture_semantics_follow_bms_methodology(): void {
        $semantics = bms_ui_service::architecture_semantics();

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
        ], array_column($semantics['flow'], 'key'));

        $this->assertSame([
            'foundation',
            'company_architecture',
            'operating_model',
            'management_regulations',
        ], array_column($semantics['groups'], 'key'));

        $this->assertSame(['owner', 'philosophy', 'market', 'businessmodel', 'financialmodel', 'deviations', 'strategy', 'adminscale'],
            array_column($semantics['groups'][0]['items'], 'route'));
        $this->assertSame(['organization', 'positions', 'roles'],
            array_column($semantics['groups'][1]['items'], 'route'));
        $this->assertSame(['processes', 'functions', 'flows', 'products', 'metrics', 'informationcenter'],
            array_column($semantics['groups'][2]['items'], 'route'));
        $this->assertSame('organization', $semantics['groups'][2]['items'][4]['stage']);
        $this->assertSame('bmsworkspace_indicators', $semantics['groups'][2]['items'][4]['labelkey']);
        $this->assertSame('organization', $semantics['groups'][2]['items'][5]['stage']);
        $this->assertSame('productinfocentertitle', $semantics['groups'][2]['items'][5]['labelkey']);
    }

}
