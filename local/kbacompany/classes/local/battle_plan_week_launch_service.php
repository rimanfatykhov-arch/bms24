<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicit management-week launcher for gaps found by Battle Plan coverage.
 *
 * The launcher does not maintain its own registry. It revalidates a factual position assignment
 * for the selected week and idempotently creates the canonical weekly Battle Plan only after an
 * explicit manager selection.
 */
class battle_plan_week_launch_service {
    public const CONTRACT_VERSION = '0.5.12';

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan_week_launch',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'local_kbacompany_bplan only',
            'sources' => [
                'coverage' => 'battle_plan_coverage_service read-model',
                'assignments' => 'local_kbacompany_posassign effective history',
                'plans' => 'local_kbacompany_bplan',
                'people' => 'company employee directory',
            ],
            'rules' => [
                'launch_requires_explicit_selected_assignments',
                'launch_is_company_manager_only',
                'historical_assignment_is_revalidated_server_side',
                'one_plan_per_employee_position_week',
                'repeated_launch_is_idempotent',
                'reviewer_is_explicitly_selected',
                'observer_is_optional_and_explicitly_selected',
                'assignment_presence_never_auto_creates_plan',
                'no_second_plan_registry',
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

    /** Current company employees that may be selected as reviewer/observer. */
    public static function people_options(string $companyuuid, int $actor): array {
        self::require_management_access($companyuuid, $actor);
        $organization = organization_service::organization_context($companyuuid, $actor);
        $out = [];
        foreach ($organization['employees'] as $employee) {
            $out[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** Opaque-enough UI token; every value is revalidated server-side before plan creation. */
    public static function candidate_token(int $userid, string $positionuuid): string {
        return $userid . '|' . $positionuuid;
    }

    private static function parse_candidate_token(string $token): array {
        if (!preg_match('/^([1-9][0-9]*)\\|([A-Za-z0-9-]{1,36})$/', $token, $matches)) {
            throw new \moodle_exception('battleplan_week_launch_invalid', 'local_kbacompany');
        }
        return [(int)$matches[1], (string)$matches[2]];
    }

    /**
     * Launch selected missing plans for one management week.
     *
     * Existing plans are returned as reused rather than duplicated, making retries safe.
     */
    public static function launch(string $companyuuid, int $actor, string $week, array $selection,
            int $reviewerid, int $observerid = 0): array {
        self::require_management_access($companyuuid, $actor);
        $weekstart = battle_plan_service::week_start($week);
        $people = self::people_options($companyuuid, $actor);
        if (!isset($people[$reviewerid])) {
            throw new \moodle_exception('battleplan_reviewererror', 'local_kbacompany');
        }
        if ($observerid > 0 && !isset($people[$observerid])) {
            throw new \moodle_exception('battleplan_observererror', 'local_kbacompany');
        }
        if (!$selection) {
            throw new \moodle_exception('battleplan_week_launch_select_error', 'local_kbacompany');
        }

        $created = [];
        $reused = [];
        $seen = [];
        foreach ($selection as $rawtoken) {
            $token = trim((string)$rawtoken);
            if ($token === '' || isset($seen[$token])) { continue; }
            $seen[$token] = true;
            list($userid, $positionuuid) = self::parse_candidate_token($token);
            $result = battle_plan_service::ensure_plan_for_assignment_week($companyuuid, $actor, $userid,
                $positionuuid, $weekstart, $reviewerid, $observerid);
            $dto = [
                'uuid' => (string)$result['uuid'],
                'user_id' => $userid,
                'position_uuid' => $positionuuid,
                'week_start' => $weekstart,
            ];
            if (!empty($result['created'])) { $created[] = $dto; }
            else { $reused[] = $dto; }
        }
        if (!$created && !$reused) {
            throw new \moodle_exception('battleplan_week_launch_select_error', 'local_kbacompany');
        }
        return [
            'week_start' => $weekstart,
            'created' => $created,
            'reused' => $reused,
            'created_count' => count($created),
            'reused_count' => count($reused),
            'contract' => self::contract(),
        ];
    }
}
