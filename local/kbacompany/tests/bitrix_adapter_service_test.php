<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\bitrix_adapter_service;

final class bitrix_adapter_service_test extends \advanced_testcase {
    public function test_contract_preserves_kba_connect_transport_ownership(): void {
        $contract = bitrix_adapter_service::contract();
        $this->assertSame('0.9.2', $contract['version']);
        $this->assertSame('local_bitrixsync_kba_connect', $contract['bitrix_rest_owner']);
        $this->assertFalse($contract['direct_bitrix_rest_from_bms']);
        $this->assertFalse($contract['credentials_in_local_kbacompany']);
        $this->assertContains('employees', $contract['resources']);
        $this->assertContains('kba_connect_transport_is_not_reimplemented', $contract['rules']);
    }
}
