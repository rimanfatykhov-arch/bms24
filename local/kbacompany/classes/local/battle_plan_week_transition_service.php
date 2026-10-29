<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicit batch orchestration over the existing Battle Plan lifecycle.
 *
 * No bulk status UPDATE is allowed here. Every selected plan is transitioned through
 * battle_plan_service::transition(), preserving the canonical lifecycle, locks and permissions.
 */
class battle_plan_week_transition_service {
    public const CONTRACT_VERSION = '0.5.14';
    public const ACTIONS = ['submit', 'approve'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan_week_transition',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'readiness' => 'battle_plan_week_readiness_service',
                'lifecycle' => 'battle_plan_service::transition()',
            ],
            'rules' => [
                'only_explicitly_selected_plans_are_processed',
                'each_plan_uses_canonical_transition_individually',
                'no_direct_bulk_status_update',
                'no_background_auto_submit_or_auto_approve',
                'repeated_submission_skips_plans_already_beyond_requested_transition',
                'company_wide_batch_requires_management_permission',
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

    /**
     * Process selected plans. Failure of one plan does not bypass canonical checks on another plan.
     *
     * @return array{selected:int,transitioned:int,skipped:int,failed:int,errors:array}
     */
    public static function execute(string $companyuuid, int $actor, string $week,
            string $action, array $planuuids): array {
        global $DB;
        self::require_management_access($companyuuid, $actor);
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \moodle_exception('battleplan_week_transition_invalid', 'local_kbacompany');
        }
        $weekstart = battle_plan_service::week_start($week !== '' ? $week : gmdate('Y-m-d'));
        $unique = [];
        foreach ($planuuids as $uuid) {
            $uuid = trim((string)$uuid);
            if ($uuid !== '') { $unique[$uuid] = true; }
        }
        if (!$unique) {
            throw new \moodle_exception('battleplan_week_transition_select_error', 'local_kbacompany');
        }

        $result = ['selected'=>count($unique), 'transitioned'=>0, 'skipped'=>0, 'failed'=>0, 'errors'=>[]];
        foreach (array_keys($unique) as $planuuid) {
            $plan = $DB->get_record('local_kbacompany_bplan', [
                'companyuuid' => $companyuuid, 'uuid' => $planuuid, 'periodstart' => $weekstart,
            ]);
            if (!$plan) {
                $result['failed']++;
                $result['errors'][] = ['uuid'=>$planuuid, 'code'=>'not_found'];
                continue;
            }

            // Idempotent replay: a plan already beyond the requested transition is not mutated again.
            if ($action === 'submit' && in_array((string)$plan->status, ['submitted','active','closed'], true)) {
                $result['skipped']++;
                continue;
            }
            if ($action === 'approve' && in_array((string)$plan->status, ['active','closed'], true)) {
                $result['skipped']++;
                continue;
            }
            if ($action === 'submit' && (string)$plan->status !== 'draft') {
                $result['skipped']++;
                continue;
            }
            if ($action === 'approve' && (string)$plan->status !== 'submitted') {
                $result['skipped']++;
                continue;
            }

            try {
                battle_plan_service::transition($companyuuid, $actor, $planuuid, $action);
                $result['transitioned']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ['uuid'=>$planuuid, 'code'=>'transition_failed'];
            }
        }
        return $result;
    }
}
