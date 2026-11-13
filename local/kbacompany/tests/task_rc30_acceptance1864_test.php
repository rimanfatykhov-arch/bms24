<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_rc30_acceptance_service as acceptance;
use local_kbacompany\local\task_rc30_integrity_service as integrity;

/** Read-only gate checks with adapters and the real installed Moodle database. */
final class task_rc30_acceptance1864_test extends \advanced_testcase {
    private const COMPANY = '22222222-2222-4222-8222-222222222222';

    public function test_real_database_acceptance_requires_exact_version_without_config_writes(): void {
        global $DB;
        $this->resetAfterTest();
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $candidate = (int)$plugin->version;
        $this->assertSame($candidate, acceptance::EXPECTED_PLUGIN_VERSION);
        $config = $DB->get_records('config', null, 'id');
        $pluginconfig = $DB->get_records('config_plugins', null, 'id');
        $writes = $DB->perf_get_writes();
        $runtime = acceptance::runtime_status(PHP_VERSION, $DB->get_dbfamily(), $DB->get_server_info());
        foreach ([false, true] as $strict) {
            $exact = acceptance::assess($DB, $candidate, self::COMPANY, $strict);
            $this->assertNotContains('plugin_version_mismatch', $exact['errors']);
            $this->assertSame($runtime['runtime'], $exact['runtime']);
            foreach ($runtime['warnings'] as $warning) { $this->assertContains($warning, $exact['warnings']); }
            $this->assertSame($candidate, $exact['expected_plugin_version']);
            $this->assertTrue($exact['read_only']);
            $this->assertFalse($exact['rc30_promotion_approved']);
            // A real MariaDB/Percona environment may retain runtime warnings:
            // exact source version alone must never be treated as release approval.
            foreach ([0, $candidate - 1, $candidate + 1] as $installed) {
                $mismatch = acceptance::assess($DB, $installed, self::COMPANY, $strict);
                $this->assertSame('failed', $mismatch['technical_gate']);
                $this->assertContains('plugin_version_mismatch', $mismatch['errors']);
                $this->assertSame($installed, $mismatch['installed_plugin_version']);
                $this->assertSame($exact['runtime'], $mismatch['runtime']);
                $this->assertSame($exact['warnings'], $mismatch['warnings']);
                $this->assertFalse($mismatch['rc30_promotion_approved']);
            }
        }
        $this->assertSame($writes, $DB->perf_get_writes(), 'Acceptance must issue no database writes');
        $this->assertEquals($config, $DB->get_records('config', null, 'id'));
        $this->assertEquals($pluginconfig, $DB->get_records('config_plugins', null, 'id'));
        $this->assertDebuggingNotCalled();
    }

    /** @return object fake Moodle db */
    private function fake_db(array $missingindexes = [], array $counts = [], string $family = 'mysql') {
        $manager = new class($missingindexes) {
            public $missing;
            public function __construct(array $missing) { $this->missing = $missing; }
            public function table_exists($table): bool { return true; }
            public function index_exists($table, $index): bool {
                return !in_array($index->getName(), $this->missing, true);
            }
        };
        return new class($manager, $counts, $family) {
            private $manager;
            private $counts;
            private $family;
            public function __construct($manager, array $counts, string $family) {
                $this->manager=$manager; $this->counts=$counts; $this->family=$family;
            }
            public function get_manager() { return $this->manager; }
            public function get_indexes(string $table, bool $usecache = true): array {
                $result = [];
                foreach (acceptance::REQUIRED_INDEXES as [$target, $name, $fields]) {
                    if ($target === $table && !in_array($name, $this->manager->missing, true)) {
                        $result[$name] = ['unique' => false, 'columns' => $fields];
                    }
                }
                return $result;
            }
            public function get_dbfamily(): string { return $this->family; }
            public function get_server_info(): array {
                return ['version'=>'5.7.21', 'description'=>'MySQL Community Server 5.7.21'];
            }
            public function get_field_sql(string $sql, array $params = []) {
                // Synthetic DB is intentionally scoped; exact count is 0 for other checks.
                foreach ($this->counts as $marker=>$count) {
                    if (strpos($sql, $marker) !== false) { return $count; }
                }
                return 0;
            }
        };
    }

    public function test_candidate_version_matches_acceptance_and_rejects_other_installed_versions(): void {
        $plugin = new \stdClass();
        require(__DIR__ . '/../version.php');
        $candidateversion = (int)$plugin->version;
        $this->assertSame($candidateversion, acceptance::EXPECTED_PLUGIN_VERSION);
        foreach ([false, true] as $strict) {
            $result = acceptance::assess($this->fake_db(), $candidateversion, self::COMPANY, $strict);
            $this->assertNotContains('plugin_version_mismatch', $result['errors']);
            $this->assertSame($candidateversion, $result['expected_plugin_version']);
            $this->assertFalse($result['rc30_promotion_approved']);
            foreach ([0, 2026101873, $candidateversion - 1, $candidateversion + 1] as $installed) {
                $result = acceptance::assess($this->fake_db(), $installed, self::COMPANY, $strict);
                $this->assertSame('failed', $result['technical_gate']);
                $this->assertContains('plugin_version_mismatch', $result['errors']);
                $this->assertSame($installed, $result['installed_plugin_version']);
                $this->assertFalse($result['rc30_promotion_approved']);
            }
        }
    }

    public function test_all_indexes_and_empty_scope_yield_warning_not_release_approval(): void {
        $result = acceptance::assess($this->fake_db(), acceptance::EXPECTED_PLUGIN_VERSION, self::COMPANY);
        $this->assertSame('warnings', $result['technical_gate']);
        $this->assertFalse($result['rc30_promotion_approved']);
        $this->assertTrue($result['read_only']);
        $this->assertSame(hash('sha256', strtolower(self::COMPANY)), $result['scope_digest']);
        $this->assertCount(4, $result['indexes']);
        $this->assertContains('no_tasks_in_scope', $result['warnings']);
        $this->assertSame([], $result['errors']);
    }

    public function test_strict_warnings_fail(): void {
        $result = acceptance::assess($this->fake_db(), acceptance::EXPECTED_PLUGIN_VERSION, self::COMPANY, true);
        $this->assertSame('failed', $result['technical_gate']);
    }

    public function test_missing_index_or_wrong_plugin_version_fails(): void {
        $result = acceptance::assess($this->fake_db(['lastlog_ix']), 2026101863, self::COMPANY);
        $this->assertSame('failed', $result['technical_gate']);
        $this->assertContains('plugin_version_mismatch', $result['errors']);
        $this->assertContains('missing_index_lastlog_ix', $result['errors']);
    }

    public function test_non_mysql_is_visible_warning(): void {
        $result = acceptance::assess($this->fake_db([], [], 'postgres'), acceptance::EXPECTED_PLUGIN_VERSION, self::COMPANY);
        $this->assertContains('mysql57_target_not_verified', $result['warnings']);
    }

    public function test_invalid_company_uuid_is_rejected_before_database_access(): void {
        $this->expectException(\invalid_parameter_exception::class);
        acceptance::assess($this->fake_db(), acceptance::EXPECTED_PLUGIN_VERSION, 'not-an-id');
    }

    public function test_runtime_gate_detects_mysql8_even_with_mysql_dbfamily(): void {
        $status = acceptance::runtime_status('8.1.34', 'mysql',
            ['version'=>'8.0.41', 'description'=>'MySQL 8.0']);
        $this->assertContains('mysql57_target_not_verified', $status['warnings']);
        $this->assertNotContains('php81_target_not_verified', $status['warnings']);
    }

    public function test_runtime_gate_accepts_target_family_and_patch_upgrade(): void {
        $status = acceptance::runtime_status('8.1.40', 'mysql',
            ['version'=>'5.7.44-log', 'description'=>'MySQL']);
        $this->assertSame([], $status['warnings']);
        $this->assertSame('5.7.44', $status['runtime']['db_server_version']);
    }

    public function test_runtime_gate_rejects_mariadb_compatibility_prefix(): void {
        $status = acceptance::runtime_status('8.1.34', 'mysql',
            ['version'=>'5.5.5-10.11.8-MariaDB', 'description'=>'MariaDB']);
        $this->assertContains('mysql57_target_not_verified', $status['warnings']);
    }

    public function test_runtime_gate_rejects_unverified_php_or_unknown_dbversion(): void {
        $status = acceptance::runtime_status('8.4.24', 'mysql', []);
        $this->assertContains('php81_target_not_verified', $status['warnings']);
        $this->assertContains('mysql57_target_not_verified', $status['warnings']);
        $this->assertNull($status['runtime']['db_server_version']);
    }

    public function test_duplicate_bms_items_compare_legacy_and_modern_task_identifiers(): void {
        $check = integrity::checks(self::COMPANY)['duplicate_bms_plan_items'];
        $this->assertSame(['companyuuid'=>self::COMPANY], $check['params']);
        $this->assertStringContainsString("COALESCE(NULLIF(older.externalid,''),older.taskuuid)", $check['sql']);
        $this->assertStringContainsString("COALESCE(NULLIF(bi.externalid,''),bi.taskuuid)", $check['sql']);
        $this->assertStringContainsString('older.planuuid=bi.planuuid', $check['sql']);
        $this->assertStringContainsString('older.companyuuid=bi.companyuuid', $check['sql']);
    }
}
