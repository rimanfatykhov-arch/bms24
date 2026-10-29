<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bms_page_shell_service;
use local_kbacompany\local\bms_ui_service;

/** Contract checks for the shared BMS page shell adapter. */
class bms_page_shell_service_test extends \advanced_testcase {
    public function test_contract_versions_are_aligned(): void {
        $this->assertSame('0.4.9', bms_page_shell_service::CONTRACT_VERSION);
        $this->assertSame('0.4.9', bms_ui_service::CONTRACT_VERSION);
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
        $this->assertSame(['processes', 'functions', 'flows', 'products'],
            array_column($semantics['groups'][2]['items'], 'route'));
    }

}
