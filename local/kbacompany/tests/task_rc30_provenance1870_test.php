<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_rc30_acceptance_service as acceptance;
use local_kbacompany\local\task_rc30_integrity_service as integrity;
use local_kbacompany\local\task_rc30_report_comparison_service as comparison;

/** RC30 evidence must not be accepted across installations or backward in time. */
final class task_rc30_provenance1870_test extends \advanced_testcase {
    private function report(): array {
        $indexes = [];
        foreach (acceptance::REQUIRED_INDEXES as [$table, $index, $fields]) {
            $indexes[$index] = ['table' => $table, 'present' => true];
        }
        $checks = [];
        foreach (integrity::checks() as $name => $definition) {
            $checks[$name] = ['severity' => $definition['severity'], 'count' => 0];
        }
        $checks['tasks_total']['count'] = 120;
        return [
            'schema_version' => 1, 'scope' => 'all', 'read_only' => true,
            'installation_digest' => hash('sha256', 'https://edu.kzn.mba'),
            'captured_at' => 1782800000,
            'rc30_promotion_approved' => false, 'strict' => false,
            'installed_plugin_version' => 2026101870,
            'expected_plugin_version' => 2026101870,
            'technical_gate' => 'passed',
            'indexes' => $indexes,
            'integrity_checks' => $checks,
            'integrity_summary' => ['checks' => count($checks), 'errors' => 0, 'warnings' => 0],
            'runtime' => ['php' => '8.1.34', 'dbfamily' => 'mysql',
                'db_server_version' => '5.7.21'],
            'errors' => [], 'warnings' => [],
        ];
    }

    public function test_matching_origin_and_forward_time_pass(): void {
        $before = $this->report();
        $after = $this->report();
        $after['captured_at'] += 60;
        $result = comparison::compare($before, $after, true);
        $this->assertSame('passed', $result['comparison_gate']);
        $this->assertTrue($result['installation_verified']);
        $this->assertTrue($result['chronology_verified']);
        $this->assertFalse($result['rc30_promotion_approved']);
    }

    public function test_other_installation_is_rejected(): void {
        $before = $this->report();
        $after = $this->report();
        $after['installation_digest'] = hash('sha256', 'https://other.kzn.mba');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_installation_mismatch');
        comparison::compare($before, $after);
    }

    public function test_backdated_snapshot_is_rejected(): void {
        $before = $this->report();
        $after = $this->report();
        $after['captured_at']--;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_time_rollback');
        comparison::compare($before, $after);
    }

    public function test_legacy_report_is_explicitly_unverified(): void {
        $before = $this->report();
        unset($before['installation_digest'], $before['captured_at']);
        $after = $this->report();
        $result = comparison::compare($before, $after);
        $this->assertSame('warnings', $result['comparison_gate']);
        $this->assertSame(['legacy_installation_unverified', 'legacy_chronology_unverified'],
            $result['provenance_warnings']);
        $this->assertSame('failed', comparison::compare($before, $after, true)['comparison_gate']);
    }
}
