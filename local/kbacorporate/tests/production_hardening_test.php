<?php
namespace local_kbacorporate;

defined('MOODLE_INTERNAL') || die();

use local_kbacorporate\local\erp_audit;
use local_kbacorporate\local\erp_exchange;
use local_kbacorporate\local\external_mapping;
use local_kbacorporate\local\health;

/** Regression coverage for production hardening controls. */
final class production_hardening_test extends \advanced_testcase {
    public function test_erp_requests_create_privacy_safe_success_and_failure_audit(): void {
        global $DB;
        $this->resetAfterTest();

        erp_exchange::push_entity('erp_test', 'company', 'company-sensitive-id', [
            'name' => 'Production Company',
        ], 'request-sensitive-id');
        try {
            erp_exchange::push_entity('erp_test', 'unsupported', 'entity-sensitive-id', [], 'failed-request');
            $this->fail('Unsupported entity must be rejected.');
        } catch (\invalid_parameter_exception $expected) {
            // Expected validation failure is audited after transaction rollback.
        }

        $records = array_values($DB->get_records('local_kbacorp_erp_log', [], 'id ASC'));
        $this->assertCount(2, $records);
        $this->assertSame(erp_audit::STATUS_SUCCESS, $records[0]->status);
        $this->assertSame(erp_audit::STATUS_FAILURE, $records[1]->status);
        $this->assertSame(64, strlen($records[0]->externalidhash));
        $this->assertNotSame('company-sensitive-id', $records[0]->externalidhash);
        $this->assertStringNotContainsString('sensitive', json_encode($records));
    }

    public function test_external_mapping_rejects_cross_company_target(): void {
        global $DB;
        $this->resetAfterTest();
        $first = erp_exchange::push_entity('erp_test', 'company', 'company-1', ['name' => 'First']);
        $second = erp_exchange::push_entity('erp_test', 'company', 'company-2', ['name' => 'Second']);
        $departmentid = $DB->insert_record('local_kbacorp_department', (object)[
            'companyid' => (int)$first['moodle_company_id'], 'name' => 'Department',
            'code' => 'DEP', 'active' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->expectException(\invalid_parameter_exception::class);
        external_mapping::upsert((int)$second['moodle_company_id'], 'erp_test', 'department',
            'department', (int)$departmentid, 'cross-company-department');
    }

    public function test_operational_audit_retention_removes_only_expired_logs(): void {
        global $DB;
        $this->resetAfterTest();
        erp_exchange::push_entity('erp_test', 'company', 'company-old-log', ['name' => 'Company']);
        $record = $DB->get_record('local_kbacorp_erp_log', [], '*', MUST_EXIST);
        $DB->set_field('local_kbacorp_erp_log', 'timecreated', time() - (31 * DAYSECS), ['id' => $record->id]);

        $this->assertSame(1, erp_audit::prune(30));
        $this->assertSame(0, $DB->count_records('local_kbacorp_erp_log'));
        $this->assertSame(1, $DB->count_records('local_kbacorp_company'));
    }

    public function test_deep_payload_is_rejected_before_business_write(): void {
        global $DB;
        $this->resetAfterTest();
        $nested = ['value' => true];
        for ($i = 0; $i < erp_exchange::MAX_PAYLOAD_DEPTH; $i++) {
            $nested = ['nested' => $nested];
        }

        $this->expectException(\invalid_parameter_exception::class);
        try {
            erp_exchange::push_entity('erp_test', 'company', 'too-deep', [
                'name' => 'Must not be created', 'extra' => $nested,
            ]);
        } finally {
            $this->assertSame(0, $DB->count_records('local_kbacorp_company'));
        }
    }

    public function test_health_center_exposes_isolation_and_operational_audit(): void {
        $this->resetAfterTest();
        $result = health::check(false);
        $this->assertArrayHasKey('company_isolation', $result['components']);
        $this->assertArrayHasKey('erp_audit', $result['components']);
        $this->assertTrue($result['components']['company_isolation']['details']['isolated']);
        $this->assertTrue($result['components']['erp_audit']['details']['available']);
    }
}
