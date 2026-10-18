<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Schema-neutral readiness read-model for one management week.
 *
 * Readiness does not create a second lifecycle or status registry. Hard blockers mirror the
 * existing Battle Plan transition contract; indicator configuration gaps are advisory only.
 */
class battle_plan_week_readiness_service {
    public const CONTRACT_VERSION = '0.5.13';
    public const READINESS_FILTERS = [
        'blocked', 'ready_submit', 'ready_selfapprove', 'ready_activate', 'active', 'closed',
    ];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan_week_readiness',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'plans' => 'local_kbacompany_bplan',
                'plan_tasks' => 'local_kbacompany_bplanitem',
                'people' => 'company employee directory',
                'positions' => 'local_kbacompany_position + local_kbacompany_orgnode',
                'indicator_readiness' => 'battle_plan_indicator_service historical projection',
            ],
            'rules' => [
                'readiness_is_read_model_not_status_registry',
                'hard_blockers_mirror_existing_battle_plan_transition_contract',
                'draft_requires_valid_reviewer_and_at_least_one_plan_task_to_submit',
                'self_review_draft_can_selfapprove_when_other_blockers_are_clear',
                'submitted_plan_with_valid_reviewer_is_ready_for_activation',
                'critical_indicator_and_norm_gaps_are_advisory_not_transition_blockers',
                'observer_is_optional_and_never_a_readiness_blocker',
                'no_hidden_thresholds',
                'company_wide_readiness_requires_management_permission',
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

    /** Pure lifecycle classification used by the read-model and regression tests. */
    public static function classify(string $status, int $userid, int $reviewerid,
            bool $reviewervalid, int $taskcount): array {
        $blockers = [];
        $action = '';
        if (!in_array($status, battle_plan_service::STATES, true)) {
            return ['readiness' => 'blocked', 'blockers' => ['invalid_status'], 'action' => ''];
        }
        if ($status === 'closed') {
            return ['readiness' => 'closed', 'blockers' => [], 'action' => ''];
        }
        if ($status === 'active') {
            return ['readiness' => 'active', 'blockers' => [], 'action' => ''];
        }
        if ($reviewerid <= 0) {
            $blockers[] = 'missing_reviewer';
        } else if (!$reviewervalid) {
            $blockers[] = 'invalid_reviewer';
        }
        if ($status === 'draft' && $taskcount <= 0) {
            $blockers[] = 'no_tasks';
        }
        if ($blockers) {
            return ['readiness' => 'blocked', 'blockers' => $blockers, 'action' => ''];
        }
        if ($status === 'submitted') {
            return ['readiness' => 'ready_activate', 'blockers' => [], 'action' => 'approve'];
        }
        if ($reviewerid === $userid) {
            $action = 'selfapprove';
            return ['readiness' => 'ready_selfapprove', 'blockers' => [], 'action' => $action];
        }
        $action = 'submit';
        return ['readiness' => 'ready_submit', 'blockers' => [], 'action' => $action];
    }

    private static function week_bounds(string $week): array {
        $start = battle_plan_service::week_start($week !== '' ? $week : gmdate('Y-m-d'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, new \DateTimeZone('UTC'));
        return [$start, $date->modify('+6 days')->format('Y-m-d')];
    }

    private static function people(string $companyuuid, int $actor): array {
        $organization = organization_service::organization_context($companyuuid, $actor);
        $out = [];
        foreach ($organization['employees'] as $employee) {
            $out[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        return $out;
    }

    public static function context(string $companyuuid, int $actor, string $week = '',
            string $orgnodeuuid = '', string $readinessfilter = 'all'): array {
        global $DB;
        self::require_management_access($companyuuid, $actor);
        list($weekstart, $weekend) = self::week_bounds($week);
        if ($readinessfilter !== 'all' && !in_array($readinessfilter, self::READINESS_FILTERS, true)) {
            $readinessfilter = 'all';
        }

        $people = self::people($companyuuid, $actor);
        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid], 'sortorder ASC, name ASC') as $row) {
            $positions[(string)$row->uuid] = $row;
        }
        $orgnodes = [];
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid], 'sortorder ASC, name ASC') as $row) {
            $orgnodes[(string)$row->uuid] = $row;
        }

        $summary = [
            'plans' => 0, 'draft' => 0, 'blocked' => 0, 'ready_transition' => 0,
            'submitted' => 0, 'active' => 0, 'closed' => 0, 'with_advisories' => 0,
        ];
        $rows = [];
        $byunit = [];
        $plans = $DB->get_records('local_kbacompany_bplan', [
            'companyuuid' => $companyuuid, 'periodstart' => $weekstart,
        ], 'status ASC, userid ASC, positionuuid ASC, id ASC');

        foreach ($plans as $plan) {
            $position = $positions[(string)$plan->positionuuid] ?? null;
            $nodeuuid = $position ? (string)$position->orgnodeuuid : '';
            if ($orgnodeuuid !== '' && $nodeuuid !== $orgnodeuuid) { continue; }
            $node = $orgnodes[$nodeuuid] ?? null;
            $taskcount = $DB->count_records('local_kbacompany_bplanitem', [
                'companyuuid' => $companyuuid, 'planuuid' => (string)$plan->uuid,
            ]);
            $reviewerid = (int)$plan->reviewerid;
            $classification = self::classify((string)$plan->status, (int)$plan->userid,
                $reviewerid, $reviewerid > 0 && isset($people[$reviewerid]), $taskcount);

            $critical = 0;
            $criticalwithoutnorm = 0;
            foreach (battle_plan_indicator_service::projection($companyuuid, $actor,
                    (string)$plan->positionuuid, $weekstart, (string)$plan->uuid) as $indicator) {
                if (empty($indicator['critical'])) { continue; }
                $critical++;
                if (empty($indicator['norm'])) { $criticalwithoutnorm++; }
            }
            $advisories = [];
            if ($critical === 0) { $advisories[] = 'no_critical'; }
            if ($criticalwithoutnorm > 0) { $advisories[] = 'critical_without_norm'; }

            $summary['plans']++;
            if (isset($summary[(string)$plan->status])) { $summary[(string)$plan->status]++; }
            if ($classification['readiness'] === 'blocked') { $summary['blocked']++; }
            if (in_array($classification['readiness'], ['ready_submit', 'ready_selfapprove', 'ready_activate'], true)) {
                $summary['ready_transition']++;
            }
            if ($advisories) { $summary['with_advisories']++; }

            $unitkey = $nodeuuid !== '' ? $nodeuuid : '__none__';
            if (!isset($byunit[$unitkey])) {
                $byunit[$unitkey] = [
                    'uuid' => $nodeuuid,
                    'name' => $node ? (string)$node->name : '',
                    'plans' => 0, 'blocked' => 0, 'ready_transition' => 0,
                    'active' => 0, 'with_advisories' => 0,
                ];
            }
            $byunit[$unitkey]['plans']++;
            if ($classification['readiness'] === 'blocked') { $byunit[$unitkey]['blocked']++; }
            if (in_array($classification['readiness'], ['ready_submit', 'ready_selfapprove', 'ready_activate'], true)) {
                $byunit[$unitkey]['ready_transition']++;
            }
            if ((string)$plan->status === 'active') { $byunit[$unitkey]['active']++; }
            if ($advisories) { $byunit[$unitkey]['with_advisories']++; }

            if ($readinessfilter !== 'all' && $classification['readiness'] !== $readinessfilter) { continue; }
            $rows[] = [
                'uuid' => (string)$plan->uuid,
                'user_id' => (int)$plan->userid,
                'employee_name' => (string)($people[(int)$plan->userid] ?? (string)$plan->userid),
                'position' => [
                    'uuid' => (string)$plan->positionuuid,
                    'name' => $position ? (string)$position->name : (string)$plan->positionuuid,
                ],
                'orgnode' => ['uuid' => $nodeuuid, 'name' => $node ? (string)$node->name : ''],
                'status' => (string)$plan->status,
                'reviewer_id' => $reviewerid,
                'reviewer_name' => $reviewerid > 0 ? (string)($people[$reviewerid] ?? '') : '',
                'observer_id' => (int)$plan->observerid,
                'task_count' => $taskcount,
                'critical_count' => $critical,
                'critical_without_norm' => $criticalwithoutnorm,
                'readiness' => $classification['readiness'],
                'blockers' => $classification['blockers'],
                'advisories' => $advisories,
                'transition_action' => $classification['action'],
                'url' => (new \moodle_url('/local/kbacompany/battle_plan.php', [
                    'uuid' => $companyuuid, 'plan' => (string)$plan->uuid,
                ]))->out(false),
            ];
        }

        uasort($byunit, static function(array $a, array $b): int {
            if ($a['blocked'] !== $b['blocked']) { return $b['blocked'] <=> $a['blocked']; }
            return strcmp((string)$a['name'], (string)$b['name']);
        });
        usort($rows, static function(array $a, array $b): int {
            $rank = ['blocked'=>0, 'ready_activate'=>1, 'ready_selfapprove'=>2, 'ready_submit'=>3, 'active'=>4, 'closed'=>5];
            $ar = $rank[$a['readiness']] ?? 99;
            $br = $rank[$b['readiness']] ?? 99;
            if ($ar !== $br) { return $ar <=> $br; }
            return strcmp($a['employee_name'] . $a['position']['name'], $b['employee_name'] . $b['position']['name']);
        });

        $nodeoptions = [];
        foreach ($orgnodes as $node) {
            if (!(int)$node->active) { continue; }
            $nodeoptions[] = ['uuid' => (string)$node->uuid, 'name' => (string)$node->name];
        }
        return [
            'week_start' => $weekstart,
            'week_end' => $weekend,
            'orgnode_filter' => $orgnodeuuid,
            'readiness_filter' => $readinessfilter,
            'orgnodes' => $nodeoptions,
            'summary' => $summary,
            'by_unit' => array_values($byunit),
            'rows' => $rows,
            'contract' => self::contract(),
        ];
    }
}
