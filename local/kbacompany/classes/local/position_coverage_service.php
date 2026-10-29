<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * BMS-POS-COVERAGE-01: audited, time-limited position coverage authorization.
 * This service intentionally NEVER changes task assignees, posassign FTE, Battle Plans,
 * RBAC permissions or recurring-task scheduler inputs. Those are future releases.
 */
final class position_coverage_service {
    public const CONTRACT_VERSION = '0.3.0';
    public const STATUSES = ['requested', 'approved', 'rejected', 'cancelled'];
    public const SCOPE = 'position_work';
    private const MAX_DAYS = 366;

    public static function can_manage(string $companyuuid, int $actor): bool {
        company_context_service::context($companyuuid, $actor);
        return organizing_board_service::can_manage($companyuuid, $actor);
    }

    private static function require_manage(string $companyuuid, int $actor): void {
        if (!self::can_manage($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    /** Parse a calendar day strictly; stored as UTC midnight for date-only comparison. */
    public static function day(string $value): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new \moodle_exception('coverage_invalid_dates', 'local_kbacompany');
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            throw new \moodle_exception('coverage_invalid_dates', 'local_kbacompany');
        }
        return $dt->getTimestamp();
    }

    /** Inclusive date range, independent of DST and time-of-day. */
    public static function valid_range(string $from, string $to): array {
        $start = self::day($from);
        $end = self::day($to);
        $days = (int)(($end - $start) / 86400) + 1;
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new \moodle_exception('coverage_invalid_dates', 'local_kbacompany');
        }
        return [$start, $end];
    }

    /** Options are sourced only from authorised company staff and active primary assignments. */
    public static function options(string $companyuuid, int $actor): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $org = organization_service::organization_context($companyuuid, $actor);
        $people = [];
        foreach ($org['employees'] as $person) {
            $people[(int)$person['user_id']] = (string)$person['full_name'];
        }
        $positions = $DB->get_records('local_kbacompany_position',
            ['companyuuid' => $companyuuid, 'active' => 1], 'name ASC, id ASC');
        $positions = array_column(array_values($positions), null, 'uuid');
        $primary = $DB->get_records('local_kbacompany_posassign',
            ['companyuuid' => $companyuuid, 'active' => 1, 'assignmenttype' => 'primary'], 'id ASC');
        $choices = [];
        $today = self::day(gmdate('Y-m-d'));
        foreach ($primary as $assignment) {
            $pos = $positions[(string)$assignment->positionuuid] ?? null;
            if (!$pos || !isset($people[(int)$assignment->userid])) { continue; }
            if (((int)$assignment->effectivefrom > 0 && (int)$assignment->effectivefrom > $today) ||
                    ((int)$assignment->effectiveto > 0 && (int)$assignment->effectiveto < $today)) { continue; }
            $key = $pos->uuid . ':' . (int)$assignment->userid;
            $choices[$key] = (string)$pos->name . ' — ' . $people[(int)$assignment->userid];
        }
        return ['primary' => $choices, 'people' => $people];
    }

    private static function validate_parties(string $companyuuid, int $actor, string $positionuuid,
            int $primaryid, int $delegateid, int $start, int $end): void {
        global $DB;
        if (!$DB->record_exists('local_kbacompany_position', ['companyuuid' => $companyuuid,
                'uuid' => $positionuuid, 'active' => 1])) {
            throw new \moodle_exception('coverage_invalid_position', 'local_kbacompany');
        }
        if ($primaryid <= 0 || $delegateid <= 0 || $primaryid === $delegateid) {
            throw new \moodle_exception('coverage_invalid_people', 'local_kbacompany');
        }
        $org = organization_service::organization_context($companyuuid, $actor);
        $eligible = array_map('intval', array_column($org['employees'], 'user_id'));
        if (!in_array($primaryid, $eligible, true) || !in_array($delegateid, $eligible, true)) {
            throw new \moodle_exception('coverage_invalid_people', 'local_kbacompany');
        }
        // The assignment timestamps may include a time-of-day; coverage uses whole calendar days.
        $assignments = $DB->get_records('local_kbacompany_posassign', [
            'companyuuid' => $companyuuid, 'positionuuid' => $positionuuid,
            'userid' => $primaryid, 'assignmenttype' => 'primary', 'active' => 1,
        ]);
        $covers = false;
        foreach ($assignments as $assignment) {
            $a = (int)$assignment->effectivefrom;
            $b = (int)$assignment->effectiveto;
            if (($a === 0 || self::day(gmdate('Y-m-d', $a)) <= $start) &&
                    ($b === 0 || self::day(gmdate('Y-m-d', $b)) >= $end)) {
                $covers = true;
                break;
            }
        }
        if (!$covers) {
            throw new \moodle_exception('coverage_missing_primary', 'local_kbacompany');
        }
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $s = bin2hex($bytes);
        return substr($s, 0, 8) . '-' . substr($s, 8, 4) . '-' . substr($s, 12, 4) . '-' .
            substr($s, 16, 4) . '-' . substr($s, 20);
    }

    private static function audit(string $companyuuid, string $uuid, int $revision, int $actor,
            string $event, string $notes = ''): void {
        global $DB;
        $DB->insert_record('local_kbacompany_poscovaudit', (object)[
            'companyuuid' => $companyuuid, 'coverageuuid' => $uuid,
            'revision' => $revision, 'actorid' => $actor, 'event' => $event,
            'notes' => $notes, 'timecreated' => time(),
        ]);
    }

    /** Create a request; does not confer any rights until separately approved. */
    public static function request(string $companyuuid, int $actor, array $input): string {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $positionuuid = trim((string)($input['positionuuid'] ?? ''));
        $primaryid = (int)($input['primaryid'] ?? 0);
        $delegateid = (int)($input['delegateid'] ?? 0);
        [$from, $to] = self::valid_range((string)($input['startdate'] ?? ''),
            (string)($input['enddate'] ?? ''));
        // No retrospective rights should be created through backdated authorizations.
        if ($from < self::day(gmdate('Y-m-d'))) {
            throw new \moodle_exception('coverage_past_start', 'local_kbacompany');
        }
        $reason = trim((string)($input['reason'] ?? ''));
        if (\core_text::strlen($reason) < 5 || \core_text::strlen($reason) > 1000) {
            throw new \moodle_exception('coverage_reason_required', 'local_kbacompany');
        }
        self::validate_parties($companyuuid, $actor, $positionuuid, $primaryid, $delegateid, $from, $to);
        $uuid = self::uuid();
        $now = time();
        $tx = $DB->start_delegated_transaction();
        try {
            $DB->insert_record('local_kbacompany_poscover', (object)[
            'companyuuid' => $companyuuid, 'uuid' => $uuid,
            'positionuuid' => $positionuuid, 'primaryid' => $primaryid, 'delegateid' => $delegateid,
            'scope' => self::SCOPE, 'startdate' => $from, 'enddate' => $to,
            'reason' => $reason, 'status' => 'requested', 'revision' => 1,
            'createdby' => $actor, 'approvedby' => 0, 'approvedat' => 0,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
            self::audit($companyuuid, $uuid, 1, $actor, 'requested', $reason);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            $tx->rollback($e);
            throw $e;
        }
        return $uuid;
    }

    private static function require_record(string $companyuuid, string $uuid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_poscover',
            ['companyuuid' => $companyuuid, 'uuid' => $uuid], '*', MUST_EXIST);
    }

    public static function detail(string $companyuuid, int $actor, string $uuid): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $record = self::require_record($companyuuid, $uuid);
        $audit = $DB->get_records('local_kbacompany_poscovaudit',
            ['companyuuid' => $companyuuid, 'coverageuuid' => $uuid], 'id DESC');
        return ['record' => $record, 'audit' => array_values($audit)];
    }

    /** Only the explicit, guarded lifecycle transitions are accepted. */
    public static function transition(string $companyuuid, int $actor, string $uuid, int $revision,
            string $action, string $comment = ''): void {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $target = ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action] ?? null;
        if ($target === null) {
            throw new \moodle_exception('coverage_invalid_action', 'local_kbacompany');
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_kbacompany');
        $lock = $factory->get_lock('position_coverage:' . $companyuuid, 10);
        if (!$lock) { throw new \moodle_exception('coverage_busy', 'local_kbacompany'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $row = self::require_record($companyuuid, $uuid);
            if ((int)$row->revision !== $revision) {
                throw new \moodle_exception('coverage_stale', 'local_kbacompany');
            }
            if (!in_array((string)$row->status, $action === 'cancel' ? ['requested', 'approved'] : ['requested'], true)) {
                throw new \moodle_exception('coverage_invalid_action', 'local_kbacompany');
            }
            $comment = trim($comment);
            if (($action === 'reject' || $action === 'cancel') && \core_text::strlen($comment) < 5) {
                throw new \moodle_exception('coverage_reason_required', 'local_kbacompany');
            }
            if ($action === 'approve') {
                self::validate_parties($companyuuid, $actor, (string)$row->positionuuid,
                    (int)$row->primaryid, (int)$row->delegateid, (int)$row->startdate, (int)$row->enddate);
                if ((int)$row->enddate < self::day(gmdate('Y-m-d'))) {
                    throw new \moodle_exception('coverage_expired', 'local_kbacompany');
                }
                // Exclusive coverage per position/date in this first-stage model.
                $overlap = $DB->record_exists_select('local_kbacompany_poscover',
                    'companyuuid = :c AND positionuuid = :p AND uuid <> :u AND status = :s
                     AND startdate <= :t AND enddate >= :f', [
                        'c' => $companyuuid, 'p' => $row->positionuuid, 'u' => $uuid,
                        's' => 'approved', 't' => $row->enddate, 'f' => $row->startdate,
                    ]);
                if ($overlap) { throw new \moodle_exception('coverage_overlap', 'local_kbacompany'); }
                $row->approvedby = $actor;
                $row->approvedat = time();
            }
            $row->status = $target;
            $row->revision = (int)$row->revision + 1;
            $row->timemodified = time();
            $DB->update_record('local_kbacompany_poscover', $row);
            self::audit($companyuuid, $uuid, (int)$row->revision, $actor, $target, $comment);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Derived, read-only state; expiry never requires an unsafe midnight cron mutation. */
    public static function effective_state(\stdClass $row, ?int $day = null): string {
        if ($row->status !== 'approved') { return (string)$row->status; }
        if ($day === null) { $day = self::day(gmdate('Y-m-d')); }
        if ($day < (int)$row->startdate) { return 'scheduled'; }
        if ($day > (int)$row->enddate) { return 'expired'; }
        return 'effective';
    }

    /**
     * Resolve an active approved substitution for an explicitly classified duty task.
     * Never changes original task assignee or its history. A completed task retains
     * its historical executor; current delegation does not rewrite the past.
     */
    public static function effective_executor(string $companyuuid, string $positionuuid,
            int $primaryid, ?int $at = null): int {
        global $DB;
        if ($primaryid <= 0 || $positionuuid === '') { return $primaryid; }
        $at = $at ?? time();
        $date = self::day(gmdate('Y-m-d', $at));
        $sql = "SELECT cv.id, cv.delegateid FROM {local_kbacompany_poscover} cv
                  JOIN {local_kbacompany_position} pos ON pos.companyuuid = cv.companyuuid
                   AND pos.uuid = cv.positionuuid AND pos.active = 1
                  JOIN {local_kbacompany_posassign} pa ON pa.companyuuid = cv.companyuuid
                   AND pa.positionuuid = cv.positionuuid AND pa.userid = cv.primaryid
                   AND pa.assignmenttype = :assignmenttype AND pa.active = 1
                   AND (pa.effectivefrom = 0 OR pa.effectivefrom <= :atfrom)
                   AND (pa.effectiveto = 0 OR pa.effectiveto >= :atto)
                 WHERE cv.companyuuid = :company AND cv.positionuuid = :position
                   AND cv.primaryid = :primaryid AND cv.status = :status AND cv.scope = :scope
                   AND cv.startdate <= :startday AND cv.enddate >= :endday
              ORDER BY cv.id DESC";
        $rows = $DB->get_records_sql($sql, ['assignmenttype'=>'primary', 'atfrom'=>$at,
            'atto'=>$at,'company'=>$companyuuid,'position'=>$positionuuid,
            'primaryid'=>$primaryid,'status'=>'approved','scope'=>self::SCOPE,
            'startday'=>$date,'endday'=>$date],0,1);
        return $rows ? (int)reset($rows)->delegateid : $primaryid;
    }

    /**
     * Read-only, manager-authorized coverage overlapping a selected Battle Plan week.
     * Historical approved coverage is shown as history, never as a new permission.
     * This method MUST NOT grant Battle Plan, metric or calendar permissions.
     */
    public static function battle_plan_window(string $companyuuid, int $actor, string $positionuuid,
            int $primaryid, string $fromdate, string $todate): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        [$from, $to] = self::valid_range($fromdate, $todate);
        if ($positionuuid === '' || $primaryid <= 0) { return []; }
        $rows = $DB->get_records_sql(
            "SELECT cv.id, cv.uuid, cv.delegateid, cv.startdate, cv.enddate, cv.approvedat
               FROM {local_kbacompany_poscover} cv
              WHERE cv.companyuuid = :company AND cv.positionuuid = :position
                AND cv.primaryid = :primary AND cv.status = :approved
                AND cv.scope = :scope AND cv.startdate <= :enddate
                AND cv.enddate >= :startdate
           ORDER BY cv.startdate, cv.id", [
                'company' => $companyuuid, 'position' => $positionuuid, 'primary' => $primaryid,
                'approved' => 'approved', 'scope' => self::SCOPE,
                'enddate' => $to, 'startdate' => $from,
            ], 0, 40);
        $today = self::day(gmdate('Y-m-d'));
        $activeid = null;
        foreach ($rows as $row) {
            if ((int)$row->startdate <= $today && (int)$row->enddate >= $today) {
                $activeid = self::effective_executor($companyuuid, $positionuuid, $primaryid);
                break;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            $active = $today >= (int)$row->startdate && $today <= (int)$row->enddate &&
                (int)$activeid === (int)$row->delegateid && (int)$row->delegateid !== $primaryid;
            $out[] = [
                'uuid' => (string)$row->uuid,
                'delegate_id' => (int)$row->delegateid,
                'start_date' => gmdate('d.m.Y', (int)$row->startdate),
                'end_date' => gmdate('d.m.Y', (int)$row->enddate),
                'active_now' => $active,
            ];
        }
        return $out;
    }

    /**
     * Read-only manager dashboard during a dated coverage window.
     * Calendar rows are derived ONLY from canonical position-bound tasks. They are
     * NOT events from an employee's personal or private work calendar.
     * Metric snapshots require their own indicator-management permission.
     * No delegation rights, grants, status changes or write operations occur here.
     */
    public static function operational_snapshot(string $companyuuid, int $actor, string $coverageuuid,
            string $requestedday = ''): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $coverage = self::require_record($companyuuid, $coverageuuid);
        $first = (int)$coverage->startdate;
        $last = (int)$coverage->enddate;
        $defaultday = self::day(gmdate('Y-m-d'));
        if ($defaultday < $first || $defaultday > $last) { $defaultday = $first; }
        $chosen = $requestedday === '' ? $defaultday : self::day($requestedday);
        if ($chosen < $first || $chosen > $last) {
            throw new \invalid_parameter_exception('Coverage dashboard date outside authorized range.');
        }
        $weekstart = \local_kbacompany\local\battle_plan_service::week_start(gmdate('Y-m-d', $chosen));
        $weekfrom = self::day($weekstart);
        $weekto = $weekfrom + 6 * 86400;
        $from = gmdate('Y-m-d', max($weekfrom, $first));
        $until = gmdate('Y-m-d', min($weekto, $last));
        $active = (string)$coverage->status === 'approved';

        $prev = $weekfrom - 7 * 86400;
        $next = $weekfrom + 7 * 86400;
        $prevday = $prev + 6 * 86400 >= $first ? gmdate('Y-m-d', max($prev, $first)) : '';
        $nextday = $next <= $last ? gmdate('Y-m-d', max($next, $first)) : '';
        $result = [
            'week_start' => $weekstart, 'week_end' => gmdate('Y-m-d', $weekto),
            'from' => $from, 'until' => $until, 'previous_day' => $prevday, 'next_day' => $nextday,
            'is_approved' => $active, 'tasks_allowed' => false, 'metric_allowed' => false,
            'task_rows' => [], 'metric_rows' => [], 'calendar_allowed' => [],
        ];
        // Draft/rejected/cancelled substitutions have no operational delegation snapshot.
        if (!$active) { return $result; }

        if (task_service::can_manage($companyuuid, $actor)) {
            $result['tasks_allowed'] = true;
            $sql = "SELECT t.id, t.uuid, t.title, t.status, t.duedate, t.startdate
                      FROM {local_kbacompany_task} t
                     WHERE t.companyuuid = :company AND t.dutypositionuuid = :position
                       AND t.assigneeid = :primaryid
                       AND t.status IN ('open','doing','returned')
                       AND ((t.duedate <> '' AND t.duedate BETWEEN :duefrom AND :dueto)
                         OR (t.duedate = '' AND t.startdate BETWEEN :startfrom AND :startto))
                  ORDER BY t.duedate ASC, t.startdate ASC, t.id ASC";
            $rows = $DB->get_records_sql($sql, [
                'company' => $companyuuid, 'position' => (string)$coverage->positionuuid,
                'primaryid' => (int)$coverage->primaryid,
                'duefrom' => $from, 'dueto' => $until,
                'startfrom' => $from, 'startto' => $until,
            ], 0, 50);
            foreach ($rows as $task) {
                $result['task_rows'][] = [
                    'uuid' => (string)$task->uuid, 'title' => (string)$task->title,
                    'status' => (string)$task->status,
                    'day' => (string)($task->duedate ?: $task->startdate),
                ];
            }
        }
        if (indicator_registry_service::can_manage($companyuuid, $actor)) {
            $result['metric_allowed'] = true;
            // The canonical indicator service handles position bindings and all read-only KPI math.
            $metrics = battle_plan_indicator_service::projection($companyuuid, $actor,
                (string)$coverage->positionuuid, $weekstart);
            foreach (array_slice($metrics, 0, 30) as $metric) {
                $result['metric_rows'][] = [
                    'code' => (string)$metric['code'], 'name' => (string)$metric['name'],
                    'unit' => (string)$metric['unit'],
                    'plan' => $metric['current_plan'], 'fact' => $metric['current_fact'],
                    'status' => (string)($metric['performance']['status'] ?? 'nodata'),
                ];
            }
        }
        // Existing explicit work-calendar grants, NOT coverage, determine link eligibility.
        foreach ([(int)$coverage->primaryid, (int)$coverage->delegateid] as $userid) {
            try {
                if (employee_work_calendar_service::allowed($companyuuid, $actor, $userid, 'view')) {
                    $result['calendar_allowed'][] = $userid;
                }
            } catch (\required_capability_exception $e) {
                // Employee directory access may be narrower than coverage management.
                // Absence of an explicit calendar permission is always a closed door.
            }
        }
        return $result;
    }

    /** SQL projection counterpart to effective_executor() (Task Center lists/counts). */
    public static function effective_executor_sql(string $alias, string $suffix, ?int $at = null): array {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias) ||
                !preg_match('/^[a-z][a-z0-9_]*$/D', $suffix)) {
            throw new \coding_exception('Invalid coverage SQL identifiers');
        }
        $p = 'cov' . $suffix;
        // Status review/done is historical, not an active execution obligation.
        $expression = "CASE WHEN {$alias}.status IN ('done','cancelled','deleted','review')
            THEN {$alias}.assigneeid ELSE COALESCE((SELECT cv.delegateid
             FROM {local_kbacompany_poscover} cv
             JOIN {local_kbacompany_position} pos ON pos.companyuuid=cv.companyuuid
               AND pos.uuid=cv.positionuuid AND pos.active=1
             JOIN {local_kbacompany_posassign} pa ON pa.companyuuid=cv.companyuuid
               AND pa.positionuuid=cv.positionuuid AND pa.userid=cv.primaryid
               AND pa.assignmenttype='primary' AND pa.active=1
               AND (pa.effectivefrom=0 OR pa.effectivefrom<=:{$p}atfrom)
               AND (pa.effectiveto=0 OR pa.effectiveto>=:{$p}atto)
             WHERE cv.companyuuid={$alias}.companyuuid
               AND cv.positionuuid={$alias}.dutypositionuuid
               AND cv.primaryid={$alias}.assigneeid
               AND cv.status='approved' AND cv.scope='position_work'
               AND cv.startdate<=:{$p}start AND cv.enddate>=:{$p}end
             ORDER BY cv.id DESC LIMIT 1), {$alias}.assigneeid) END";
        $at = $at ?? time(); $date = self::day(gmdate('Y-m-d', $at));
        return [$expression, ["{$p}atfrom"=>$at, "{$p}atto"=>$at,
            "{$p}start"=>$date, "{$p}end"=>$date]];
    }

    public static function registry(string $companyuuid, int $actor, string $status = 'all', int $page = 0): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $valid = array_merge(['all'], self::STATUSES);
        if (!in_array($status, $valid, true)) { $status = 'all'; }
        $page = max(0, $page);
        $condition = 'companyuuid = :company';
        $params = ['company' => $companyuuid];
        if ($status !== 'all') { $condition .= ' AND status = :status'; $params['status'] = $status; }
        $total = $DB->count_records_select('local_kbacompany_poscover', $condition, $params);
        $rows = $DB->get_records_select('local_kbacompany_poscover', $condition, $params,
            'timecreated DESC, id DESC', '*', $page * 20, 20);
        return ['rows' => array_values($rows), 'total' => $total,
            'page' => $page, 'pagesize' => 20, 'status' => $status];
    }
}
