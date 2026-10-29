<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Schema-neutral management cycle read-model for indicator exceptions.
 *
 * It connects existing manager decisions, canonical Tasks, Battle Plans and the
 * exact next weekly fact. Nothing in this service is a new incident/action state.
 */
class indicator_management_cycle_service {
    public const CONTRACT_VERSION = '0.5.9';
    public const FILTERS = [
        'needs_decision', 'needs_execution', 'overdue', 'in_progress',
        'awaiting_followup', 'improved', 'worsened', 'unchanged', 'resolved',
    ];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_indicator_management_cycle_read_model',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'manager_decision' => 'local_kbacompany_stateevt',
                'execution' => 'local_kbacompany_task',
                'task_progress' => 'local_kbacompany_taskcheck',
                'battle_plan' => 'local_kbacompany_bplan + local_kbacompany_bplanitem',
                'fact_history' => 'local_kbacompany_statval',
            ],
            'rules' => [
                'management_cycle_is_read_model_not_incident_storage',
                'task_details_respect_canonical_task_acl',
                'battle_plan_links_respect_plan_participant_or_task_manager_access',
                'followup_compares_exact_next_week_fact_without_claiming_causality',
                'new_decision_is_required_only_when_followup_is_worsening_or_unchanged_and_no_later_decision_exists',
                'no_hidden_percentage_or_age_thresholds',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function next_week(string $period): string {
        if ($period === '') { return ''; }
        return (new \DateTimeImmutable($period, new \DateTimeZone('UTC')))
            ->modify('+7 days')->format('Y-m-d');
    }

    /** Latest append-only fact revision for one exact period. */
    private static function fact(string $companyuuid, string $statuuid, string $period): ?float {
        global $DB;
        if ($period === '') { return null; }
        $rows = $DB->get_records('local_kbacompany_statval', [
            'companyuuid' => $companyuuid, 'statuuid' => $statuuid,
            'periodstart' => $period, 'kind' => 'fact',
        ], 'revision DESC, id DESC', '*', 0, 1);
        if (!$rows) { return null; }
        $row = reset($rows);
        return (float)$row->amount;
    }

    /** All immutable decisions for one binding, newest business period first. */
    private static function decisions(string $companyuuid, string $binduuid): array {
        global $DB;
        if ($binduuid === '' || strpos($binduuid, 'compat-') === 0) { return []; }
        return array_values($DB->get_records('local_kbacompany_stateevt', [
            'companyuuid' => $companyuuid, 'binduuid' => $binduuid,
        ], 'periodkey DESC, timecreated DESC, id DESC'));
    }

    /** First non-deleted canonical Task sourced by one immutable decision. */
    private static function task_for_decision(string $companyuuid, ?\stdClass $decision): ?\stdClass {
        global $DB;
        if (!$decision) { return null; }
        $rows = $DB->get_records('local_kbacompany_task', [
            'companyuuid' => $companyuuid, 'sourcekind' => 'condition', 'sourceuuid' => (string)$decision->uuid,
        ], 'id ASC');
        foreach ($rows as $row) {
            if ((string)$row->status !== 'deleted') { return $row; }
        }
        return null;
    }

    /** Public task projection: protected details are returned only through canonical Task ACL. */
    private static function task_projection(string $companyuuid, int $actor, ?\stdClass $task): array {
        global $DB;
        $out = [
            'exists' => $task !== null, 'visible' => false, 'uuid' => '', 'title' => '', 'status' => '',
            'due_date' => '', 'overdue' => false, 'result' => '', 'completed_at' => 0,
            'checklist_done' => 0, 'checklist_total' => 0, 'checklist_percent' => null, 'url' => '',
        ];
        if (!$task) { return $out; }
        $status = (string)$task->status;
        $open = !in_array($status, ['done', 'cancelled'], true);
        $out['overdue'] = $open && (string)$task->duedate !== '' && (string)$task->duedate < gmdate('Y-m-d');
        if (!task_service::can_view_task($companyuuid, $actor, (string)$task->uuid)) { return $out; }
        $out['visible'] = true;
        $out['uuid'] = (string)$task->uuid;
        $out['title'] = (string)$task->title;
        $out['status'] = $status;
        $out['due_date'] = (string)$task->duedate;
        $out['result'] = (string)$task->result;
        $out['completed_at'] = (int)$task->completedat;
        $out['url'] = (new \moodle_url('/local/kbacompany/tasks.php', [
            'uuid' => $companyuuid, 'task' => (string)$task->uuid,
        ]))->out(false);
        $out['checklist_total'] = $DB->count_records('local_kbacompany_taskcheck', [
            'companyuuid' => $companyuuid, 'taskuuid' => (string)$task->uuid,
        ]);
        $out['checklist_done'] = $DB->count_records('local_kbacompany_taskcheck', [
            'companyuuid' => $companyuuid, 'taskuuid' => (string)$task->uuid, 'done' => 1,
        ]);
        if ($out['checklist_total'] > 0) {
            $out['checklist_percent'] = ($out['checklist_done'] / $out['checklist_total']) * 100;
        }
        return $out;
    }

    /** Exact next-week fact comparison after a decision that has canonical execution. */
    private static function observation(string $companyuuid, string $statuuid, string $direction,
            string $actionperiod, string $latestperiod): array {
        $followup = self::next_week($actionperiod);
        $out = [
            'action_period' => $actionperiod, 'followup_period' => $followup,
            'baseline_fact' => null, 'followup_fact' => null,
            'change' => 'insufficient', 'quality' => 'insufficient',
        ];
        if ($actionperiod === '' || $followup === '' || $followup > $latestperiod) { return $out; }
        $before = self::fact($companyuuid, $statuuid, $actionperiod);
        $after = self::fact($companyuuid, $statuuid, $followup);
        $out['baseline_fact'] = $before;
        $out['followup_fact'] = $after;
        if ($before === null || $after === null) { return $out; }
        $change = indicator_trend_service::compare($before, $after);
        $quality = 'neutral';
        if ($change === 'flat') {
            $quality = 'unchanged';
        } else if ($direction === 'up') {
            $quality = $change === 'up' ? 'improving' : 'worsening';
        } else if ($direction === 'down') {
            $quality = $change === 'down' ? 'improving' : 'worsening';
        }
        $out['change'] = $change;
        $out['quality'] = $quality;
        return $out;
    }

    /** Whether a more recent immutable decision exists at/after the follow-up week. */
    private static function has_later_decision(array $decisions, string $actionperiod, string $followupperiod): bool {
        foreach ($decisions as $decision) {
            $period = (string)$decision->periodkey;
            if ($period !== '' && $period > $actionperiod && ($followupperiod === '' || $period >= $followupperiod)) {
                return true;
            }
        }
        return false;
    }

    private static function can_access_plan(\stdClass $plan, int $actor, bool $canmanage): bool {
        return $canmanage || (int)$plan->userid === $actor || (int)$plan->reviewerid === $actor ||
            (int)$plan->observerid === $actor;
    }

    /** Read-only Battle Plan links relevant to this binding/task. */
    private static function plan_links(string $companyuuid, int $actor, array $binding, ?\stdClass $task): array {
        global $DB;
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $currentweek = battle_plan_service::week_start(gmdate('Y-m-d'));
        $taskvisible = $task && task_service::can_view_task($companyuuid, $actor, (string)$task->uuid);
        $current = [];
        if ((string)($binding['contextkind'] ?? '') === 'position' && (string)($binding['contextuuid'] ?? '') !== '') {
            foreach ($DB->get_records('local_kbacompany_bplan', [
                'companyuuid' => $companyuuid, 'positionuuid' => (string)$binding['contextuuid'],
                'periodstart' => $currentweek,
            ], 'userid ASC, id ASC') as $plan) {
                if (!self::can_access_plan($plan, $actor, $canmanage)) { continue; }
                $user = $DB->get_record('user', ['id' => (int)$plan->userid], 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
                $current[] = [
                    'uuid' => (string)$plan->uuid,
                    'status' => (string)$plan->status,
                    'employee' => $user ? fullname($user) : (string)$plan->userid,
                    'period_start' => (string)$plan->periodstart,
                    'url' => (new \moodle_url('/local/kbacompany/battle_plan.php', [
                        'uuid' => $companyuuid, 'plan' => (string)$plan->uuid,
                    ]))->out(false),
                    'task_in_plan' => $taskvisible ? $DB->record_exists('local_kbacompany_bplanitem', [
                        'companyuuid' => $companyuuid, 'planuuid' => (string)$plan->uuid,
                        'provider' => 'bms', 'externalid' => (string)$task->uuid,
                    ]) : false,
                ];
            }
        }

        $linked = [];
        if ($taskvisible) {
            $items = $DB->get_records('local_kbacompany_bplanitem', [
                'companyuuid' => $companyuuid, 'provider' => 'bms', 'externalid' => (string)$task->uuid,
            ], 'id ASC');
            foreach ($items as $item) {
                $plan = $DB->get_record('local_kbacompany_bplan', [
                    'companyuuid' => $companyuuid, 'uuid' => (string)$item->planuuid,
                ]);
                if (!$plan || !self::can_access_plan($plan, $actor, $canmanage)) { continue; }
                $linked[] = [
                    'uuid' => (string)$plan->uuid,
                    'status' => (string)$plan->status,
                    'period_start' => (string)$plan->periodstart,
                    'url' => (new \moodle_url('/local/kbacompany/battle_plan.php', [
                        'uuid' => $companyuuid, 'plan' => (string)$plan->uuid,
                    ]))->out(false),
                ];
            }
        }
        return ['current_week' => $currentweek, 'current' => $current, 'linked' => $linked];
    }

    /**
     * One current management-cycle snapshot for a canonical indicator binding.
     */
    public static function snapshot(string $companyuuid, int $actor, array $binding, array $metric,
            string $latestperiod, bool $needsdecision): array {
        company_context_service::context($companyuuid, $actor);
        $decisions = self::decisions($companyuuid, (string)($binding['uuid'] ?? ''));
        $exactdecision = null;
        $actiondecision = null;
        $actiontask = null;
        foreach ($decisions as $decision) {
            if ($exactdecision === null && (string)$decision->periodkey === $latestperiod) {
                $exactdecision = $decision;
            }
            $task = self::task_for_decision($companyuuid, $decision);
            if ($actiondecision === null && $task && ($latestperiod === '' || (string)$decision->periodkey <= $latestperiod)) {
                $actiondecision = $decision;
                $actiontask = $task;
            }
        }

        $exacttask = self::task_for_decision($companyuuid, $exactdecision);
        if ($exactdecision && $exacttask) {
            $actiondecision = $exactdecision;
            $actiontask = $exacttask;
        }
        $taskprojection = self::task_projection($companyuuid, $actor, $actiontask);
        $observation = $actiondecision ? self::observation($companyuuid, (string)$metric['uuid'],
            (string)$metric['direction'], (string)$actiondecision->periodkey, $latestperiod) :
            self::observation($companyuuid, (string)$metric['uuid'], (string)$metric['direction'], '', $latestperiod);
        $laterdecision = $actiondecision ? self::has_later_decision($decisions, (string)$actiondecision->periodkey,
            (string)$observation['followup_period']) : false;
        $requiresnew = false;
        if (!$exactdecision && $needsdecision) { $requiresnew = true; }
        if (in_array((string)$observation['quality'], ['worsening', 'unchanged'], true) && !$laterdecision) {
            $requiresnew = true;
        }

        $status = 'resolved';
        if (!$exactdecision && $needsdecision) {
            $status = 'needs_decision';
        } else if ($exactdecision && !$exacttask) {
            $status = 'needs_execution';
        } else if ($actiontask) {
            $taskstatus = (string)$actiontask->status;
            $open = !in_array($taskstatus, ['done', 'cancelled'], true);
            $overdue = $open && (string)$actiontask->duedate !== '' && (string)$actiontask->duedate < gmdate('Y-m-d');
            if ($overdue) {
                $status = 'overdue';
            } else if ($open) {
                $status = 'in_progress';
            } else if ((string)$observation['quality'] === 'improving') {
                $status = 'improved';
            } else if ((string)$observation['quality'] === 'worsening') {
                $status = 'worsened';
            } else if ((string)$observation['quality'] === 'unchanged') {
                $status = 'unchanged';
            } else if ((string)$observation['quality'] === 'insufficient') {
                $status = 'awaiting_followup';
            }
        }
        if ($requiresnew && in_array($status, ['improved', 'resolved'], true)) { $status = 'needs_decision'; }

        return [
            'status' => $status,
            'requires_new_decision' => $requiresnew,
            'decision_uuid' => $exactdecision ? (string)$exactdecision->uuid : '',
            'decision_period' => $exactdecision ? (string)$exactdecision->periodkey : '',
            'action_event_uuid' => $actiondecision ? (string)$actiondecision->uuid : '',
            'action_period' => $actiondecision ? (string)$actiondecision->periodkey : '',
            'task' => $taskprojection,
            'observation' => $observation,
            'has_later_decision' => $laterdecision,
            'plans' => self::plan_links($companyuuid, $actor, $binding, $actiontask),
        ];
    }
}
