<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_rc30_integrity_service as integrity;

/** RC30 build1860: new anomaly checks use company-scoped, read-only SQL. */
final class task_rc30_integrity1860_test extends \advanced_testcase {
    private const COMPANY = '22222222-2222-4222-8222-222222222222';

    public function test_extended_diagnostics_are_read_only_and_scoped(): void {
        $checks = integrity::checks(self::COMPANY);
        $this->assertCount(18, $checks);
        foreach ($checks as $name => $check) {
            $this->assertSame(['companyuuid' => self::COMPANY], $check['params'], $name);
            $this->assertStringStartsWith('SELECT COUNT(', trim($check['sql']), $name);
            $this->assertStringContainsString(':companyuuid', $check['sql'], $name);
            $this->assertStringNotContainsString('UPDATE ', strtoupper($check['sql']), $name);
            $this->assertStringNotContainsString('DELETE ', strtoupper($check['sql']), $name);
            $this->assertStringNotContainsString('INSERT ', strtoupper($check['sql']), $name);
        }
        foreach (['duplicate_task_uuid', 'self_parent_task', 'negative_task_minutes',
                'duplicate_bms_plan_items'] as $key) {
            $this->assertSame('error', $checks[$key]['severity']);
        }
        foreach (['reversed_task_dates', 'mismatched_bms_identifiers',
                'duplicate_role_assignments'] as $key) {
            $this->assertSame('warning', $checks[$key]['severity']);
        }
        $this->assertStringContainsString('older.companyuuid=t.companyuuid',
            $checks['duplicate_task_uuid']['sql']);
        $this->assertStringContainsString('older.companyuuid=bi.companyuuid',
            $checks['duplicate_bms_plan_items']['sql']);
        $this->assertStringContainsString('older.companyuuid=a.companyuuid',
            $checks['duplicate_role_assignments']['sql']);
    }

    public function test_extended_diagnostics_do_not_expand_global_company_scope(): void {
        $checks = integrity::checks();
        $this->assertCount(18, $checks);
        foreach ($checks as $name => $check) {
            $this->assertSame([], $check['params'], $name);
        }
    }

    public function test_extended_summary_remains_category_based(): void {
        $results = [];
        foreach (integrity::checks(self::COMPANY) as $name => $check) {
            $results[$name] = ['severity' => $check['severity'], 'count' => 0];
        }
        $results['duplicate_task_uuid']['count'] = 2;
        $results['reversed_task_dates']['count'] = 3;
        $results['tasks_total']['count'] = 35;
        $this->assertSame(['errors' => 1, 'warnings' => 1, 'checks' => 18],
            integrity::summary($results));
    }
}
