<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Canonical company task center. Battle plans reference these tasks instead of duplicating them. */
class task_service {
    public const CONTRACT_VERSION = '0.5.8';
    public const STATUSES = ['open', 'doing', 'review', 'returned', 'done', 'cancelled', 'deleted'];
    public const STREAMS = ['change', 'run', 'operate', 'develop', 'unplanned'];
    public const PRIORITIES = ['low', 'normal', 'high'];
    public const CONTEXT_KINDS = ['longtermgoal', 'goal', 'policy', 'plan', 'program', 'programstep', 'project', 'decision'];
    public const SOURCE_KINDS = ['manual', 'adminscale', 'positionwork', 'learning', 'legacy', 'communication', 'decision', 'recurring', 'template', 'bplantemplate', 'condition'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_task_center',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['Task', 'TaskChecklistItem', 'TaskHistory'],
            'states' => self::STATUSES,
            'streams' => self::STREAMS,
            'priorities' => self::PRIORITIES,
            'sources' => self::SOURCE_KINDS,
            'rules' => [
                'task_is_canonical_execution_object',
                'all_task_sources_converge_in_one_task_center',
                'expected_product_is_required',
                'administrative_scale_task_remains_definition_not_execution_fact',
                'position_work_item_remains_recurring_definition_not_execution_fact',
                'generated_task_pins_exact_source_revision',
                'adminscale_generated_task_keeps_immediate_management_context',
                'battle_plan_references_task_instead_of_copying_it',
                'participant_or_management_visibility',
                'optimistic_task_revision',
                'task_result_is_recorded_before_completion',
                'legacy_workflow_is_not_destructively_migrated',
                'decision_task_preserves_project_traceability_via_meeting_source',
                'recurring_definition_generates_idempotent_canonical_task_occurrences',
                'library_template_can_create_idempotent_one_off_task_for_battle_plan_week',
                'task_creator_can_choose_any_company_employee_including_self_as_assignee',
                'task_attachments_use_moodle_file_api_and_task_visibility',
                'task_discussion_reuses_participant_scoped_communication_threads',
                'task_delete_is_soft_audited_and_removed_from_execution_views',
                'task_result_files_are_separate_protected_evidence_area',
                'task_observer_has_read_and_discussion_access_without_execution_authority',
                'battle_plan_template_rows_generate_idempotent_canonical_tasks_for_one_week',
                'task_can_reference_one_versioned_management_context_or_parent_task',
                'task_creation_can_add_same_canonical_task_to_selected_draft_battle_plan',
                'indicator_condition_confirmation_can_source_canonical_task_without_task_copy',
                'position_coverage_only_executes_explicit_position_bound_tasks_without_reassigning_original',
                'pattern_trace_lookup_reuses_canonical_task_visibility',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function can_manage(string $companyuuid, int $userid): bool {
        return permission_service::can_manage_tasks($companyuuid, $userid)
            || access_role_service::can($companyuuid, $userid, 'task', 'edit');
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('taskcenter_' . $key, 'local_kbacompany');
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) .
            '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    /**
     * Calendar-date boundaries in the current Moodle user's timezone.
     *
     * Task deadlines are stored as YYYY-MM-DD dates, not UTC instants. Always
     * compare with the same local calendar day used in the workspace and
     * Battle Plans; otherwise a task can show as overdue in one view but not
     * another around midnight. The six-day horizon is calendar arithmetic so
     * daylight-saving transitions do not shorten/extend the seven-day window.
     *
     * @param int|null $timestamp Timestamp injection for deterministic tests.
     * @return array{today: string, until: string} Seven dates including today.
     */
    public static function reporting_dates(?int $timestamp = null): array {
        $today = business_date_service::day($timestamp ?? time());
        $base = \DateTimeImmutable::createFromFormat('!Y-m-d', $today, new \DateTimeZone('UTC'));
        if ($base === false) {
            throw new \coding_exception('Task Center expected an ISO local calendar date');
        }
        return ['today' => $today, 'until' => $base->modify('+6 days')->format('Y-m-d')];
    }

    /**
     * Task deadlines: five active calendar groups and a separate closed group.
     * A closed task must never be shown as overdue, regardless of its saved due date.
     * Dates are ISO calendar days in the Moodle user's timezone (reporting_dates()).
     */
    public static function deadline_bucket(string $duedate, string $status, array $dates): string {
        if (in_array($status, ['done', 'cancelled'], true)) { return 'closed'; }
        if ($duedate === '') { return 'undated'; }
        if ($duedate < $dates['today']) { return 'overdue'; }
        if ($duedate === $dates['today']) { return 'today'; }
        if ($duedate <= $dates['until']) { return 'week'; }
        return 'later';
    }

    public static function normalise_duegroup(string $group): string {
        return in_array($group, ['overdue','today','week','later','undated','closed'], true) ? $group : '';
    }

    /** Pure, whitelisted SQL predicates, always ANDed to list_conditions ACL/company clauses. */
    private static function deadline_group_condition(string $group, array $dates): array {
        $active = "t.status NOT IN ('done','cancelled')";
        switch ($group) {
            case 'overdue': return ["$active AND t.duedate <> '' AND t.duedate < :groupoverdue", ['groupoverdue'=>$dates['today']]];
            case 'today': return ["$active AND t.duedate = :grouptoday", ['grouptoday'=>$dates['today']]];
            case 'week': return ["$active AND t.duedate > :groupafter AND t.duedate <= :groupuntil",
                ['groupafter'=>$dates['today'], 'groupuntil'=>$dates['until']]];
            case 'later': return ["$active AND t.duedate > :grouplater", ['grouplater'=>$dates['until']]];
            case 'undated': return ["$active AND t.duedate = ''", []];
            case 'closed': return ["t.status IN ('done','cancelled')", []];
            default: return ['1=1', []];
        }
    }

    /**
     * Page-independent bucket totals for the *same* permitted selection as Task List.
     * Selecting a bucket only affects list_page, not the totals shown in the navigator.
     */
    public static function deadline_summary(string $companyuuid, int $actor, array $filters = []): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        unset($filters['duegroup']);
        [$where, $params] = self::list_conditions($companyuuid, $actor, $filters);
        $dates = self::reporting_dates();
        $active = "t.status NOT IN ('done','cancelled')";
        $sql = "SELECT COUNT(t.id) AS total,
            SUM(CASE WHEN $active AND t.duedate <> '' AND t.duedate < :dsover THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE WHEN $active AND t.duedate = :dstoday THEN 1 ELSE 0 END) AS today,
            SUM(CASE WHEN $active AND t.duedate > :dsafter AND t.duedate <= :dsuntil THEN 1 ELSE 0 END) AS week,
            SUM(CASE WHEN $active AND t.duedate > :dslater THEN 1 ELSE 0 END) AS later,
            SUM(CASE WHEN $active AND t.duedate = '' THEN 1 ELSE 0 END) AS undated,
            SUM(CASE WHEN t.status IN ('done','cancelled') THEN 1 ELSE 0 END) AS closed
            FROM {local_kbacompany_task} t WHERE $where";
        $r = $DB->get_record_sql($sql, $params + [
            'dsover'=>$dates['today'], 'dstoday'=>$dates['today'], 'dsafter'=>$dates['today'],
            'dsuntil'=>$dates['until'], 'dslater'=>$dates['until'],
        ]);
        $summary = [];
        foreach (['total','overdue','today','week','later','undated','closed'] as $key) {
            $summary[$key] = (int)($r->$key ?? 0);
        }
        return $summary;
    }

    private static function text(array $data, string $key, int $max, bool $required = false): string {
        $value = trim((string)($data[$key] ?? ''));
        if (($required && $value === '') || \core_text::strlen($value) > $max) {
            self::fail('invalid');
        }
        return $value;
    }

    private static function date(array $data, string $key): string {
        $value = self::text($data, $key, 10, false);
        if ($value === '') { return ''; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $value) { self::fail('invalid'); }
        return $value;
    }

    private static function clock(array $data, string $key, string $default = ''): string {
        $value = trim((string)($data[$key] ?? $default));
        if ($value === '') { return ''; }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value)) { self::fail('date'); }
        return $value;
    }

    private static function employees(string $companyuuid, int $actor): array {
        $organization = organization_service::organization_context($companyuuid, $actor);
        $employees = [];
        foreach ($organization['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        return $employees;
    }

    private static function require_employee(array $employees, int $userid): void {
        if ($userid <= 0 || !isset($employees[$userid])) { self::fail('employee'); }
    }

    private static function task(string $companyuuid, string $taskuuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_task', ['companyuuid' => $companyuuid, 'uuid' => $taskuuid]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }


    private static function discussion_thread(string $companyuuid, string $taskuuid): ?\stdClass {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_commthread', [
            'companyuuid' => $companyuuid, 'threadtype' => 'object', 'contextkind' => 'task',
            'contextuuid' => $taskuuid, 'status' => 'active',
        ], 'id ASC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    private static function ensure_discussion_member(string $companyuuid, string $threaduuid, int $userid): void {
        global $DB;
        if ($userid <= 0) { return; }
        $existing = $DB->get_record('local_kbacompany_commpart', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $userid,
        ]);
        $now = time();
        if ($existing) {
            if (empty($existing->active)) {
                $existing->active = 1; $existing->leftat = 0; $existing->joinedat = $now;
                $DB->update_record('local_kbacompany_commpart', $existing);
            }
        } else {
            $DB->insert_record('local_kbacompany_commpart', (object)[
                'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $userid,
                'role' => 'member', 'active' => 1, 'muted' => 0, 'joinedat' => $now, 'leftat' => 0, 'timecreated' => $now,
            ]);
        }
        if (!$DB->record_exists('local_kbacompany_commread', [
            'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $userid,
        ])) {
            $DB->insert_record('local_kbacompany_commread', (object)[
                'companyuuid' => $companyuuid, 'threaduuid' => $threaduuid, 'userid' => $userid,
                'lastmessageid' => 0, 'timeread' => $now,
            ]);
        }
    }

    private static function sync_discussion(\stdClass $row): void {
        global $DB;
        $thread = self::discussion_thread((string)$row->companyuuid, (string)$row->uuid);
        if (!$thread) { return; }
        $allowed = array_values(array_unique(array_filter([(int)$row->creatorid, (int)$row->assigneeid, (int)$row->reviewerid, (int)$row->observerid])));
        foreach ($allowed as $userid) {
            self::ensure_discussion_member((string)$row->companyuuid, (string)$thread->uuid, (int)$userid);
        }
        foreach ($DB->get_records('local_kbacompany_commpart', [
            'companyuuid' => (string)$row->companyuuid, 'threaduuid' => (string)$thread->uuid, 'active' => 1,
        ]) as $part) {
            if (!in_array((int)$part->userid, $allowed, true)) {
                $part->active = 0; $part->leftat = time();
                $DB->update_record('local_kbacompany_commpart', $part);
            }
        }
        $thread->title = get_string('taskcenter_discussion_title', 'local_kbacompany', (string)$row->title);
        $thread->contextrevision = (int)$row->revision;
        $thread->timemodified = time();
        $DB->update_record('local_kbacompany_commthread', $thread);
    }

    private static function ensure_discussion(string $companyuuid, int $actor, \stdClass $row): string {
        global $DB;
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('taskdiscussion:' . $companyuuid . ':' . $row->uuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $thread = self::discussion_thread($companyuuid, (string)$row->uuid);
            if (!$thread) {
                $participants = array_values(array_unique(array_filter([
                    (int)$row->creatorid, (int)$row->assigneeid, (int)$row->reviewerid, (int)$row->observerid,
                ])));
                $participants = array_values(array_diff($participants, [$actor]));
                $threaduuid = communication_service::create_thread($companyuuid, $actor, [
                    'threadtype' => 'object',
                    'title' => get_string('taskcenter_discussion_title', 'local_kbacompany', (string)$row->title),
                    'contextkind' => 'task', 'contextuuid' => (string)$row->uuid,
                    'contextrevision' => (int)$row->revision, 'participants' => $participants,
                ]);
                $thread = $DB->get_record('local_kbacompany_commthread', [
                    'companyuuid' => $companyuuid, 'uuid' => $threaduuid,
                ]);
            }
            foreach (array_unique([(int)$row->creatorid, (int)$row->assigneeid, (int)$row->reviewerid, (int)$row->observerid, $actor]) as $userid) {
                self::ensure_discussion_member($companyuuid, (string)$thread->uuid, (int)$userid);
            }
            return (string)$thread->uuid;
        } finally { $lock->release(); }
    }

    /** Return the newest revision for a versioned source UUID, regardless of lifecycle state. */
    private static function latest_source(string $table, string $companyuuid, string $sourceuuid, array $extra = []): ?\stdClass {
        global $DB;
        $conditions = array_merge(['companyuuid' => $companyuuid, 'uuid' => $sourceuuid], $extra);
        $rows = $DB->get_records($table, $conditions, 'revision DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /** Reject self and indirect parent cycles in canonical task/subtask hierarchy. */
    private static function validate_parent_chain(string $companyuuid, string $parentuuid, string $taskuuid = ''): void {
        if ($parentuuid === '') { return; }
        $seen = [];
        $cursor = $parentuuid;
        while ($cursor !== '') {
            if ($cursor === $taskuuid || isset($seen[$cursor])) { self::fail('parent'); }
            $seen[$cursor] = true;
            $parent = self::task($companyuuid, $cursor);
            $cursor = (string)$parent->parentuuid;
        }
    }

    /** Immutable original assignee plus date-scoped delegate for explicitly classified work. */
    public static function effective_assignee(\stdClass $row): int {
        if (in_array((string)$row->status, ['done','review','cancelled','deleted'], true)) {
            return (int)$row->assigneeid;
        }
        return position_coverage_service::effective_executor((string)$row->companyuuid,
            (string)($row->dutypositionuuid ?? ''), (int)$row->assigneeid);
    }

    /** Manager-owned choice list. Never automatically classifies ambiguous legacy tasks. */
    public static function duty_position_options(string $companyuuid, int $actor, int $primaryid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $now = time();
        $sql = "SELECT p.id, p.uuid, p.name FROM {local_kbacompany_position} p
                 JOIN {local_kbacompany_posassign} a ON a.companyuuid=p.companyuuid
                  AND a.positionuuid=p.uuid AND a.userid=:person
                  AND a.assignmenttype='primary' AND a.active=1
                  AND (a.effectivefrom=0 OR a.effectivefrom<=:fromat)
                  AND (a.effectiveto=0 OR a.effectiveto>=:toat)
                 WHERE p.companyuuid=:company AND p.active=1 ORDER BY p.name,p.id";
        $choices = [''=>get_string('coverage_task_personal','local_kbacompany')];
        foreach ($DB->get_records_sql($sql, ['person'=>$primaryid,'fromat'=>$now,'toat'=>$now,
                'company'=>$companyuuid]) as $position) {
            $choices[(string)$position->uuid] = (string)$position->name;
        }
        return $choices;
    }

    /** Read-only preview for a coverage card; never changes task ownership or grants. */
    public static function position_duty_preview(string $companyuuid, int $actor,
            string $positionuuid, int $primaryid, int $limit = 20): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $sql = "SELECT t.* FROM {local_kbacompany_task} t
                 WHERE t.companyuuid=:company AND t.dutypositionuuid=:position
                   AND t.assigneeid=:primaryid
                   AND t.status IN ('open','doing','returned')
              ORDER BY t.duedate, t.id";
        $employees = self::employees($companyuuid, $actor);
        $out = [];
        foreach ($DB->get_records_sql($sql,['company'=>$companyuuid,'position'=>$positionuuid,
                'primaryid'=>$primaryid],0,max(1,min(50,$limit))) as $row) {
            $out[] = self::row_dto($row, $employees, true);
        }
        return $out;
    }

    /** Explicit manager classification with optimistic locking and a canonical task audit. */
    public static function classify_duty(string $companyuuid, int $actor, string $taskuuid,
            int $expectedrevision, string $positionuuid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $row = self::task($companyuuid, $taskuuid);
            if ((int)$row->revision !== $expectedrevision) { self::fail('conflict'); }
            if (!in_array((string)$row->status, ['open','doing','returned'], true)) { self::fail('state'); }
            $choices = self::duty_position_options($companyuuid, $actor, (int)$row->assigneeid);
            if (!array_key_exists($positionuuid, $choices)) { self::fail('permission'); }
            $previous = (string)($row->dutypositionuuid ?? '');
            if ($previous !== $positionuuid) {
                $row->dutypositionuuid = $positionuuid;
                $row->revision++; $row->timemodified = time();
                $DB->update_record('local_kbacompany_task', $row);
                self::log($companyuuid, $taskuuid, $actor, 'duty_classification',
                    (string)$row->status, (string)$row->status, [
                        'previouspositionuuid'=>$previous,'positionuuid'=>$positionuuid]);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** A single SQL expression for scoped list/count projections, including temporary coverage. */
    private static function effective_sql(string $suffix): array {
        return position_coverage_service::effective_executor_sql('t', $suffix);
    }

    private static function can_view_via_battle_plan(\stdClass $row, int $actor): bool {
        global $DB;
        if ($actor <= 0) { return false; }
        $sql = "SELECT bp.id
                  FROM {local_kbacompany_bplanitem} bi
                  JOIN {local_kbacompany_bplan} bp
                    ON bp.companyuuid = bi.companyuuid AND bp.uuid = bi.planuuid
                 WHERE bi.companyuuid = :companyuuid
                   AND bi.provider = :provider
                   AND bi.externalid = :taskuuid
                   AND (bp.userid = :ownerid OR bp.reviewerid = :reviewerid OR bp.observerid = :observerid)";
        return $DB->record_exists_sql($sql, [
            'companyuuid' => (string)$row->companyuuid, 'provider' => 'bms', 'taskuuid' => (string)$row->uuid,
            'ownerid' => $actor, 'reviewerid' => $actor, 'observerid' => $actor,
        ]);
    }

    private static function can_view_row(\stdClass $row, int $actor, bool $canmanage): bool {
        $departmentids = access_role_service::department_view_userids((string)$row->companyuuid, $actor, 'task');
        if ((string)$row->status === 'deleted') { return false; }
        return $canmanage || access_role_service::may_act_on_department_task((string)$row->companyuuid,
            $actor, (int)$row->assigneeid, 'edit') ||
            access_role_service::may_act_on_department_task((string)$row->companyuuid,
            $actor, (int)$row->assigneeid, 'approve') || in_array((int)$row->assigneeid, $departmentids, true) || (int)$row->assigneeid === $actor || self::effective_assignee($row) === $actor ||
            (int)$row->creatorid === $actor ||
            (int)$row->reviewerid === $actor || (int)$row->observerid === $actor ||
            self::can_view_via_battle_plan($row, $actor);
    }

    /** Viewing a department task must not imply permission to mutate its content. */
    private static function can_contribute_to_task(\stdClass $row, int $actor, bool $canmanage): bool {
        return $canmanage || (int)$row->creatorid === $actor ||
            self::effective_assignee($row) === $actor || (int)$row->reviewerid === $actor ||
            (int)$row->observerid === $actor ||
            access_role_service::may_act_on_department_task((string)$row->companyuuid,
                $actor, (int)$row->assigneeid, 'edit');
    }

    /** Reading a task does not grant write access to its discussion. */
    private static function can_post_discussion(\stdClass $row, int $actor, bool $canmanage): bool {
        return self::can_contribute_to_task($row, $actor, $canmanage) ||
            access_role_service::may_act_on_department_task((string)$row->companyuuid,
                $actor, (int)$row->assigneeid, 'approve') ||
            access_role_service::can((string)$row->companyuuid, $actor, 'task', 'comment');
    }

    private static function can_edit_definition(\stdClass $row, int $actor, bool $canmanage): bool {
        return $canmanage || (int)$row->creatorid === $actor ||
            access_role_service::may_act_on_department_task((string)$row->companyuuid,
                $actor, (int)$row->assigneeid, 'edit');
    }

    private static function can_execute(\stdClass $row, int $actor, bool $canmanage): bool {
        return $canmanage || self::effective_assignee($row) === $actor;
    }

    private static function log(string $companyuuid, string $taskuuid, int $actor, string $action,
            string $oldstatus = '', string $newstatus = '', array $details = []): void {
        global $DB;
        $DB->insert_record('local_kbacompany_tasklog', (object)[
            'companyuuid' => $companyuuid,
            'taskuuid' => $taskuuid,
            'actorid' => $actor,
            'action' => $action,
            'oldstatus' => $oldstatus,
            'newstatus' => $newstatus,
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'timecreated' => time(),
        ]);
    }

    private static function validate_context_reference(string $companyuuid, string $kind, string $uuid, int $revision): void {
        global $DB;
        if ($kind === '') {
            if ($uuid !== '' || $revision !== 0) { self::fail('context'); }
            return;
        }
        if (!in_array($kind, self::CONTEXT_KINDS, true) || $uuid === '' || $revision <= 0) { self::fail('context'); }
        if (!$DB->record_exists('local_kbacompany_adminitem', [
            'companyuuid' => $companyuuid, 'uuid' => $uuid, 'revision' => $revision, 'kind' => $kind,
        ])) { self::fail('context'); }
    }

    private static function management_context_candidates(string $companyuuid, string $search = ''): array {
        global $DB;
        $out = [];
        foreach (self::CONTEXT_KINDS as $kind) { $out[$kind] = []; }
        $sql = "SELECT a.* FROM {local_kbacompany_adminitem} a
                 WHERE a.companyuuid = :ctxcompany AND a.status = 'published'
                   AND NOT EXISTS (SELECT newer.id FROM {local_kbacompany_adminitem} newer
                        WHERE newer.companyuuid = a.companyuuid AND newer.uuid = a.uuid
                          AND newer.status = 'published' AND newer.revision > a.revision)
                   AND NOT EXISTS (SELECT archived.id FROM {local_kbacompany_adminitem} archived
                        WHERE archived.companyuuid = a.companyuuid AND archived.uuid = a.uuid
                          AND archived.status = 'archived' AND archived.revision > a.revision
                          AND NOT EXISTS (SELECT lastrev.id FROM {local_kbacompany_adminitem} lastrev WHERE lastrev.companyuuid=archived.companyuuid AND lastrev.uuid=archived.uuid AND lastrev.revision>archived.revision))
                   AND a.kind IN ('longtermgoal','goal','policy','plan','program','programstep','project','decision')
              ";
        $params = ['ctxcompany' => $companyuuid];
        if ($search !== '') {
            $sql .= ' AND (' . $DB->sql_like('a.name', ':ctxname', false) . ' OR ' . $DB->sql_like('a.code', ':ctxcode', false) . ')';
            $params['ctxname'] = '%' . $DB->sql_like_escape(\core_text::substr($search, 0, 200)) . '%';
            $params['ctxcode'] = $params['ctxname'];
        }
        $sql .= ' ORDER BY a.code,a.name,a.id';
        foreach ($DB->get_records_sql($sql, $params, 0, 100) as $row) {
            $out[$row->kind][] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'code' => (string)$row->code, 'name' => (string)$row->name];
        }
        return $out;
    }

    private static function context_label(\stdClass $row): string {
        global $DB;
        $kind = (string)($row->contextkind ?? '');
        $uuid = (string)($row->contextuuid ?? '');
        $revision = (int)($row->contextrevision ?? 0);
        if ($kind === '' || $uuid === '' || $revision <= 0) { return ''; }
        $source = $DB->get_record('local_kbacompany_adminitem', [
            'companyuuid' => (string)$row->companyuuid, 'uuid' => $uuid, 'revision' => $revision, 'kind' => $kind,
        ]);
        if (!$source) { return ''; }
        $kindlabel = $kind === 'project'
            ? get_string('taskcenter_relation_management_project', 'local_kbacompany')
            : get_string('adminscale_kind_' . $kind, 'local_kbacompany');
        return $kindlabel . ': ' .
            trim(((string)$source->code !== '' ? (string)$source->code . ' — ' : '') . (string)$source->name);
    }

    private static function validate_common(array $data, array $employees, int $actor, bool $canmanage,
            ?\stdClass $existing = null): array {
        $title = self::text($data, 'title', 255, true);
        $description = self::text($data, 'description', 12000, false);
        $product = self::text($data, 'product', 12000, true);
        $stream = self::text($data, 'stream', 16, true);
        $priority = self::text($data, 'priority', 10, true);
        if (!in_array($stream, self::STREAMS, true) || !in_array($priority, self::PRIORITIES, true)) {
            self::fail('invalid');
        }
        $assigneeid = (int)($data['assigneeid'] ?? ($existing ? $existing->assigneeid : $actor));
        $reviewerid = (int)($data['reviewerid'] ?? ($existing ? $existing->reviewerid : 0));
        $observerid = (int)($data['observerid'] ?? ($existing ? $existing->observerid : 0));
        self::require_employee($employees, $assigneeid);
        // Any authorised BMS task creator may delegate to a company employee or assign the task to self.
        // Company membership/capability is already enforced by company_context(); the target must be an employee.
        if ($reviewerid > 0) {
            self::require_employee($employees, $reviewerid);
        }
        if ($observerid > 0) { self::require_employee($employees, $observerid); }
        $parentuuid = self::text($data, 'parentuuid', 36, false);
        $contextkind = self::text($data, 'contextkind', 20, false);
        $contextuuid = self::text($data, 'contextuuid', 36, false);
        $contextrevision = max(0, (int)($data['contextrevision'] ?? ($existing ? ($existing->contextrevision ?? 0) : 0)));
        $parentcompany = (string)($existing->companyuuid ?? $data['companyuuid'] ?? '');
        if ($parentuuid !== '') {
            if (!$existing || $parentuuid !== (string)$existing->parentuuid) {
                if (!self::can_add_subtasks($parentcompany, $actor, $parentuuid)) { self::fail('parent'); }
            }
            self::validate_parent_chain($parentcompany, $parentuuid, $existing ? (string)$existing->uuid : '');
            // One primary placement per task in the editor: parent task or management context.
            $contextkind = ''; $contextuuid = ''; $contextrevision = 0;
        } else {
            self::validate_context_reference($parentcompany, $contextkind, $contextuuid, $contextrevision);
        }
        $startdate = self::date($data, 'startdate');
        $duedate = self::date($data, 'duedate');
        $starttime = self::clock($data, 'starttime', $existing ? (string)($existing->starttime ?? '') : '');
        $duetime = self::clock($data, 'duetime', $existing ? (string)($existing->duetime ?? '') : '');
        if ($starttime !== '' && $startdate === '') { self::fail('date'); }
        if ($duetime !== '' && $duedate === '') { self::fail('date'); }
        if ($startdate !== '' && $duedate !== '') {
            if ($duedate < $startdate) { self::fail('date'); }
            if ($duedate === $startdate && $starttime !== '' && $duetime !== '' && $duetime < $starttime) { self::fail('date'); }
        }
        $planminutes = max(0, min(525600, (int)($data['planminutes'] ?? ($existing ? $existing->planminutes : 0))));
        return compact('title', 'description', 'product', 'stream', 'priority', 'assigneeid', 'reviewerid', 'observerid',
            'parentuuid', 'contextkind', 'contextuuid', 'contextrevision', 'startdate', 'starttime', 'duedate', 'duetime', 'planminutes');
    }

    public static function create(string $companyuuid, int $actor, array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor)
            || access_role_service::can($companyuuid, $actor, 'task', 'create');
        $employees = self::employees($companyuuid, $actor);
        $data['companyuuid'] = $companyuuid;
        $values = self::validate_common($data, $employees, $actor, $canmanage);
        $dutypositionuuid = trim((string)($data['dutypositionuuid'] ?? ''));
        if ($dutypositionuuid !== '') {
            if (!$canmanage) { self::fail('permission'); }
            if (!array_key_exists($dutypositionuuid,
                    self::duty_position_options($companyuuid, $actor, (int)$values['assigneeid']))) {
                throw new \moodle_exception('coverage_task_invalid_position', 'local_kbacompany');
            }
            if (empty($data['dutyconfirmed'])) {
                throw new \moodle_exception('tui_confirm_required', 'local_kbacompany');
            }
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('taskcenter:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            // Parent could have been submitted since validate_common() ran.
            if ($values['parentuuid'] !== '' &&
                    !self::can_add_subtasks($companyuuid, $actor, (string)$values['parentuuid'])) {
                self::fail('parent');
            }
            $uuid = self::uuid();
            $now = time();
            $row = (object)array_merge($values, [
                'companyuuid' => $companyuuid, 'uuid' => $uuid,
                'sourcekind' => 'manual', 'sourceuuid' => '', 'sourcerevision' => 0, 'periodkey' => '',
                'creatorid' => $actor, 'dutypositionuuid' => $dutypositionuuid,
                'status' => 'open', 'factminutes' => 0, 'result' => '',
                'revision' => 1, 'startedat' => 0, 'completedat' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            $DB->insert_record('local_kbacompany_task', $row);
            self::log($companyuuid, $uuid, $actor, 'create', '', 'open',
                ['source' => 'manual', 'dutypositionuuid' => $dutypositionuuid]);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Create or return a canonical execution task for one exact published Administrative Scale task revision. */
    public static function ensure_from_admin_scale(string $companyuuid, int $actor, string $sourceuuid,
            int $assigneeid, string $positionuuid, int $observerid = 0): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $assigneeid);
        if ($actor !== $assigneeid && !self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $source = self::latest_source('local_kbacompany_adminitem', $companyuuid, $sourceuuid, ['kind' => 'task']);
        if (!$source || $source->status !== 'published') { self::fail('sourceerror'); }
        if ($source->ownerpositionuuid !== '' && $positionuuid !== '' && $source->ownerpositionuuid !== $positionuuid) {
            self::fail('sourceerror');
        }
        $existing = $DB->get_records('local_kbacompany_task', [
            'companyuuid' => $companyuuid, 'sourcekind' => 'adminscale', 'sourceuuid' => $source->uuid,
            'sourcerevision' => (int)$source->revision, 'assigneeid' => $assigneeid,
        ], 'id ASC', '*', 0, 1);
        if ($existing) { return (string)reset($existing)->uuid; }
        $contextkind = '';
        $contextuuid = '';
        $contextrevision = 0;
        if ((string)$source->parentuuid !== '' && (int)$source->parentrevision > 0) {
            $parent = $DB->get_record('local_kbacompany_adminitem', [
                'companyuuid' => $companyuuid, 'uuid' => (string)$source->parentuuid,
                'revision' => (int)$source->parentrevision,
            ]);
            if ($parent && in_array((string)$parent->kind, self::CONTEXT_KINDS, true)) {
                $contextkind = (string)$parent->kind;
                $contextuuid = (string)$parent->uuid;
                $contextrevision = (int)$parent->revision;
            }
        }
        return self::create_generated($companyuuid, $actor, [
            'sourcekind' => 'adminscale', 'sourceuuid' => (string)$source->uuid,
            'sourcerevision' => (int)$source->revision, 'periodkey' => '', 'stream' => 'change',
            'title' => (string)$source->name, 'description' => (string)$source->description,
            'product' => trim((string)$source->expectedresult) !== '' ? (string)$source->expectedresult : (string)$source->name,
            'assigneeid' => $assigneeid, 'reviewerid' => 0, 'observerid' => $observerid, 'priority' => 'normal',
            'startdate' => (string)$source->startdate, 'duedate' => (string)$source->duedate,
            'planminutes' => 0, 'parentuuid' => '', 'contextkind' => $contextkind,
            'contextuuid' => $contextuuid, 'contextrevision' => $contextrevision,
        ]);
    }

    /** Create or return one execution task derived from a Communication message. */
    public static function create_from_communication(string $companyuuid, int $actor, string $messageuuid, array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $message = $DB->get_record('local_kbacompany_commmsg', [
            'companyuuid' => $companyuuid, 'uuid' => $messageuuid, 'status' => 'active',
        ]);
        if (!$message || !communication_service::can_view_message($companyuuid, $actor, $messageuuid)) {
            self::fail('sourceerror');
        }
        $employees = self::employees($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $data['companyuuid'] = $companyuuid;
        $values = self::validate_common($data, $employees, $actor, $canmanage);
        return self::create_generated($companyuuid, $actor, array_merge($values, [
            'sourcekind' => 'communication', 'sourceuuid' => $messageuuid,
            'sourcerevision' => 0, 'periodkey' => '',
        ]));
    }

    /** Create or return one canonical execution task for an accepted meeting decision and assignee. */
    public static function create_from_decision(string $companyuuid, int $actor, string $decisionuuid, array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $decision = $DB->get_record('local_kbacompany_decision', [
            'companyuuid' => $companyuuid, 'uuid' => $decisionuuid, 'status' => 'accepted',
        ]);
        if (!$decision) { self::fail('sourceerror'); }
        $employees = self::employees($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $data['companyuuid'] = $companyuuid;
        if (trim((string)($data['title'] ?? '')) === '') { $data['title'] = (string)$decision->title; }
        if (trim((string)($data['product'] ?? '')) === '') {
            $data['product'] = trim((string)$decision->expectedresult) !== '' ?
                (string)$decision->expectedresult : (string)$decision->statement;
        }
        if (trim((string)($data['description'] ?? '')) === '') { $data['description'] = (string)$decision->statement; }
        if (trim((string)($data['stream'] ?? '')) === '') { $data['stream'] = 'change'; }
        if (trim((string)($data['priority'] ?? '')) === '') { $data['priority'] = 'normal'; }
        $values = self::validate_common($data, $employees, $actor, $canmanage);
        return self::create_generated($companyuuid, $actor, array_merge($values, [
            'sourcekind' => 'decision', 'sourceuuid' => $decisionuuid,
            'sourcerevision' => (int)$decision->revision, 'periodkey' => '',
        ]));
    }

    /** Create or return one weekly execution task from a published Position Work Program item. */
    public static function ensure_from_position_work(string $companyuuid, int $actor, string $sourceuuid,
            int $assigneeid, string $positionuuid, string $periodkey, string $duedate, int $observerid = 0): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $assigneeid);
        if ($actor !== $assigneeid && !self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        self::date(['periodkey' => $periodkey], 'periodkey');
        self::date(['duedate' => $duedate], 'duedate');
        $source = self::latest_source('local_kbacompany_poswork', $companyuuid, $sourceuuid,
            ['positionuuid' => $positionuuid]);
        if (!$source || $source->status !== 'published') { self::fail('sourceerror'); }
        $existing = $DB->get_records('local_kbacompany_task', [
            'companyuuid' => $companyuuid, 'sourcekind' => 'positionwork', 'sourceuuid' => $source->uuid,
            'sourcerevision' => (int)$source->revision, 'periodkey' => $periodkey, 'assigneeid' => $assigneeid,
        ], 'id ASC', '*', 0, 1);
        if ($existing) { return (string)reset($existing)->uuid; }
        return self::create_generated($companyuuid, $actor, [
            'sourcekind' => 'positionwork', 'sourceuuid' => (string)$source->uuid,
            'sourcerevision' => (int)$source->revision, 'periodkey' => $periodkey, 'stream' => 'run',
            'title' => \core_text::substr(trim((string)$source->action), 0, 255),
            'description' => trim((string)$source->schedule), 'product' => trim((string)$source->product),
            'assigneeid' => $assigneeid, 'dutypositionuuid' => $positionuuid,
            'reviewerid' => 0, 'observerid' => $observerid, 'priority' => 'normal',
            'startdate' => $periodkey, 'duedate' => $duedate, 'planminutes' => 0, 'parentuuid' => '',
        ]);
    }


    /** Does the person still hold this primary role? Company-scoped and safe for scheduled jobs. */
    private static function primary_position_exists(string $companyuuid, int $userid, string $positionuuid): bool {
        global $DB;
        $now = time();
        $sql = "SELECT p.id FROM {local_kbacompany_position} p
                  JOIN {local_kbacompany_posassign} a ON a.companyuuid=p.companyuuid
                   AND a.positionuuid=p.uuid AND a.userid=:person
                   AND a.assignmenttype='primary' AND a.active=1
                   AND (a.effectivefrom=0 OR a.effectivefrom<=:fromdate)
                   AND (a.effectiveto=0 OR a.effectiveto>=:todate)
                 WHERE p.companyuuid=:company AND p.uuid=:position AND p.active=1";
        return $DB->record_exists_sql($sql, ['person'=>$userid,'fromdate'=>$now,
            'todate'=>$now,'company'=>$companyuuid,'position'=>$positionuuid]);
    }

    /** Create or return one canonical occurrence for an exact recurring definition revision and date. */
    public static function ensure_from_recurrence(string $companyuuid, int $actor, string $sourceuuid,
            int $sourcerevision, string $periodkey): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        self::date(['periodkey' => $periodkey], 'periodkey');
        $source = $DB->get_record('local_kbacompany_taskrec', [
            'companyuuid' => $companyuuid, 'uuid' => $sourceuuid, 'revision' => $sourcerevision,
        ]);
        $latestrows = $DB->get_records('local_kbacompany_taskrec', [
            'companyuuid' => $companyuuid, 'uuid' => $sourceuuid,
        ], 'revision DESC', '*', 0, 1);
        $latest = $latestrows ? reset($latestrows) : null;
        if (!$source || !$latest || (int)$latest->revision !== $sourcerevision || $source->status !== 'active' ||
                trim((string)$source->product) === '' || (int)$source->assigneeid <= 0 || $source->cadence === 'review') {
            self::fail('sourceerror');
        }
        $existing = $DB->get_records('local_kbacompany_task', [
            'companyuuid' => $companyuuid, 'sourcekind' => 'recurring', 'sourceuuid' => $sourceuuid,
            'periodkey' => $periodkey,
        ], 'id ASC', '*', 0, 1);
        if ($existing) { return (string)reset($existing)->uuid; }
        $positionuuid = (string)($source->dutypositionuuid ?? '');
        if ($positionuuid !== '') {
            // Never silently turn a stale position-bound definition into personal work.
            $valid = self::primary_position_exists($companyuuid, (int)$source->assigneeid, $positionuuid);
            if (!$valid) { self::fail('sourceerror'); }
        }
        $due = new \DateTimeImmutable($periodkey, new \DateTimeZone('UTC'));
        if ((int)$source->dueoffsetdays > 0) { $due = $due->modify('+' . (int)$source->dueoffsetdays . ' days'); }
        return self::create_generated($companyuuid, $actor, [
            'sourcekind' => 'recurring', 'sourceuuid' => $sourceuuid, 'sourcerevision' => $sourcerevision,
            'periodkey' => $periodkey, 'stream' => (string)$source->stream, 'title' => (string)$source->title,
            'description' => (string)$source->description, 'product' => (string)$source->product,
            'assigneeid' => (int)$source->assigneeid, 'reviewerid' => (int)$source->reviewerid,
            'dutypositionuuid' => (string)($source->dutypositionuuid ?? ''),
            'priority' => (string)$source->priority, 'startdate' => $periodkey, 'duedate' => $due->format('Y-m-d'),
            'planminutes' => (int)$source->planminutes, 'parentuuid' => '',
        ]);
    }

    /** Create or return one canonical task derived from one exact Battle Plan template row. */
    public static function ensure_from_battle_plan_template(string $companyuuid, int $actor, string $templateuuid,
            int $templaterevision, int $rowindex, int $assigneeid, int $reviewerid, int $observerid,
            string $periodkey, array $row): string {
        company_context_service::context($companyuuid, $actor);
        $template = battle_plan_template_service::exact_template($companyuuid, $templateuuid, $templaterevision);
        if (!$template || $template['status'] !== 'published') { self::fail('sourceerror'); }
        $rows = isset($template['payload']['rows']) && is_array($template['payload']['rows']) ? array_values($template['payload']['rows']) : [];
        if (!isset($rows[$rowindex])) { self::fail('sourceerror'); }
        $source = $rows[$rowindex];
        $title = trim((string)($source['title'] ?? ''));
        $product = trim((string)($source['product'] ?? ''));
        $stream = (string)($source['stream'] ?? 'operate');
        $priority = (string)($source['priority'] ?? 'normal');
        if ($title === '' || $product === '' || !in_array($stream, self::STREAMS, true) ||
                !in_array($priority, self::PRIORITIES, true)) { self::fail('sourceerror'); }
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $assigneeid);
        if ($reviewerid > 0) { self::require_employee($employees, $reviewerid); }
        if ($observerid > 0) { self::require_employee($employees, $observerid); }
        if ($actor !== $assigneeid && !self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $periodkey = self::date(['periodkey' => $periodkey], 'periodkey');
        $weekstart = new \DateTimeImmutable($periodkey, new \DateTimeZone('UTC'));
        $due = $weekstart->modify('+6 days');
        $day = trim((string)($source['day'] ?? ''));
        $weekday = ['пн'=>0,'вт'=>1,'ср'=>2,'чт'=>3,'пт'=>4,'сб'=>5,'вс'=>6,'mon'=>0,'tue'=>1,'wed'=>2,'thu'=>3,'fri'=>4,'sat'=>5,'sun'=>6];
        $daykey = \core_text::strtolower($day);
        if (isset($weekday[$daykey])) { $due = $weekstart->modify('+' . $weekday[$daykey] . ' days'); }
        // Deterministic row source UUID: one exact template revision row becomes one task per employee/week.
        $hex = hash('sha256', $templateuuid . ':' . $templaterevision . ':' . $rowindex);
        $rowuuid = substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20,12);
        return self::create_generated($companyuuid, $actor, [
            'sourcekind' => 'bplantemplate', 'sourceuuid' => $rowuuid, 'sourcerevision' => $templaterevision,
            'periodkey' => $periodkey, 'stream' => $stream, 'title' => $title,
            'description' => $day !== '' ? get_string('battleplan_template_day', 'local_kbacompany') . ': ' . $day : '',
            'product' => $product, 'assigneeid' => $assigneeid, 'reviewerid' => $reviewerid,
            'observerid' => $observerid, 'priority' => $priority, 'startdate' => $periodkey,
            'duedate' => $due->format('Y-m-d'), 'planminutes' => max(0, min(10080, (int)($source['plan_minutes'] ?? 0))),
            'parentuuid' => '',
        ]);
    }

    /** Create or return one canonical one-off task derived from a built-in task template for a Battle Plan week. */
    public static function ensure_from_library_template(string $companyuuid, int $actor, string $templatekey,
            int $assigneeid, int $reviewerid, string $periodkey, array $data): string {
        company_context_service::context($companyuuid, $actor);
        $template = recurring_task_library_service::template($templatekey, $companyuuid);
        if (!$template) { self::fail('sourceerror'); }
        $employees = self::employees($companyuuid, $actor);
        self::require_employee($employees, $assigneeid);
        if ($reviewerid > 0) { self::require_employee($employees, $reviewerid); }
        if ($actor !== $assigneeid && !self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $periodkey = self::date(['periodkey' => $periodkey], 'periodkey');
        $product = self::text($data, 'product', 12000, true);
        $description = self::text($data, 'description', 12000, false);
        $stream = self::text($data, 'stream', 16, true);
        $priority = self::text($data, 'priority', 10, true);
        if (!in_array($stream, self::STREAMS, true) || !in_array($priority, self::PRIORITIES, true)) { self::fail('invalid'); }
        $startdate = self::date($data, 'startdate');
        $duedate = self::date($data, 'duedate');
        if ($startdate !== '' && $duedate !== '' && $duedate < $startdate) { self::fail('date'); }
        $planminutes = max(0, min(525600, (int)($data['planminutes'] ?? 0)));
        return self::create_generated($companyuuid, $actor, [
            'sourcekind' => 'template', 'sourceuuid' => (string)$template['source_uuid'],
            'sourcerevision' => (int)$template['revision'], 'periodkey' => $periodkey, 'stream' => $stream,
            'title' => (string)$template['title'], 'description' => $description, 'product' => $product,
            'assigneeid' => $assigneeid, 'reviewerid' => $reviewerid, 'observerid' => (int)($data['observerid'] ?? 0), 'priority' => $priority,
            'startdate' => $startdate, 'duedate' => $duedate, 'planminutes' => $planminutes, 'parentuuid' => '',
        ]);
    }

    /** Create or return one canonical execution task sourced from an immutable indicator-condition event. */
    public static function ensure_from_condition_event(string $companyuuid, int $actor, string $eventuuid,
            array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $event = $DB->get_record('local_kbacompany_stateevt', [
            'companyuuid' => $companyuuid, 'uuid' => $eventuuid,
        ]);
        if (!$event) { self::fail('sourceerror'); }
        $employees = self::employees($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $data['companyuuid'] = $companyuuid;
        if (trim((string)($data['stream'] ?? '')) === '') { $data['stream'] = 'change'; }
        if (trim((string)($data['priority'] ?? '')) === '') { $data['priority'] = 'normal'; }
        $values = self::validate_common($data, $employees, $actor, $canmanage);
        return self::create_generated($companyuuid, $actor, array_merge($values, [
            'sourcekind' => 'condition', 'sourceuuid' => $eventuuid, 'sourcerevision' => 1,
            'periodkey' => (string)$event->periodkey,
        ]));
    }

    private static function create_generated(string $companyuuid, int $actor, array $data): string {
        global $DB;
        if (!in_array($data['sourcekind'], self::SOURCE_KINDS, true) || $data['sourcekind'] === 'manual') {
            self::fail('sourceerror');
        }
        if (!in_array($data['stream'], self::STREAMS, true) || trim($data['title']) === '' || trim($data['product']) === '') {
            self::fail('invalid');
        }
        $observerid = (int)($data['observerid'] ?? 0);
        if ($observerid > 0) {
            $employees = self::employees($companyuuid, $actor);
            self::require_employee($employees, $observerid);
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('taskcenter:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            // Re-check under lock so two imports cannot create duplicate generated work.
            if ($data['sourcekind'] === 'recurring') {
                $existing = $DB->get_records('local_kbacompany_task', [
                    'companyuuid' => $companyuuid, 'sourcekind' => 'recurring', 'sourceuuid' => $data['sourceuuid'],
                    'periodkey' => $data['periodkey'],
                ], 'id ASC', '*', 0, 1);
            } else {
                $existing = $DB->get_records('local_kbacompany_task', [
                    'companyuuid' => $companyuuid, 'sourcekind' => $data['sourcekind'], 'sourceuuid' => $data['sourceuuid'],
                    'sourcerevision' => (int)$data['sourcerevision'], 'periodkey' => $data['periodkey'],
                    'assigneeid' => (int)$data['assigneeid'],
                ], 'id ASC', '*', 0, 1);
            }
            if ($existing) {
                $tx->allow_commit();
                return (string)reset($existing)->uuid;
            }
            $uuid = self::uuid(); $now = time();
            $DB->insert_record('local_kbacompany_task', (object)[
                'companyuuid' => $companyuuid, 'uuid' => $uuid, 'parentuuid' => (string)$data['parentuuid'],
                'contextkind' => (string)($data['contextkind'] ?? ''),
                'contextuuid' => (string)($data['contextuuid'] ?? ''),
                'contextrevision' => (int)($data['contextrevision'] ?? 0),
                'sourcekind' => (string)$data['sourcekind'], 'sourceuuid' => (string)$data['sourceuuid'],
                'sourcerevision' => (int)$data['sourcerevision'], 'periodkey' => (string)$data['periodkey'],
                'stream' => (string)$data['stream'], 'title' => (string)$data['title'],
                'description' => (string)$data['description'], 'product' => (string)$data['product'],
                'assigneeid' => (int)$data['assigneeid'],
                'dutypositionuuid' => (string)($data['dutypositionuuid'] ?? ''), 'creatorid' => $actor, 'reviewerid' => (int)$data['reviewerid'],
                'observerid' => $observerid, 'priority' => (string)$data['priority'], 'status' => 'open', 'startdate' => (string)$data['startdate'],
                'starttime' => (string)($data['starttime'] ?? ''), 'duedate' => (string)$data['duedate'], 'duetime' => (string)($data['duetime'] ?? ''), 'planminutes' => (int)$data['planminutes'], 'factminutes' => 0,
                'result' => '', 'revision' => 1, 'startedat' => 0, 'completedat' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            self::log($companyuuid, $uuid, $actor, 'generate', '', 'open', [
                'sourcekind' => $data['sourcekind'], 'sourceuuid' => $data['sourceuuid'],
                'sourcerevision' => (int)$data['sourcerevision'], 'periodkey' => $data['periodkey'],
            ]);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function save(string $companyuuid, int $actor, string $taskuuid, int $expectedrevision, array $data): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $row = self::task($companyuuid, $taskuuid);
            if (!self::can_edit_definition($row, $actor, $canmanage)) { self::fail('permission'); }
            if ((int)$row->revision !== $expectedrevision) { self::fail('conflict'); }
            if (!in_array($row->status, ['open', 'returned'], true)) { self::fail('state'); }
            $data['companyuuid'] = $companyuuid;
            $departmentedit = !$canmanage && (int)$row->creatorid !== $actor &&
                access_role_service::may_act_on_department_task($companyuuid, $actor, (int)$row->assigneeid, 'edit');
            $values = self::validate_common($data, $employees, $actor, $canmanage, $row);
            // Department edit may correct task definition, but must not reassign or alter management links.
            if ($departmentedit && ((int)$values['assigneeid'] !== (int)$row->assigneeid ||
                    (int)$values['reviewerid'] !== (int)$row->reviewerid ||
                    (int)$values['observerid'] !== (int)$row->observerid ||
                    (string)$values['parentuuid'] !== (string)$row->parentuuid ||
                    (string)$values['contextkind'] !== (string)$row->contextkind ||
                    (string)$values['contextuuid'] !== (string)$row->contextuuid)) { self::fail('permission'); }
            $oldpositionuuid = (string)($row->dutypositionuuid ?? '');
            $newpositionuuid = array_key_exists('dutypositionuuid', $data)
                ? trim((string)$data['dutypositionuuid']) : $oldpositionuuid;
            $assigneechanged = (int)$values['assigneeid'] !== (int)$row->assigneeid;
            // Classification is a management action; regular editors cannot silently change it.
            if ($newpositionuuid !== $oldpositionuuid && !$canmanage) { self::fail('permission'); }
            // Re-check a position if either its binding or the original assignee changes.
            // An unchanged legacy binding remains intact if its historic assignment has ended.
            if ($newpositionuuid !== '' && ($newpositionuuid !== $oldpositionuuid || $assigneechanged)) {
                if (!$canmanage) { self::fail('permission'); }
                if (!array_key_exists($newpositionuuid,
                        self::duty_position_options($companyuuid, $actor, (int)$values['assigneeid']))) {
                    throw new \moodle_exception('coverage_task_invalid_position', 'local_kbacompany');
                }
                if (empty($data['dutyconfirmed'])) {
                    throw new \moodle_exception('tui_confirm_required', 'local_kbacompany');
                }
            }
            foreach ($values as $key => $value) { $row->{$key} = $value; }
            $row->dutypositionuuid = $newpositionuuid;
            $row->revision++; $row->timemodified = time();
            $DB->update_record('local_kbacompany_task', $row);
            self::sync_discussion($row);
            self::log($companyuuid, $taskuuid, $actor, 'edit', $row->status, $row->status);
            if ($newpositionuuid !== $oldpositionuuid) {
                self::log($companyuuid, $taskuuid, $actor, 'duty_classification',
                    (string)$row->status, (string)$row->status,
                    ['previouspositionuuid'=>$oldpositionuuid, 'positionuuid'=>$newpositionuuid]);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Change the task observer without changing execution responsibility. */
    public static function set_observer(string $companyuuid, int $actor, string $taskuuid, int $expectedrevision, int $observerid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        if ($observerid > 0) { self::require_employee($employees, $observerid); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $row = self::task($companyuuid, $taskuuid);
            if (!$canmanage && (int)$row->creatorid !== $actor) { self::fail('permission'); }
            if ((int)$row->revision !== $expectedrevision) { self::fail('conflict'); }
            if ((string)$row->status === 'deleted') { self::fail('state'); }
            $oldobserver = (int)$row->observerid;
            $row->observerid = $observerid;
            $row->revision++; $row->timemodified = time();
            $DB->update_record('local_kbacompany_task', $row);
            self::sync_discussion($row);
            self::log($companyuuid, $taskuuid, $actor, 'observer_change', $row->status, $row->status,
                ['oldobserverid' => $oldobserver, 'observerid' => $observerid]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /**
     * Block premature parent submission/review while direct children have unfinished
     * work. This query intentionally checks ALL children; permission-limited child
     * lists cannot make an incomplete parent look complete. Never expose child IDs.
     */
    private static function require_subtasks_resolved(string $companyuuid, string $parentuuid): void {
        global $DB;
        $unfinished = $DB->record_exists_sql("SELECT t.id FROM {local_kbacompany_task} t
             WHERE t.companyuuid = :childcompany AND t.parentuuid = :childparent
               AND t.status NOT IN ('done','cancelled','deleted')",
            ['childcompany' => $companyuuid, 'childparent' => $parentuuid]);
        if ($unfinished) { self::fail('subtasksunfinished'); }
    }

    public static function transition(string $companyuuid, int $actor, string $taskuuid, int $expectedrevision,
            string $action, array $data = []): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        // Use the same company-level lock as create/link to prevent a child from
        // appearing between the parent completion check and its status change.
        $hierarchylock = null;
        if (in_array($action, ['submit', 'accept'], true)) {
            $hierarchylock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
                ->get_lock('taskcenter:' . $companyuuid, 10);
            if (!$hierarchylock) { self::fail('lock'); }
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) {
            if ($hierarchylock) { $hierarchylock->release(); }
            self::fail('lock');
        }
        try {
            $tx = $DB->start_delegated_transaction();
            $row = self::task($companyuuid, $taskuuid);
            if (!self::can_view_row($row, $actor, $canmanage)) { self::fail('permission'); }
            if ((int)$row->revision !== $expectedrevision) { self::fail('conflict'); }
            $oldstatus = (string)$row->status;
            $reason = self::text($data, 'reason', 4000, false);
            if ($action === 'start') {
                if (!self::can_execute($row, $actor, $canmanage) || !in_array($row->status, ['open', 'returned'], true)) {
                    self::fail('state');
                }
                $row->status = 'doing'; if (!(int)$row->startedat) { $row->startedat = time(); }
            } else if ($action === 'submit') {
                if (!self::can_execute($row, $actor, $canmanage) || !in_array($row->status, ['open', 'doing', 'returned'], true)) {
                    self::fail('state');
                }
                $pending = $DB->count_records('local_kbacompany_taskcheck', [
                    'companyuuid' => $companyuuid, 'taskuuid' => $taskuuid, 'done' => 0,
                ]);
                if ($pending > 0) { self::fail('checklisterror'); }
                self::require_subtasks_resolved($companyuuid, $taskuuid);
                $result = self::text($data, 'result', 12000, true);
                $row->result = $result;
                $row->factminutes = max(0, min(525600, (int)($data['factminutes'] ?? $row->factminutes)));
                if ((int)$row->reviewerid > 0) {
                    $row->status = 'review';
                } else {
                    $row->status = 'done'; $row->completedat = time();
                }
            } else if ($action === 'accept') {
                if ($row->status !== 'review' || (!$canmanage && (int)$row->reviewerid !== $actor &&
                        !access_role_service::may_act_on_department_task($companyuuid, $actor,
                            (int)$row->assigneeid, 'approve'))) { self::fail('state'); }
                self::require_subtasks_resolved($companyuuid, $taskuuid);
                $row->status = 'done'; $row->completedat = time();
            } else if ($action === 'return') {
                if ($row->status !== 'review' || (!$canmanage && (int)$row->reviewerid !== $actor &&
                        !access_role_service::may_act_on_department_task($companyuuid, $actor,
                            (int)$row->assigneeid, 'approve')) || $reason === '') {
                    self::fail('state');
                }
                $row->status = 'returned'; $row->completedat = 0;
            } else if ($action === 'cancel') {
                if (!$canmanage && (int)$row->creatorid !== $actor) { self::fail('permission'); }
                if (in_array($row->status, ['done', 'cancelled'], true)) { self::fail('state'); }
                $row->status = 'cancelled'; $row->completedat = time();
            } else {
                self::fail('invalid');
            }
            $row->revision++; $row->timemodified = time();
            $DB->update_record('local_kbacompany_task', $row);
            self::log($companyuuid, $taskuuid, $actor, $action, $oldstatus, $row->status,
                ['reason' => $reason, 'result' => (string)$row->result, 'factminutes' => (int)$row->factminutes]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
            if ($hierarchylock) { $hierarchylock->release(); }
        }
    }

    /** Soft-delete a task while retaining its audit trail and source identity. */
    public static function delete_task(string $companyuuid, int $actor, string $taskuuid, int $expectedrevision): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $row = self::task($companyuuid, $taskuuid);
            if (!$canmanage && (int)$row->creatorid !== $actor) { self::fail('permission'); }
            if ((int)$row->revision !== $expectedrevision) { self::fail('conflict'); }
            if (!in_array((string)$row->status, ['open', 'doing', 'returned', 'cancelled'], true)) { self::fail('state'); }
            // Preserve submitted/approved/closed plan composition and review history.
            if ($DB->record_exists_sql("SELECT bi.id FROM {local_kbacompany_bplanitem} bi
                    JOIN {local_kbacompany_bplan} bp ON bp.uuid=bi.planuuid AND bp.companyuuid=bi.companyuuid
                   WHERE bi.companyuuid=:dc AND bi.provider='bms'
                     AND (bi.externalid=:dt OR bi.taskuuid=:legacydt) AND bp.status<>'draft'",
                    ['dc'=>$companyuuid,'dt'=>$taskuuid,'legacydt'=>$taskuuid])) {
                throw new \moodle_exception('tui_delete_linked_plan', 'local_kbacompany');
            }
            $children = $DB->count_records_select('local_kbacompany_task',
                'companyuuid = :c AND parentuuid = :p AND status <> :deleted',
                ['c' => $companyuuid, 'p' => $taskuuid, 'deleted' => 'deleted']);
            if ($children > 0) { self::fail('deletechildren'); }
            $oldstatus = (string)$row->status;
            $row->status = 'deleted'; $row->revision++; $row->timemodified = time();
            if (!(int)$row->completedat) { $row->completedat = time(); }
            $DB->update_record('local_kbacompany_task', $row);
            $DB->delete_records('local_kbacompany_bplanitem', ['companyuuid' => $companyuuid, 'taskuuid' => $taskuuid]);
            $DB->delete_records('local_kbacompany_bplanitem', ['companyuuid' => $companyuuid, 'provider' => 'bms', 'externalid' => $taskuuid]);
            $thread = self::discussion_thread($companyuuid, $taskuuid);
            if ($thread) {
                $thread->status = 'archived'; $thread->timemodified = time();
                $DB->update_record('local_kbacompany_commthread', $thread);
            }
            self::log($companyuuid, $taskuuid, $actor, 'delete', $oldstatus, 'deleted');
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Task-scoped discussion projected from the Communication Center. */
    public static function discussion_context(string $companyuuid, int $actor, string $taskuuid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!self::can_view_row($row, $actor, $canmanage)) { self::fail('permission'); }
        $canpost = self::can_post_discussion($row, $actor, $canmanage);
        $thread = self::discussion_thread($companyuuid, $taskuuid);
        if (!$thread) {
            return ['thread_uuid' => '', 'messages' => [], 'restricted' => false, 'can_post' => $canpost];
        }
        $isparticipant = $DB->record_exists('local_kbacompany_commpart', [
            'companyuuid' => $companyuuid, 'threaduuid' => $thread->uuid, 'userid' => $actor, 'active' => 1,
        ]);
        if (!$isparticipant) {
            return ['thread_uuid' => (string)$thread->uuid, 'messages' => [], 'restricted' => true, 'can_post' => $canpost];
        }
        $ctx = communication_service::context($companyuuid, $actor, (string)$thread->uuid, 'all');
        return ['thread_uuid' => (string)$thread->uuid, 'messages' => $ctx['messages'], 'restricted' => false, 'can_post' => $canpost];
    }

    /** Append a message to the task discussion. Posting explicitly joins an authorised manager to the thread. */
    public static function post_discussion_message(string $companyuuid, int $actor, string $taskuuid, string $body): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!self::can_view_row($row, $actor, $canmanage) ||
                !self::can_post_discussion($row, $actor, $canmanage)) { self::fail('permission'); }
        if ((string)$row->status === 'deleted') { self::fail('state'); }
        $body = trim($body);
        if ($body === '' || \core_text::strlen($body) > 12000) { self::fail('invalid'); }
        $threaduuid = self::ensure_discussion($companyuuid, $actor, $row);
        $messageuuid = communication_service::send_message($companyuuid, $actor, $threaduuid, $body);
        self::log($companyuuid, $taskuuid, $actor, 'discussion_post', $row->status, $row->status,
            ['threaduuid' => $threaduuid, 'messageuuid' => $messageuuid]);
        return $messageuuid;
    }

    /** Public visibility check used by pluginfile and attachment UI. */
    public static function can_view_task(string $companyuuid, int $actor, string $taskuuid): bool {
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        return (string)$row->status !== 'deleted' && self::can_view_row($row, $actor, self::can_manage($companyuuid, $actor));
    }

    /** Return protected Moodle File API attachments for a task. */
    public static function attachments(string $companyuuid, int $actor, string $taskuuid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!self::can_view_row($row, $actor, $canmanage)) { self::fail('permission'); }
        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_kbacompany', 'task_attachment', (int)$row->id,
            'filename ASC, id ASC', false);
        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'filename' => $file->get_filename(),
                'filesize' => $file->get_filesize(),
                'mimetype' => (string)$file->get_mimetype(),
                'userid' => (int)$file->get_userid(),
                'url' => \moodle_url::make_pluginfile_url($context->id, 'local_kbacompany', 'task_attachment',
                    (int)$row->id, '/', $file->get_filename(), true)->out(false),
                'can_delete' => $canmanage || (int)$row->creatorid === $actor || (int)$file->get_userid() === $actor,
            ];
        }
        return $result;
    }

    /** Store one uploaded file in the task's protected Moodle File API area. */
    public static function add_attachment(string $companyuuid, int $actor, string $taskuuid, array $upload): void {
        global $CFG;
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!self::can_contribute_to_task($row, $actor, $canmanage)) { self::fail('permission'); }
        if (in_array((string)$row->status, ['cancelled'], true)) { self::fail('state'); }
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) { return; }
        if ($error !== UPLOAD_ERR_OK) { self::fail('upload'); }
        $tmp = (string)($upload['tmp_name'] ?? '');
        $name = clean_filename((string)($upload['name'] ?? ''));
        $size = (int)($upload['size'] ?? 0);
        if ($tmp === '' || $name === '' || $size < 0 || !is_uploaded_file($tmp)) { self::fail('upload'); }
        $maxbytes = get_max_upload_file_size();
        if ($maxbytes > 0 && $size > $maxbytes) { self::fail('filesize'); }
        $context = \context_system::instance();
        $fs = get_file_storage();
        $filename = $name;
        $pathinfo = pathinfo($name);
        $base = (string)($pathinfo['filename'] ?? 'file');
        $ext = isset($pathinfo['extension']) && $pathinfo['extension'] !== '' ? '.' . $pathinfo['extension'] : '';
        $n = 2;
        while ($fs->file_exists($context->id, 'local_kbacompany', 'task_attachment', (int)$row->id, '/', $filename)) {
            $filename = $base . ' (' . $n . ')' . $ext;
            $n++;
        }
        $record = [
            'contextid' => $context->id,
            'component' => 'local_kbacompany',
            'filearea' => 'task_attachment',
            'itemid' => (int)$row->id,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $actor,
            'source' => $name,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $fs->create_file_from_pathname($record, $tmp);
        self::log($companyuuid, $taskuuid, $actor, 'attachment_add', $row->status, $row->status,
            ['filename' => $filename, 'filesize' => $size]);
    }

    /** Delete one task attachment when the actor owns it, created the task, or manages company tasks. */
    public static function delete_attachment(string $companyuuid, int $actor, string $taskuuid, string $filename): void {
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!self::can_view_row($row, $actor, $canmanage)) { self::fail('permission'); }
        $filename = clean_filename($filename);
        if ($filename === '') { self::fail('invalid'); }
        $context = \context_system::instance();
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_kbacompany', 'task_attachment', (int)$row->id, '/', $filename);
        if (!$file) { self::fail('reference'); }
        if (!$canmanage && (int)$row->creatorid !== $actor && (int)$file->get_userid() !== $actor) {
            self::fail('permission');
        }
        $file->delete();
        self::log($companyuuid, $taskuuid, $actor, 'attachment_delete', $row->status, $row->status,
            ['filename' => $filename]);
    }


    /** Return protected result/evidence files for a task. */
    public static function result_attachments(string $companyuuid, int $actor, string $taskuuid): array {
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!self::can_view_row($row, $actor, $canmanage)) { self::fail('permission'); }
        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_kbacompany', 'task_result', (int)$row->id,
            'filename ASC, id ASC', false);
        $result = [];
        foreach ($files as $file) {
            $result[] = [
                'filename' => $file->get_filename(), 'filesize' => $file->get_filesize(),
                'mimetype' => (string)$file->get_mimetype(), 'userid' => (int)$file->get_userid(),
                'url' => \moodle_url::make_pluginfile_url($context->id, 'local_kbacompany', 'task_result',
                    (int)$row->id, '/', $file->get_filename(), true)->out(false),
                'can_delete' => !in_array((string)$row->status, ['done', 'cancelled', 'deleted'], true) &&
                    ($canmanage || self::can_execute($row, $actor, $canmanage) || (int)$file->get_userid() === $actor),
            ];
        }
        return $result;
    }

    /** Append one protected file to the task result/evidence area. */
    public static function add_result_attachment(string $companyuuid, int $actor, string $taskuuid, array $upload): void {
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        $canmanage = self::can_manage($companyuuid, $actor);
        // The submit flow transitions to review/done BEFORE attaching evidence.
        // Allow its author only in the same short-lived action window; unlike a
        // blanket delegate capability, this does not extend execution of completed work.
        $allowed = self::can_execute($row, $actor, $canmanage);
        if (!$allowed && in_array((string)$row->status, ['review','done'], true) &&
                $actor === position_coverage_service::effective_executor($companyuuid,
                    (string)($row->dutypositionuuid ?? ''), (int)$row->assigneeid) &&
                $actor !== (int)$row->assigneeid) {
            global $DB;
            $recent = $DB->get_records('local_kbacompany_tasklog', [
                'companyuuid'=>$companyuuid, 'taskuuid'=>$taskuuid,
                'actorid'=>$actor, 'action'=>'submit',
            ], 'id DESC', '*', 0, 1);
            $entry = $recent ? reset($recent) : null;
            $allowed = $entry && time() - (int)$entry->timecreated <= 60;
        }
        if (!$allowed) { self::fail('permission'); }
        if (in_array((string)$row->status, ['cancelled', 'deleted'], true)) { self::fail('state'); }
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) { return; }
        if ($error !== UPLOAD_ERR_OK) { self::fail('upload'); }
        $tmp = (string)($upload['tmp_name'] ?? '');
        $name = clean_filename((string)($upload['name'] ?? ''));
        $size = (int)($upload['size'] ?? 0);
        if ($tmp === '' || $name === '' || $size < 0 || !is_uploaded_file($tmp)) { self::fail('upload'); }
        $maxbytes = get_max_upload_file_size();
        if ($maxbytes > 0 && $size > $maxbytes) { self::fail('filesize'); }
        $context = \context_system::instance(); $fs = get_file_storage();
        $filename = $name; $pathinfo = pathinfo($name); $base = (string)($pathinfo['filename'] ?? 'file');
        $ext = isset($pathinfo['extension']) && $pathinfo['extension'] !== '' ? '.' . $pathinfo['extension'] : '';
        $n = 2;
        while ($fs->file_exists($context->id, 'local_kbacompany', 'task_result', (int)$row->id, '/', $filename)) {
            $filename = $base . ' (' . $n . ')' . $ext; $n++;
        }
        $fs->create_file_from_pathname([
            'contextid' => $context->id, 'component' => 'local_kbacompany', 'filearea' => 'task_result',
            'itemid' => (int)$row->id, 'filepath' => '/', 'filename' => $filename, 'userid' => $actor,
            'source' => $name, 'timecreated' => time(), 'timemodified' => time(),
        ], $tmp);
        self::log($companyuuid, $taskuuid, $actor, 'result_attachment_add', $row->status, $row->status,
            ['filename' => $filename, 'filesize' => $size]);
    }

    /** Delete result evidence only before final acceptance/completion. */
    public static function delete_result_attachment(string $companyuuid, int $actor, string $taskuuid, string $filename): void {
        company_context_service::context($companyuuid, $actor);
        $row = self::task($companyuuid, $taskuuid);
        $canmanage = self::can_manage($companyuuid, $actor);
        // Ownership of an old attachment is not authority after task access is revoked.
        if (!self::can_view_row($row, $actor, $canmanage)) { self::fail('permission'); }
        if (in_array((string)$row->status, ['done', 'cancelled', 'deleted'], true)) { self::fail('state'); }
        $filename = clean_filename($filename);
        if ($filename === '') { self::fail('invalid'); }
        $context = \context_system::instance(); $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_kbacompany', 'task_result', (int)$row->id, '/', $filename);
        if (!$file) { self::fail('reference'); }
        if (!self::can_execute($row, $actor, $canmanage) && (int)$file->get_userid() !== $actor) { self::fail('permission'); }
        $file->delete();
        self::log($companyuuid, $taskuuid, $actor, 'result_attachment_delete', $row->status, $row->status,
            ['filename' => $filename]);
    }

    public static function add_checklist(string $companyuuid, int $actor, string $taskuuid, string $text,
            int $assigneeid = 0): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $text = trim($text);
        if ($text === '' || \core_text::strlen($text) > 1000 || $assigneeid < 0) { self::fail('invalid'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $canmanage = self::can_manage($companyuuid, $actor);
            $task = self::task($companyuuid, $taskuuid);
            if (!self::can_contribute_to_task($task, $actor, $canmanage)) { self::fail('permission'); }
            if (in_array($task->status, ['done', 'cancelled', 'deleted'], true)) { self::fail('state'); }
            if ($assigneeid > 0) { self::require_employee(self::employees($companyuuid, $actor), $assigneeid); }
            // Reserve next position while holding the same lock used by move/edit/delete.
            $lastorder = (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) FROM {local_kbacompany_taskcheck} '
                . 'WHERE companyuuid = :company AND taskuuid = :task',
                ['company' => $companyuuid, 'task' => $taskuuid]);
            $count = $DB->count_records('local_kbacompany_taskcheck',
                ['companyuuid' => $companyuuid, 'taskuuid' => $taskuuid]);
            $uuid = self::uuid();
            $DB->insert_record('local_kbacompany_taskcheck', (object)[
                'companyuuid' => $companyuuid, 'taskuuid' => $taskuuid, 'uuid' => $uuid, 'itemtext' => $text,
                'assigneeid' => $assigneeid, 'done' => 0, 'sortorder' => max($lastorder, $count) + 1,
                'timecreated' => time(), 'timemodified' => time(),
            ]);
            self::log($companyuuid, $taskuuid, $actor, 'checklist_add', $task->status, $task->status,
                ['checkuuid' => $uuid]);
            $tx->allow_commit();
            return $uuid;
        } catch (\Throwable $e) { if (isset($tx)) { $tx->rollback($e); } throw $e; }
        finally { $lock->release(); }
    }

    public static function toggle_checklist(string $companyuuid, int $actor, string $taskuuid, string $checkuuid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $task = self::task($companyuuid, $taskuuid);
        if (!self::can_view_row($task, $actor, $canmanage) || in_array($task->status, ['done', 'cancelled'], true)) {
            self::fail('state');
        }
        $check = $DB->get_record('local_kbacompany_taskcheck', [
            'companyuuid' => $companyuuid, 'taskuuid' => $taskuuid, 'uuid' => $checkuuid,
        ]);
        if (!$check) { self::fail('reference'); }
        if (!self::can_execute($task, $actor, $canmanage) && (int)$check->assigneeid !== $actor) {
            self::fail('permission');
        }
        $check->done = empty($check->done) ? 1 : 0; $check->timemodified = time();
        $DB->update_record('local_kbacompany_taskcheck', $check);
        self::log($companyuuid, $taskuuid, $actor, 'checklist_toggle', $task->status, $task->status,
            ['checkuuid' => $checkuuid, 'done' => (int)$check->done]);
    }

    /**
     * Edit a checklist item without replacing its identity or completion state.
     * All mutations are scoped to the parent task, company and authenticated actor.
     */
    public static function update_checklist(string $companyuuid, int $actor, string $taskuuid, string $checkuuid,
            string $text, int $assigneeid = 0): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $text = trim($text);
        if ($text === '' || \core_text::strlen($text) > 1000 || $assigneeid < 0) { self::fail('invalid'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $task = self::task($companyuuid, $taskuuid);
            $manager = self::can_manage($companyuuid, $actor);
            if (!self::can_contribute_to_task($task, $actor, $manager)) { self::fail('permission'); }
            if (in_array($task->status, ['done', 'cancelled', 'deleted'], true)) { self::fail('state'); }
            $check = $DB->get_record('local_kbacompany_taskcheck',
                ['companyuuid' => $companyuuid, 'taskuuid' => $taskuuid, 'uuid' => $checkuuid]);
            if (!$check) { self::fail('reference'); }
            if ($assigneeid > 0) { self::require_employee(self::employees($companyuuid, $actor), $assigneeid); }
            if ((string)$check->itemtext !== $text || (int)$check->assigneeid !== $assigneeid) {
                $oldtext = (string)$check->itemtext;
                $oldassignee = (int)$check->assigneeid;
                $check->itemtext = $text;
                $check->assigneeid = $assigneeid;
                $check->timemodified = time();
                $DB->update_record('local_kbacompany_taskcheck', $check);
                self::log($companyuuid, $taskuuid, $actor, 'checklist_edit', $task->status, $task->status,
                    ['checkuuid' => $checkuuid, 'oldtext' => $oldtext, 'newtext' => $text,
                        'oldassigneeid' => $oldassignee, 'assigneeid' => $assigneeid]);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) { if (isset($tx)) { $tx->rollback($e); } throw $e; }
        finally { $lock->release(); }
    }

    /** Delete one item, never another company's item or an item of a different task. */
    public static function delete_checklist(string $companyuuid, int $actor, string $taskuuid, string $checkuuid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $task = self::task($companyuuid, $taskuuid);
            $manager = self::can_manage($companyuuid, $actor);
            if (!self::can_contribute_to_task($task, $actor, $manager)) { self::fail('permission'); }
            if (in_array($task->status, ['done', 'cancelled', 'deleted'], true)) { self::fail('state'); }
            $check = $DB->get_record('local_kbacompany_taskcheck',
                ['companyuuid' => $companyuuid, 'taskuuid' => $taskuuid, 'uuid' => $checkuuid]);
            if (!$check) { self::fail('reference'); }
            $DB->delete_records('local_kbacompany_taskcheck', ['id' => $check->id]);
            self::log($companyuuid, $taskuuid, $actor, 'checklist_delete', $task->status, $task->status,
                ['checkuuid' => $checkuuid, 'text' => (string)$check->itemtext,
                    'assigneeid' => (int)$check->assigneeid, 'done' => (int)$check->done]);
            $tx->allow_commit();
        } catch (\Throwable $e) { if (isset($tx)) { $tx->rollback($e); } throw $e; }
        finally { $lock->release(); }
    }

    /** Move a check exactly one position. Never accept unchecked client-supplied sort indexes. */
    public static function move_checklist(string $companyuuid, int $actor, string $taskuuid,
            string $checkuuid, string $direction): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!in_array($direction, ['up', 'down'], true)) { self::fail('invalid'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('task:' . $companyuuid . ':' . $taskuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $task = self::task($companyuuid, $taskuuid);
            $manager = self::can_manage($companyuuid, $actor);
            if (!self::can_contribute_to_task($task, $actor, $manager)) { self::fail('permission'); }
            if (in_array($task->status, ['done', 'cancelled', 'deleted'], true)) { self::fail('state'); }
            $items = array_values($DB->get_records('local_kbacompany_taskcheck',
                ['companyuuid' => $companyuuid, 'taskuuid' => $taskuuid], 'sortorder ASC, id ASC'));
            $index = null;
            foreach ($items as $i => $item) {
                if ((string)$item->uuid === $checkuuid) { $index = $i; break; }
            }
            if ($index === null) { self::fail('reference'); }
            $nextindex = $index + ($direction === 'up' ? -1 : 1);
            if ($nextindex >= 0 && $nextindex < count($items)) {
                $otheruuid = (string)$items[$nextindex]->uuid;
                $moved = $items[$index];
                $items[$index] = $items[$nextindex];
                $items[$nextindex] = $moved;
                foreach ($items as $i => $item) {
                    if ((int)$item->sortorder !== $i + 1) {
                        $DB->update_record('local_kbacompany_taskcheck', (object)[
                            'id' => $item->id, 'sortorder' => $i + 1, 'timemodified' => time(),
                        ]);
                    }
                }
                self::log($companyuuid, $taskuuid, $actor, 'checklist_move', $task->status, $task->status,
                    ['checkuuid' => $checkuuid, 'direction' => $direction, 'otheruuid' => $otheruuid]);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) { if (isset($tx)) { $tx->rollback($e); } throw $e; }
        finally { $lock->release(); }
    }

    private static function source_label(\stdClass $row): string {
        global $DB;
        if ($row->sourcekind === 'manual') { return get_string('taskcenter_source_manual', 'local_kbacompany'); }
        if ($row->sourcekind === 'adminscale') {
            $sources = $DB->get_records('local_kbacompany_adminitem', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid, 'revision' => (int)$row->sourcerevision,
            ], '', '*', 0, 1);
            if ($sources) { $s = reset($sources); return $s->code . ' — ' . $s->name; }
        }
        if ($row->sourcekind === 'positionwork') {
            $sources = $DB->get_records('local_kbacompany_poswork', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid, 'revision' => (int)$row->sourcerevision,
            ], '', '*', 0, 1);
            if ($sources) { $s = reset($sources); return \core_text::substr((string)$s->action, 0, 255); }
        }
        if ($row->sourcekind === 'recurring') {
            $source = $DB->get_record('local_kbacompany_taskrec', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid,
                'revision' => (int)$row->sourcerevision,
            ]);
            if ($source) { return get_string('taskcenter_source_recurring_title', 'local_kbacompany', $source->title); }
        }
        if ($row->sourcekind === 'template') {
            $template = recurring_task_library_service::template_by_source_uuid((string)$row->sourceuuid, (string)$row->companyuuid);
            if ($template) {
                return get_string('taskcenter_source_template_title', 'local_kbacompany', (string)$template['title']);
            }
        }
        if ($row->sourcekind === 'decision') {
            $decision = $DB->get_record('local_kbacompany_decision', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid,
            ]);
            if ($decision) { return get_string('taskcenter_source_decision_title', 'local_kbacompany', $decision->title); }
        }
        if ($row->sourcekind === 'communication') {
            $message = $DB->get_record('local_kbacompany_commmsg', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid,
            ]);
            if ($message) {
                $thread = $DB->get_record('local_kbacompany_commthread', [
                    'companyuuid' => $row->companyuuid, 'uuid' => $message->threaduuid,
                ]);
                if ($thread) { return get_string('taskcenter_source_communication_title', 'local_kbacompany', $thread->title); }
            }
        }
        if ($row->sourcekind === 'condition') {
            $event = $DB->get_record('local_kbacompany_stateevt', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid,
            ]);
            if ($event) {
                $stat = $DB->get_record('local_kbacompany_stat', [
                    'companyuuid' => $row->companyuuid, 'uuid' => $event->statuuid,
                ], 'id,name');
                $conditionkey = 'indicator_condition_' . (string)$event->conditioncode;
                $condition = get_string_manager()->string_exists($conditionkey, 'local_kbacompany') ?
                    get_string($conditionkey, 'local_kbacompany') : (string)$event->conditioncode;
                $name = $stat ? (string)$stat->name : (string)$event->statuuid;
                return get_string('taskcenter_source_condition_title', 'local_kbacompany', (object)[
                    'indicator' => $name, 'condition' => $condition,
                ]);
            }
        }
        if ($row->sourcekind === 'bplantemplate') { return get_string('taskcenter_source_bplantemplate', 'local_kbacompany'); }
        return get_string('taskcenter_source_' . $row->sourcekind, 'local_kbacompany');
    }

    public static function row_dto(\stdClass $row, array $employees = [], bool $sourceprivate = false): array {
        global $DB;
        $effectiveassignee = self::effective_assignee($row);
        $sourcethreaduuid = '';
        $sourcemeetinguuid = '';
        $sourcerecurrenceuuid = '';
        $sourcetemplatekey = '';
        $sourceconditioneventuuid = '';
        $sourceindicatoruuid = '';
        if ((string)$row->sourcekind === 'recurring' && (string)$row->sourceuuid !== '') {
            $sourcerecurrenceuuid = (string)$row->sourceuuid;
        }
        if ((string)$row->sourcekind === 'template' && (string)$row->sourceuuid !== '') {
            $template = recurring_task_library_service::template_by_source_uuid((string)$row->sourceuuid, (string)$row->companyuuid);
            if ($template) { $sourcetemplatekey = (string)$template['key']; }
        }
        if (!$sourceprivate && (string)$row->sourcekind === 'decision' && (string)$row->sourceuuid !== '') {
            $decision = $DB->get_record('local_kbacompany_decision', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid,
            ], 'id,meetinguuid,threaduuid');
            if ($decision) {
                $sourcemeetinguuid = (string)$decision->meetinguuid;
                $sourcethreaduuid = (string)$decision->threaduuid;
            }
        }
        if (!$sourceprivate && (string)$row->sourcekind === 'communication' && (string)$row->sourceuuid !== '') {
            $message = $DB->get_record('local_kbacompany_commmsg', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid,
            ], 'id,threaduuid');
            if ($message) { $sourcethreaduuid = (string)$message->threaduuid; }
        }
        if ((string)$row->sourcekind === 'condition' && (string)$row->sourceuuid !== '') {
            $event = $DB->get_record('local_kbacompany_stateevt', [
                'companyuuid' => $row->companyuuid, 'uuid' => $row->sourceuuid,
            ], 'id,uuid,statuuid');
            if ($event) {
                $sourceconditioneventuuid = (string)$event->uuid;
                $sourceindicatoruuid = (string)$event->statuuid;
            }
        }
        return [
            'uuid' => (string)$row->uuid, 'parent_uuid' => (string)$row->parentuuid,
            'context_kind' => (string)($row->contextkind ?? ''), 'context_uuid' => (string)($row->contextuuid ?? ''),
            'context_revision' => (int)($row->contextrevision ?? 0), 'context_label' => self::context_label($row),
            'source_kind' => (string)$row->sourcekind, 'source_uuid' => (string)$row->sourceuuid,
            'source_revision' => (int)$row->sourcerevision, 'period_key' => (string)$row->periodkey,
            'source_label' => $sourceprivate && in_array($row->sourcekind, ['decision','communication'], true) ? get_string('taskcenter_source_' . $row->sourcekind, 'local_kbacompany') : self::source_label($row), 'source_thread_uuid' => $sourcethreaduuid,
            'source_meeting_uuid' => $sourcemeetinguuid, 'source_recurrence_uuid' => $sourcerecurrenceuuid,
            'source_template_key' => $sourcetemplatekey, 'source_condition_event_uuid' => $sourceconditioneventuuid,
            'source_indicator_uuid' => $sourceindicatoruuid, 'stream' => (string)$row->stream,
            'title' => (string)$row->title, 'description' => (string)$row->description, 'product' => (string)$row->product,
            'assignee_id' => (int)$row->assigneeid, 'assignee_name' => $employees[(int)$row->assigneeid] ?? '',
            'duty_position_uuid' => (string)($row->dutypositionuuid ?? ''),
            'effective_assignee_id' => $effectiveassignee,
            'effective_assignee_name' => $employees[$effectiveassignee] ?? '',
            'creator_id' => (int)$row->creatorid, 'creator_name' => $employees[(int)$row->creatorid] ?? '',
            'reviewer_id' => (int)$row->reviewerid, 'reviewer_name' => $employees[(int)$row->reviewerid] ?? '',
            'observer_id' => (int)$row->observerid, 'observer_name' => $employees[(int)$row->observerid] ?? '',
            'priority' => (string)$row->priority, 'status' => (string)$row->status,
            'start_date' => (string)$row->startdate, 'start_time' => (string)($row->starttime ?? ''),
            'due_date' => (string)$row->duedate, 'due_time' => (string)($row->duetime ?? ''),
            'plan_minutes' => (int)$row->planminutes, 'fact_minutes' => (int)$row->factminutes,
            'result' => (string)$row->result, 'revision' => (int)$row->revision,
            'started_at' => (int)$row->startedat, 'completed_at' => (int)$row->completedat,
            'time_created' => (int)$row->timecreated, 'time_modified' => (int)$row->timemodified,
        ];
    }

    public static function context(string $companyuuid, int $actor, string $selected = '', string $scope = 'my', array $options = []): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $today = self::reporting_dates()['today'];
        $visible = []; $parentcandidates = [];
        $scope = self::normalise_scope($scope, $canmanage);
        if (empty($options['recordonly'])) {
            list($where, $params) = self::list_conditions($companyuuid, $actor, ['scope' => $scope]);
            foreach ($DB->get_records_sql("SELECT t.* FROM {local_kbacompany_task} t WHERE $where ORDER BY t.duedate,t.id", $params) as $row) {
                $visible[$row->uuid] = self::row_dto($row, $employees);
            }
        }
        if (empty($options['noformoptions'])) {
            list($where, $params) = self::projection_access($companyuuid, $actor);
            $where .= " AND t.status NOT IN ('done','cancelled')";
            $pickq = trim((string)($options['pickq'] ?? ''));
            if ($pickq !== '') { $where .= ' AND ' . $DB->sql_like('t.title', ':parentsearch', false); $params['parentsearch'] = '%' . $DB->sql_like_escape($pickq) . '%'; }
            foreach ($DB->get_records_sql("SELECT t.uuid,t.title FROM {local_kbacompany_task} t WHERE $where ORDER BY t.title,t.id", $params, 0, 100) as $row) {
                $parentcandidates[(string)$row->uuid] = (string)$row->title;
            }
        }
        $selecteddto = null; $selectedcanmanageparticipants = false; $checklist = []; $history = []; $attachments = []; $resultattachments = [];
        $discussion = ['thread_uuid' => '', 'messages' => [], 'restricted' => false, 'can_post' => false];
        if ($selected !== '') {
            list($where, $params) = self::projection_access($companyuuid, $actor);
            $row = $DB->get_record_sql("SELECT t.* FROM {local_kbacompany_task} t WHERE $where AND t.uuid = :selectedtask", $params + ['selectedtask' => $selected]);
            if (!$row) { self::fail('reference'); }
            $selecteddto = self::row_dto($row, $employees, !empty($options['safesources']));
            $selectedcanmanageparticipants = $canmanage || (int)$row->creatorid === $actor;
            $attachments = self::attachments($companyuuid, $actor, $selected);
            $resultattachments = self::result_attachments($companyuuid, $actor, $selected);
            $discussion = empty($options['nodiscussion']) ? self::discussion_context($companyuuid, $actor, $selected) : $discussion;
            foreach ($DB->get_records('local_kbacompany_taskcheck', [
                'companyuuid' => $companyuuid, 'taskuuid' => $selected,
            ], 'sortorder ASC, id ASC') as $item) {
                $checklist[] = ['uuid' => (string)$item->uuid, 'text' => (string)$item->itemtext,
                    'assignee_id' => (int)$item->assigneeid, 'assignee_name' => $employees[(int)$item->assigneeid] ?? '',
                    'done' => !empty($item->done), 'sort_order' => (int)$item->sortorder,
                    'can_toggle' => $canmanage || self::can_execute($row, $actor, $canmanage) || (int)$item->assigneeid === $actor];
            }
            foreach ($DB->get_records('local_kbacompany_tasklog', [
                'companyuuid' => $companyuuid, 'taskuuid' => $selected,
            ], 'id DESC', '*', 0, 50) as $entry) {
                $history[] = ['actor_id' => (int)$entry->actorid, 'actor_name' => $employees[(int)$entry->actorid] ?? '',
                    'action' => (string)$entry->action, 'old_status' => (string)$entry->oldstatus,
                    'new_status' => (string)$entry->newstatus, 'details' => json_decode((string)$entry->details, true) ?: [],
                    'time_created' => (int)$entry->timecreated];
            }
        }
        $counts = self::dashboard_counts($companyuuid, $actor);
        return ['company' => $company, 'can_manage' => $canmanage, 'scope' => $scope,
            'tasks' => array_values($visible), 'selected' => $selecteddto, 'checklist' => $checklist,
            'history' => $history, 'attachments' => $attachments, 'result_attachments' => $resultattachments,
            'discussion' => $discussion, 'employees' => $employees, 'parent_candidates' => $parentcandidates,
            'management_contexts' => empty($options['noformoptions']) ? self::management_context_candidates($companyuuid, trim((string)($options['pickq'] ?? ''))) : [],
            'selected_can_manage_participants' => $selectedcanmanageparticipants,
            'counts' => $counts,
            'legacy_task_count' => $DB->count_records_sql("SELECT COUNT(w.id) FROM {local_kbacompany_wfwork} w WHERE w.companyuuid=:legacycompany AND w.kind='task'" . ($canmanage ? '' : ' AND (w.userid=:legacyuser OR w.reviewerid=:legacyreviewer)'), ['legacycompany'=>$companyuuid] + ($canmanage ? [] : ['legacyuser'=>$actor,'legacyreviewer'=>$actor]))];
    }

    /**
     * Existing canonical tasks explicitly linked to a project, its exact task definitions,
     * or decisions of project meetings visible to this actor. No task generation.
     */
    public static function for_project(string $companyuuid, int $actor, string $projectuuid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($projectuuid === '') { self::fail('context'); }
        $canmanage = self::can_manage($companyuuid, $actor);
        $params = ['company' => $companyuuid, 'deleted' => 'deleted', 'contextproject' => $projectuuid,
            'sourceproject' => $projectuuid, 'meetingproject' => $projectuuid, 'participant' => $actor];
        $sql = "SELECT t.* FROM {local_kbacompany_task} t
                 WHERE t.companyuuid = :company AND t.status <> :deleted
                   AND ((t.contextkind = 'project' AND t.contextuuid = :contextproject)
                     OR (t.sourcekind = 'adminscale' AND EXISTS (
                         SELECT a.id FROM {local_kbacompany_adminitem} a
                          WHERE a.companyuuid = t.companyuuid AND a.uuid = t.sourceuuid
                            AND a.revision = t.sourcerevision AND a.kind = 'task' AND a.parentuuid = :sourceproject))
                     OR (t.sourcekind = 'decision' AND EXISTS (
                         SELECT d.id FROM {local_kbacompany_decision} d
                           JOIN {local_kbacompany_meeting} m ON m.companyuuid = d.companyuuid AND m.uuid = d.meetinguuid
                          WHERE d.companyuuid = t.companyuuid AND d.uuid = t.sourceuuid
                            AND m.sourcekind = 'admin_project' AND m.sourceuuid = :meetingproject
                            AND EXISTS (SELECT p.id FROM {local_kbacompany_meetpart} p
                                         WHERE p.companyuuid = m.companyuuid AND p.meetinguuid = m.uuid
                                           AND p.userid = :participant AND p.active = 1))))";
        if (!$canmanage) {
            // Same participants as can_view_row/can_view_via_battle_plan, applied before fetching.
            $sql .= " AND (t.assigneeid = :assignee OR t.creatorid = :creator OR t.reviewerid = :reviewer
                        OR t.observerid = :observer OR EXISTS (
                            SELECT bi.id FROM {local_kbacompany_bplanitem} bi
                              JOIN {local_kbacompany_bplan} bp ON bp.companyuuid = bi.companyuuid AND bp.uuid = bi.planuuid
                             WHERE bi.companyuuid = t.companyuuid AND bi.provider = 'bms' AND bi.externalid = t.uuid
                               AND (bp.userid = :planowner OR bp.reviewerid = :planreviewer OR bp.observerid = :planobserver)))";
            $params += ['assignee' => $actor, 'creator' => $actor, 'reviewer' => $actor, 'observer' => $actor,
                'planowner' => $actor, 'planreviewer' => $actor, 'planobserver' => $actor];
        }
        $sql .= ' ORDER BY t.duedate ASC, t.id ASC';
        $employees = self::employees($companyuuid, $actor);
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $out[] = self::row_dto($row, $employees);
        }
        return $out;
    }


    /** Permission predicate shared by narrow Battle Plan projections. Matches can_view_row. */
    private static function projection_access(string $companyuuid, int $actor): array {
        global $DB;
        $where = "t.companyuuid = :taskcompany AND t.status <> 'deleted'"; $params=['taskcompany'=>$companyuuid];
        if (!self::can_manage($companyuuid,$actor)) {
            // Department edit/approve is also a legitimate read permission in
            // can_view_row(). SQL filtering must not hide those same tasks.
            $departmentids = access_role_service::department_task_visibility_userids($companyuuid, $actor);
            $departmentclause = '';
            if ($departmentids) {
                list($insql, $inparams) = $DB->get_in_or_equal($departmentids, SQL_PARAMS_NAMED, 'depttask');
                $departmentclause = 't.assigneeid ' . $insql . ' OR ';
                $params += $inparams;
            }
            [$coverexpr, $coverparams] = self::effective_sql('visibility');
            $params += $coverparams;
            $where .= " AND ({$departmentclause}t.assigneeid=:tassignee OR {$coverexpr}=:tcovered OR t.creatorid=:tcreator OR t.reviewerid=:treviewer OR t.observerid=:tobserver
                OR EXISTS (SELECT bi.id FROM {local_kbacompany_bplanitem} bi
                  JOIN {local_kbacompany_bplan} bp ON bp.companyuuid=bi.companyuuid AND bp.uuid=bi.planuuid
                 WHERE bi.companyuuid=t.companyuuid AND bi.provider='bms' AND bi.externalid=t.uuid
                   AND (bp.userid=:towner OR bp.reviewerid=:tplanreviewer OR bp.observerid=:tplanobserver)))";
            $params += ['tassignee'=>$actor,'tcovered'=>$actor,'tcreator'=>$actor,'treviewer'=>$actor,'tobserver'=>$actor,
                'towner'=>$actor,'tplanreviewer'=>$actor,'tplanobserver'=>$actor];
        }
        return [$where,$params];
    }

    /**
     * Read-only task deadlines for the BMS Calendar.
     *
     * Visibility is applied in SQL through the same projection boundary as the
     * Task Center. No task is created or changed by the calendar.
     */
    public static function calendar_projection(string $companyuuid, int $actor, string $fromdate, string $todate): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $fromdate) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $todate)) {
            throw new \moodle_exception('invalidparameter');
        }
        list($where, $params) = self::projection_access($companyuuid, $actor);
        $where .= " AND t.status <> 'cancelled' AND ((t.duedate <> '' AND t.duedate BETWEEN :calfromdue AND :caltodue)\n"
            . " OR (t.duedate = '' AND t.startdate <> '' AND t.startdate BETWEEN :calfromstart AND :caltostart))";
        $params += [
            'calfromdue' => $fromdate, 'caltodue' => $todate,
            'calfromstart' => $fromdate, 'caltostart' => $todate,
        ];
        $rows = [];
        $sql = "SELECT t.uuid,t.title,t.status,t.priority,t.startdate,t.duedate,t.assigneeid,t.reviewerid,t.observerid\n"
            . " FROM {local_kbacompany_task} t WHERE $where ORDER BY t.duedate,t.startdate,t.id";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $date = (string)$row->duedate !== '' ? (string)$row->duedate : (string)$row->startdate;
            $rows[] = [
                'uuid' => (string)$row->uuid,
                'title' => (string)$row->title,
                'date' => $date,
                'date_kind' => (string)$row->duedate !== '' ? 'due' : 'start',
                'status' => (string)$row->status,
                'priority' => (string)$row->priority,
                'assignee_id' => (int)$row->assigneeid,
            ];
        }
        return $rows;
    }

    /** Company and task visibility for related-record joins; callers use the fixed alias t. */
    public static function readable_query_conditions(string $companyuuid, int $actor): array {
        $companyuuid = company_context_service::normalise_uuid($companyuuid);
        company_context_service::context($companyuuid, $actor);
        return self::projection_access($companyuuid, $actor);
    }

    /** Exact canonical task, without loading all tasks, attachments or discussion history. */
    public static function readable_projection(string $companyuuid, int $actor, string $taskuuid): ?array {
        global $DB;
        company_context_service::context($companyuuid,$actor);
        list($where,$params)=self::projection_access($companyuuid,$actor);
        $row=$DB->get_record_sql("SELECT t.* FROM {local_kbacompany_task} t WHERE $where AND t.uuid=:selectedtask",
            $params+['selectedtask'=>$taskuuid]);
        if (!$row) { return null; }
        return self::row_dto($row, [], true);
    }

    /**
     * Visible manual tasks linked to a BMS24 management-pattern trace marker.
     *
     * The marker is traceability metadata only. The canonical Task Center projection
     * visibility predicate remains authoritative. A bounded exact-title fallback is
     * used only when no marker match exists, for build1704 tasks created before the
     * marker was introduced.
     */
    public static function manual_tasks_by_trace_marker(string $companyuuid, int $actor,
            string $marker, int $assigneeid, string $legacytitle = '', int $limit = 5): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $marker = trim($marker);
        $legacytitle = trim($legacytitle);
        if ($assigneeid <= 0 || ($marker === '' && $legacytitle === '')) { return []; }
        $limit = max(1, min(20, $limit));
        list($where, $params) = self::projection_access($companyuuid, $actor);
        $where .= " AND t.sourcekind = :patternsource AND t.assigneeid = :patternassignee";
        $params += ['patternsource'=>'manual', 'patternassignee'=>$assigneeid];
        $rows = [];
        if ($marker !== '') {
            $markerwhere = $where . ' AND ' . $DB->sql_like('t.description', ':patternmarker', false);
            $markerparams = $params + ['patternmarker'=>'%' . $DB->sql_like_escape($marker) . '%'];
            $rows = $DB->get_records_sql("SELECT t.* FROM {local_kbacompany_task} t WHERE $markerwhere ORDER BY t.id DESC",
                $markerparams, 0, $limit);
        }
        if (!$rows && $legacytitle !== '') {
            $legacywhere = $where . ' AND t.title = :patternlegacytitle';
            $rows = $DB->get_records_sql("SELECT t.* FROM {local_kbacompany_task} t WHERE $legacywhere ORDER BY t.id DESC",
                $params + ['patternlegacytitle'=>$legacytitle], 0, $limit);
        }
        $employees = self::employees($companyuuid, $actor);
        $out = [];
        foreach ($rows as $row) { $out[] = self::row_dto($row, $employees, true); }
        return $out;
    }

    /** Bounded picker for open tasks of the plan employee; excludes already linked tasks in SQL. */
    public static function battle_plan_candidates(string $companyuuid, int $actor, int $targetuser,
            string $planuuid, string $search = '', int $limit = 50): array {
        global $DB;
        company_context_service::context($companyuuid,$actor);
        list($where,$params)=self::projection_access($companyuuid,$actor);
        $where .= " AND t.assigneeid=:targetuser AND t.status NOT IN ('done','cancelled')
            AND NOT EXISTS (SELECT bi.id FROM {local_kbacompany_bplanitem} bi
              WHERE bi.companyuuid=t.companyuuid AND bi.planuuid=:targetplan AND bi.provider='bms' AND bi.externalid=t.uuid)";
        $params += ['targetuser'=>$targetuser,'targetplan'=>$planuuid];
        if ($search !== '') { $where .= ' AND '.$DB->sql_like('t.title',':tasksearch',false); $params['tasksearch']='%'.$DB->sql_like_escape($search).'%'; }
        $out=[];
        foreach ($DB->get_records_sql("SELECT t.* FROM {local_kbacompany_task} t WHERE $where ORDER BY t.duedate,t.id",$params,0,max(1,min(100,$limit))) as $row) {
            $task=self::row_dto($row);
            $task += ['provider'=>'bms','external_id'=>$task['uuid'],'provider_label'=>get_string('taskprovider_bms','local_kbacompany'),
                'source_url'=>(new \moodle_url('/local/kbacompany/tasks.php',['uuid'=>$companyuuid,'task'=>$task['uuid']]))->out(false)];
            $out[]=$task;
        }
        return $out;
    }


    /** Default role-union is intentionally distinct from company-wide manager visibility. */
    public static function normalise_scope(string $scope, bool $manager): string {
        if (!in_array($scope, ['roles','my','created','review','watching','overdue','department','all'], true)) {
            return 'roles';
        }
        return $scope === 'all' && !$manager ? 'roles' : $scope;
    }

    /**
     * One distinct Task row for all direct task roles, coverage and Battle Plan participants.
     * Never infer a user's role from their company-wide management rights.
     * This condition only narrows the projection_access() company/ACL boundary.
     */
    private static function personal_roles_condition(int $actor, string $suffix): array {
        [$covered, $coverageparams] = self::effective_sql('personal' . $suffix);
        $ids = [
            'roleassignee'.$suffix => $actor, 'rolecovered'.$suffix => $actor,
            'rolecreator'.$suffix => $actor, 'rolereviewer'.$suffix => $actor,
            'roleobserver'.$suffix => $actor, 'roleplanowner'.$suffix => $actor,
            'roleplanreviewer'.$suffix => $actor, 'roleplanobserver'.$suffix => $actor,
        ];
        $condition = "(t.assigneeid = :roleassignee{$suffix} OR {$covered} = :rolecovered{$suffix}
            OR t.creatorid = :rolecreator{$suffix} OR t.reviewerid = :rolereviewer{$suffix}
            OR t.observerid = :roleobserver{$suffix}
            OR EXISTS (SELECT bi.id FROM {local_kbacompany_bplanitem} bi
               JOIN {local_kbacompany_bplan} bp
                 ON bp.companyuuid = bi.companyuuid AND bp.uuid = bi.planuuid
               WHERE bi.companyuuid = t.companyuuid AND bi.provider = 'bms' AND bi.externalid = t.uuid
                 AND (bp.userid = :roleplanowner{$suffix} OR bp.reviewerid = :roleplanreviewer{$suffix}
                      OR bp.observerid = :roleplanobserver{$suffix})))";
        return [$condition, $coverageparams + $ids];
    }

    /** Badges describe task relationships, not permissions to edit or accept the task. */
    public static function role_keys(array $task, int $actor): array {
        $roles = [];
        if ($actor <= 0) { return $roles; }
        if ((int)($task['assignee_id'] ?? 0) === $actor ||
                (int)($task['effective_assignee_id'] ?? 0) === $actor) { $roles[] = 'assignee'; }
        if ((int)($task['creator_id'] ?? 0) === $actor) { $roles[] = 'creator'; }
        if ((int)($task['reviewer_id'] ?? 0) === $actor) { $roles[] = 'reviewer'; }
        if ((int)($task['observer_id'] ?? 0) === $actor) { $roles[] = 'observer'; }
        return $roles;
    }

    /** Tab counters use one company/permission-constrained aggregate, not N role queries. */
    public static function scope_counts(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        [$where, $params] = self::projection_access($companyuuid, $actor);
        // Department role grants may allow reviewing team tasks without granting
        // company-wide management. A separate bounded tab exposes just this set.
        $departmentids = access_role_service::department_task_visibility_userids($companyuuid, $actor);
        $departmentexpr = '0';
        if ($departmentids) {
            [$departmentin, $departmentparams] = $DB->get_in_or_equal($departmentids,
                SQL_PARAMS_NAMED, 'depttab');
            $departmentexpr = "SUM(CASE WHEN t.assigneeid {$departmentin} THEN 1 ELSE 0 END)";
            $params += $departmentparams;
        }
        [$roles, $roleparams] = self::personal_roles_condition($actor, 'tabs');
        [$executor, $executorparams] = self::effective_sql('tabexec');
        [$dueexecutor, $dueparams] = self::effective_sql('tabdue');
        $params += $roleparams + $executorparams + $dueparams;
        $params += ['tabactor' => $actor, 'tabdueactor' => $actor,
            'tabtoday' => self::reporting_dates()['today']];
        $row = $DB->get_record_sql("SELECT COUNT(t.id) AS allcount,
            {$departmentexpr} AS departmentcount,
            SUM(CASE WHEN {$roles} THEN 1 ELSE 0 END) AS rolescount,
            SUM(CASE WHEN {$executor} = :tabactor THEN 1 ELSE 0 END) AS mycount,
            SUM(CASE WHEN t.creatorid = :tabcreator THEN 1 ELSE 0 END) AS createdcount,
            SUM(CASE WHEN t.reviewerid = :tabreviewer AND t.status = 'review' THEN 1 ELSE 0 END) AS reviewcount,
            SUM(CASE WHEN t.observerid = :tabobserver THEN 1 ELSE 0 END) AS watchingcount,
            SUM(CASE WHEN {$dueexecutor} = :tabdueactor AND t.duedate <> '' AND t.duedate < :tabtoday
                AND t.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END) AS overduecount
            FROM {local_kbacompany_task} t WHERE {$where}", $params + [
                'tabcreator' => $actor, 'tabreviewer' => $actor, 'tabobserver' => $actor,
            ]);
        return [
            'roles' => (int)($row->rolescount ?? 0), 'my' => (int)($row->mycount ?? 0),
            'created' => (int)($row->createdcount ?? 0), 'review' => (int)($row->reviewcount ?? 0),
            'watching' => (int)($row->watchingcount ?? 0), 'overdue' => (int)($row->overduecount ?? 0),
            'all' => (int)($row->allcount ?? 0),
            'department' => (int)($row->departmentcount ?? 0),
            'department_allowed' => !empty($departmentids),
        ];
    }

    private static function list_conditions(string $companyuuid, int $actor, array $filters): array {
        global $DB;
        list($where, $params) = self::projection_access($companyuuid, $actor);
        $scope = self::normalise_scope((string)($filters['scope'] ?? 'my'), self::can_manage($companyuuid, $actor));
        if ($scope === 'roles') {
            [$rolecondition, $roleparams] = self::personal_roles_condition($actor, 'list');
            $where .= ' AND ' . $rolecondition;
            $params += $roleparams;
        } else if ($scope === 'department') {
            $departmentids = access_role_service::department_task_visibility_userids($companyuuid, $actor);
            if (!$departmentids) {
                $where .= ' AND 1 = 0'; // No department grant: fail closed.
            } else {
                [$departmentin, $departmentparams] = $DB->get_in_or_equal($departmentids,
                    SQL_PARAMS_NAMED, 'deptsco');
                $where .= ' AND t.assigneeid ' . $departmentin;
                $params += $departmentparams;
            }
        }
        else if ($scope === 'my' || $scope === 'overdue') {
            [$expr, $extra] = self::effective_sql('myscope');
            $where .= ' AND ' . $expr . ' = :scopeactor';
            $params += $extra; $params['scopeactor'] = $actor;
        }
        else if ($scope === 'created') { $where .= ' AND t.creatorid = :scopeactor'; $params['scopeactor'] = $actor; }
        else if ($scope === 'review') { $where .= " AND t.reviewerid = :scopeactor AND t.status = 'review'"; $params['scopeactor'] = $actor; }
        else if ($scope === 'watching') { $where .= ' AND t.observerid = :scopeactor'; $params['scopeactor'] = $actor; }
        if ($scope === 'overdue') { $where .= " AND t.duedate <> '' AND t.duedate < :scopetoday AND t.status NOT IN ('done','cancelled')"; $params['scopetoday'] = self::reporting_dates()['today']; }
        // Workspace can page over open tasks without completed rows consuming its first 25 slots.
        if (!empty($filters['openonly'])) { $where .= " AND t.status NOT IN ('done','cancelled')"; }
        foreach (['status' => self::STATUSES, 'stream' => self::STREAMS, 'sourcekind' => self::SOURCE_KINDS, 'priority' => self::PRIORITIES] as $field => $allowed) {
            $value = (string)($filters[$field] ?? '');
            if ($value !== '' && in_array($value, $allowed, true)) { $where .= " AND t.$field = :f$field"; $params['f'.$field] = $value; }
        }
        $battleplan = trim((string)($filters['battleplan'] ?? ''));
        if ($battleplan !== '') {
            $where .= " AND EXISTS (SELECT bi.id FROM {local_kbacompany_bplanitem} bi
                WHERE bi.companyuuid=t.companyuuid AND bi.planuuid=:fbattleplan AND bi.provider='bms'
                  AND (bi.externalid=t.uuid OR bi.taskuuid=t.uuid))";
            $params['fbattleplan'] = $battleplan;
        }
        // Analytics drill-down is an additional read-only predicate over the same canonical task set.
        // It deliberately preserves scope and Battle Plan filters instead of switching the user to a
        // different Task Center scope (for example, the special "overdue" personal scope).
        $signal = (string)($filters['signal'] ?? '');
        if ($signal === 'open') {
            $where .= " AND t.status NOT IN ('done','cancelled')";
        } else if ($signal === 'done') {
            $where .= " AND t.status = 'done'";
        } else if ($signal === 'review') {
            $where .= " AND t.status = 'review'";
        } else if ($signal === 'overdue' || $signal === 'subtaskoverdue') {
            $where .= " AND t.duedate <> '' AND t.duedate < :signaltoday AND t.status NOT IN ('done','cancelled')";
            if ($signal === 'subtaskoverdue') { $where .= " AND t.parentuuid <> ''"; }
            $params['signaltoday'] = self::reporting_dates()['today'];
        } else if ($signal === 'returned') {
            $where .= " AND t.status = 'returned'";
        } else if ($signal === 'soon') {
            $where .= " AND t.duedate >= :signalfrom AND t.duedate <= :signaluntil AND t.status NOT IN ('done','cancelled')";
            // Seven calendar dates including today: today + six days.
            $dates = self::reporting_dates();
            $params['signalfrom'] = $dates['today'];
            $params['signaluntil'] = $dates['until'];
        } else if ($signal === 'undated') {
            $where .= " AND t.duedate = '' AND t.status NOT IN ('done','cancelled')";
        }
        $duegroup = self::normalise_duegroup((string)($filters['duegroup'] ?? ''));
        if ($duegroup !== '') {
            [$groupwhere, $groupparams] = self::deadline_group_condition($duegroup, self::reporting_dates());
            $where .= ' AND (' . $groupwhere . ')';
            $params += $groupparams;
        }
        // Narrow analytics drill-down predicates are ALWAYS combined with the existing
        // actor visibility, company and Battle Plan restrictions (never replace them).
        $assignee = (int)($filters['assignee'] ?? 0);
        if ($assignee > 0) {
            // Manager drill-downs must filter by the SAME effective executor shown
            // in the task list. A position substitute must not disappear from
            // their workload when a supervisor clicks the employee's name.
            [$effective, $effectiveparams] = self::effective_sql('filterassignee');
            $where .= ' AND ' . $effective . ' = :reportassignee';
            $params += $effectiveparams;
            $params['reportassignee'] = $assignee;
        }
        $project = (string)($filters['project'] ?? '');
        if ($project !== '') {
            if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $project)) {
                self::fail('invalid');
            }
            // Direct project context only: subtask inheritance is deliberately not guessed.
            $where .= " AND t.contextkind = 'project' AND t.contextuuid = :reportproject";
            $params['reportproject'] = $project;
        }
        $search = \core_text::substr(trim((string)($filters['q'] ?? '')), 0, 200);
        if ($search !== '') { $where .= ' AND ' . $DB->sql_like('t.title', ':tasksearch', false); $params['tasksearch'] = '%' . $DB->sql_like_escape($search) . '%'; }
        return [$where, $params];
    }

    /** SQL counts, independent of the current page and limited to the actor's tasks. */
    public static function dashboard_counts(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        list($where, $params) = self::projection_access($companyuuid, $actor);
        [$myexecutor, $myparams] = self::effective_sql('dashmy');
        [$dueexecutor, $dueparams] = self::effective_sql('dashdue');
        [$doneexecutor, $doneparams] = self::effective_sql('dashdone');
        $params += $myparams + $dueparams + $doneparams;
        $sql = "SELECT COUNT(t.id) AS total,
            SUM(CASE WHEN {$myexecutor} = :myactor AND t.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END) AS my_open,
            SUM(CASE WHEN {$dueexecutor} = :dueactor AND t.duedate <> '' AND t.duedate < :today AND t.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END) AS overdue,
            SUM(CASE WHEN t.reviewerid = :reviewactor AND t.status = 'review' THEN 1 ELSE 0 END) AS review,
            SUM(CASE WHEN t.observerid = :watchactor THEN 1 ELSE 0 END) AS watching,
            SUM(CASE WHEN {$doneexecutor} = :doneactor AND t.status = 'done' THEN 1 ELSE 0 END) AS done
            FROM {local_kbacompany_task} t WHERE $where";
        $r = $DB->get_record_sql($sql, $params + ['myactor'=>$actor,'dueactor'=>$actor,'today'=>self::reporting_dates()['today'],
            'reviewactor'=>$actor,'watchactor'=>$actor,'doneactor'=>$actor]);
        return array_map('intval', (array)$r);
    }

    /** Active deadline groups first; pagination happens after this order, with stable id tie-breaker. */
    private static function deadline_sql_order(array $dates): array {
        $order = "CASE WHEN t.status IN ('done','cancelled') THEN 5
            WHEN t.duedate = '' THEN 4
            WHEN t.duedate < :ordersover THEN 0
            WHEN t.duedate = :orderstoday THEN 1
            WHEN t.duedate <= :ordersuntil THEN 2 ELSE 3 END ASC,
            CASE t.priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END ASC,
            CASE WHEN t.duedate = '' THEN 1 ELSE 0 END ASC, t.duedate ASC, t.id ASC";
        return [$order, ['ordersover'=>$dates['today'], 'orderstoday'=>$dates['today'],
            'ordersuntil'=>$dates['until']]];
    }

    /** ACL- and filter-scoped full Kanban counts, unaffected by list pagination. */
    public static function kanban_status_counts(string $companyuuid, int $actor, array $filters = []): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        [$where, $params] = self::list_conditions($companyuuid, $actor, $filters);
        $counts = array_fill_keys(array_values(array_diff(self::STATUSES, ['deleted'])), 0);
        foreach ($DB->get_records_sql("SELECT t.status, COUNT(t.id) AS quantity
                FROM {local_kbacompany_task} t WHERE {$where} GROUP BY t.status", $params) as $row) {
            if (array_key_exists((string)$row->status, $counts)) {
                $counts[(string)$row->status] = (int)$row->quantity;
            }
        }
        return $counts;
    }

    /**
     * Latest canonical audit event for each task on an already-ACL-filtered page.
     * The ACL is re-applied in SQL; no raw log details or inaccessible task events leak.
     * Bounded to 100 visible UUIDs, using one grouped query rather than N queries.
     */
    public static function latest_page_activity(string $companyuuid, int $actor, array $tasks,
            ?array $employees = null): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $uuids = [];
        foreach (array_slice($tasks, 0, 100) as $task) {
            if (!empty($task['uuid']) && is_string($task['uuid'])) { $uuids[] = $task['uuid']; }
        }
        $uuids = array_values(array_unique($uuids));
        if (!$uuids) { return []; }
        [$scopewhere, $scopeparams] = self::projection_access($companyuuid, $actor);
        [$in, $inparams] = $DB->get_in_or_equal($uuids, SQL_PARAMS_NAMED, 'activityuuid');
        $sql = "SELECT l.id, l.taskuuid, l.actorid, l.action, l.newstatus, l.timecreated
                  FROM {local_kbacompany_tasklog} l
                  JOIN (SELECT taskuuid, MAX(id) AS lastid
                          FROM {local_kbacompany_tasklog}
                         WHERE companyuuid = :activitycompany AND taskuuid {$in}
                      GROUP BY taskuuid) latest ON latest.lastid = l.id
                  JOIN {local_kbacompany_task} t
                    ON t.companyuuid = l.companyuuid AND t.uuid = l.taskuuid
                 WHERE {$scopewhere} AND l.companyuuid = :activitycompany2";
        $entries = $DB->get_records_sql($sql,
            $scopeparams + $inparams + ['activitycompany'=>$companyuuid, 'activitycompany2'=>$companyuuid]);
        if (!$entries) { return []; }
        // list_page() already loaded the permission-scoped employee directory.
        // Reuse it rather than fetching organization context a second time.
        $employees = $employees ?? self::employees($companyuuid, $actor);
        $result = [];
        foreach ($entries as $entry) {
            $result[(string)$entry->taskuuid] = [
                'action'=>(string)$entry->action,
                'actor_name'=>(string)($employees[(int)$entry->actorid] ?? ''),
                'time_created'=>(int)$entry->timecreated,
                'new_status'=>(string)$entry->newstatus,
            ];
        }
        return $result;
    }

    /** Derived attention signals; existing status/deadline/priority remain authoritative. */
    public static function attention_signals(array $task, ?array $dates = null): array {
        $dates = $dates ?? self::reporting_dates();
        $status = (string)($task['status'] ?? '');
        if (in_array($status, ['done', 'cancelled', 'deleted'], true)) { return []; }
        $signals = [];
        $due = (string)($task['due_date'] ?? '');
        if ($due !== '' && $due < $dates['today']) { $signals[] = 'overdue'; }
        if ($status === 'review') { $signals[] = 'review'; }
        if ($status === 'returned') { $signals[] = 'returned'; }
        if ((string)($task['priority'] ?? '') === 'high') { $signals[] = 'priority'; }
        return $signals;
    }

    /** One bounded page and full filtered summary; list/kanban/analytics share the same predicates. */
    public static function list_page(string $companyuuid, int $actor, array $filters = [], int $page = 0, int $pagesize = 25): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        list($where, $params) = self::list_conditions($companyuuid, $actor, $filters);
        $counts = $DB->get_record_sql("SELECT COUNT(t.id) AS total,
            SUM(CASE WHEN t.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END) AS open,
            SUM(CASE WHEN t.status = 'review' THEN 1 ELSE 0 END) AS review,
            SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS done,
            SUM(CASE WHEN t.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
            SUM(CASE WHEN t.duedate <> '' AND t.duedate < :counttoday AND t.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END) AS overdue
            FROM {local_kbacompany_task} t WHERE $where", $params + ['counttoday'=>self::reporting_dates()['today']]);
        $counts = array_map('intval', (array)$counts);
        $pagesize = max(1, min(100, $pagesize));
        $page = max(0, min($page, max(0, (int)ceil($counts['total'] / $pagesize)-1)));
        $employees = self::employees($companyuuid, $actor); $rows = [];
        // Apply a fixed allowlisted SQL order to the entire filtered set BEFORE pagination.
        // The same ACL/company predicates apply regardless of the requested sort.
        if (!empty($filters['deadlineview'])) {
            $sortjoin = '';
            [$sortorder, $sortparams] = self::deadline_sql_order(self::reporting_dates());
            $params += $sortparams;
        } else {
            [$sortjoin, $sortorder, $sortparams] = task_list_preferences_service::sql_order(
                (string)($filters['sort'] ?? 'duedate'), (string)($filters['dir'] ?? 'asc'));
            $params += $sortparams;
        }
        foreach ($DB->get_records_sql("SELECT t.* FROM {local_kbacompany_task} t{$sortjoin} WHERE $where
                ORDER BY {$sortorder}", $params, $page*$pagesize, $pagesize) as $row) {
            $rows[] = self::row_dto($row, $employees, true);
        }
        return ['tasks'=>$rows, 'counts'=>$counts, 'page'=>$page, 'pagesize'=>$pagesize, 'total'=>$counts['total'],
            'can_manage'=>self::can_manage($companyuuid, $actor), 'employees'=>$employees];
    }

    /**
     * Task Execution Analytics 1.0. Read-only grouped queries over the SAME permission
     * and filter predicate as list_page(); no unbounded PHP scans, no cross-company data.
     * Limits apply only to presentation lists, not to aggregate totals.
     */
    public static function execution_report(string $companyuuid, int $actor, array $filters = []): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        [$where, $baseparams] = self::list_conditions($companyuuid, $actor, $filters);
        $dates = self::reporting_dates();
        $today = $dates['today'];
        $until = $dates['until'];
        $pending = "t.status NOT IN ('done','cancelled')";
        $totals = $DB->get_record_sql("SELECT COUNT(t.id) AS total,
                SUM(CASE WHEN t.status = 'returned' THEN 1 ELSE 0 END) AS returned,
                SUM(CASE WHEN $pending AND t.duedate = '' THEN 1 ELSE 0 END) AS undated,
                SUM(CASE WHEN $pending AND t.duedate BETWEEN :reportfrom AND :reportuntil THEN 1 ELSE 0 END) AS soon,
                SUM(CASE WHEN $pending AND t.parentuuid <> '' AND t.duedate <> ''
                    AND t.duedate < :reporttoday THEN 1 ELSE 0 END) AS subtaskoverdue
                FROM {local_kbacompany_task} t WHERE $where",
            $baseparams + ['reportfrom'=>$today, 'reportuntil'=>$until, 'reporttoday'=>$today]);
        $summary = [];
        foreach (['total','returned','undated','soon','subtaskoverdue'] as $key) {
            $summary[$key] = (int)($totals->$key ?? 0);
        }
        // Match the effective executor shown by list_page(), including active
        // position coverage. Use a derived table so the parameterised coverage
        // expression appears ONCE: Moodle's named placeholders may not repeat.
        [$effectiveexecutor, $executorparams] = self::effective_sql('reportexecutor');
        $assigned = $DB->get_records_sql("SELECT report.effectiveuserid AS userid, COUNT(*) AS total,
                SUM(CASE WHEN report.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN report.status = 'review' THEN 1 ELSE 0 END) AS review,
                SUM(CASE WHEN report.status = 'returned' THEN 1 ELSE 0 END) AS returned,
                SUM(CASE WHEN report.status = 'done' THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN report.status NOT IN ('done','cancelled') AND report.duedate <> ''
                    AND report.duedate < :assigneetoday THEN 1 ELSE 0 END) AS overdue
                FROM (SELECT {$effectiveexecutor} AS effectiveuserid, t.status, t.duedate
                      FROM {local_kbacompany_task} t WHERE $where) report
                GROUP BY report.effectiveuserid
                ORDER BY overdue DESC, active DESC, total DESC, report.effectiveuserid ASC",
            $baseparams + $executorparams + ['assigneetoday'=>$today], 0, 15);
        $employees = self::employees($companyuuid, $actor);
        $byassignee = [];
        foreach ($assigned as $row) {
            $id = (int)$row->userid;
            $byassignee[] = ['userid'=>$id,'name'=>$employees[$id] ?? ('#'.$id),
                'total'=>(int)$row->total,'active'=>(int)$row->active,
                'review'=>(int)$row->review,'returned'=>(int)$row->returned,
                'done'=>(int)$row->done,'overdue'=>(int)$row->overdue];
        }
        // Only direct, explicitly recorded BMS project links are included here.
        $projectwhere = $where . " AND t.contextkind = 'project' AND t.contextuuid <> ''";
        $projects = $DB->get_records_sql("SELECT t.contextuuid AS projectuuid,
                MAX(a.name) AS projectname, MAX(a.code) AS projectcode,
                COUNT(t.id) AS total,
                SUM(CASE WHEN $pending THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN $pending AND t.duedate <> '' AND t.duedate < :projecttoday THEN 1 ELSE 0 END) AS overdue
                FROM {local_kbacompany_task} t
                LEFT JOIN {local_kbacompany_adminitem} a ON a.companyuuid = t.companyuuid
                    AND a.uuid = t.contextuuid AND a.revision = t.contextrevision AND a.kind = 'project'
                WHERE $projectwhere GROUP BY t.contextuuid
                ORDER BY overdue DESC, active DESC, total DESC, t.contextuuid ASC",
            $baseparams + ['projecttoday'=>$today], 0, 10);
        $byproject = [];
        foreach ($projects as $row) {
            $byproject[] = ['uuid'=>(string)$row->projectuuid,
                'name'=>trim((string)$row->projectcode.' '.(string)$row->projectname) ?: (string)$row->projectuuid,
                'total'=>(int)$row->total, 'active'=>(int)$row->active,
                'done'=>(int)$row->done,'overdue'=>(int)$row->overdue];
        }
        [$riskeffective, $riskparams] = self::effective_sql('reportrisk');
        $risks = $DB->get_records_sql("SELECT t.id, t.uuid, t.title, t.parentuuid,
                    {$riskeffective} AS effectiveassigneeid, t.status, t.duedate
                FROM {local_kbacompany_task} t
                WHERE $where AND (t.status = 'returned' OR
                    ($pending AND t.duedate <> '' AND t.duedate < :risktoday))
                ORDER BY CASE WHEN t.duedate <> '' AND t.duedate < :riskorderdate THEN 0 ELSE 1 END,
                    t.duedate ASC, t.id ASC",
            $baseparams + $riskparams + ['risktoday'=>$today,'riskorderdate'=>$today], 0, 12);
        $riskitems = [];
        foreach ($risks as $row) {
            $id = (int)$row->effectiveassigneeid;
            $riskitems[] = ['uuid'=>(string)$row->uuid,'title'=>(string)$row->title,
                'subtask'=>(string)$row->parentuuid !== '', 'assignee'=>$employees[$id] ?? ('#'.$id),
                'status'=>(string)$row->status,'duedate'=>(string)$row->duedate];
        }
        return ['summary'=>$summary, 'assignees'=>$byassignee, 'projects'=>$byproject,
            'risks'=>$riskitems, 'limits'=>['assignees'=>15, 'projects'=>10, 'risks'=>12]];
    }

    /** Canonical provider selection; filters are applied BEFORE fetching rows. */
    public static function provider_rows(string $companyuuid, int $actor, int $target, string $scope): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        list($where,$params) = self::projection_access($companyuuid,$actor);
        if ($target > 0) { $where .= ' AND t.assigneeid=:providertarget'; $params['providertarget']=$target; }
        if ($scope==='created') { $where .= ' AND t.creatorid=:provideractor'; $params['provideractor']=$actor; }
        if ($scope==='review') { $where .= " AND t.reviewerid=:providerreview AND t.status='review'"; $params['providerreview']=$actor; }
        if ($scope==='overdue') { $where .= " AND t.duedate<>'' AND t.duedate<:providertoday AND t.status NOT IN ('done','cancelled')"; $params['providertoday']=self::reporting_dates()['today']; }
        $employees=self::employees($companyuuid,$actor); $out=[];
        foreach ($DB->get_records_sql("SELECT t.* FROM {local_kbacompany_task} t WHERE $where ORDER BY t.duedate,t.id",$params) as $row) { $out[]=self::row_dto($row,$employees,true); }
        return $out;
    }

    /** The existing domain's operation availability, used by the record UI. */
    public static function record_actions(array $task, int $actor, bool $manager, string $companyuuid = ''): array {
        $state=$task['status']; $editor=$manager || $task['creator_id']===$actor;
        $originaleditor = $editor;
        $executor=$manager || ($task['effective_assignee_id'] ?? $task['assignee_id'])===$actor;
        $reviewer=$manager || $task['reviewer_id']===$actor;
        if ($companyuuid !== '') {
            $editor = $editor || access_role_service::may_act_on_department_task($companyuuid, $actor,
                (int)$task['assignee_id'], 'edit');
            $reviewer = $reviewer || access_role_service::may_act_on_department_task($companyuuid, $actor,
                (int)$task['assignee_id'], 'approve');
        }
        return ['classify_duty'=>$manager && in_array($state,['open','doing','returned'],true),
            'save'=>$editor && in_array($state,['open','returned'],true),
            'start'=>$executor && in_array($state,['open','returned'],true),
            'submit'=>$executor && in_array($state,['open','doing','returned'],true),
            'accept'=>$reviewer && $state==='review','return'=>$reviewer && $state==='review',
            'cancel'=>$originaleditor && !in_array($state,['done','cancelled','deleted'],true),
            'delete'=>$originaleditor && in_array($state,['open','doing','returned','cancelled'],true),
            'observer'=>$originaleditor && $state!=='deleted',
            'resultattachmentadd'=>$executor && !in_array($state,['cancelled','deleted'],true),
            'attachmentadd'=>($manager || $task['creator_id']===$actor ||
                ($task['effective_assignee_id'] ?? $task['assignee_id'])===$actor || $task['reviewer_id']===$actor ||
                $task['observer_id']===$actor || $editor) && !in_array($state,['cancelled','deleted'],true),
            'checkadd'=>($manager || $task['creator_id']===$actor ||
                ($task['effective_assignee_id'] ?? $task['assignee_id'])===$actor || $task['reviewer_id']===$actor ||
                $task['observer_id']===$actor || $editor) && !in_array($state,['done','cancelled','deleted'],true)];
    }

    /** Idempotent checklist state change for the UI. The old toggle API remains compatible. */
    public static function set_checklist_state(string $companyuuid, int $actor, string $taskuuid, string $checkuuid,
            bool $done): void {
        global $DB;
        company_context_service::context($companyuuid,$actor);
        $lock=\core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('task:'.$companyuuid.':'.$taskuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx=$DB->start_delegated_transaction();
            $task=self::task($companyuuid,$taskuuid); $manager=self::can_manage($companyuuid,$actor);
            if (!self::can_view_row($task,$actor,$manager) || in_array($task->status,['done','cancelled','deleted'],true)) { self::fail('state'); }
            $check=$DB->get_record('local_kbacompany_taskcheck',['companyuuid'=>$companyuuid,'taskuuid'=>$taskuuid,'uuid'=>$checkuuid]);
            if (!$check) { self::fail('reference'); }
            if (!self::can_execute($task,$actor,$manager) && (int)$check->assigneeid!==$actor) { self::fail('permission'); }
            if ((bool)$check->done!==$done) {
                $check->done=(int)$done; $check->timemodified=time(); $DB->update_record('local_kbacompany_taskcheck',$check);
                self::log($companyuuid,$taskuuid,$actor,'checklist_toggle',$task->status,$task->status,['checkuuid'=>$checkuuid,'done'=>(int)$done]);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) { if(isset($tx)){$tx->rollback($e);} throw $e; }
        finally { $lock->release(); }
    }

    /**
     * Whether an actor may decompose this active parent task. Reading alone never
     * permits creating child work under another owner's task.
     */
    public static function can_add_subtasks(string $companyuuid, int $actor, string $parentuuid): bool {
        company_context_service::context($companyuuid, $actor);
        $parent = self::task($companyuuid, $parentuuid);
        $manager = self::can_manage($companyuuid, $actor);
        if (!self::can_view_row($parent, $actor, $manager) ||
                !in_array((string)$parent->status, ['open', 'doing', 'returned'], true)) {
            return false;
        }
        return $manager || (int)$parent->creatorid === $actor ||
            self::effective_assignee($parent) === $actor ||
            access_role_service::may_act_on_department_task($companyuuid, $actor,
                (int)$parent->assigneeid, 'edit');
    }

    /** Compute display progress only from tasks actually visible to this user. */
    public static function subtask_progress(array $counts, bool $limited = false): array {
        $total = max(0, (int)($counts['total'] ?? 0));
        $done = max(0, min($total, (int)($counts['done'] ?? 0)));
        $cancelled = max(0, min($total - $done, (int)($counts['cancelled'] ?? 0)));
        $active = $total - $cancelled;
        return [
            'total' => $total, 'done' => $done, 'cancelled' => $cancelled,
            'pending' => max(0, $active - $done),
            'review' => max(0, (int)($counts['review'] ?? 0)),
            'overdue' => max(0, (int)($counts['overdue'] ?? 0)),
            'limited' => $limited,
            'percent' => $limited || $active === 0 ? null : (int)round(100 * $done / $active),
        ];
    }

    /**
     * Direct children, bounded to 20 per page. Aggregates use the full visible set
     * (not the current page). Hidden child existence is reported only as a boolean;
     * child titles, counts and statuses stay confidential.
     */
    public static function subtasks_page(string $companyuuid, int $actor, string $parentuuid, int $page = 0): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!self::readable_projection($companyuuid, $actor, $parentuuid)) { self::fail('reference'); }
        list($where, $params) = self::projection_access($companyuuid, $actor);
        $where .= ' AND t.parentuuid = :childparent';
        $params['childparent'] = $parentuuid;
        $today = self::reporting_dates()['today'];
        $counts = $DB->get_record_sql("SELECT COUNT(t.id) AS total,
                 SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END) AS done,
                 SUM(CASE WHEN t.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                 SUM(CASE WHEN t.status = 'review' THEN 1 ELSE 0 END) AS review,
                 SUM(CASE WHEN t.status NOT IN ('done','cancelled') AND t.duedate <> ''
                           AND t.duedate < :childtoday THEN 1 ELSE 0 END) AS overdue
               FROM {local_kbacompany_task} t WHERE $where", $params + ['childtoday' => $today]);
        $values = array_map('intval', (array)$counts);
        $total = (int)$values['total'];
        // A user may see the parent but not every child. Never claim the partial
        // progress is the parent-wide completion percentage.
        $allcount = (int)$DB->count_records_select('local_kbacompany_task',
            "companyuuid = :allcompany AND parentuuid = :allparent AND status <> :alldeleted",
            ['allcompany' => $companyuuid, 'allparent' => $parentuuid, 'alldeleted' => 'deleted']);
        $progress = self::subtask_progress($values, $allcount > $total);
        $pagesize = 20;
        $page = max(0, min(100000, $page));
        if ($total > 0 && $page * $pagesize >= $total) {
            $page = (int)floor(($total - 1) / $pagesize);
        }
        $employees = self::employees($companyuuid, $actor);
        $items = [];
        foreach ($DB->get_records_sql("SELECT t.* FROM {local_kbacompany_task} t WHERE $where ORDER BY t.id ASC",
                $params, $page * $pagesize, $pagesize) as $row) {
            $items[] = self::row_dto($row, $employees, true);
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pagesize' => $pagesize,
            'progress' => $progress];
    }

    /**
     * Weekly Battle Plan coverage of direct children of a readable parent.
     *
     * Counting takes place in SQL over every visible child, independently of the
     * 20-row task-card pager. Only accessible Battle Plans participate: for a
     * non-manager, "not in an accessible plan" is not proof of being unplanned.
     * This is a read model; it creates no copies and changes no task state.
     */
    public static function subtask_week_plan_projection(string $companyuuid, int $actor,
            string $parentuuid, string $weekstart): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!self::readable_projection($companyuuid, $actor, $parentuuid)) {
            self::fail('reference');
        }
        $weekstart = battle_plan_service::week_start($weekstart);
        $weekend = (new \DateTimeImmutable($weekstart, new \DateTimeZone('UTC')))
            ->modify('+6 days')->format('Y-m-d');
        list($where, $params) = self::projection_access($companyuuid, $actor);
        $where .= ' AND t.parentuuid = :bmsweekparent';
        $params['bmsweekparent'] = $parentuuid;
        $params['bmsweekfrom'] = $weekstart;
        $params['bmsweekto'] = $weekend;
        $params['bmsweekcompany'] = $companyuuid;
        // Task editing is not Battle Plan management. Reuse the exact
        // Battle Plan permission rule instead of broadening plan visibility.
        $canmanageplans = permission_service::can_manage_tasks($companyuuid, $actor)
            || access_role_service::can($companyuuid, $actor, 'battle_plan', 'edit');
        $planvisibility = '';
        if (!$canmanageplans) {
            $planvisibility = ' AND (bp.userid = :bmsweekowner OR bp.reviewerid = :bmsweekreviewer '
                . 'OR bp.observerid = :bmsweekobserver)';
            $params += ['bmsweekowner' => $actor, 'bmsweekreviewer' => $actor,
                'bmsweekobserver' => $actor];
        }
        // An EXISTS per visible child prevents double-counting a task that is
        // attached to more than one Battle Plan in the selected week.
        $inplan = "EXISTS (SELECT bi.id FROM {local_kbacompany_bplanitem} bi
            JOIN {local_kbacompany_bplan} bp ON bp.companyuuid = bi.companyuuid
                AND bp.uuid = bi.planuuid
            WHERE bi.companyuuid = :bmsweekcompany AND bi.provider = 'bms'
                AND (bi.externalid = t.uuid OR bi.taskuuid = t.uuid)
                AND bp.periodstart <= :bmsweekto AND bp.periodend >= :bmsweekfrom$planvisibility)";
        $sql = "SELECT COUNT(t.id) AS visiblecount,
                COALESCE(SUM(CASE WHEN t.status NOT IN ('done','cancelled') THEN 1 ELSE 0 END),0) AS open_count,
                COALESCE(SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END),0) AS done_count,
                COALESCE(SUM(CASE WHEN t.status = 'cancelled' THEN 1 ELSE 0 END),0) AS cancelled_count,
                COALESCE(SUM(CASE WHEN t.status NOT IN ('done','cancelled') AND $inplan THEN 1 ELSE 0 END),0)
                    AS planned_count
            FROM {local_kbacompany_task} t WHERE $where";
        $row = $DB->get_record_sql($sql, $params);
        $visible = (int)($row->visiblecount ?? 0);
        $open = (int)($row->open_count ?? 0);
        $planned = (int)($row->planned_count ?? 0);
        // A task's existence must not be exposed to an actor who cannot read it.
        $allcount = (int)$DB->count_records_select('local_kbacompany_task',
            'companyuuid = :bmsweekallcompany AND parentuuid = :bmsweekallparent AND status <> :bmsweekdeleted',
            ['bmsweekallcompany' => $companyuuid, 'bmsweekallparent' => $parentuuid,
                'bmsweekdeleted' => 'deleted']);
        $limited = $allcount > $visible;
        // Workload is only calculated from the visible children that are
        // referenced by an accessible plan in the selected week.
        $workloadsql = "SELECT t.assigneeid,COUNT(t.id) AS taskcount,
                COALESCE(SUM(t.planminutes),0) AS plannedminutes
            FROM {local_kbacompany_task} t WHERE $where
                AND t.status NOT IN ('done','cancelled') AND $inplan
            GROUP BY t.assigneeid ORDER BY taskcount DESC,t.assigneeid ASC";
        $workloadparams = $params;
        $workload = [];
        $employees = self::employees($companyuuid, $actor);
        $workloadlimited = false;
        foreach ($DB->get_records_sql($workloadsql, $workloadparams, 0, 13) as $record) {
            if (count($workload) === 12) { $workloadlimited = true; break; }
            $id = (int)$record->assigneeid;
            $workload[] = [
                'assignee_id' => $id,
                'assignee_name' => (string)($employees[$id] ?? get_string('tui_unavailable', 'local_kbacompany')),
                'tasks' => (int)$record->taskcount,
                'planned_minutes' => (int)$record->plannedminutes,
            ];
        }
        return [
            'weekstart' => $weekstart, 'weekend' => $weekend,
            'visible_count' => $visible, 'open_count' => $open,
            'planned_count' => $planned, 'unmatched_count' => max(0, $open - $planned),
            'done_count' => (int)($row->done_count ?? 0),
            'cancelled_count' => (int)($row->cancelled_count ?? 0),
            'limited' => $limited, 'all_plans_visible' => $canmanageplans,
            'workload' => $workload, 'workload_limited' => $workloadlimited,
        ];
    }

    /** Safe, bounded existing-task picker: only own editable, active, unparented manual tasks. */
    public static function subtask_candidates(string $companyuuid, int $actor, string $parentuuid,
            string $search = '', int $limit = 50): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $limit = max(1, min(50, $limit));
        if (!self::can_add_subtasks($companyuuid, $actor, $parentuuid)) { self::fail('permission'); }
        list($where, $params) = self::projection_access($companyuuid, $actor);
        $where .= " AND t.sourcekind = :childsource AND t.parentuuid = :emptyparent
                   AND t.contextuuid = :emptycontext
                   AND t.status IN ('open', 'returned') AND t.uuid <> :excludedparent";
        $params += ['childsource' => 'manual', 'emptyparent' => '',
            'emptycontext' => '', 'excludedparent' => $parentuuid];
        if (!self::can_manage($companyuuid, $actor)) {
            $where .= ' AND t.creatorid = :childcreator';
            $params['childcreator'] = $actor;
        }
        $search = \core_text::substr(trim($search), 0, 160);
        if ($search !== '') {
            $where .= ' AND ' . $DB->sql_like('t.title', ':childquery', false);
            $params['childquery'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        $out = [];
        foreach ($DB->get_records_sql("SELECT t.id,t.uuid,t.title,t.revision,t.assigneeid,t.duedate
                FROM {local_kbacompany_task} t WHERE $where ORDER BY t.id DESC", $params, 0, $limit) as $row) {
            $out[] = ['uuid' => (string)$row->uuid, 'revision' => (int)$row->revision,
                'title' => (string)$row->title, 'assigneeid' => (int)$row->assigneeid,
                'duedate' => (string)$row->duedate];
        }
        return $out;
    }

    /** Attach one existing canonical task as a child; never clone tasks, discussions or plan membership. */
    public static function link_subtask(string $companyuuid, int $actor, string $parentuuid,
            string $childuuid, int $expectedrevision): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($parentuuid === $childuuid || $parentuuid === '' || $childuuid === '') { self::fail('parent'); }
        // Company lock coordinates with create() and serialises simultaneous reciprocal reparenting.
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('taskcenter:' . $companyuuid, 10);
        if (!$lock) { self::fail('lock'); }
        $childlock = null;
        try {
            $childlock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
                ->get_lock('task:' . $companyuuid . ':' . $childuuid, 10);
            if (!$childlock) { self::fail('lock'); }
            $tx = $DB->start_delegated_transaction();
            if (!self::can_add_subtasks($companyuuid, $actor, $parentuuid)) { self::fail('permission'); }
            $child = self::task($companyuuid, $childuuid);
            $manager = self::can_manage($companyuuid, $actor);
            if (!self::can_view_row($child, $actor, $manager) ||
                    !($manager || (int)$child->creatorid === $actor)) { self::fail('permission'); }
            if ((int)$child->revision !== $expectedrevision) { self::fail('conflict'); }
            if ((string)$child->sourcekind !== 'manual' ||
                    !in_array((string)$child->status, ['open', 'returned'], true) ||
                    (string)$child->parentuuid !== '' || (string)$child->contextuuid !== '') {
                self::fail('state');
            }
            self::validate_parent_chain($companyuuid, $parentuuid, $childuuid);
            $child->parentuuid = $parentuuid;
            $child->revision++;
            $child->timemodified = time();
            $DB->update_record('local_kbacompany_task', $child);
            self::log($companyuuid, $childuuid, $actor, 'subtask_link', $child->status, $child->status,
                ['parentuuid' => $parentuuid]);
            self::log($companyuuid, $parentuuid, $actor, 'subtask_added', '', '',
                ['childuuid' => $childuuid]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            if ($childlock) { $childlock->release(); }
            $lock->release();
        }
    }

    /** Bounded, read-only canonical relation picker. Raw UUIDs are option values, not labels. */
    public static function relation_options(string $companyuuid, int $actor, string $search = '', string $exclude = ''): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $search = \core_text::substr(trim($search), 0, 200);
        list($where, $params) = self::projection_access($companyuuid, $actor);
        $where .= " AND t.status NOT IN ('done','cancelled')";
        if ($search !== '') {
            $where .= ' AND ' . $DB->sql_like('t.title', ':relationsearch', false);
            $params['relationsearch'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        if ($exclude !== '') { $where .= ' AND t.uuid <> :exclude'; $params['exclude'] = $exclude; }
        $out = [];
        foreach ($DB->get_records_sql("SELECT t.uuid,t.title FROM {local_kbacompany_task} t WHERE $where ORDER BY t.title,t.id", $params, 0, 100) as $row) {
            $out[] = ['value' => 'task|' . $row->uuid . '|0',
                'label' => get_string('taskcenter_relation_task', 'local_kbacompany') . ': ' . $row->title];
        }
        foreach (self::management_context_candidates($companyuuid, $search) as $kind => $items) {
            foreach ($items as $item) {
                $out[] = ['value' => $kind . '|' . $item['uuid'] . '|' . $item['revision'],
                    'label' => get_string('adminscale_kind_' . $kind, 'local_kbacompany') . ': ' . $item['code'] . ' — ' . $item['name']];
            }
        }
        return $out;
    }

}
