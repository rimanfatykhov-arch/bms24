<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\integration_service;

/** Native Moodle regression tests. The release kit records whether these were actually run. */
final class integration_identity_service_test extends \advanced_testcase {
    private const COMPANY_A = '90000000-0000-4000-8000-000000000001';
    private const COMPANY_B = '90000000-0000-4000-8000-000000000002';

    private function setup_companies(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach ([self::COMPANY_A, self::COMPANY_B] as $uuid) {
            $DB->insert_record('local_kbacompany_company', (object)[
                'uuid' => $uuid, 'name' => 'Identity test company', 'code' => '',
                'primaryactivity' => null, 'foundedyear' => 0, 'bmsclient' => 1,
                'sourcekind' => 'manual', 'legacycompanyid' => 0, 'syncstate' => 'local',
                'active' => 1, 'archivedat' => 0, 'createdby' => 0, 'modifiedby' => 0,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
        }
    }

    private function data(string $companyuuid): array {
        return ['provider' => 'odoo', 'environment' => 'test-env', 'externalmodel' => 'res.company',
            'entitykind' => 'company', 'localref' => $companyuuid, 'externalid' => '14'];
    }

    private function save(string $uuid): \stdClass {
        global $USER;
        $data = $this->data($uuid);
        $preview = integration_service::preview_mapping($uuid, (int)$USER->id, $data);
        return integration_service::confirm_mapping($uuid, (int)$USER->id, $data, $preview['fingerprint'], true);
    }

    public function test_preview_is_read_only_and_confirmation_is_idempotent(): void {
        global $DB, $USER;
        $this->setup_companies();
        $sink = $this->redirectEvents();
        integration_service::preview_mapping(self::COMPANY_A, (int)$USER->id, $this->data(self::COMPANY_A));
        $this->assertEquals(0, $DB->count_records('local_kbacompany_extmap'));
        $one = $this->save(self::COMPANY_A);
        $two = $this->save(self::COMPANY_A);
        $this->assertSame($one->mappinguuid, $two->mappinguuid);
        $this->assertEquals(1, $DB->count_records('local_kbacompany_extmap'));
        $this->assertCount(1, $sink->get_events());
    }

    public function test_mapping_uuid_cannot_cross_company_scope(): void {
        global $USER;
        $this->setup_companies();
        $one = $this->save(self::COMPANY_A);
        $this->expectException(\moodle_exception::class);
        integration_service::scoped_mapping(self::COMPANY_B, (int)$USER->id, $one->mappinguuid);
    }

    public function test_global_external_uniqueness_is_enforced_by_database(): void {
        $this->setup_companies();
        $this->save(self::COMPANY_A);
        $this->expectException(\moodle_exception::class);
        $this->save(self::COMPANY_B);
    }

    public function test_closed_mapping_retains_ids_and_cannot_be_reactivated(): void {
        global $USER;
        $this->setup_companies();
        $one = $this->save(self::COMPANY_A);
        integration_service::close_mapping(self::COMPANY_A, (int)$USER->id, $one->mappinguuid, true);
        $closed = integration_service::scoped_mapping(self::COMPANY_A, (int)$USER->id, $one->mappinguuid);
        $this->assertSame($one->externalid, $closed->externalid);
        $this->assertEquals(0, $closed->active);
        $this->expectException(\moodle_exception::class);
        $this->save(self::COMPANY_A);
    }

    public function test_legacy_bitrix_mapping_is_not_replaced(): void {
        global $USER;
        $this->setup_companies();
        integration_service::bind_external('bitrix24', 'company', self::COMPANY_A, '100');
        $data = $this->data(self::COMPANY_A);
        $data['provider'] = 'bitrix24'; $data['externalmodel'] = 'crm.company'; $data['externalid'] = '200';
        $preview = integration_service::preview_mapping(self::COMPANY_A, (int)$USER->id, $data);
        integration_service::confirm_mapping(self::COMPANY_A, (int)$USER->id, $data, $preview['fingerprint'], true);
        $this->assertSame('100', integration_service::external_id('bitrix24', 'company', self::COMPANY_A));
    }
}
