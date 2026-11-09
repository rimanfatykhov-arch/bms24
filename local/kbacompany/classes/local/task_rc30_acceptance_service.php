<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Build 1865: RC30 read-only technical acceptance with verified runtime targets.
 *
 * A PASS is not authorization to promote RC30: UI, ACL and MySQL performance
 * must still be signed off independently on the target Moodle installation.
 */
final class task_rc30_acceptance_service {
    // Keep exact candidate identity; CI checks this against version.php.
    public const EXPECTED_PLUGIN_VERSION = 2026110904;
    public const REQUIRED_INDEXES = [
        ['local_kbacompany_task', 'status_due_ix', ['companyuuid', 'status', 'duedate']],
        ['local_kbacompany_tasklog', 'lastlog_ix', ['companyuuid', 'taskuuid', 'id']],
        ['local_kbacompany_bplanitem', 'legacy_task_ix', ['companyuuid', 'provider', 'taskuuid']],
        ['local_kbacompany_bplanitem', 'provider_ref_ix', ['companyuuid', 'provider', 'externalid']],
    ];

    /**
     * Describe the actual PHP/MySQL environment without assuming the target is installed.
     * A MySQL driver may connect to MariaDB; the server version is authoritative.
     *
     * Public/pure so it can be tested under PHP 8.4 without disguising the
     * runtime of a real Moodle CLI invocation.
     *
     * @return array{runtime:array,warnings:array}
     */
    public static function runtime_status(string $phpversion, string $dbfamily,
            array $serverinfo = []): array {
        $rawversion = (string)($serverinfo['version'] ?? '');
        $description = (string)($serverinfo['description'] ?? '');
        $version = '';
        if (preg_match('/^(\d+\.\d+\.\d+)/', $rawversion, $matches)) {
            $version = $matches[1];
        }
        $warnings = [];
        // Version family, not an individual patch build: later security updates
        // on PHP 8.1 remain eligible for the same acceptance procedure.
        if (!preg_match('/^8\.1(?:\.|$)/', $phpversion)) {
            $warnings[] = 'php81_target_not_verified';
        }
        // Some MariaDB servers announce a 5.5.5 compatibility prefix. Do not
        // mistake that for an actual 5.7 installation.
        $ismariadb = stripos($rawversion, 'mariadb') !== false ||
            stripos($description, 'mariadb') !== false;
        if ($dbfamily !== 'mysql' || $ismariadb ||
                $version === '' || !preg_match('/^5\.7\./', $version)) {
            $warnings[] = 'mysql57_target_not_verified';
        }
        return [
            'runtime'=>[
                'php'=>$phpversion,
                'dbfamily'=>$dbfamily,
                'db_server_version'=>$version !== '' ? $version : null,
                'target_php'=>'8.1',
                'target_db'=>'MySQL 5.7',
            ],
            'warnings'=>$warnings,
        ];
    }

    /** The DB handle is Moodle's $DB; no SQL mutations are performed. */
    public static function assess($db, int $installedversion, string $companyuuid = '',
            bool $strict = false, string $installationurl = ''): array {
        // Validate UUID before any expensive or globally scoped SQL.
        $checks = task_rc30_integrity_service::checks($companyuuid);
        $errors = [];
        $warnings = [];
        if ($installedversion !== self::EXPECTED_PLUGIN_VERSION) {
            $errors[] = 'plugin_version_mismatch';
        }

        $manager = $db->get_manager();
        $indexes = [];
        foreach (self::REQUIRED_INDEXES as [$table, $name, $fields]) {
            $tablename = new \xmldb_table($table);
            $exists = false;
            if ($manager->table_exists($tablename)) {
                // XMLDB index_exists compares an unordered set of columns and
                // ignores uniqueness. Acceptance needs the real ordered contract.
                foreach ($db->get_indexes($table, false) as $definition) {
                    if (array_key_exists('unique', $definition) && $definition['unique'] === false &&
                            isset($definition['columns']) && array_values($definition['columns']) === $fields) {
                        $exists = true;
                        break;
                    }
                }
            }
            $indexes[$name] = ['table'=>$table, 'present'=>$exists];
            if (!$exists) {
                $errors[] = 'missing_index_' . $name;
            }
        }

        // No table access is bypassed: integrity::scan checks every table first.
        $integrity = task_rc30_integrity_service::scan($db, $companyuuid);
        $summary = task_rc30_integrity_service::summary($integrity);
        if ($summary['errors'] > 0) {
            $errors[] = 'data_integrity_errors';
        }
        if ($summary['warnings'] > 0) {
            $warnings[] = 'data_integrity_warnings';
        }
        if ((int)($integrity['tasks_total']['count'] ?? 0) === 0) {
            // A clean empty database is not proof that real task data is healthy.
            $warnings[] = 'no_tasks_in_scope';
        }

        $family = method_exists($db, 'get_dbfamily') ? (string)$db->get_dbfamily() : 'unknown';
        $serverinfo = method_exists($db, 'get_server_info') ? $db->get_server_info() : [];
        $status = self::runtime_status(PHP_VERSION, $family, is_array($serverinfo) ? $serverinfo : []);
        $warnings = array_merge($warnings, $status['warnings']);

        $failed = count($errors) > 0 || ($strict && count($warnings) > 0);
        return [
            'schema_version'=>1,
            'scope'=>$companyuuid === '' ? 'all' : 'company',
            'scope_digest'=>$companyuuid === '' ? null : hash('sha256', strtolower($companyuuid)),
            'read_only'=>true,
            // 1870: origin and chronology are verified before comparing two snapshots.
            // The canonical URL is never written into the evidence.
            'installation_digest'=>$installationurl !== '' ?
                hash('sha256', strtolower(rtrim(trim($installationurl), '/'))) : null,
            'captured_at'=>time(),
            'strict'=>$strict,
            'technical_gate'=>$failed ? 'failed' : (count($warnings) ? 'warnings' : 'passed'),
            'rc30_promotion_approved'=>false,
            'installed_plugin_version'=>$installedversion,
            'expected_plugin_version'=>self::EXPECTED_PLUGIN_VERSION,
            'runtime'=>$status['runtime'],
            'indexes'=>$indexes,
            'integrity_summary'=>$summary,
            'integrity_checks'=>$integrity,
            'errors'=>$errors,
            'warnings'=>$warnings,
            'manual_acceptance_required'=>[
                'moodle_phpunit', 'acl_company_isolation', 'task_workflow',
                'kanban_drag_and_drop', 'battle_plan_lifecycle',
                'mysql57_explain_and_latency', 'backup_restore',
            ],
        ];
    }
}
