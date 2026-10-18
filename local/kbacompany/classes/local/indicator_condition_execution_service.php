<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Governance bridge from indicator conditions to canonical execution.
 *
 * Automatic conditions remain read-model output. This service stores only immutable
 * manager confirmation/context events. Canonical Task remains the sole execution object;
 * Battle Plan keeps a reference to that Task.
 */
class indicator_condition_execution_service {
    public const EVENT_KINDS = ['confirm', 'context'];

    private static function fail(string $key): void {
        throw new \moodle_exception('indicator_condition_execution_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private static function text(string $value, int $max, bool $required = false): string {
        $value = trim($value);
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    /** Resolve the current analytical snapshot for one canonical binding. */
    public static function snapshot(string $companyuuid, int $actor, string $binduuid): array {
        $ctx = indicator_registry_service::context($companyuuid, $actor);
        $binding = null;
        $metric = null;
        foreach ($ctx['bindings'] as $row) {
            if (!empty($row['compatibility']) || (string)$row['uuid'] !== $binduuid) { continue; }
            $binding = $row;
            break;
        }
        if (!$binding) { self::fail('binding'); }
        foreach ($ctx['metrics'] as $row) {
            if ((string)$row['uuid'] === (string)$binding['statuuid']) { $metric = $row; break; }
        }
        if (!$metric) { self::fail('indicator'); }
        $series = indicator_registry_service::series_for_metric($companyuuid, (string)$metric['uuid'], 52);
        $analysis = indicator_condition_service::analyze($series, (string)$metric['direction'],
            $ctx['normhistory'], $ctx['condition_policy_history'], (string)$binding['uuid']);
        $facts = indicator_trend_service::facts($series);
        $periodkey = $facts ? (string)$facts[count($facts) - 1]['period'] : '';
        return [
            'binding' => $binding,
            'metric' => $metric,
            'analysis' => $analysis,
            'period_key' => $periodkey,
        ];
    }

    /** Store one immutable manager confirmation/context event. */
    public static function confirm(string $companyuuid, int $actor, string $binduuid,
            string $condition, string $comment = ''): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!indicator_registry_service::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        if (!in_array($condition, indicator_condition_service::CONDITIONS, true)) { self::fail('condition'); }
        $snapshot = self::snapshot($companyuuid, $actor, $binduuid);
        $automatic = (string)($snapshot['analysis']['condition'] ?? '');
        $reason = (string)($snapshot['analysis']['reason'] ?? 'insufficient');
        $eventkind = ($automatic !== '' && $automatic === $condition) ? 'confirm' : 'context';
        $comment = self::text($comment, 12000, $eventkind === 'context');
        $eventuuid = self::uuid();
        $DB->insert_record('local_kbacompany_stateevt', (object)[
            'companyuuid' => $companyuuid,
            'uuid' => $eventuuid,
            'eventkind' => $eventkind,
            'statuuid' => (string)$snapshot['metric']['uuid'],
            'binduuid' => (string)$snapshot['binding']['uuid'],
            'bindrevision' => (int)$snapshot['binding']['revision'],
            'periodkey' => (string)$snapshot['period_key'],
            'conditioncode' => $condition,
            'analyticcondition' => $automatic,
            'analyticreason' => self::text($reason, 64, false),
            'comment' => $comment,
            'actorid' => $actor,
            'timecreated' => time(),
        ]);
        return $eventuuid;
    }

    /** Confirm/contextualize a condition and optionally create one canonical task + Battle Plan reference atomically. */
    public static function confirm_to_execution(string $companyuuid, int $actor, string $binduuid,
            string $condition, string $comment, array $taskdata = [], string $planuuid = ''): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!indicator_registry_service::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $createtask = !empty($taskdata);
        if ($createtask) {
            $assigneeid = (int)($taskdata['assigneeid'] ?? 0);
            if ($assigneeid <= 0) { self::fail('task'); }
            if ($planuuid !== '') {
                battle_plan_service::validate_creation_plan_target($companyuuid, $actor, $planuuid, $assigneeid);
            }
        }
        $tx = $DB->start_delegated_transaction();
        try {
            $eventuuid = self::confirm($companyuuid, $actor, $binduuid, $condition, $comment);
            $taskuuid = '';
            if ($createtask) {
                $taskuuid = task_service::ensure_from_condition_event($companyuuid, $actor, $eventuuid, $taskdata);
                if ($planuuid !== '') {
                    battle_plan_service::add_task($companyuuid, $actor, $planuuid, $taskuuid);
                }
            }
            $tx->allow_commit();
            return ['event_uuid' => $eventuuid, 'task_uuid' => $taskuuid, 'plan_uuid' => $planuuid];
        } catch (\Throwable $e) {
            $tx->rollback($e);
            throw $e;
        }
    }


    /**
     * Confirm/contextualize the state shown in one exact Battle Plan row and create/link the canonical Task.
     *
     * Unlike the generic current-indicator confirmation flow, this method recomputes the row from the
     * selected Battle Plan week on the server. This prevents an old plan from confirming a newer fact period.
     * Exact repeated submissions reuse the latest identical immutable decision and the same canonical Task.
     */
    public static function confirm_from_battle_plan(string $companyuuid, int $actor, string $planuuid,
            string $binduuid, string $condition, string $comment, array $taskdata): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!indicator_registry_service::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        if (!in_array($condition, indicator_condition_service::CONDITIONS, true)) { self::fail('condition'); }
        $plan = $DB->get_record('local_kbacompany_bplan', [
            'companyuuid' => $companyuuid, 'uuid' => $planuuid,
        ]);
        if (!$plan) { self::fail('invalid'); }

        // This also enforces draft-plan access and guarantees the task assignee matches the plan owner.
        battle_plan_service::validate_creation_plan_target($companyuuid, $actor, $planuuid, (int)$plan->userid);
        $projection = battle_plan_indicator_service::projection($companyuuid, $actor, (string)$plan->positionuuid,
            (string)$plan->periodstart, $planuuid);
        $row = null;
        foreach ($projection as $candidate) {
            if ((string)$candidate['bind_uuid'] === $binduuid) { $row = $candidate; break; }
        }
        if (!$row || !empty($row['compatibility'])) { self::fail('binding'); }

        $automatic = (string)($row['automatic_condition'] ?? '');
        $reason = self::text((string)($row['condition_reason'] ?? 'insufficient'), 64, false);
        $eventkind = ($automatic !== '' && $automatic === $condition) ? 'confirm' : 'context';
        $comment = self::text($comment, 12000, $eventkind === 'context');
        $periodkey = (string)($row['decision_period'] ?? '');
        if ($periodkey === '') { $periodkey = (string)$plan->periodstart; }

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('indicator-execution:' . $companyuuid . ':' . $binduuid . ':' . $periodkey, 10);
        if (!$lock) { self::fail('invalid'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $eventuuid = '';
            $latest = $DB->get_records('local_kbacompany_stateevt', [
                'companyuuid' => $companyuuid,
                'binduuid' => $binduuid,
                'bindrevision' => (int)$row['bind_revision'],
                'periodkey' => $periodkey,
            ], 'timecreated DESC, id DESC', '*', 0, 1);
            $latest = $latest ? reset($latest) : null;
            $reuselatest = false;
            if ($latest && (string)$latest->conditioncode === $condition &&
                    trim((string)$latest->comment) === $comment) {
                $sameassigneetasks = $DB->get_records('local_kbacompany_task', [
                    'companyuuid' => $companyuuid, 'sourcekind' => 'condition', 'sourceuuid' => (string)$latest->uuid,
                    'assigneeid' => (int)$plan->userid,
                ], 'id ASC');
                $hasdeleted = false;
                foreach ($sameassigneetasks as $existingtask) {
                    if ((string)$existingtask->status === 'deleted') { $hasdeleted = true; break; }
                }
                // Reuse an identical governance event if execution has not been explicitly deleted.
                // A deleted Task remains a tombstone; a new management attempt gets a new event/source.
                $reuselatest = !$hasdeleted;
            }
            if ($reuselatest) {
                $eventuuid = (string)$latest->uuid;
            } else {
                $eventuuid = self::uuid();
                $DB->insert_record('local_kbacompany_stateevt', (object)[
                    'companyuuid' => $companyuuid,
                    'uuid' => $eventuuid,
                    'eventkind' => $eventkind,
                    'statuuid' => (string)$row['stat_uuid'],
                    'binduuid' => $binduuid,
                    'bindrevision' => (int)$row['bind_revision'],
                    'periodkey' => $periodkey,
                    'conditioncode' => $condition,
                    'analyticcondition' => $automatic,
                    'analyticreason' => $reason,
                    'comment' => $comment,
                    'actorid' => $actor,
                    'timecreated' => time(),
                ]);
            }

            // Battle Plan placement is authoritative for responsibility; do not accept client-side assignee/reviewer changes.
            $taskdata['assigneeid'] = (int)$plan->userid;
            $taskdata['reviewerid'] = (int)$plan->reviewerid;
            $taskdata['observerid'] = (int)$plan->observerid;
            $taskdata['stream'] = 'change';
            $taskdata['startdate'] = (string)$plan->periodstart;
            if (trim((string)($taskdata['duedate'] ?? '')) === '') {
                $taskdata['duedate'] = (string)$plan->periodend;
            }
            $taskdata['parentuuid'] = '';
            $taskdata['contextkind'] = '';
            $taskdata['contextuuid'] = '';
            $taskdata['contextrevision'] = 0;
            $taskuuid = task_service::ensure_from_condition_event($companyuuid, $actor, $eventuuid, $taskdata);
            battle_plan_service::add_task($companyuuid, $actor, $planuuid, $taskuuid);
            $tx->allow_commit();
            return ['event_uuid' => $eventuuid, 'task_uuid' => $taskuuid, 'plan_uuid' => $planuuid];
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Recent immutable governance events with canonical execution links derived on read. */
    public static function history(string $companyuuid, int $actor, string $statuuid = '', int $limit = 100): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $limit = max(1, min(500, $limit));
        $params = ['company' => $companyuuid];
        $where = 'companyuuid = :company';
        if ($statuuid !== '') {
            $where .= ' AND statuuid = :stat';
            $params['stat'] = $statuuid;
        }
        $events = $DB->get_records_select('local_kbacompany_stateevt', $where, $params,
            'timecreated DESC, id DESC', '*', 0, $limit);
        if (!$events) { return []; }
        $userids = [];
        foreach ($events as $event) { $userids[(int)$event->actorid] = true; }
        $users = [];
        if ($userids) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'uid');
            foreach ($DB->get_records_select('user', 'id ' . $insql, $inparams, '', 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename') as $user) {
                $users[(int)$user->id] = fullname($user);
            }
        }
        $out = [];
        $canmanagetasks = task_service::can_manage($companyuuid, $actor);
        foreach ($events as $event) {
            $taskrows = $DB->get_records('local_kbacompany_task', [
                'companyuuid' => $companyuuid, 'sourcekind' => 'condition', 'sourceuuid' => (string)$event->uuid,
            ], 'id ASC');
            $tasks = [];
            foreach ($taskrows as $task) {
                if ((string)$task->status === 'deleted' ||
                        !task_service::can_view_task($companyuuid, $actor, (string)$task->uuid)) {
                    continue;
                }
                $plans = [];
                foreach ($DB->get_records('local_kbacompany_bplanitem', [
                    'companyuuid' => $companyuuid, 'provider' => 'bms', 'externalid' => (string)$task->uuid,
                ], 'id ASC') as $item) {
                    $plan = $DB->get_record('local_kbacompany_bplan', [
                        'companyuuid' => $companyuuid, 'uuid' => (string)$item->planuuid,
                    ], 'id,uuid,periodstart,periodend,status,userid,reviewerid,observerid');
                    if ($plan && ($canmanagetasks || (int)$plan->userid === $actor ||
                            (int)$plan->reviewerid === $actor || (int)$plan->observerid === $actor)) {
                        $plans[] = ['uuid' => (string)$plan->uuid, 'period_start' => (string)$plan->periodstart,
                            'period_end' => (string)$plan->periodend, 'status' => (string)$plan->status,
                            'user_id' => (int)$plan->userid];
                    }
                }
                $tasks[] = ['uuid' => (string)$task->uuid, 'title' => (string)$task->title,
                    'status' => (string)$task->status, 'assignee_id' => (int)$task->assigneeid, 'plans' => $plans];
            }
            $out[] = [
                'uuid' => (string)$event->uuid,
                'event_kind' => (string)$event->eventkind,
                'stat_uuid' => (string)$event->statuuid,
                'bind_uuid' => (string)$event->binduuid,
                'bind_revision' => (int)$event->bindrevision,
                'period_key' => (string)$event->periodkey,
                'condition' => (string)$event->conditioncode,
                'analytic_condition' => (string)$event->analyticcondition,
                'analytic_reason' => (string)$event->analyticreason,
                'comment' => (string)$event->comment,
                'actor_id' => (int)$event->actorid,
                'actor_name' => $users[(int)$event->actorid] ?? '',
                'time_created' => (int)$event->timecreated,
                'tasks' => $tasks,
            ];
        }
        return $out;
    }

    /** Employees and editable draft Battle Plans available to the actor for execution forms. */
    public static function execution_options(string $companyuuid, int $actor): array {
        $organization = organization_service::organization_context($companyuuid, $actor);
        $employees = [];
        foreach ($organization['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        return [
            'employees' => $employees,
            'battle_plans' => battle_plan_service::creation_plan_options($companyuuid, $actor),
        ];
    }
}
