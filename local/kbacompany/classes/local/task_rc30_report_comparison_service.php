<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * build2026101867: compare two completed RC30 acceptance reports without a DB connection.
 * Does not output UUIDs, file paths, task titles, or other business data.
 */
final class task_rc30_report_comparison_service {
    public const MAX_REPORT_BYTES = 2097152;

    /** Read a bounded JSON evidence file. Error codes, not file paths, leave this layer. */
    public static function read_report(string $path): array {
        if ($path === '' || !is_file($path) || !is_readable($path) ||
                is_link($path) || filesize($path) === false ||
                filesize($path) > self::MAX_REPORT_BYTES) {
            throw new \InvalidArgumentException('report_file_unavailable');
        }
        $data = file_get_contents($path);
        if ($data === false || strlen($data) > self::MAX_REPORT_BYTES) {
            throw new \InvalidArgumentException('report_file_unavailable');
        }
        try {
            $report = json_decode($data, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('report_invalid_json');
        }
        if (!is_array($report)) {
            throw new \InvalidArgumentException('report_invalid_schema');
        }
        return $report;
    }

    /**
     * Validate a COMPLETE RC30 technical-acceptance report. A comparison of two
     * equally incomplete files must never produce a reassuring PASS.
     * The sets are obtained from the authoritative read-only diagnostics and
     * required-index specification, not from an untrusted JSON report.
     */
    private static function validate(array $report): void {
        if (($report['schema_version'] ?? null) !== 1 ||
                !in_array($report['scope'] ?? null, ['all', 'company'], true) ||
                ($report['read_only'] ?? null) !== true ||
                ($report['rc30_promotion_approved'] ?? null) !== false ||
                ($report['diagnostic_complete'] ?? true) === false ||
                !in_array($report['technical_gate'] ?? null, ['passed', 'warnings', 'failed'], true) ||
                !is_bool($report['strict'] ?? null) ||
                !is_int($report['installed_plugin_version'] ?? null) ||
                !is_int($report['expected_plugin_version'] ?? null) ||
                !is_array($report['indexes'] ?? null) ||
                !is_array($report['integrity_checks'] ?? null) ||
                !is_array($report['integrity_summary'] ?? null) ||
                !is_array($report['errors'] ?? null) ||
                !is_array($report['warnings'] ?? null) ||
                !is_array($report['runtime'] ?? null)) {
            throw new \InvalidArgumentException('report_invalid_schema');
        }
        if ($report['installed_plugin_version'] < 1 || $report['expected_plugin_version'] < 1) {
            throw new \InvalidArgumentException('report_invalid_schema');
        }
        foreach (['errors', 'warnings'] as $key) {
            if (!array_is_list($report[$key]) || count($report[$key]) !== count(array_unique($report[$key]))) {
                throw new \InvalidArgumentException('report_invalid_schema');
            }
            foreach ($report[$key] as $value) {
                if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_]{0,95}$/D', $value)) {
                    throw new \InvalidArgumentException('report_invalid_schema');
                }
            }
        }

        $requiredindexes = [];
        foreach (task_rc30_acceptance_service::REQUIRED_INDEXES as [$tablename, $indexname, $fields]) {
            $requiredindexes[$indexname] = $tablename;
        }
        // No omitted/extra indexes are silently accepted by the RC30 gate.
        $presentindexnames = array_keys($report['indexes']);
        sort($presentindexnames);
        $expectedindexnames = array_keys($requiredindexes);
        sort($expectedindexnames);
        if ($presentindexnames !== $expectedindexnames) {
            throw new \InvalidArgumentException('report_checks_missing');
        }
        foreach ($requiredindexes as $name => $table) {
            $item = $report['indexes'][$name];
            if (!is_array($item) || ($item['table'] ?? null) !== $table ||
                    !is_bool($item['present'] ?? null)) {
                throw new \InvalidArgumentException('report_invalid_schema');
            }
        }

        $canonicalchecks = task_rc30_integrity_service::checks();
        $presentchecknames = array_keys($report['integrity_checks']);
        sort($presentchecknames);
        $expectedchecknames = array_keys($canonicalchecks);
        sort($expectedchecknames);
        if ($presentchecknames !== $expectedchecknames) {
            throw new \InvalidArgumentException('report_checks_missing');
        }
        $expectedsummary = ['errors'=>0, 'warnings'=>0, 'checks'=>count($canonicalchecks)];
        foreach ($canonicalchecks as $name => $check) {
            $item = $report['integrity_checks'][$name];
            if (!is_array($item) || ($item['severity'] ?? null) !== $check['severity'] ||
                    !is_int($item['count'] ?? null) || $item['count'] < 0) {
                throw new \InvalidArgumentException('report_checks_changed');
            }
            if ($item['count'] > 0) {
                if ($item['severity'] === 'error') { $expectedsummary['errors']++; }
                if ($item['severity'] === 'warning') { $expectedsummary['warnings']++; }
            }
        }
        foreach ($expectedsummary as $key => $value) {
            if (($report['integrity_summary'][$key] ?? null) !== $value) {
                throw new \InvalidArgumentException('report_summary_mismatch');
            }
        }
        // Prevent manual editing of a failure into a passed report. A version
        // mismatch, missing index or nonzero diagnostic failure must be visible.
        $expectederrors = [];
        $expectedwarnings = [];
        if ($report['installed_plugin_version'] !== $report['expected_plugin_version']) {
            $expectederrors[] = 'plugin_version_mismatch';
        }
        foreach ($requiredindexes as $name => $table) {
            if (!$report['indexes'][$name]['present']) {
                $expectederrors[] = 'missing_index_' . $name;
            }
        }
        if ($expectedsummary['errors'] > 0) { $expectederrors[] = 'data_integrity_errors'; }
        if ($expectedsummary['warnings'] > 0) { $expectedwarnings[] = 'data_integrity_warnings'; }
        if ($report['integrity_checks']['tasks_total']['count'] === 0) {
            $expectedwarnings[] = 'no_tasks_in_scope';
        }
        foreach ($expectederrors as $code) {
            if (!in_array($code, $report['errors'], true)) {
                throw new \InvalidArgumentException('report_summary_mismatch');
            }
        }
        foreach ($expectedwarnings as $code) {
            if (!in_array($code, $report['warnings'], true)) {
                throw new \InvalidArgumentException('report_summary_mismatch');
            }
        }
        $failed = $report['errors'] !== [] || ($report['strict'] && $report['warnings'] !== []);
        $expectedgate = $failed ? 'failed' : ($report['warnings'] !== [] ? 'warnings' : 'passed');
        if ($report['technical_gate'] !== $expectedgate) {
            throw new \InvalidArgumentException('report_summary_mismatch');
        }
        // Runtime values go to the diff report: allow bounded version tokens,
        // never arbitrary strings that could contain SQL, paths or private data.
        foreach (['php', 'dbfamily', 'db_server_version'] as $field) {
            $value = $report['runtime'][$field] ?? null;
            if ($value !== null &&
                    (!is_string($value) ||
                    !preg_match('/^[a-zA-Z0-9._-]{1,60}$/D', $value))) {
                throw new \InvalidArgumentException('report_invalid_schema');
            }
        }
        if (!is_string($report['runtime']['php'] ?? null) ||
                !is_string($report['runtime']['dbfamily'] ?? null)) {
            throw new \InvalidArgumentException('report_invalid_schema');
        }
        $runtimecheck = task_rc30_acceptance_service::runtime_status(
            $report['runtime']['php'], $report['runtime']['dbfamily'],
            ['version'=>(string)($report['runtime']['db_server_version'] ?? '')]);
        foreach ($runtimecheck['warnings'] as $code) {
            if (!in_array($code, $report['warnings'], true)) {
                throw new \InvalidArgumentException('report_summary_mismatch');
            }
        }
        // Optional only for pre-1870 snapshots; malformed new metadata is rejected.
        if (array_key_exists('installation_digest', $report) &&
                $report['installation_digest'] !== null &&
                (!is_string($report['installation_digest']) ||
                !preg_match('/^[a-f0-9]{64}$/D', $report['installation_digest']))) {
            throw new \InvalidArgumentException('report_invalid_schema');
        }
        if (array_key_exists('captured_at', $report) &&
                (!is_int($report['captured_at']) || $report['captured_at'] <= 0)) {
            throw new \InvalidArgumentException('report_invalid_schema');
        }
        if (isset($report['scope_digest']) &&
                (!is_string($report['scope_digest']) ||
                !preg_match('/^[a-f0-9]{64}$/D', $report['scope_digest']))) {
            throw new \InvalidArgumentException('report_invalid_schema');
        }
    }

    private static function safe_name($value): bool {
        return is_string($value) && (bool)preg_match('/^[a-z][a-z0-9_]{0,95}$/D', $value);
    }

    /**
     * Compare two complete evidence reports. Positive change in a violation
     * count is a regression; decreases are recoveries. No task data is copied.
     */
    public static function compare(array $baseline, array $current, bool $strict = false,
            bool $confirmedcompany = false): array {
        self::validate($baseline);
        self::validate($current);
        if ($baseline['scope'] !== $current['scope']) {
            throw new \InvalidArgumentException('report_scope_mismatch');
        }
        if ($current['installed_plugin_version'] < $baseline['installed_plugin_version']) {
            // A roll-back can appear clean if both snapshots have zero defects.
            throw new \InvalidArgumentException('report_version_rollback');
        }
        // 1870: a healthy test installation must never certify a production
        // installation. Older snapshots remain readable but are explicitly
        // unverified; in strict mode they block the technical comparison gate.
        $baselineorigin = $baseline['installation_digest'] ?? null;
        $currentorigin = $current['installation_digest'] ?? null;
        $installationverified = is_string($baselineorigin) && is_string($currentorigin);
        if ($installationverified && !hash_equals($baselineorigin, $currentorigin)) {
            throw new \InvalidArgumentException('report_installation_mismatch');
        }
        $baselinetime = $baseline['captured_at'] ?? null;
        $currenttime = $current['captured_at'] ?? null;
        $chronologyverified = is_int($baselinetime) && is_int($currenttime);
        if ($chronologyverified && $currenttime < $baselinetime) {
            throw new \InvalidArgumentException('report_time_rollback');
        }
        $provenancewarnings = [];
        if (!$installationverified) { $provenancewarnings[] = 'legacy_installation_unverified'; }
        if (!$chronologyverified) { $provenancewarnings[] = 'legacy_chronology_unverified'; }
        if ($current['scope'] === 'company') {
            $baseid = $baseline['scope_digest'] ?? null;
            $currentid = $current['scope_digest'] ?? null;
            if ($baseid !== null && $currentid !== null) {
                if (!hash_equals($baseid, $currentid)) {
                    throw new \InvalidArgumentException('report_scope_mismatch');
                }
            } else if (!$confirmedcompany) {
                // Older reports do not identify the company; don't silently
                // compare two unrelated tenants even if their scope is 'company'.
                throw new \InvalidArgumentException('report_company_unverified');
            }
        }

        $newerrors = array_values(array_diff($current['errors'], $baseline['errors']));
        $resolvederrors = array_values(array_diff($baseline['errors'], $current['errors']));
        $newwarnings = array_values(array_diff($current['warnings'], $baseline['warnings']));
        $resolvedwarnings = array_values(array_diff($baseline['warnings'], $current['warnings']));
        sort($newerrors); sort($resolvederrors); sort($newwarnings); sort($resolvedwarnings);

        $indexregressions = [];
        $indexrecoveries = [];
        foreach ($baseline['indexes'] as $name => $item) {
            if (!isset($current['indexes'][$name])) {
                throw new \InvalidArgumentException('report_checks_missing');
            }
            $before = $item['present'];
            $after = $current['indexes'][$name]['present'];
            if ($before && !$after) { $indexregressions[] = $name; }
            if (!$before && $after) { $indexrecoveries[] = $name; }
        }
        foreach ($current['indexes'] as $name => $item) {
            if (!isset($baseline['indexes'][$name]) && !$item['present']) {
                $indexregressions[] = $name;
            }
        }
        sort($indexregressions); sort($indexrecoveries);

        $regressions = [];
        $improvements = [];
        foreach ($baseline['integrity_checks'] as $name => $item) {
            if (!isset($current['integrity_checks'][$name])) {
                throw new \InvalidArgumentException('report_checks_missing');
            }
        }
        foreach ($current['integrity_checks'] as $name => $item) {
            $prior = $baseline['integrity_checks'][$name] ?? null;
            $before = (int)($prior['count'] ?? 0);
            $after = $item['count'];
            if ($prior !== null && $prior['severity'] !== $item['severity']) {
                throw new \InvalidArgumentException('report_checks_changed');
            }
            // tasks_total is a population statistic, not a defect count.
            if ($name === 'tasks_total' || $item['severity'] === 'info') { continue; }
            $delta = ['check'=>$name, 'severity'=>$item['severity'],
                'before'=>$before, 'after'=>$after];
            if ($after > $before) { $regressions[] = $delta; }
            else if ($after < $before) { $improvements[] = $delta; }
        }
        usort($regressions, function($a, $b) { return strcmp($a['check'], $b['check']); });
        usort($improvements, function($a, $b) { return strcmp($a['check'], $b['check']); });

        // A decrease in the total number of visible tasks can be legitimate,
        // but must not silently yield PASS: it can also mean a broken company
        // scope, filter or a loss of tasks after deployment. Unlike defect
        // categories, tasks_total is a population counter. Flag a decrease as
        // a warning for explicit review and as blocking under --strict.
        $beforetasks = $baseline['integrity_checks']['tasks_total']['count'];
        $aftertasks = $current['integrity_checks']['tasks_total']['count'];
        $taskdelta = $aftertasks - $beforetasks;
        $populationchange = [
            'before'=>$beforetasks,
            'after'=>$aftertasks,
            'delta'=>$taskdelta,
            'decreased'=>$taskdelta < 0,
            'review_required'=>$taskdelta < 0,
        ];

        $runtimechanges = [];
        foreach (['php', 'dbfamily', 'db_server_version'] as $field) {
            $before = $baseline['runtime'][$field] ?? null;
            $after = $current['runtime'][$field] ?? null;
            if ($before !== $after) {
                $runtimechanges[$field] = ['before'=>$before, 'after'=>$after];
            }
        }

        $critical = count($newerrors) > 0 || count($indexregressions) > 0 ||
            $current['technical_gate'] === 'failed';
        $warning = count($newwarnings) > 0 || $current['technical_gate'] === 'warnings';
        if ($populationchange['review_required'] || $provenancewarnings) { $warning = true; }
        foreach ($regressions as $item) {
            if ($item['severity'] === 'error') { $critical = true; }
            else { $warning = true; }
        }
        $status = $critical || ($strict && $warning) ? 'failed' : ($warning ? 'warnings' : 'passed');
        return [
            'schema_version'=>1,
            'comparison_complete'=>true,
            'read_only'=>true,
            'rc30_promotion_approved'=>false,
            'scope'=>$current['scope'],
            'installation_verified'=>$installationverified,
            'chronology_verified'=>$chronologyverified,
            'provenance_warnings'=>$provenancewarnings,
            'baseline_captured_at'=>$baselinetime,
            'current_captured_at'=>$currenttime,
            'company_scope_verified'=>$current['scope'] === 'all' ||
                (isset($baseline['scope_digest'], $current['scope_digest']) &&
                    $baseline['scope_digest'] === $current['scope_digest']),
            'strict'=>$strict,
            'baseline_plugin_version'=>$baseline['installed_plugin_version'],
            'current_plugin_version'=>$current['installed_plugin_version'],
            'baseline_gate'=>$baseline['technical_gate'],
            'current_gate'=>$current['technical_gate'],
            'comparison_gate'=>$status,
            'new_error_codes'=>$newerrors,
            'resolved_error_codes'=>$resolvederrors,
            'new_warning_codes'=>$newwarnings,
            'resolved_warning_codes'=>$resolvedwarnings,
            'index_regressions'=>$indexregressions,
            'index_recoveries'=>$indexrecoveries,
            'runtime_changes'=>$runtimechanges,
            'integrity_regressions'=>$regressions,
            'integrity_improvements'=>$improvements,
            'task_population_change'=>$populationchange,
            'manual_acceptance_required'=>true,
        ];
    }
}
