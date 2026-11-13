<?php
namespace local_kbacompany;
defined('MOODLE_INTERNAL') || die();
use local_kbacompany\local\market_model_service;
class market_model_service_test extends \advanced_testcase {
    public function test_contract(): void {
        $c=market_model_service::contract();
        $this->assertSame('0.4.11',$c['version']);
        $this->assertSame('required',$c['company_scope']);
        $this->assertContains('published_revisions_are_immutable',$c['rules']);
        $this->assertContains('no_crm_or_erp_fact_mutation',$c['rules']);
        $this->assertContains('Market',$c['objects']);
        $this->assertContains('value_proposition_is_segment_specific',$c['rules']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$c['schema_hash']);
    }
}
