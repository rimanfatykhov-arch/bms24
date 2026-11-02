<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * RC30 preflight for the canonical task tables (build2026101860).
 *
 * Read-only, count-only queries. No employee names, task titles, payloads or UUIDs
 * are emitted. A single company scope is preferred to avoid large DB scans.
 */
final class task_rc30_integrity_service {
    public const TABLES = [
        'local_kbacompany_task',
        'local_kbacompany_taskcheck',
        'local_kbacompany_tasklog',
        'local_kbacompany_bplan',
        'local_kbacompany_bplanitem',
        'local_kbacompany_aclrole',
        'local_kbacompany_aclgrant',
        'local_kbacompany_aclassign',
    ];

    /**
     * Named, parameterised SQL statements, scoped by the source row's company.
     * A joined row ALWAYS has to share both UUID and company UUID.
     *
     * @return array<string,array{severity:string,sql:string,params:array}>
     */
    public static function checks(string $companyuuid = ''): array {
        if ($companyuuid !== '' && !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $companyuuid)) {
            throw new \invalid_parameter_exception('Invalid company UUID');
        }
        $scope = static function(string $alias) use ($companyuuid): array {
            return $companyuuid === '' ? ['', []] : [" AND {$alias}.companyuuid = :companyuuid", ['companyuuid'=>$companyuuid]];
        };
        [$taskwhere, $taskparams] = $scope('t');
        [$checkwhere, $checkparams] = $scope('c');
        [$logwhere, $logparams] = $scope('l');
        [$itemwhere, $itemparams] = $scope('bi');
        [$grantwhere, $grantparams] = $scope('g');
        [$assignwhere, $assignparams] = $scope('a');
        // Only literal, whitelisted statuses are emitted into SQL.
        $validstatuses = "'open','doing','review','returned','done','cancelled','deleted'";
        return [
            'tasks_total' => [
                'severity'=>'info',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t WHERE 1=1{$taskwhere}",
                'params'=>$taskparams,
            ],
            'unknown_status' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t WHERE t.status NOT IN ({$validstatuses}){$taskwhere}",
                'params'=>$taskparams,
            ],
            // Duplicate UUIDs are counted as surplus records, not entire matching pairs.
            'duplicate_task_uuid' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t
                    WHERE EXISTS (SELECT 1 FROM {local_kbacompany_task} older
                        WHERE older.companyuuid=t.companyuuid AND older.uuid=t.uuid AND older.id<t.id){$taskwhere}",
                'params'=>$taskparams,
            ],
            'self_parent_task' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t
                    WHERE t.parentuuid<>'' AND t.parentuuid=t.uuid{$taskwhere}",
                'params'=>$taskparams,
            ],
            'negative_task_minutes' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t
                    WHERE (t.planminutes<0 OR t.factminutes<0){$taskwhere}",
                'params'=>$taskparams,
            ],
            'reversed_task_dates' => [
                'severity'=>'warning',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t
                    WHERE t.startdate<>'' AND t.duedate<>'' AND t.startdate>t.duedate{$taskwhere}",
                'params'=>$taskparams,
            ],
            'invalid_revision' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t WHERE t.revision < 1{$taskwhere}",
                'params'=>$taskparams,
            ],
            'missing_expected_product' => [
                'severity'=>'warning',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t WHERE t.status NOT IN ('cancelled','deleted') AND TRIM(t.product) = ''{$taskwhere}",
                'params'=>$taskparams,
            ],
            'missing_parent_task' => [
                'severity'=>'warning',
                'sql'=>"SELECT COUNT(t.id) FROM {local_kbacompany_task} t
                    LEFT JOIN {local_kbacompany_task} parent
                        ON parent.uuid=t.parentuuid AND parent.companyuuid=t.companyuuid
                    WHERE t.parentuuid <> '' AND parent.id IS NULL{$taskwhere}",
                'params'=>$taskparams,
            ],
            'orphan_checklists' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(c.id) FROM {local_kbacompany_taskcheck} c
                    LEFT JOIN {local_kbacompany_task} t
                        ON t.uuid=c.taskuuid AND t.companyuuid=c.companyuuid
                    WHERE t.id IS NULL{$checkwhere}",
                'params'=>$checkparams,
            ],
            'orphan_task_history' => [
                'severity'=>'warning',
                'sql'=>"SELECT COUNT(l.id) FROM {local_kbacompany_tasklog} l
                    LEFT JOIN {local_kbacompany_task} t
                        ON t.uuid=l.taskuuid AND t.companyuuid=l.companyuuid
                    WHERE t.id IS NULL{$logwhere}",
                'params'=>$logparams,
            ],
            'missing_battle_plan' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(bi.id) FROM {local_kbacompany_bplanitem} bi
                    LEFT JOIN {local_kbacompany_bplan} bp
                        ON bp.uuid=bi.planuuid AND bp.companyuuid=bi.companyuuid
                    WHERE bp.id IS NULL{$itemwhere}",
                'params'=>$itemparams,
            ],
            // The canonical BMS provider saves taskuuid and externalid as the same UUID.
            'mismatched_bms_identifiers' => [
                'severity'=>'warning',
                'sql'=>"SELECT COUNT(bi.id) FROM {local_kbacompany_bplanitem} bi
                    WHERE bi.provider='bms' AND bi.externalid<>'' AND bi.taskuuid<>''
                      AND bi.externalid<>bi.taskuuid{$itemwhere}",
                'params'=>$itemparams,
            ],
            'duplicate_bms_plan_items' => [
                'severity'=>'error',
                // Canonical task key works for modern, legacy, and mixed-format links.
                // A duplicate must match the same company AND the same Battle Plan.
                'sql'=>"SELECT COUNT(bi.id) FROM {local_kbacompany_bplanitem} bi
                    WHERE bi.provider='bms'
                      AND COALESCE(NULLIF(bi.externalid,''),bi.taskuuid)<>''
                      AND EXISTS (SELECT 1 FROM {local_kbacompany_bplanitem} older
                          WHERE older.companyuuid=bi.companyuuid AND older.planuuid=bi.planuuid
                            AND older.provider=bi.provider
                            AND COALESCE(NULLIF(older.externalid,''),older.taskuuid)
                                =COALESCE(NULLIF(bi.externalid,''),bi.taskuuid)
                            AND older.id<bi.id){$itemwhere}",
                'params'=>$itemparams,
            ],
            'missing_bms_task_link' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(bi.id) FROM {local_kbacompany_bplanitem} bi
                    LEFT JOIN {local_kbacompany_task} t
                        ON t.companyuuid=bi.companyuuid
                        AND t.uuid=COALESCE(NULLIF(bi.externalid,''),bi.taskuuid)
                    WHERE bi.provider='bms' AND t.id IS NULL{$itemwhere}",
                'params'=>$itemparams,
            ],
            'orphan_role_grants' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(g.id) FROM {local_kbacompany_aclgrant} g
                    LEFT JOIN {local_kbacompany_aclrole} r
                        ON r.id=g.roleid AND r.companyuuid=g.companyuuid
                    WHERE r.id IS NULL{$grantwhere}",
                'params'=>$grantparams,
            ],
            'duplicate_role_assignments' => [
                'severity'=>'warning',
                'sql'=>"SELECT COUNT(a.id) FROM {local_kbacompany_aclassign} a
                    WHERE EXISTS (SELECT 1 FROM {local_kbacompany_aclassign} older
                        WHERE older.companyuuid=a.companyuuid AND older.roleid=a.roleid
                          AND older.userid=a.userid AND older.id<a.id){$assignwhere}",
                'params'=>$assignparams,
            ],
            'orphan_role_assignments' => [
                'severity'=>'error',
                'sql'=>"SELECT COUNT(a.id) FROM {local_kbacompany_aclassign} a
                    LEFT JOIN {local_kbacompany_aclrole} r
                        ON r.id=a.roleid AND r.companyuuid=a.companyuuid
                    WHERE r.id IS NULL{$assignwhere}",
                'params'=>$assignparams,
            ],
        ];
    }

    /** Execute only the predefined SELECT COUNT checks; fail on missing schemas or SQL errors. */
    public static function scan($db, string $companyuuid = ''): array {
        $checks = self::checks($companyuuid);
        $manager = $db->get_manager();
        foreach (self::TABLES as $table) {
            if (!$manager->table_exists(new \xmldb_table($table))) {
                throw new \coding_exception('RC30 schema missing: ' . $table);
            }
        }
        $results = [];
        foreach ($checks as $name => $check) {
            $quantity = (int)$db->get_field_sql($check['sql'], $check['params']);
            $results[$name] = ['severity'=>$check['severity'], 'count'=>max(0, $quantity)];
        }
        return $results;
    }

    public static function summary(array $results): array {
        $counts = ['errors'=>0, 'warnings'=>0, 'checks'=>count($results)];
        foreach ($results as $name => $item) {
            if ($name === 'tasks_total' || (int)($item['count'] ?? 0) <= 0) { continue; }
            if (($item['severity'] ?? '') === 'error') { $counts['errors']++; }
            if (($item['severity'] ?? '') === 'warning') { $counts['warnings']++; }
        }
        return $counts;
    }
}
