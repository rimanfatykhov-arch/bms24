<?php
namespace local_kbacompany;

defined('MOODLE_INTERNAL') || die();

use local_kbacompany\local\task_rc30_report_comparison_service as comparison;

/** Pure report comparison; no database or Moodle records required. */
final class task_rc30_report_comparison1867_test extends \advanced_testcase {
    private function report(string $scope = 'all'): array {
        $indexes = [];
        foreach (\local_kbacompany\local\task_rc30_acceptance_service::REQUIRED_INDEXES as [$table, $name, $fields]) {
            $indexes[$name] = ['table'=>$table, 'present'=>true];
        }
        $checks = [];
        foreach (\local_kbacompany\local\task_rc30_integrity_service::checks() as $name => $definition) {
            $checks[$name] = ['severity'=>$definition['severity'], 'count'=>0];
        }
        $checks['tasks_total']['count'] = 100;
        return [
            'schema_version'=>1,
            'scope'=>$scope,
            'read_only'=>true,
            'installation_digest'=>str_repeat('c',64), 'captured_at'=>1782800000,
            'rc30_promotion_approved'=>false,
            'strict'=>false,
            'technical_gate'=>'passed',
            'installed_plugin_version'=>2026101866,
            'expected_plugin_version'=>2026101866,
            'indexes'=>$indexes,
            'integrity_checks'=>$checks,
            'integrity_summary'=>['errors'=>0, 'warnings'=>0, 'checks'=>count($checks)],
            'runtime'=>['php'=>'8.1.34', 'dbfamily'=>'mysql', 'db_server_version'=>'5.7.21'],
            'errors'=>[], 'warnings'=>[],
        ];
    }

    /** Keep full acceptance evidence internally consistent. */
    private function normalise(array &$report): void {
        $errors = [];
        $warnings = [];
        $summary = ['errors'=>0, 'warnings'=>0, 'checks'=>count($report['integrity_checks'])];
        foreach ($report['integrity_checks'] as $name => $entry) {
            if ($entry['count'] > 0 && $entry['severity'] === 'error') { $summary['errors']++; }
            if ($entry['count'] > 0 && $entry['severity'] === 'warning') { $summary['warnings']++; }
        }
        if ($report['installed_plugin_version'] !== $report['expected_plugin_version']) {
            $errors[] = 'plugin_version_mismatch';
        }
        foreach ($report['indexes'] as $name => $entry) {
            if (!$entry['present']) { $errors[] = 'missing_index_'.$name; }
        }
        if ($summary['errors'] > 0) { $errors[] = 'data_integrity_errors'; }
        if ($summary['warnings'] > 0) { $warnings[] = 'data_integrity_warnings'; }
        if ($report['integrity_checks']['tasks_total']['count'] === 0) {
            $warnings[] = 'no_tasks_in_scope';
        }
        $report['integrity_summary'] = $summary;
        $report['errors'] = $errors;
        $report['warnings'] = $warnings;
        $report['technical_gate'] = $errors || ($report['strict'] && $warnings) ? 'failed' :
            ($warnings ? 'warnings' : 'passed');
    }

    public function test_successful_comparison_does_not_authorise_promotion(): void {
        $before = $this->report();
        $after = $this->report();
        $after['installed_plugin_version']=2026101867;
        $after['expected_plugin_version']=2026101867;
        $after['integrity_checks']['tasks_total']['count']=1000;
        $result = comparison::compare($before, $after);
        $this->assertSame('passed', $result['comparison_gate']);
        $this->assertSame([], $result['integrity_regressions']);
        $this->assertTrue($result['comparison_complete']);
        $this->assertFalse($result['rc30_promotion_approved']);
    }

    public function test_regressed_error_count_blocks_comparison(): void {
        $before=$this->report(); $after=$this->report();
        $after['integrity_checks']['orphan_task_history']['count']=2;
        $this->normalise($after);
        $result=comparison::compare($before, $after);
        $this->assertSame('failed', $result['comparison_gate']);
        $this->assertSame('orphan_task_history', $result['integrity_regressions'][0]['check']);
    }

    public function test_warning_regression_respects_strict_mode(): void {
        $before=$this->report(); $after=$this->report();
        $after['integrity_checks']['duplicate_role_assignments']['count']=2;
        $this->normalise($after);
        $this->assertSame('warnings', comparison::compare($before,$after)['comparison_gate']);
        $this->assertSame('failed', comparison::compare($before,$after,true)['comparison_gate']);
    }

    public function test_missing_index_and_new_error_are_blockers(): void {
        $before=$this->report(); $after=$this->report();
        $after['indexes']['lastlog_ix']['present']=false;
        $this->normalise($after);
        $result=comparison::compare($before,$after);
        $this->assertSame('failed', $result['comparison_gate']);
        $this->assertSame(['lastlog_ix'], $result['index_regressions']);
    }

    public function test_company_fingerprint_prevents_cross_tenant_comparison(): void {
        $before=$this->report('company'); $after=$this->report('company');
        $before['scope_digest']=str_repeat('a',64);
        $after['scope_digest']=str_repeat('b',64);
        $this->expectException(\InvalidArgumentException::class);
        comparison::compare($before,$after);
    }

    public function test_older_company_report_requires_explicit_confirmation(): void {
        $before=$this->report('company'); $after=$this->report('company');
        $this->expectException(\InvalidArgumentException::class);
        comparison::compare($before,$after);
    }

    public function test_failed_diagnostic_cannot_be_used_as_a_clean_report(): void {
        $before=$this->report(); $after=$this->report();
        $after['diagnostic_complete']=false;
        $this->expectException(\InvalidArgumentException::class);
        comparison::compare($before,$after);
    }

    public function test_runtime_change_is_reported_without_guessing_performance(): void {
        $before=$this->report(); $after=$this->report();
        $after['runtime']['php']='8.1.40';
        $after['runtime']['db_server_version']='5.7.44';
        $result=comparison::compare($before,$after);
        $this->assertSame('8.1.34', $result['runtime_changes']['php']['before']);
        $this->assertSame('5.7.44', $result['runtime_changes']['db_server_version']['after']);
        $this->assertSame('passed', $result['comparison_gate']);
    }

    public function test_missing_check_is_not_silently_ignored(): void {
        $before=$this->report(); $after=$this->report();
        unset($after['integrity_checks']['orphan_task_history']);
        $this->expectException(\InvalidArgumentException::class);
        comparison::compare($before,$after);
    }
    public function test_two_equally_incomplete_reports_are_rejected(): void {
        $before=$this->report(); $after=$this->report();
        unset($before['integrity_checks']['unknown_status']);
        unset($after['integrity_checks']['unknown_status']);
        $before['integrity_summary']['checks']--;
        $after['integrity_summary']['checks']--;
        $this->expectException(\InvalidArgumentException::class);
        comparison::compare($before,$after);
    }

    public function test_inconsistent_summary_is_rejected(): void {
        $before=$this->report(); $after=$this->report();
        $after['integrity_checks']['duplicate_role_assignments']['count']=3;
        // Tampered count but unchanged summary and gate must not pass.
        $this->expectException(\InvalidArgumentException::class);
        comparison::compare($before,$after);
    }

    public function test_version_rollback_is_rejected(): void {
        $before=$this->report(); $after=$this->report();
        $before['installed_plugin_version']=2026101868;
        $before['expected_plugin_version']=2026101868;
        $this->expectException(\InvalidArgumentException::class);
        comparison::compare($before,$after);
    }

    public function test_missing_index_in_both_reports_is_rejected(): void {
        $before=$this->report(); $after=$this->report();
        unset($before['indexes']['status_due_ix'], $after['indexes']['status_due_ix']);
        $this->expectException(\InvalidArgumentException::class);
        comparison::compare($before,$after);
    }

}
