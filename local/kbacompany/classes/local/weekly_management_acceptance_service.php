<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stabilization / Weekly Management Acceptance read-model.
 *
 * Acceptance is computed, never stored. It combines existing coverage, readiness and
 * management overview projections with a small set of technical integrity invariants.
 */
class weekly_management_acceptance_service {
    public const CONTRACT_VERSION = '0.5.15';
    public const STATES = ['blocked', 'accepted_with_attention', 'accepted'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_weekly_management_acceptance',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'coverage' => 'battle_plan_coverage_service',
                'readiness' => 'battle_plan_week_readiness_service',
                'overview' => 'battle_plan_management_overview_service',
                'plans' => 'local_kbacompany_bplan',
                'plan_items' => 'local_kbacompany_bplanitem',
                'tasks' => 'local_kbacompany_task',
            ],
            'rules' => [
                'acceptance_is_computed_not_persisted',
                'hard_fail_missing_weekly_plan',
                'hard_fail_blocked_battle_plan',
                'hard_fail_pending_draft_or_submitted_plan',
                'hard_fail_duplicate_weekly_plan_key',
                'hard_fail_orphan_battle_plan_item',
                'indicator_configuration_gaps_are_attention_not_lifecycle_rules',
                'management_exceptions_and_overdue_tasks_are_attention_not_integrity_failure',
                'no_hidden_percentage_or_age_thresholds',
                'company_wide_acceptance_requires_management_permission',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function require_management_access(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid, $actor);
        if (!task_service::can_manage($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
    }

    private static function integrity(string $companyuuid, string $weekstart): array {
        global $DB;
        $params = ['companyuuid'=>$companyuuid, 'periodstart'=>$weekstart];
        $duplicatesql = "SELECT COUNT(1)
                           FROM (
                                SELECT userid, positionuuid, periodstart, COUNT(1) AS cnt
                                  FROM {local_kbacompany_bplan}
                                 WHERE companyuuid = :companyuuid AND periodstart = :periodstart
                              GROUP BY userid, positionuuid, periodstart
                                HAVING COUNT(1) > 1
                           ) dup";
        $duplicate = (int)$DB->count_records_sql($duplicatesql, $params);

        $orphansql = "SELECT COUNT(i.id)
                        FROM {local_kbacompany_bplanitem} i
                   LEFT JOIN {local_kbacompany_bplan} p
                          ON p.companyuuid = i.companyuuid AND p.uuid = i.planuuid
                       WHERE i.companyuuid = :companyuuid2
                         AND p.id IS NULL";
        $orphanplan = (int)$DB->count_records_sql($orphansql, ['companyuuid2'=>$companyuuid]);

        $orphantasksql = "SELECT COUNT(i.id)
                            FROM {local_kbacompany_bplanitem} i
                            JOIN {local_kbacompany_bplan} p
                              ON p.companyuuid = i.companyuuid AND p.uuid = i.planuuid
                       LEFT JOIN {local_kbacompany_task} t
                              ON t.companyuuid = i.companyuuid AND t.uuid = i.taskuuid
                           WHERE i.companyuuid = :companyuuid3
                             AND p.periodstart = :periodstart3
                             AND i.provider = :provider
                             AND t.id IS NULL";
        $orphantask = (int)$DB->count_records_sql($orphantasksql, [
            'companyuuid3'=>$companyuuid, 'periodstart3'=>$weekstart,
            'provider'=>'bms',
        ]);
        return [
            'duplicate_weekly_plans'=>$duplicate,
            'orphan_plan_items'=>$orphanplan,
            'missing_canonical_tasks'=>$orphantask,
        ];
    }


    /** Pure acceptance classification for regression tests and UI. */
    public static function classify(array $gates): array {
        $hard = 0;
        $attention = 0;
        foreach ($gates as $gate) {
            $count = max(0, (int)($gate['count'] ?? 0));
            if ($count <= 0) { continue; }
            if ((string)($gate['level'] ?? '') === 'hard') { $hard += $count; }
            else { $attention += $count; }
        }
        $state = $hard > 0 ? 'blocked' : ($attention > 0 ? 'accepted_with_attention' : 'accepted');
        return ['state'=>$state, 'hard_count'=>$hard, 'attention_count'=>$attention];
    }

    private static function gate(string $code, string $level, int $count): array {
        return ['code'=>$code, 'level'=>$level, 'count'=>$count, 'passed'=>$count === 0];
    }

    public static function context(string $companyuuid, int $actor, string $week = '', string $orgnodeuuid = ''): array {
        self::require_management_access($companyuuid, $actor);
        $weekstart = battle_plan_service::week_start($week !== '' ? $week : gmdate('Y-m-d'));
        $coverage = battle_plan_coverage_service::context($companyuuid, $actor, $weekstart, $orgnodeuuid, 'all');
        $readiness = battle_plan_week_readiness_service::context($companyuuid, $actor, $weekstart, $orgnodeuuid, 'all');
        $overview = battle_plan_management_overview_service::context($companyuuid, $actor, $weekstart, $orgnodeuuid, 0, 'all');
        $integrity = self::integrity($companyuuid, $weekstart);

        $gates = [
            self::gate('missing_plans', 'hard', (int)$coverage['summary']['missing_plans']),
            self::gate('blocked_plans', 'hard', (int)$readiness['summary']['blocked']),
            self::gate('draft_plans', 'hard', (int)$readiness['summary']['draft']),
            self::gate('submitted_plans', 'hard', (int)$readiness['summary']['submitted']),
            self::gate('duplicate_weekly_plans', 'hard', (int)$integrity['duplicate_weekly_plans']),
            self::gate('orphan_plan_items', 'hard', (int)$integrity['orphan_plan_items']),
            self::gate('missing_canonical_tasks', 'hard', (int)$integrity['missing_canonical_tasks']),
            self::gate('positions_without_critical', 'attention', (int)$coverage['summary']['positions_without_critical']),
            self::gate('critical_without_norm', 'attention', (int)$coverage['summary']['critical_without_norm']),
            self::gate('needs_decision', 'attention', (int)$overview['summary']['needs_decision']),
            self::gate('overdue_tasks', 'attention', (int)$overview['summary']['overdue_tasks']),
        ];

        $classification = self::classify($gates);

        return [
            'company'=>company_context_service::context($companyuuid, $actor),
            'week_start'=>$coverage['week_start'], 'week_end'=>$coverage['week_end'],
            'orgnode_filter'=>$orgnodeuuid, 'orgnodes'=>$coverage['orgnodes'],
            'state'=>$classification['state'], 'hard_count'=>$classification['hard_count'], 'attention_count'=>$classification['attention_count'],
            'gates'=>$gates, 'coverage'=>$coverage['summary'], 'readiness'=>$readiness['summary'],
            'overview'=>$overview['summary'], 'integrity'=>$integrity, 'contract'=>self::contract(),
        ];
    }
}
