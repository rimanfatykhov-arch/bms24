<?php
namespace local_kbacompany\local;
defined('MOODLE_INTERNAL') || die();
/** Append-only decisions tied to visible weekly coordination tasks. */
final class battle_plan_protocol_service {
    private const TABLE = 'local_kbacompany_bp_proto';

    /** Reject impossible dates; DateTimeImmutable otherwise normalises 31 February. */
    public static function valid_iso_date(string $date): bool {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }

    private static function accessible(string $companyuuid, int $actor, string $week, string $orgnode): array {
        if (!has_capability('local/kbacompany:manageorganization', \context_system::instance(), $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        $context = battle_plan_week_coordination_service::context($companyuuid, $actor, $week, $orgnode);
        $visible = [];
        foreach ((array)($context['previous_week_followup']['rows'] ?? []) as $employee) {
            foreach ((array)($employee['open_items'] ?? []) as $task) {
                if (!empty($task['uuid'])) { $visible[(string)$task['uuid']] = true; }
            }
        }
        return [$context, $visible];
    }

    public static function latest(string $companyuuid, int $actor, string $week,
            string $orgnode, array $taskuuids): array {
        global $DB;
        list($context, $visible) = self::accessible($companyuuid, $actor, $week, $orgnode);
        $week = (string)$context['week_start'];
        $result = [];
        foreach ($taskuuids as $taskuuid) {
            if (!isset($visible[$taskuuid])) { continue; }
            $records = $DB->get_records(self::TABLE,
                ['companyuuid'=>$companyuuid,'weekstart'=>$week,'taskuuid'=>$taskuuid],
                'revision DESC', '*', 0, 1);
            if ($records) { $result[$taskuuid] = reset($records); }
        }
        return $result;
    }

    /** Latest saved decisions for visible follow-up tasks, regardless of the 30-card editor limit. */
    public static function decision_queue(string $companyuuid, int $actor, string $week,
            string $orgnode): array {
        global $DB;
        list($context, $visible) = self::accessible($companyuuid, $actor, $week, $orgnode);
        if (!$visible) { return []; }
        $week = (string)$context['week_start'];
        $items = [];
        foreach ((array)($context['previous_week_followup']['rows'] ?? []) as $employee) {
            foreach ((array)($employee['open_items'] ?? []) as $task) {
                $id = (string)($task['uuid'] ?? '');
                if ($id !== '' && isset($visible[$id])) {
                    $items[$id] = ['title'=>(string)($task['title'] ?? ''),
                        'employee'=>(string)($employee['employee_name'] ?? '')];
                }
            }
        }
        $ids = array_keys($items);
        $result = [];
        // Chunk the query to avoid database driver parameter limits.
        foreach (array_chunk($ids, 200) as $chunk) {
            list($insql, $inparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'taskid');
            $params = ['company'=>$companyuuid, 'week'=>$week] + $inparams;
            $sql = "SELECT p.* FROM {local_kbacompany_bp_proto} p
                     JOIN (SELECT taskuuid, MAX(revision) AS latestrevision
                             FROM {local_kbacompany_bp_proto}
                            WHERE companyuuid = :company AND weekstart = :week
                              AND taskuuid $insql GROUP BY taskuuid) r
                       ON r.taskuuid = p.taskuuid AND r.latestrevision = p.revision
                    WHERE p.companyuuid = :companyouter AND p.weekstart = :weekouter";
            $params['companyouter'] = $companyuuid;
            $params['weekouter'] = $week;
            foreach ($DB->get_records_sql($sql, $params) as $record) {
                $record->tasktitle = $items[$record->taskuuid]['title'] ?? '';
                $record->employeename = $items[$record->taskuuid]['employee'] ?? '';
                $result[] = $record;
            }
        }
        usort($result, static function($a, $b) {
            return strcmp((string)$a->duedate ?: '9999-12-31', (string)$b->duedate ?: '9999-12-31');
        });
        return $result;
    }

    /** Responsible picker for company decision authors/assignees, management-only. */
    public static function company_owner_options(string $companyuuid, int $actor): array {
        global $DB;
        if (!permission_service::can_manage_management($companyuuid, $actor) ||
                !has_capability('local/kbacompany:manageorganization', \context_system::instance(), $actor)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        battle_plan_management_overview_service::context($companyuuid, $actor, '', '', 0, 'all');
        $ids = $DB->get_fieldset_sql('SELECT DISTINCT owneruserid FROM {local_kbacompany_bp_proto}
            WHERE companyuuid = :company AND owneruserid > 0', ['company'=>$companyuuid]);
        if (!$ids) { return []; }
        $users = $DB->get_records_list('user', 'id', $ids, '', 'id,firstname,lastname');
        $out = [];
        foreach ($users as $user) { $out[(int)$user->id] = fullname($user); }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /** Company-scoped cross-week registry, latest revision only; all filters are SQL-bound. */
    public static function company_registry(string $companyuuid, int $actor, array $filters, int $page = 0): array {
        global $DB;
        if (!permission_service::can_manage_management($companyuuid, $actor) ||
                !has_capability('local/kbacompany:manageorganization', \context_system::instance(), $actor)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        // Canonical overview checks membership/company scope in addition to global capability.
        battle_plan_management_overview_service::context($companyuuid, $actor, '', '', 0, 'all');
        $where = ['p.companyuuid = :company'];
        $params = ['company' => $companyuuid];
        $status = (string)($filters['status'] ?? '');
        if (in_array($status, ['pending', 'inprogress', 'completed', 'cancelled'], true)) {
            $where[] = 'p.executionstatus = :status'; $params['status'] = $status;
        }
        $owner = (int)($filters['owner'] ?? 0);
        if ($owner > 0) { $where[] = 'p.owneruserid = :owner'; $params['owner'] = $owner; }
        foreach (['from'=>'weekfrom','to'=>'weekto'] as $filter => $field) {
            $value = (string)($filters[$filter] ?? '');
            if ($value !== '') {
                $where[] = 'p.weekstart ' . ($filter === 'from' ? '>=' : '<=') . ' :' . $field;
                $params[$field] = $value;
            }
        }
        $today = date('Y-m-d');
        if (!empty($filters['overdue'])) {
            $where[] = "p.executionstatus IN ('pending','inprogress')";
            $where[] = "p.duedate <> '' AND p.duedate < :today";
            $params['today'] = $today;
        }
        $where[] = 'NOT EXISTS (SELECT 1 FROM {local_kbacompany_bp_proto} newer
            WHERE newer.companyuuid = p.companyuuid AND newer.weekstart = p.weekstart
            AND newer.taskuuid = p.taskuuid AND newer.revision > p.revision)';
        $condition = implode(' AND ', $where);
        $count = (int)$DB->count_records_sql('SELECT COUNT(1) FROM {local_kbacompany_bp_proto} p WHERE '.$condition, $params);
        $pagesize = 30;
        $page = max(0, min($page, 100000));
        $records = array_values($DB->get_records_sql('SELECT p.* FROM {local_kbacompany_bp_proto} p WHERE '.
            $condition.' ORDER BY p.weekstart DESC, p.duedate ASC, p.id DESC', $params,
            $page * $pagesize, $pagesize));
        return ['total'=>$count, 'rows'=>$records, 'pagesize'=>$pagesize, 'page'=>$page];
    }

    /** Single cross-week decision, manager-scoped. Read only; never grants task editing rights. */
    public static function company_decision(string $companyuuid, int $actor,
            string $week, string $taskuuid): ?\stdClass {
        global $DB;
        if (!permission_service::can_manage_management($companyuuid, $actor) ||
                !has_capability('local/kbacompany:manageorganization', \context_system::instance(), $actor)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        battle_plan_management_overview_service::context($companyuuid, $actor, '', '', 0, 'all');
        $records = $DB->get_records(self::TABLE,
            ['companyuuid'=>$companyuuid, 'weekstart'=>$week, 'taskuuid'=>$taskuuid],
            'revision DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /** Manager-confirmed execution status; append-only, never modifies Task Center. */
    public static function change_status(string $companyuuid, int $actor, string $week,
            string $orgnode, string $taskuuid, int $expectedrevision, string $newstatus): void {
        global $DB;
        if (!in_array($newstatus, ['pending', 'inprogress', 'completed', 'cancelled'], true)) {
            throw new \invalid_parameter_exception('Invalid execution status');
        }
        list($context, $visible) = self::accessible($companyuuid, $actor, $week, $orgnode);
        if (!isset($visible[$taskuuid])) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $week = (string)$context['week_start'];
        $transaction = $DB->start_delegated_transaction();
        $records = $DB->get_records(self::TABLE,
            ['companyuuid'=>$companyuuid, 'weekstart'=>$week, 'taskuuid'=>$taskuuid],
            'revision DESC', '*', 0, 1);
        $latest = $records ? reset($records) : null;
        if (!$latest || (int)$latest->revision !== $expectedrevision) {
            throw new \moodle_exception('battleplan_protocol_conflict', 'local_kbacompany');
        }
        if ((string)$latest->executionstatus === $newstatus) {
            $transaction->allow_commit();
            return;
        }
        $next = clone $latest;
        unset($next->id);
        $next->revision = (int)$latest->revision + 1;
        $next->executionstatus = $newstatus;
        $next->createdby = $actor;
        $next->timecreated = time();
        $DB->insert_record(self::TABLE, $next);
        $transaction->allow_commit();
    }

    /** Safely update one latest decision, if it is editable in its weekly coordination. */
    public static function bulk_change_status(string $companyuuid, int $actor,
            int $recordid, string $newstatus): bool {
        global $DB;
        if (!in_array($newstatus, ['pending', 'inprogress', 'completed', 'cancelled'], true)) {
            throw new \invalid_parameter_exception('Invalid status');
        }
        if (!permission_service::can_manage_management($companyuuid, $actor) ||
                !has_capability('local/kbacompany:manageorganization', \context_system::instance(), $actor)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $entry = $DB->get_record(self::TABLE, ['id'=>$recordid, 'companyuuid'=>$companyuuid]);
        if (!$entry) { return false; }
        // Only the current revision is selectable; stale selections never overwrite updates.
        $latest = $DB->get_records(self::TABLE, ['companyuuid'=>$companyuuid,
            'weekstart'=>$entry->weekstart, 'taskuuid'=>$entry->taskuuid],
            'revision DESC', 'id,revision', 0, 1);
        if (!$latest || (int)reset($latest)->id !== $recordid) { return false; }
        // Delegate final weekly-task visibility and version checks to the canonical service.
        self::change_status($companyuuid, $actor, (string)$entry->weekstart, '',
            (string)$entry->taskuuid, (int)$entry->revision, $newstatus);
        return true;
    }

    /** Bulk owner/deadline: per-record checks and append-only history. */
    public static function bulk_change_assignment(string $companyuuid, int $actor,
            int $recordid, string $operation, int $owneruserid, string $duedate): bool {
        global $DB;
        if (!in_array($operation, ['owner', 'duedate'], true)) {
            throw new \invalid_parameter_exception('Invalid bulk operation');
        }
        if (!permission_service::can_manage_management($companyuuid, $actor) ||
                !has_capability('local/kbacompany:manageorganization', \context_system::instance(), $actor)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $record = $DB->get_record(self::TABLE, ['id'=>$recordid, 'companyuuid'=>$companyuuid]);
        if (!$record) { return false; }
        // The same weekly context / visibility requirements as a single edit.
        list($context, $visible) = self::accessible($companyuuid, $actor, (string)$record->weekstart, '');
        if (!isset($visible[(string)$record->taskuuid]) ||
                (string)$context['week_start'] !== (string)$record->weekstart) { return false; }
        if ($operation === 'owner') {
            if ($owneruserid < 1 || !isset(self::owner_options($context)[$owneruserid]) ||
                    !$DB->record_exists('user', ['id'=>$owneruserid, 'deleted'=>0])) { return false; }
        } else if ($duedate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $duedate) ||
                !self::valid_iso_date($duedate))) {
            throw new \invalid_parameter_exception('Invalid due date');
        }
        $transaction = $DB->start_delegated_transaction();
        $latestlist = $DB->get_records(self::TABLE,
            ['companyuuid'=>$companyuuid, 'weekstart'=>$record->weekstart, 'taskuuid'=>$record->taskuuid],
            'revision DESC', '*', 0, 1);
        $latest = $latestlist ? reset($latestlist) : null;
        if (!$latest || (int)$latest->id !== $recordid) {
            $transaction->allow_commit();
            return false;
        }
        $next = clone $latest;
        unset($next->id);
        if ($operation === 'owner') {
            if ((int)$latest->owneruserid === $owneruserid) { $transaction->allow_commit(); return false; }
            $next->owneruserid = $owneruserid;
        } else {
            if ((string)$latest->duedate === $duedate) { $transaction->allow_commit(); return false; }
            $next->duedate = $duedate;
        }
        $next->revision = (int)$latest->revision + 1;
        $next->createdby = $actor;
        $next->timecreated = time();
        $DB->insert_record(self::TABLE, $next);
        $transaction->allow_commit();
        return true;
    }

    /** Verified revision trail for tasks visible in the weekly manager worksheet. */
    public static function history(string $companyuuid, int $actor, string $week,
            string $orgnode, array $taskuuids): array {
        global $DB;
        list($context, $visible) = self::accessible($companyuuid, $actor, $week, $orgnode);
        $week = (string)$context['week_start'];
        $result = [];
        foreach (array_unique($taskuuids) as $taskuuid) {
            if (!isset($visible[$taskuuid])) { continue; }
            $result[$taskuuid] = array_values($DB->get_records(self::TABLE,
                ['companyuuid'=>$companyuuid,'weekstart'=>$week,'taskuuid'=>$taskuuid],
                'revision DESC', '*', 0, 25));
        }
        return $result;
    }

    /** Scoped staff picker: weekly management view, previous followups and filtered history. */
    public static function owner_options(array $context): array {
        $options = [];
        foreach (['previous_week_followup', 'overview', 'previous_overview'] as $key) {
            $section = (array)($context[$key] ?? []);
            foreach ((array)($section['rows'] ?? []) as $employee) {
                $id = (int)($employee['employee_id'] ?? 0);
                $name = trim((string)($employee['employee_name'] ?? ''));
                if ($id > 0 && $name !== '') { $options[$id] = $name; }
            }
        }
        // Do not expand assignment scope to arbitrary Moodle accounts.
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    public static function save(string $companyuuid, int $actor, string $week, string $orgnode,
            string $taskuuid, int $expectedrevision, string $reason, string $decision,
            int $owneruserid, string $duedate): void {
        global $DB;
        list($context, $visible) = self::accessible($companyuuid, $actor, $week, $orgnode);
        if (!isset($visible[$taskuuid])) { throw new \moodle_exception('nopermissions', 'error'); }
        $week = (string)$context['week_start'];
        $allowedowners = self::owner_options($context);
        if (!isset($allowedowners[$owneruserid])) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $reason = trim($reason); $decision = trim($decision);
        if ($reason === '' || $decision === '' || \core_text::strlen($reason) > 4000 ||
                \core_text::strlen($decision) > 4000 || $owneruserid < 1 ||
                !$DB->record_exists('user', ['id'=>$owneruserid,'deleted'=>0])) {
            throw new \invalid_parameter_exception('Invalid coordination protocol fields');
        }
        if ($duedate !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $duedate) ||
                !\DateTimeImmutable::createFromFormat('!Y-m-d', $duedate))) {
            throw new \invalid_parameter_exception('Invalid due date');
        }
        $transaction = $DB->start_delegated_transaction();
        $existing = $DB->get_records(self::TABLE,
            ['companyuuid'=>$companyuuid,'weekstart'=>$week,'taskuuid'=>$taskuuid],
            'revision DESC', '*', 0, 1);
        $revision = $existing ? (int)reset($existing)->revision : 0;
        if ($revision !== $expectedrevision) {
            throw new \moodle_exception('battleplan_protocol_conflict','local_kbacompany');
        }
        $DB->insert_record(self::TABLE, (object)[
            'companyuuid'=>$companyuuid,'weekstart'=>$week,'taskuuid'=>$taskuuid,
            'revision'=>$revision+1,'reason'=>$reason,'decisiontext'=>$decision,
            'owneruserid'=>$owneruserid,'duedate'=>$duedate,
            'executionstatus'=>$existing ? (string)reset($existing)->executionstatus : 'pending',
            'createdby'=>$actor,'timecreated'=>time(),
        ]);
        $transaction->allow_commit();
    }
}
