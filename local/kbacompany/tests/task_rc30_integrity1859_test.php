<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_rc30_integrity_service as integrity;

/** Read-only SQL contracts and fail-closed preflight gate. */
final class task_rc30_integrity1859_test extends \advanced_testcase {
    private const COMPANY = '11111111-2222-4333-8444-555555555555';

    public function test_every_scoped_query_has_a_bound_company_parameter(): void {
        $checks = integrity::checks(self::COMPANY);
        $this->assertCount(18, $checks);
        foreach ($checks as $name => $check) {
            $this->assertSame(['companyuuid'=>self::COMPANY], $check['params'], $name);
            $this->assertStringContainsString(':companyuuid', $check['sql'], $name);
            $this->assertStringStartsWith('SELECT COUNT(', trim($check['sql']), $name);
            $this->assertStringNotContainsString('UPDATE ', strtoupper($check['sql']), $name);
            $this->assertStringNotContainsString('DELETE ', strtoupper($check['sql']), $name);
            $this->assertStringNotContainsString('INSERT ', strtoupper($check['sql']), $name);
        }
        $this->assertStringContainsString('t.companyuuid=c.companyuuid',
            $checks['orphan_checklists']['sql']);
        $this->assertStringContainsString('t.companyuuid=l.companyuuid',
            $checks['orphan_task_history']['sql']);
        $this->assertStringContainsString('t.companyuuid=bi.companyuuid',
            $checks['missing_bms_task_link']['sql']);
        $this->assertStringContainsString('bp.companyuuid=bi.companyuuid',
            $checks['missing_battle_plan']['sql']);
    }

    public function test_global_queries_have_no_company_binding(): void {
        foreach (integrity::checks('') as $name => $check) {
            $this->assertSame([], $check['params'], $name);
        }
    }

    public function test_malformed_company_uuid_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        integrity::checks('not-a-company');
    }

    public function test_scan_reads_only_counts_and_has_a_deterministic_gate(): void {
        $manager = new class {
            public $tables = [];
            public function table_exists(\xmldb_table $table): bool {
                $this->tables[] = $table->getName();
                return true;
            }
        };
        $database = new class($manager) {
            private $manager;
            public $queries = [];
            public function __construct($manager) { $this->manager = $manager; }
            public function get_manager() { return $this->manager; }
            public function get_field_sql(string $sql, array $params) {
                $this->queries[] = [$sql, $params];
                if (strpos($sql, 't.revision < 1') !== false) { return 3; }
                if (strpos($sql, "TRIM(t.product) = ''") !== false) { return 1; }
                return 0;
            }
        };
        $results = integrity::scan($database, self::COMPANY);
        $this->assertCount(count(integrity::TABLES), $manager->tables);
        $this->assertCount(18, $database->queries);
        $this->assertSame(3, $results['invalid_revision']['count']);
        $this->assertSame(1, $results['missing_expected_product']['count']);
        $this->assertSame(['errors'=>1,'warnings'=>1,'checks'=>18], integrity::summary($results));
    }

    public function test_missing_table_stops_scan_before_query(): void {
        $manager = new class {
            public function table_exists(\xmldb_table $table): bool { return false; }
        };
        $db = new class($manager) {
            private $manager;
            public function __construct($manager) { $this->manager=$manager; }
            public function get_manager() { return $this->manager; }
            public function get_field_sql() { throw new \coding_exception('Unexpected query'); }
        };
        $this->expectException(\coding_exception::class);
        integrity::scan($db, self::COMPANY);
    }
}
