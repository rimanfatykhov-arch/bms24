<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\integration_service;
use local_kbacompany\local\odoo_adapter_service;

final class odoo_adapter_service_test extends \advanced_testcase {
    private const COMPANY = '91000000-0000-4000-8000-000000000001';

    public function test_contract_requires_human_confirmation_for_actual_import(): void {
        $contract = odoo_adapter_service::contract();
        $this->assertSame('0.9.1.3', $contract['version']);
        $this->assertFalse($contract['credentials_in_moodle']);
        $this->assertFalse($contract['direct_odoo_database_access']);
        $this->assertTrue($contract['actual_ingestion_enabled']);
        $this->assertSame('human_confirmed_only', $contract['actual_ingestion_mode']);
        $this->assertSame('json2_for_odoo_19_plus', $contract['preferred_odoo_transport']);
        $this->assertSame('json2_transient_probe_cli', $contract['moodle_to_odoo_transport']);
    }

    public function test_readiness_requires_confirmed_company_mapping(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $now = time();
        $DB->insert_record('local_kbacompany_company', (object)[
            'uuid' => self::COMPANY, 'name' => 'Odoo Adapter Test', 'code' => '', 'primaryactivity' => null,
            'foundedyear' => 0, 'bmsclient' => 1, 'sourcekind' => 'manual', 'legacycompanyid' => 0,
            'syncstate' => 'local', 'active' => 1, 'archivedat' => 0, 'createdby' => 0, 'modifiedby' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $before = odoo_adapter_service::readiness(self::COMPANY, (int)$USER->id, 'test-env');
        $this->assertFalse($before['ready']);
        $data = ['provider' => 'odoo', 'environment' => 'test-env', 'externalmodel' => 'res.company',
            'entitykind' => 'company', 'localref' => self::COMPANY, 'externalid' => '7'];
        $preview = integration_service::preview_mapping(self::COMPANY, (int)$USER->id, $data);
        integration_service::confirm_mapping(self::COMPANY, (int)$USER->id, $data, $preview['fingerprint'], true);
        $after = odoo_adapter_service::readiness(self::COMPANY, (int)$USER->id, 'test-env');
        $this->assertTrue($after['ready']);
        $this->assertSame('7', $after['company_mapping']['external_id']);
    }
}
