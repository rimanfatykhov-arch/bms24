<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Weekly Battle Plan: a projection over canonical tasks, not a second task database. */
class battle_plan_service {
    public const CONTRACT_VERSION = '0.6.2';
    public const STATES = ['draft', 'submitted', 'active', 'closed'];

    /** Company-wide Battle Plan manager; deliberately independent of task grants. */
    private static function can_manage(string $companyuuid, int $actor): bool {
        return permission_service::can_manage_tasks($companyuuid, $actor)
            || access_role_service::can($companyuuid, $actor, 'battle_plan', 'edit');
    }

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['WeeklyBattlePlan', 'BattlePlanTaskReference', 'BattlePlanTemplateRevision', 'BattlePlanQuotaRevision'],
            'states' => self::STATES,
            'streams' => task_service::STREAMS,
            'rules' => [
                'battle_plan_is_weekly_employee_projection',
                'battle_plan_references_task_provider_objects_without_copying',
                'change_stream_accepts_canonical_tasks_from_administrative_cycle_decisions',
                'legacy_administrative_scale_task_projection_remains_supported',
                'run_stream_comes_from_position_work_program',
                'operate_stream_is_current_role_work',
                'develop_stream_is_learning_and_development',
                'unplanned_stream_is_explicit',
                'task_expected_product_remains_visible',
                'task_library_template_can_be_confirmed_as_one_off_canonical_task_in_draft_plan',
                'plan_requires_selected_reviewer_or_explicit_self_approval_before_activation',
                'employee_may_choose_self_as_battle_plan_reviewer_and_self_approve',
                'battle_plan_is_exportable_as_real_docx',
                'battle_plan_observer_has_read_only_access_without_approval_or_edit_authority',
                'canonical_task_can_be_added_to_draft_battle_plan_from_task_center_without_copying',
                'new_task_can_be_created_and_immediately_referenced_by_selected_draft_plan',
                'active_plan_allows_manual_task_creation_with_post_approval_timestamp_marker',
                'closed_plan_requires_all_tasks_done_or_cancelled',
                'weekly_plan_pins_exact_template_and_quota_revisions',
                'quota_state_uses_canonical_weekly_statistic_facts',
                'published_template_can_generate_idempotent_canonical_tasks_for_plan_week',
                'position_indicator_header_reads_canonical_indicator_bindings_and_history',
                'battle_plan_fact_entry_appends_to_canonical_statval_without_copying_indicator',
                'submitted_plan_review_can_approve_or_return_specific_items_for_correction',
                'battle_plan_review_is_append_only_and_company_scoped',
                'draft_plan_period_reviewer_observer_and_empty_owner_position_are_editable_with_stale_form_protection',
                'battle_plan_edit_screen_manages_canonical_task_references_without_copying_tasks',
                'battle_plan_status_changes_only_through_lifecycle_transitions',
                'week_goal_and_outcome_live_in_existing_statepayload_without_parallel_storage',
                'week_meetings_are_read_only_canonical_meeting_projections',
                'weekly_load_is_task_plan_minutes_plus_meeting_duration_without_timesheet_semantics',
                'unfinished_tasks_roll_forward_as_canonical_references_without_copying_or_rescheduling',
                'active_week_may_close_when_every_unfinished_item_is_explicitly_carried_to_next_draft_plan',
                'approved_position_coverage_is_manager_visible_without_rewriting_plan_or_metric_acl',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function fail(string $key): void {
        throw new \moodle_exception('battleplan_' . $key, 'local_kbacompany');
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
     * Current civil date in the Moodle user's timezone, always Gregorian ISO 8601.
     *
     * userdate() is intended for locale-facing display and may not give an ASCII
     * Y-m-d value on every calendar/locale configuration. Business-date keys
     * must not depend on translated formatting (battleplan_date on workspace).
     * $timestamp is injectable so timezone and UTC-midnight transitions can be tested.
     */
    public static function user_today(?int $timestamp = null): string {
        $instant = new \DateTimeImmutable('@' . ($timestamp ?? time()));
        return $instant->setTimezone(\core_date::get_user_timezone_object())->format('Y-m-d');
    }

    public static function week_start(string $date): string {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $date) { self::fail('date'); }
        return $d->modify('-' . ((int)$d->format('N') - 1) . ' days')->format('Y-m-d');
    }

    /** Human-readable week period without relying on optional langconfig date-string keys. */
    public static function period_label(string $start, string $end): string {
        $startts = strtotime($start . ' 12:00:00 UTC');
        $endts = strtotime($end . ' 12:00:00 UTC');
        if ($startts === false || $endts === false) { return trim($start . ' — ' . $end); }
        $left = preg_replace('/\s+/u', ' ', trim(userdate($startts, '%e %B')));
        $right = preg_replace('/\s+/u', ' ', trim(userdate($endts, '%e %B %Y')));
        return $left . ' — ' . $right;
    }

    /** Draft Battle Plans that the actor may edit and therefore receive a task-library template. */
    public static function draft_plan_options(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $positioncache = [];
        $result = [];
        foreach ($DB->get_records('local_kbacompany_bplan', [
            'companyuuid' => $companyuuid, 'status' => 'draft',
        ], 'periodstart DESC, id DESC') as $plan) {
            if (!$canmanage && (int)$plan->userid !== $actor) { continue; }
            $userid = (int)$plan->userid;
            if (!array_key_exists($userid, $positioncache)) {
                $positioncache[$userid] = self::current_positions($companyuuid, $actor, $userid);
            }
            $positionname = $positioncache[$userid][(string)$plan->positionuuid] ?? '';
            if ($positionname === '') {
                $positionname = (string)($DB->get_field('local_kbacompany_position', 'name', [
                    'companyuuid' => $companyuuid, 'uuid' => (string)$plan->positionuuid,
                ]) ?: (string)$plan->positionuuid);
            }
            $result[] = [
                'uuid' => (string)$plan->uuid,
                'user_id' => $userid,
                'employee_name' => (string)($employees[$userid] ?? $userid),
                'position_name' => (string)$positionname,
                'position_uuid' => (string)$plan->positionuuid,
                'period_start' => (string)$plan->periodstart,
                'period_end' => (string)$plan->periodend,
                'label' => self::period_label((string)$plan->periodstart, (string)$plan->periodend),
            ];
        }
        return $result;
    }

    private static function week_end(string $start): string {
        return (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
    }

    private static function employees(string $companyuuid, int $actor): array {
        $organization = organization_service::organization_context($companyuuid, $actor);
        $employees = [];
        foreach ($organization['employees'] as $employee) {
            $employees[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        return $employees;
    }

    private static function current_positions(string $companyuuid, int $actor, int $userid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $sql = "SELECT p.uuid, p.name FROM {local_kbacompany_position} p
                 WHERE p.companyuuid = :company AND p.active = 1 AND EXISTS (
                   SELECT a.id FROM {local_kbacompany_posassign} a
                    WHERE a.companyuuid = p.companyuuid AND a.positionuuid = p.uuid
                      AND a.userid = :userid AND a.active = 1
                      AND (a.effectivefrom = 0 OR a.effectivefrom <= :startnow)
                      AND (a.effectiveto = 0 OR a.effectiveto >= :endnow))
                 ORDER BY p.name, p.uuid";
        $positions = [];
        foreach ($DB->get_records_sql($sql, ['company'=>$companyuuid,'userid'=>$userid,
                'startnow'=>time(),'endnow'=>time()]) as $row) { $positions[$row->uuid] = $row->name; }
        return $positions;
    }

    /** Existing plan permissions expressed before the selected row is fetched. */
    private static function access_sql(string $companyuuid, int $actor, string $alias = 'p'): array {
        $sql = $alias.'.companyuuid = :bpcompany'; $params = ['bpcompany'=>$companyuuid];
        if (!self::can_manage($companyuuid, $actor)) {
            global $DB;
            $departmentids = array_values(array_unique(array_merge(
                access_role_service::department_view_userids($companyuuid, $actor, 'battle_plan'),
                access_role_service::department_approve_userids($companyuuid, $actor))));
            $departmentclause = '';
            if ($departmentids) {
                list($insql, $inparams) = $DB->get_in_or_equal($departmentids, SQL_PARAMS_NAMED, 'deptplan');
                $departmentclause = $alias.'.userid '.$insql.' OR ';
                $params += $inparams;
            }
            // Scoped creator may inspect only draft plans they themselves created for a current subordinate.
            $createdclause = '';
            $createids = access_role_service::department_plan_create_userids($companyuuid, $actor);
            if ($createids) {
                list($createdinsql, $createdparams) = $DB->get_in_or_equal($createids, SQL_PARAMS_NAMED, 'createddept');
                $createdclause = "($alias.createdby = :bpcreatedby AND $alias.status = 'draft' AND $alias.userid $createdinsql) OR ";
                $params += $createdparams;
                $params['bpcreatedby'] = $actor;
            }
            $sql .= " AND ({$departmentclause}{$createdclause}$alias.userid = :bpowner OR $alias.reviewerid = :bpreviewer OR $alias.observerid = :bpobserver)";
            $params += ['bpowner'=>$actor,'bpreviewer'=>$actor,'bpobserver'=>$actor];
        }
        return [$sql, $params];
    }

    private static function plan(string $companyuuid, string $uuid, int $actor): \stdClass {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        list($where, $params) = self::access_sql($companyuuid, $actor);
        $row = $DB->get_record_sql("SELECT p.* FROM {local_kbacompany_bplan} p WHERE $where AND p.uuid = :bpuuid",
            $params + ['bpuuid'=>$uuid]);
        if (!$row) { self::fail('reference'); }
        return $row;
    }

    private static function can_access_plan(\stdClass $plan, int $actor, bool $canmanage): bool {
        return $canmanage || access_role_service::may_edit_department_plan((string)$plan->companyuuid, $actor, (int)$plan->userid)
            || ((int)$plan->createdby === $actor && (string)$plan->status === 'draft'
                && in_array((int)$plan->userid, access_role_service::department_plan_create_userids(
                    (string)$plan->companyuuid, $actor), true))
            || in_array((int)$plan->userid,
            access_role_service::department_view_userids((string)$plan->companyuuid, $actor, 'battle_plan'), true)
            || (int)$plan->userid === $actor || (int)$plan->reviewerid === $actor || (int)$plan->observerid === $actor;
    }

    private static function authorise_target(string $companyuuid, int $actor, int $userid): bool {
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        if ($actor !== $userid && !$canmanage
                && !access_role_service::can($companyuuid, $actor, 'battle_plan', 'create')
                && !in_array($userid, access_role_service::department_plan_create_userids($companyuuid, $actor), true)) { self::fail('permission'); }
        $employees = self::employees($companyuuid, $actor);
        if (!isset($employees[$userid])) { self::fail('employeeerror'); }
        return $canmanage;
    }

    /** Validate reviewer/observer against the current company employee directory. */
    private static function validate_plan_people(string $companyuuid, int $actor, int $reviewerid, int $observerid): void {
        $employees = self::employees($companyuuid, $actor);
        if (!isset($employees[$reviewerid])) { self::fail('reviewererror'); }
        if ($observerid > 0 && !isset($employees[$observerid])) { self::fail('observererror'); }
    }

    /** Resolve and validate exact published design references for a position. */
    private static function design_revisions(string $companyuuid, string $positionuuid,
            string $templateuuid, string $normuuid): array {
        $published = battle_plan_template_service::published_options($companyuuid, $positionuuid);
        $templaterevision = 0;
        $normrevision = 0;
        if ($templateuuid !== '') {
            if (!isset($published['templates'][$templateuuid])) { self::fail('reference'); }
            $templaterevision = (int)$published['templates'][$templateuuid]['revision'];
        }
        if ($normuuid !== '') {
            if (!isset($published['norms'][$normuuid])) { self::fail('reference'); }
            $normrevision = (int)$published['norms'][$normuuid]['revision'];
        }
        return [$templaterevision, $normrevision];
    }

    /** Insert one weekly plan idempotently after the caller has validated the employee/position relationship. */
    private static function ensure_plan_record(string $companyuuid, int $actor, int $userid, string $positionuuid,
            string $periodstart, int $reviewerid, int $observerid, string $templateuuid, int $templaterevision,
            string $normuuid, int $normrevision): array {
        global $DB;
        $periodstart = self::week_start($periodstart);
        $periodend = self::week_end($periodstart);
        $params = [
            'companyuuid' => $companyuuid,
            'userid' => $userid,
            'positionuuid' => $positionuuid,
            'periodstart' => $periodstart,
        ];
        $existing = $DB->get_record('local_kbacompany_bplan', $params);
        if ($existing) {
            return ['uuid' => (string)$existing->uuid, 'created' => false];
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('battleplan:' . $companyuuid . ':' . $userid . ':' . $positionuuid . ':' . $periodstart, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $existing = $DB->get_record('local_kbacompany_bplan', $params);
            if ($existing) {
                $tx->allow_commit();
                return ['uuid' => (string)$existing->uuid, 'created' => false];
            }
            $uuid = self::uuid();
            $now = time();
            $DB->insert_record('local_kbacompany_bplan', (object)[
                'companyuuid' => $companyuuid, 'uuid' => $uuid, 'userid' => $userid,
                'positionuuid' => $positionuuid, 'periodstart' => $periodstart, 'periodend' => $periodend,
                'status' => 'draft', 'reviewerid' => $reviewerid, 'observerid' => $observerid,
                'templateuuid' => $templateuuid, 'templaterevision' => $templaterevision,
                'normuuid' => $normuuid, 'normrevision' => $normrevision, 'statepayload' => '',
                'createdby' => $actor, 'approvedby' => 0,
                'submittedat' => 0, 'approvedat' => 0, 'closedat' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            $tx->allow_commit();
            return ['uuid' => $uuid, 'created' => true];
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Resolve an already existing weekly plan after validating the requested create context. */
    public static function existing_plan_for_context(string $companyuuid, int $actor, int $userid, string $positionuuid,
            string $periodstart): string {
        global $DB;
        $context = self::form_context($companyuuid, $actor, $userid, $positionuuid);
        if ((int)$context['target_user'] !== $userid || (string)$context['position_uuid'] !== $positionuuid) {
            self::fail('permission');
        }
        $periodstart = self::week_start($periodstart);
        $record = $DB->get_record('local_kbacompany_bplan', [
            'companyuuid' => $companyuuid, 'userid' => $userid, 'positionuuid' => $positionuuid,
            'periodstart' => $periodstart,
        ], 'uuid', IGNORE_MISSING);
        return $record ? (string)$record->uuid : '';
    }

    public static function create_plan(string $companyuuid, int $actor, int $userid, string $positionuuid,
            string $periodstart, int $reviewerid, int $observerid = 0, string $templateuuid = '', string $normuuid = ''): string {
        $canmanage = self::authorise_target($companyuuid, $actor, $userid);
        self::validate_plan_people($companyuuid, $actor, $reviewerid, $observerid);
        $positions = self::current_positions($companyuuid, $actor, $userid);
        if (!isset($positions[$positionuuid])) { self::fail('positionerror'); }
        list($templaterevision, $normrevision) = self::design_revisions($companyuuid, $positionuuid, $templateuuid, $normuuid);
        $result = self::ensure_plan_record($companyuuid, $actor, $userid, $positionuuid, $periodstart,
            $reviewerid, $observerid, $templateuuid, $templaterevision, $normuuid, $normrevision);
        if (!$canmanage && !$result['created']) {
            global $DB;
            $existing = $DB->get_record('local_kbacompany_bplan', ['companyuuid' => $companyuuid, 'uuid' => $result['uuid']]);
            if ($existing && (int)$existing->userid !== $actor) { self::fail('permission'); }
        }
        return (string)$result['uuid'];
    }

    /**
     * Explicitly launch one weekly plan from a factual assignment that existed during that week.
     *
     * This is intentionally manager-only and is used by the coverage screen. It does not infer
     * or auto-create plans merely because an assignment exists; the caller must explicitly select it.
     */
    public static function ensure_plan_for_assignment_week(string $companyuuid, int $actor, int $userid,
            string $positionuuid, string $periodstart, int $reviewerid, int $observerid = 0): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!self::can_manage($companyuuid, $actor)) { self::fail('permission'); }
        $employees = self::employees($companyuuid, $actor);
        if (!isset($employees[$userid])) { self::fail('employeeerror'); }
        self::validate_plan_people($companyuuid, $actor, $reviewerid, $observerid);
        if (!$DB->record_exists('local_kbacompany_position', ['companyuuid' => $companyuuid, 'uuid' => $positionuuid])) {
            self::fail('positionerror');
        }

        $periodstart = self::week_start($periodstart);
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $periodstart, new \DateTimeZone('UTC'));
        $startts = $start->getTimestamp();
        $endts = $start->modify('+7 days')->getTimestamp() - 1;
        $assigned = false;
        foreach ($DB->get_records('local_kbacompany_posassign', [
                'companyuuid' => $companyuuid, 'userid' => $userid, 'positionuuid' => $positionuuid,
            ], 'effectivefrom ASC, id ASC') as $assignment) {
            $from = (int)$assignment->effectivefrom;
            $to = (int)$assignment->effectiveto;
            if ($from > 0 && $from > $endts) { continue; }
            if ($to > 0 && $to < $startts) { continue; }
            $assigned = true;
            break;
        }
        if (!$assigned) { self::fail('positionerror'); }

        return self::ensure_plan_record($companyuuid, $actor, $userid, $positionuuid, $periodstart,
            $reviewerid, $observerid, '', 0, '', 0);
    }

    private static function assert_draft_access(string $companyuuid, int $actor, \stdClass $plan): bool {
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!self::can_access_plan($plan, $actor, $canmanage)) { self::fail('permission'); }
        if ($plan->status !== 'draft') { self::fail('state'); }
        if (!$canmanage && (int)$plan->userid !== $actor) { self::fail('permission'); }
        return $canmanage;
    }

    /** Change pinned template/quota revisions while the Battle Plan is still a draft. */
    public static function set_design_references(string $companyuuid, int $actor, string $planuuid,
            string $templateuuid, string $normuuid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('battleplan:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $plan = self::plan($companyuuid, $planuuid, $actor);
            self::assert_draft_access($companyuuid, $actor, $plan);
            $nextend = (new \DateTimeImmutable($nextstart, new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
            $nextstartts = strtotime($nextstart . ' 00:00:00 UTC');
            $nextendts = strtotime($nextend . ' 23:59:59 UTC');
            $assigned = false;
            foreach ($DB->get_records('local_kbacompany_posassign', [
                'companyuuid'=>$companyuuid,'userid'=>(int)$plan->userid,'positionuuid'=>(string)$plan->positionuuid,
            ]) as $assignment) {
                $from = (int)$assignment->effectivefrom; $to = (int)$assignment->effectiveto;
                if ($from > 0 && $from > $nextendts) { continue; }
                if ($to > 0 && $to < $nextstartts) { continue; }
                $assigned = true; break;
            }
            if (!$assigned) { self::fail('positionerror'); }
            $published = battle_plan_template_service::published_options($companyuuid, (string)$plan->positionuuid);
            $templaterevision = 0; $normrevision = 0;
            if ($templateuuid !== '') {
                if (!isset($published['templates'][$templateuuid])) { self::fail('reference'); }
                $templaterevision = (int)$published['templates'][$templateuuid]['revision'];
            }
            if ($normuuid !== '') {
                if (!isset($published['norms'][$normuuid])) { self::fail('reference'); }
                $normrevision = (int)$published['norms'][$normuuid]['revision'];
            }
            $plan->templateuuid = $templateuuid; $plan->templaterevision = $templaterevision;
            $plan->normuuid = $normuuid; $plan->normrevision = $normrevision;
            $plan->statepayload = ''; $plan->timemodified = time();
            $DB->update_record('local_kbacompany_bplan', $plan);
        } finally { $lock->release(); }
    }

    /** Change the read-only observer of an existing Battle Plan. */
    public static function set_observer(string $companyuuid, int $actor, string $planuuid, int $observerid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        if ($observerid > 0 && !isset($employees[$observerid])) { self::fail('observererror'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('battleplan:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $plan = self::plan($companyuuid, $planuuid, $actor);
            if (!$canmanage && (int)$plan->userid !== $actor) { self::fail('permission'); }
            if ((string)$plan->status === 'closed') { self::fail('state'); }
            $plan->observerid = $observerid;
            $plan->timemodified = time();
            $DB->update_record('local_kbacompany_bplan', $plan);
        } finally { $lock->release(); }
    }

    /** Populate a draft plan from its pinned template revision. Repeated application is idempotent. */
    public static function apply_pinned_template(string $companyuuid, int $actor, string $planuuid): int {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $plan = self::plan($companyuuid, $planuuid, $actor);
        self::assert_draft_access($companyuuid, $actor, $plan);
        if ((string)$plan->templateuuid === '' || (int)$plan->templaterevision <= 0) { self::fail('reference'); }
        $template = battle_plan_template_service::exact_template($companyuuid, (string)$plan->templateuuid, (int)$plan->templaterevision);
        if (!$template || (string)$template['position_uuid'] !== (string)$plan->positionuuid || (string)$template['status'] !== 'published') {
            self::fail('reference');
        }
        $rows = isset($template['payload']['rows']) && is_array($template['payload']['rows']) ? array_values($template['payload']['rows']) : [];
        $count = 0;
        foreach ($rows as $index => $row) {
            $taskuuid = task_service::ensure_from_battle_plan_template($companyuuid, $actor,
                (string)$plan->templateuuid, (int)$plan->templaterevision, (int)$index,
                (int)$plan->userid, (int)$plan->reviewerid, (int)$plan->observerid, (string)$plan->periodstart, $row);
            self::add_task($companyuuid, $actor, $planuuid, $taskuuid);
            $count++;
        }
        return $count;
    }

    /** Explicit, idempotent sync of active recurring occurrences into a draft week's Battle Plan. */
    public static function sync_recurring_week(string $companyuuid, int $actor, string $planuuid): int {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $plan = self::plan($companyuuid, $planuuid, $actor);
        self::assert_draft_access($companyuuid, $actor, $plan);
        $latest = [];
        foreach ($DB->get_records('local_kbacompany_taskrec',
                ['companyuuid' => $companyuuid, 'assigneeid' => (int)$plan->userid],
                'uuid ASC, revision DESC') as $row) {
            if (!isset($latest[$row->uuid])) { $latest[$row->uuid] = $row; }
        }
        $count = 0;
        $start = new \DateTimeImmutable((string)$plan->periodstart, new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable((string)$plan->periodend, new \DateTimeZone('UTC'));
        foreach ($latest as $row) {
            if ($row->status !== 'active') { continue; }
            for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
                $date = $day->format('Y-m-d');
                if (!recurring_task_service::is_due_on($row, $date)) { continue; }
                // Canonical recurrence occurrence and plan association each have their own idempotency key.
                $taskuuid = task_service::ensure_from_recurrence($companyuuid, $actor,
                    (string)$row->uuid, (int)$row->revision, $date);
                $exists = $DB->record_exists('local_kbacompany_bplanitem', [
                    'companyuuid' => $companyuuid, 'planuuid' => $planuuid,
                    'provider' => 'bms', 'externalid' => $taskuuid,
                ]);
                self::add_task($companyuuid, $actor, $planuuid, $taskuuid);
                if (!$exists) { $count++; }
            }
        }
        return $count;
    }

    public static function add_task(string $companyuuid, int $actor, string $planuuid, string $taskuuid): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $plan = self::plan($companyuuid, $planuuid, $actor);
        self::assert_draft_access($companyuuid, $actor, $plan);
        $task = task_service::readable_projection($companyuuid, $actor, $taskuuid);
        if (!$task || (int)$task['assignee_id'] !== (int)$plan->userid ||
                in_array($task['status'], ['done', 'cancelled'], true)) {
            self::fail('task');
        }
        // Older BMS plan items may identify their task only via taskuuid.
        // A repeated add must not create a second item for the same canonical task.
        if ($DB->record_exists_sql("SELECT bi.id FROM {local_kbacompany_bplanitem} bi
                WHERE bi.companyuuid=:linkcompany AND bi.planuuid=:linkplan AND bi.provider='bms'
                  AND (bi.externalid=:linkexternal OR bi.taskuuid=:linklegacy)", [
            'linkcompany'=>$companyuuid, 'linkplan'=>$planuuid,
            'linkexternal'=>$taskuuid, 'linklegacy'=>$taskuuid,
        ])) { return; }
        $DB->insert_record('local_kbacompany_bplanitem', (object)[
            'companyuuid' => $companyuuid, 'uuid' => self::uuid(), 'planuuid' => $planuuid, 'taskuuid' => $taskuuid,
            'provider' => 'bms', 'externalid' => $taskuuid,
            'stream' => $task['stream'], 'sortorder' => 0, 'timecreated' => time(),
        ]);
    }

    /** Draft Battle Plans that may receive a newly created canonical task. */
    public static function creation_plan_options(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $positioncache = [];
        $out = [];
        $conditions = "bp.companyuuid=:cpcompany AND bp.status='draft' AND EXISTS (
            SELECT u.id FROM {user} u WHERE u.id=bp.userid AND u.deleted=0 AND u.suspended=0)";
        $params = ['cpcompany'=>$companyuuid];
        if (!$canmanage) { $conditions.=' AND bp.userid=:cpowner'; $params['cpowner']=$actor; }
        foreach ($DB->get_records_sql("SELECT bp.* FROM {local_kbacompany_bplan} bp WHERE $conditions ORDER BY bp.periodstart DESC,bp.id DESC",$params,0,100) as $plan) {
            if (!isset($employees[(int)$plan->userid])) { continue; }
            $userid = (int)$plan->userid;
            if (!array_key_exists($userid, $positioncache)) {
                $positioncache[$userid] = self::current_positions($companyuuid, $actor, $userid);
            }
            $positionname = $positioncache[$userid][(string)$plan->positionuuid] ?? '';
            $periodlabel = self::period_label((string)$plan->periodstart, (string)$plan->periodend);
            $out[(string)$plan->uuid] = [
                'uuid' => (string)$plan->uuid,
                'user_id' => $userid,
                'employee_name' => (string)$employees[$userid],
                'position_name' => (string)$positionname,
                'period_start' => (string)$plan->periodstart,
                'period_end' => (string)$plan->periodend,
                'period_label' => $periodlabel,
                'label' => (string)$employees[$userid] . ($positionname !== '' ? ' · ' . $positionname : '') . ' · ' . $periodlabel,
            ];
        }
        return $out;
    }

    /** Validate a Battle Plan selection before a task is created. */
    public static function validate_creation_plan_target(string $companyuuid, int $actor, string $planuuid,
            int $assigneeid): void {
        company_context_service::context($companyuuid, $actor);
        $plan = self::plan($companyuuid, $planuuid, $actor);
        self::assert_draft_access($companyuuid, $actor, $plan);
        if ((int)$plan->userid !== $assigneeid) {
            throw new \moodle_exception('taskcenter_battleplan_assignee_mismatch', 'local_kbacompany');
        }
    }

    /** Battle Plans that may receive a newly-created manual Task Center task. */
    public static function manual_task_plan_options(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $positioncache = [];
        $out = [];
        $sql = "SELECT bp.* FROM {local_kbacompany_bplan} bp
                  WHERE bp.companyuuid = :mtcompany AND bp.status IN ('draft','active')
                    AND EXISTS (SELECT u.id FROM {user} u WHERE u.id = bp.userid AND u.deleted = 0 AND u.suspended = 0)
               ORDER BY bp.periodstart DESC, bp.id DESC";
        foreach ($DB->get_records_sql($sql, ['mtcompany'=>$companyuuid], 0, 100) as $plan) {
            $userid = (int)$plan->userid;
            if (!$canmanage && $userid !== $actor &&
                    !access_role_service::may_edit_department_plan($companyuuid, $actor, $userid)) {
                continue;
            }
            if (!isset($employees[$userid])) { continue; }
            if (!array_key_exists($userid, $positioncache)) {
                $positioncache[$userid] = self::current_positions($companyuuid, $actor, $userid);
            }
            $positionname = $positioncache[$userid][(string)$plan->positionuuid] ?? '';
            $periodlabel = self::period_label((string)$plan->periodstart, (string)$plan->periodend);
            $statuslabel = get_string('battleplan_status_' . (string)$plan->status, 'local_kbacompany');
            $out[(string)$plan->uuid] = [
                'uuid'=>(string)$plan->uuid, 'user_id'=>$userid,
                'employee_name'=>(string)$employees[$userid], 'position_name'=>(string)$positionname,
                'period_start'=>(string)$plan->periodstart, 'period_end'=>(string)$plan->periodend,
                'period_label'=>$periodlabel, 'status'=>(string)$plan->status,
                'label'=>(string)$employees[$userid] . ($positionname !== '' ? ' · ' . $positionname : '') .
                    ' · ' . $periodlabel . ' · ' . $statuslabel,
            ];
        }
        return $out;
    }

    /** Validate a draft/active Battle Plan before Task Center creates and links a manual task. */
    public static function validate_manual_task_plan_target(string $companyuuid, int $actor, string $planuuid,
            int $assigneeid): void {
        company_context_service::context($companyuuid, $actor);
        $plan = self::plan($companyuuid, $planuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!in_array((string)$plan->status, ['draft', 'active'], true)) { self::fail('state'); }
        if (!$canmanage && (int)$plan->userid !== $actor &&
                !access_role_service::may_edit_department_plan($companyuuid, $actor, (int)$plan->userid)) {
            self::fail('permission');
        }
        if ((int)$plan->userid !== $assigneeid) {
            throw new \moodle_exception('taskcenter_battleplan_assignee_mismatch', 'local_kbacompany');
        }
    }

    /** Visible Battle Plans available as a Task Center list filter. */
    public static function task_filter_plan_options(string $companyuuid, int $actor): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $positioncache = [];
        $conditions = 'bp.companyuuid=:tfcompany';
        $params = ['tfcompany'=>$companyuuid];
        if (!$canmanage) {
            $conditions .= ' AND (bp.userid=:tfowner OR bp.reviewerid=:tfreviewer OR bp.observerid=:tfobserver)';
            $params += ['tfowner'=>$actor, 'tfreviewer'=>$actor, 'tfobserver'=>$actor];
        }
        $out = [];
        foreach ($DB->get_records_sql("SELECT bp.* FROM {local_kbacompany_bplan} bp WHERE $conditions
                ORDER BY bp.periodstart DESC,bp.id DESC", $params, 0, 100) as $plan) {
            $userid = (int)$plan->userid;
            if (!isset($employees[$userid])) { continue; }
            if (!array_key_exists($userid, $positioncache)) {
                $positioncache[$userid] = self::current_positions($companyuuid, $actor, $userid);
            }
            $positionname = (string)($positioncache[$userid][(string)$plan->positionuuid] ?? '');
            $periodlabel = self::period_label((string)$plan->periodstart, (string)$plan->periodend);
            $label = (string)$employees[$userid] . ($positionname !== '' ? ' · ' . $positionname : '') . ' · ' . $periodlabel;
            $out[(string)$plan->uuid] = $label;
        }
        return $out;
    }

    /**
     * Batch-read Battle Plan membership for already-visible canonical BMS tasks.
     *
     * This is a presentation/read-model helper for Task Center lists. It never grants task or plan access:
     * callers must pass tasks they already may see, and non-managers only receive plans where they are
     * owner/reviewer/observer. The relation remains owned by local_kbacompany_bplanitem.
     *
     * @param string $companyuuid
     * @param int $actor
     * @param array $taskuuids
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function task_plan_memberships(string $companyuuid, int $actor, array $taskuuids): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $taskuuids = array_values(array_unique(array_filter(array_map(static function($value): string {
            return trim((string)$value);
        }, $taskuuids), static function(string $value): bool { return $value !== ''; })));
        if (!$taskuuids) { return []; }
        // The Task Center page is bounded, but keep this helper independently bounded as well.
        $taskuuids = array_slice($taskuuids, 0, 100);
        [$externalinsql, $externalparams] = $DB->get_in_or_equal($taskuuids, SQL_PARAMS_NAMED, 'bplist_ext');
        [$legacyinsql, $legacyparams] = $DB->get_in_or_equal($taskuuids, SQL_PARAMS_NAMED, 'bplist_task');
        $params = ['bplist_company'=>$companyuuid] + $externalparams + $legacyparams;
        $visibility = '';
        if (!self::can_manage($companyuuid, $actor)) {
            $visibility = ' AND (bp.userid=:bplist_owner OR bp.reviewerid=:bplist_reviewer OR bp.observerid=:bplist_observer)';
            $params += ['bplist_owner'=>$actor, 'bplist_reviewer'=>$actor, 'bplist_observer'=>$actor];
        }
        $sql = "SELECT i.id,i.externalid,i.taskuuid,i.stream,bp.uuid AS planuuid,bp.periodstart,bp.periodend,bp.status
                  FROM {local_kbacompany_bplanitem} i
                  JOIN {local_kbacompany_bplan} bp ON bp.companyuuid=i.companyuuid AND bp.uuid=i.planuuid
                 WHERE i.companyuuid=:bplist_company AND i.provider='bms'
                   AND (i.externalid $externalinsql OR i.taskuuid $legacyinsql)$visibility
              ORDER BY bp.periodstart DESC,bp.id DESC,i.id DESC";
        $out = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $taskuuid = trim((string)$row->externalid) !== '' ? (string)$row->externalid : (string)$row->taskuuid;
            if ($taskuuid === '') { continue; }
            $out[$taskuuid][] = [
                'uuid'=>(string)$row->planuuid,
                'period_start'=>(string)$row->periodstart,
                'period_end'=>(string)$row->periodend,
                'period_label'=>self::period_label((string)$row->periodstart, (string)$row->periodend),
                'status'=>(string)$row->status,
                'stream'=>(string)$row->stream,
            ];
        }
        return $out;
    }

    /** Return Battle Plan choices for a visible canonical task without duplicating task data. */
    public static function task_plan_context(string $companyuuid, int $actor, string $taskuuid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $task = task_service::readable_projection($companyuuid, $actor, $taskuuid);
        if (!$task) { self::fail('task'); }

        $eligible = [];
        $included = [];
        $assigneeid = (int)$task['assignee_id'];
        $employees = self::employees($companyuuid, $actor);
        $positionnames = self::current_positions($companyuuid, $actor, $assigneeid);
        $conditions='bp.companyuuid=:tpcompany AND bp.userid=:tpassignee';
        $params=['tpcompany'=>$companyuuid,'tpassignee'=>$assigneeid];
        if (!$canmanage) { $conditions.=' AND (bp.userid=:tpowner OR bp.reviewerid=:tpreviewer OR bp.observerid=:tpobserver)'; $params+=['tpowner'=>$actor,'tpreviewer'=>$actor,'tpobserver'=>$actor]; }
        foreach ($DB->get_records_sql("SELECT bp.* FROM {local_kbacompany_bplan} bp WHERE $conditions ORDER BY bp.periodstart DESC,bp.id DESC",$params) as $plan) {
            $ownername = (string)($employees[$assigneeid] ?? $assigneeid);
            $positionname = (string)($positionnames[(string)$plan->positionuuid] ?? '');
            $label = $ownername . ($positionname !== '' ? ' · ' . $positionname : '') . ' · ' .
                self::period_label((string)$plan->periodstart, (string)$plan->periodend);
            $isinplan = $DB->record_exists('local_kbacompany_bplanitem', [
                'companyuuid' => $companyuuid, 'planuuid' => (string)$plan->uuid,
                'provider' => 'bms', 'externalid' => $taskuuid,
            ]);
            $dto = [
                'uuid' => (string)$plan->uuid,
                'period_start' => (string)$plan->periodstart,
                'period_end' => (string)$plan->periodend,
                'status' => (string)$plan->status,
                'label' => $label,
            ];
            if ($isinplan) {
                $included[] = $dto;
                continue;
            }
            if ((string)$plan->status !== 'draft') { continue; }
            if (!$canmanage && (int)$plan->userid !== $actor) { continue; }
            if (in_array((string)$task['status'], ['done', 'cancelled'], true)) { continue; }
            $eligible[] = $dto;
        }
        return [
            'task_uuid' => $taskuuid,
            'assignee_id' => $assigneeid,
            'can_open_plan' => $canmanage || $assigneeid === $actor,
            'eligible' => $eligible,
            'included' => $included,
        ];
    }

    /** Confirm a methodology template as a one-off canonical task and reference it from the draft plan. */
    public static function add_from_template(string $companyuuid, int $actor, string $planuuid,
            string $templatekey, array $data): string {
        company_context_service::context($companyuuid, $actor);
        $plan = self::plan($companyuuid, $planuuid, $actor);
        self::assert_draft_access($companyuuid, $actor, $plan);
        $data = array_merge([
            'description' => '', 'priority' => 'normal', 'stream' => 'operate',
            'startdate' => (string)$plan->periodstart, 'duedate' => (string)$plan->periodend, 'planminutes' => 0,
        ], $data);
        $taskuuid = task_service::ensure_from_library_template($companyuuid, $actor, $templatekey,
            (int)$plan->userid, (int)$plan->reviewerid, (string)$plan->periodstart, array_merge($data, ['observerid' => (int)$plan->observerid]));
        self::add_task($companyuuid, $actor, $planuuid, $taskuuid);
        return $taskuuid;
    }

    /** Add a provider-owned task-like object to the weekly plan without copying it into local_kbacompany_task. */
    public static function add_external_task(string $companyuuid, int $actor, string $planuuid,
            string $provider, string $externalid): void {
        global $DB;
        if ($provider === 'bms') { self::add_task($companyuuid, $actor, $planuuid, $externalid); return; }
        company_context_service::context($companyuuid, $actor);
        $plan = self::plan($companyuuid, $planuuid, $actor);
        self::assert_draft_access($companyuuid, $actor, $plan);
        $task = task_provider_service::get_task($companyuuid, $actor, $provider, $externalid);
        if (!$task || ($provider === 'kbacorporate_impl' && empty($task['attachable'])) ||
                (int)$task['assignee_id'] !== (int)$plan->userid || in_array($task['status'], ['done', 'cancelled'], true)) {
            self::fail('task');
        }
        if ($DB->record_exists('local_kbacompany_bplanitem', [
            'companyuuid' => $companyuuid, 'planuuid' => $planuuid, 'provider' => $provider, 'externalid' => $externalid,
        ])) { return; }
        $DB->insert_record('local_kbacompany_bplanitem', (object)[
            'companyuuid' => $companyuuid, 'uuid' => self::uuid(), 'planuuid' => $planuuid,
            'taskuuid' => '', 'provider' => $provider, 'externalid' => $externalid,
            'stream' => (string)$task['stream'], 'sortorder' => 0, 'timecreated' => time(),
        ]);
    }

    public static function remove_task(string $companyuuid, int $actor, string $planuuid, string $itemuuid, string $expected = ''): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('battleplan:'.$companyuuid.':'.$planuuid,10);
        if (!$lock) { self::fail('lock'); }
        try {
            $plan=self::plan($companyuuid,$planuuid,$actor);
            self::assert_draft_access($companyuuid,$actor,$plan); self::assert_token($plan,$expected);
            $item=$DB->get_record('local_kbacompany_bplanitem',['companyuuid'=>$companyuuid,'planuuid'=>$planuuid,'uuid'=>$itemuuid]);
            if (!$item) { self::fail('reference'); }
            // Removing the link must not remove the task or a review-history row.
            $DB->delete_records('local_kbacompany_bplanitem',['companyuuid'=>$companyuuid,'planuuid'=>$planuuid,'uuid'=>$itemuuid]);
        } finally { $lock->release(); }
    }

    public static function add_from_admin_scale(string $companyuuid, int $actor, string $planuuid, string $sourceuuid): string {
        $plan = self::plan($companyuuid, $planuuid, $actor);
        self::assert_draft_access($companyuuid, $actor, $plan);
        $taskuuid = task_service::ensure_from_admin_scale($companyuuid, $actor, $sourceuuid,
            (int)$plan->userid, (string)$plan->positionuuid, (int)$plan->observerid);
        self::add_task($companyuuid, $actor, $planuuid, $taskuuid);
        return $taskuuid;
    }

    public static function add_from_position_work(string $companyuuid, int $actor, string $planuuid, string $sourceuuid): string {
        $plan = self::plan($companyuuid, $planuuid, $actor);
        self::assert_draft_access($companyuuid, $actor, $plan);
        $taskuuid = task_service::ensure_from_position_work($companyuuid, $actor, $sourceuuid,
            (int)$plan->userid, (string)$plan->positionuuid, (string)$plan->periodstart, (string)$plan->periodend, (int)$plan->observerid);
        self::add_task($companyuuid, $actor, $planuuid, $taskuuid);
        return $taskuuid;
    }

    /**
     * Create one canonical task and link it to a draft or in-progress weekly plan.
     * An active plan keeps its approval metadata; later insertions are identifiable
     * by bplanitem.timecreated > bplan.approvedat. Other attachment methods remain draft-only.
     */
    public static function create_manual_task(string $companyuuid, int $actor, string $planuuid, array $data): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('battleplan:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $plan = self::plan($companyuuid, $planuuid, $actor);
            $canmanage = self::can_manage($companyuuid, $actor);
            if (!in_array((string)$plan->status, ['draft', 'active'], true)) { self::fail('state'); }
            if (!$canmanage && (int)$plan->userid !== $actor &&
                    !access_role_service::may_edit_department_plan($companyuuid, $actor, (int)$plan->userid)) {
                self::fail('permission');
            }
            $data['assigneeid'] = (int)$plan->userid;
            if (empty($data['reviewerid'])) { $data['reviewerid'] = (int)$plan->reviewerid; }
            if (empty($data['observerid'])) { $data['observerid'] = (int)$plan->observerid; }
            $taskuuid = task_service::create($companyuuid, $actor, $data);
            if ($plan->status === 'draft') {
                // Preserve the single canonical link path and draft-only validation.
                self::add_task($companyuuid, $actor, $planuuid, $taskuuid);
            } else {
                // Exception is limited to newly created manual tasks, never imported/provider tasks.
                // This operation runs in the same transaction as the canonical task creation.
                $DB->insert_record('local_kbacompany_bplanitem', (object)[
                    'companyuuid'=>$companyuuid, 'uuid'=>self::uuid(), 'planuuid'=>$planuuid,
                    'taskuuid'=>$taskuuid, 'provider'=>'bms', 'externalid'=>$taskuuid,
                    'stream'=>(string)$data['stream'], 'sortorder'=>0, 'timecreated'=>max(time(), (int)$plan->approvedat + 1),
                ]);
            }
            $tx->allow_commit();
            return $taskuuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    public static function transition(string $companyuuid, int $actor, string $planuuid, string $action, string $expected = ''): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('battleplan:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $plan = self::plan($companyuuid, $planuuid, $actor);
            self::assert_token($plan, $expected);
            if (!self::can_access_plan($plan, $actor, $canmanage)
                    && !($action === 'approve' && access_role_service::may_approve_department_plan(
                        $companyuuid, $actor, (int)$plan->userid))) { self::fail('permission'); }
            if (in_array($action, ['submit', 'approve', 'selfapprove'], true)) {
                // Stabilization: server lifecycle enforces the same reviewer validity already
                // exposed as a hard readiness blocker. Observer remains optional.
                $employees = self::employees($companyuuid, $actor);
                if (!isset($employees[(int)$plan->reviewerid])) { self::fail('reviewererror'); }
            }
            if ($action === 'submit') {
                if ($plan->status !== 'draft' || (!$canmanage && (int)$plan->userid !== $actor)) { self::fail('state'); }
                if (!$DB->record_exists('local_kbacompany_bplanitem', ['companyuuid' => $companyuuid, 'planuuid' => $planuuid])) {
                    self::fail('empty');
                }
                $plan->status = 'submitted'; $plan->submittedat = time();
            } else if ($action === 'approve') {
                if ($plan->status !== 'submitted' || (!$canmanage && (int)$plan->reviewerid !== $actor
                        && !access_role_service::may_approve_department_plan($companyuuid, $actor, (int)$plan->userid))) { self::fail('state'); }
                $plan->status = 'active'; $plan->approvedby = $actor; $plan->approvedat = time();
            } else if ($action === 'selfapprove') {
                if ($plan->status !== 'draft' || (int)$plan->userid !== $actor || (int)$plan->reviewerid !== $actor) {
                    self::fail('state');
                }
                if (!$DB->record_exists('local_kbacompany_bplanitem', ['companyuuid' => $companyuuid, 'planuuid' => $planuuid])) {
                    self::fail('empty');
                }
                $now = time();
                $plan->status = 'active'; $plan->submittedat = $now; $plan->approvedby = $actor; $plan->approvedat = $now;
            } else if ($action === 'close') {
                if ($plan->status !== 'active' || (!$canmanage && (int)$plan->userid !== $actor)) { self::fail('state'); }
                $weekstate = self::week_workspace_payload((string)($plan->statepayload ?? ''));
                $carried = array_flip(array_map('strval', (array)($weekstate['rollover']['itemuuids'] ?? [])));
                $targetplanuuid = trim((string)($weekstate['rollover']['targetplanuuid'] ?? ''));
                $closedsnapshot = ['version' => 1, 'capturedat' => time(), 'items' => []];
                foreach ($DB->get_records('local_kbacompany_bplanitem', [
                    'companyuuid' => $companyuuid, 'planuuid' => $planuuid,
                ]) as $item) {
                    $provider = (string)($item->provider ?: 'bms');
                    $externalid = (string)($item->externalid ?: $item->taskuuid);
                    if ($provider === 'bms') {
                        $task = $DB->get_record('local_kbacompany_task', ['companyuuid' => $companyuuid, 'uuid' => $item->taskuuid]);
                        $finished = $task && in_array($task->status, ['done', 'cancelled'], true);
                    } else {
                        $task = task_provider_service::get_task($companyuuid, $actor, $provider, $externalid);
                        $finished = $task && in_array($task['status'], ['done', 'cancelled'], true);
                    }
                    $rolled = $finished ? false : (isset($carried[(string)$item->uuid]) && $targetplanuuid !== '' &&
                        $DB->record_exists('local_kbacompany_bplanitem', [
                            'companyuuid'=>$companyuuid,'planuuid'=>$targetplanuuid,
                            'provider'=>$provider,'externalid'=>$externalid,
                        ]));
                    $closedsnapshot['items'][] = [
                        'itemuuid' => (string)$item->uuid,
                        'provider' => $provider,
                        'externalid' => $externalid,
                        'status' => (string)($provider === 'bms' ? ($task->status ?? 'missing') : ($task['status'] ?? 'missing')),
                        'carried' => (bool)$rolled,
                    ];
                    if (!$finished && !$rolled) { self::fail('unfinished'); }
                }
                $payload = self::state_payload_array((string)($plan->statepayload ?? ''));
                $payload['closure_snapshot'] = $closedsnapshot;
                $plan->statepayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $plan->status = 'closed'; $plan->closedat = time();
            } else { self::fail('invalid'); }
            // Keep a durable event for every successful review transition.
            // This executes under the existing plan lock and transaction.
            if (in_array($action, ['submit', 'approve', 'selfapprove'], true)) {
                $kind = $action === 'submit' ? 'submitted' : ($action === 'approve' ? 'approved' : 'selfapproved');
                $plan->statepayload = battle_plan_review_cycle_service::append_event(
                    (string)($plan->statepayload ?? ''), $kind, $actor,
                    $action === 'submit' ? (int)$plan->submittedat : (int)$plan->approvedat);
            }
            $plan->timemodified = time();
            $DB->update_record('local_kbacompany_bplan', $plan);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Review a submitted Battle Plan. Approval activates it; correction returns it to draft. */
    public static function review(string $companyuuid, int $actor, string $planuuid, string $decision,
            string $comment = '', array $itemuuids = [], string $expected = ''): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $decision = trim($decision);
        $comment = trim($comment);
        if (!in_array($decision, ['approve', 'changes'], true)) { self::fail('reviewdecision'); }
        if ($decision === 'changes' && $comment === '') { self::fail('reviewcomment'); }
        if (\core_text::strlen($comment) > 4000) { self::fail('reviewcomment'); }

        $canmanage = self::can_manage($companyuuid, $actor);
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('battleplan:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $plan = self::plan($companyuuid, $planuuid, $actor);
            self::assert_token($plan, $expected);
            if ((string)$plan->status !== 'submitted' || (!$canmanage && (int)$plan->reviewerid !== $actor
                    && !access_role_service::may_approve_department_plan($companyuuid, $actor, (int)$plan->userid))) {
                self::fail('reviewpermission');
            }
            $employees = self::employees($companyuuid, $actor);
            if (!isset($employees[(int)$plan->reviewerid])) { self::fail('reviewererror'); }

            $targets = [];
            if ($decision === 'changes') {
                $itemuuids = array_values(array_unique(array_filter(array_map('trim', $itemuuids))));
                if (count($itemuuids) > 100) { self::fail('reviewitems'); }
                if ($itemuuids) {
                    foreach ($itemuuids as $itemuuid) {
                        $item = $DB->get_record('local_kbacompany_bplanitem', [
                            'companyuuid' => $companyuuid, 'planuuid' => $planuuid, 'uuid' => $itemuuid,
                        ]);
                        if (!$item) { self::fail('reviewitems'); }
                        $targets[] = (string)$item->uuid;
                    }
                } else {
                    $targets[] = '';
                }
            } else {
                $targets[] = '';
            }

            $now = time();
            foreach ($targets as $itemuuid) {
                $DB->insert_record('local_kbacompany_bplanrev', (object)[
                    'uuid' => self::uuid(), 'companyuuid' => $companyuuid, 'planuuid' => $planuuid,
                    'itemuuid' => $itemuuid, 'decision' => $decision === 'approve' ? 'approved' : 'changes',
                    'comment' => $comment, 'actorid' => $actor, 'timecreated' => $now,
                ]);
            }
            if ($decision === 'approve') {
                $plan->status = 'active';
                $plan->approvedby = $actor;
                $plan->approvedat = $now;
            } else {
                $plan->status = 'draft';
                $plan->submittedat = 0;
                $plan->approvedby = 0;
                $plan->approvedat = 0;
            }
            $plan->statepayload = battle_plan_review_cycle_service::append_event(
                (string)($plan->statepayload ?? ''), $decision === 'approve' ? 'approved' : 'returned',
                $actor, $now);
            $plan->timemodified = $now;
            $DB->update_record('local_kbacompany_bplan', $plan);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /** Outstanding acknowledgements on the most recent returned-review batch (advisory only). */
    public static function outstanding_review_corrections(array $data): array {
        $plan=$data['selected']??null;
        if (!$plan || (string)($plan['status']??'')!=='draft') { return ['total'=>0,'outstanding'=>0]; }
        $history=(array)($data['review_history']??[]);
        if (!$history) { return ['total'=>0,'outstanding'=>0]; }
        $last=end($history);
        if ((string)($last['decision']??'')!=='changes') { return ['total'=>0,'outstanding'=>0]; }
        $time=(int)($last['time_created']??0);
        $actor=(int)($last['actor_id']??0);
        $comment=(string)($last['comment']??'');
        $targets=[];
        foreach (array_reverse($history) as $row) {
            if ((string)($row['decision']??'')!=='changes' || (int)($row['time_created']??0)!==$time ||
                (int)($row['actor_id']??0)!==$actor || (string)($row['comment']??'')!==$comment) { continue; }
            $targets[(string)($row['item_uuid']??'')]=true;
        }
        if (!$targets) { return ['total'=>0,'outstanding'=>0]; }
        $payload=json_decode((string)($plan['state_payload']??''),true);
        $events=(array)(is_array($payload)?($payload['review_correction_events']??[]):[]);
        $batch=$time.':'.$actor.':'.$comment;
        $latest=[];
        foreach ($events as $event) {
            if (!is_array($event) || (string)($event['batch']??'')!==$batch) { continue; }
            $target=(string)($event['target']??'');
            if (isset($targets[$target])) { $latest[$target]=(string)($event['action']??''); }
        }
        $outstanding=0;
        foreach ($targets as $target=>$unused) {
            if (($latest[$target]??'')!=='accepted') { ++$outstanding; }
        }
        return ['total'=>count($targets),'outstanding'=>$outstanding];
    }

    /** Record a correction acknowledgement or review against the current returned-review batch. */
    public static function correction_action(string $companyuuid, int $actor, string $planuuid,
            string $target, string $operation, string $note, string $expected): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!in_array($operation, ['claimed', 'accepted', 'rework'], true) ||
                \core_text::strlen($note) > 1500 || ($operation === 'rework' && trim($note) === '')) {
            self::fail('invalid');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('battleplan:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $plan = self::plan($companyuuid, $planuuid, $actor);
            self::assert_token($plan, $expected);
            if ((string)$plan->status !== 'draft') { self::fail('state'); }
            $canmanage = self::can_manage($companyuuid, $actor);
            $isreviewer = $canmanage || (int)$plan->reviewerid === $actor ||
                access_role_service::may_approve_department_plan($companyuuid, $actor, (int)$plan->userid);
            $isowner = $canmanage || (int)$plan->userid === $actor ||
                access_role_service::may_edit_department_plan($companyuuid, $actor, (int)$plan->userid);
            if ($operation === 'claimed' ? !$isowner : !$isreviewer) { self::fail('permission'); }
            $reviews = $DB->get_records('local_kbacompany_bplanrev',
                ['companyuuid'=>$companyuuid, 'planuuid'=>$planuuid], 'timecreated DESC, id DESC');
            $batch = null; $valid = [];
            foreach ($reviews as $review) {
                if ((string)$review->decision !== 'changes') { break; }
                $key = (int)$review->timecreated . ':' . (int)$review->actorid . ':' . (string)$review->comment;
                if ($batch === null) { $batch = $key; }
                if ($batch !== $key) { break; }
                $valid[(string)$review->itemuuid] = true;
            }
            if ($batch === null || !array_key_exists($target, $valid)) { self::fail('reference'); }
            $payload = self::state_payload_array((string)($plan->statepayload ?? ''));
            $events = (array)($payload['review_correction_events'] ?? []);
            $current = null;
            for ($i=count($events)-1; $i>=0; --$i) {
                $event=$events[$i];
                if (($event['batch']??'') === $batch && ($event['target']??'') === $target) {
                    $current=(string)($event['action']??''); break;
                }
            }
            if (($operation === 'claimed' && $current === 'claimed') ||
                (in_array($operation, ['accepted', 'rework'], true) && $current !== 'claimed')) {
                self::fail('state');
            }
            if (count($events) >= 250) { self::fail('invalid'); }
            $events[] = ['batch'=>$batch, 'target'=>$target, 'action'=>$operation,
                'note'=>trim($note), 'actor'=>$actor, 'time'=>time()];
            $payload['review_correction_events'] = $events;
            $plan->statepayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $plan->timemodified = time();
            $DB->update_record('local_kbacompany_bplan', $plan);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Append-only review history for one Battle Plan. */
    public static function review_history(string $companyuuid, int $actor, string $planuuid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $plan = self::plan($companyuuid, $planuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        if (!self::can_access_plan($plan, $actor, $canmanage)) { self::fail('permission'); }
        $rows = [];
        foreach ($DB->get_records('local_kbacompany_bplanrev', [
            'companyuuid' => $companyuuid, 'planuuid' => $planuuid,
        ], 'timecreated ASC, id ASC') as $row) {
            $actorname = (string)($DB->get_field('user', 'firstname', ['id' => (int)$row->actorid]) ?: '');
            $lastname = (string)($DB->get_field('user', 'lastname', ['id' => (int)$row->actorid]) ?: '');
            $actorname = trim($actorname . ' ' . $lastname);
            $targetlabel = '';
            if ((string)$row->itemuuid !== '') {
                $item = $DB->get_record('local_kbacompany_bplanitem', [
                    'companyuuid'=>$companyuuid, 'planuuid'=>$planuuid, 'uuid'=>(string)$row->itemuuid,
                ]);
                if ($item) {
                    if ((string)$item->provider === 'bms') {
                        $task = task_service::readable_projection($companyuuid, $actor, (string)$item->taskuuid);
                        $targetlabel = $task ? $task['title'] : get_string('bpu_unavailable','local_kbacompany');
                    } else {
                        $task = task_provider_service::get_task($companyuuid, $actor, (string)$item->provider, (string)$item->externalid);
                        $targetlabel = $task ? $task['title'] : get_string('bpu_unavailable','local_kbacompany');
                    }
                }
            }
            $rows[] = [
                'uuid' => (string)$row->uuid,
                'decision' => (string)$row->decision,
                'item_uuid' => (string)$row->itemuuid,
                'target_label' => $targetlabel,
                'comment' => (string)$row->comment,
                'actor_id' => (int)$row->actorid,
                'actor_name' => $actorname,
                'time_created' => (int)$row->timecreated,
            ];
        }
        return $rows;
    }

    private static function latest_published(string $table, string $companyuuid, array $extra = [],
            string $extraquery = '', array $extraparams = []): array {
        global $DB;
        // All callers use literal internal table/column names.
        $where = 'a.companyuuid = :sourcecompany AND a.status = :sourcestatus';
        $params = ['sourcecompany'=>$companyuuid, 'sourcestatus'=>'published'];
        foreach ($extra as $field=>$value) { $where .= " AND a.$field = :src_$field"; $params['src_'.$field] = $value; }
        if ($extraquery !== '') { $where .= ' AND '.$extraquery; $params += $extraparams; }
        return $DB->get_records_sql("SELECT a.* FROM {{$table}} a WHERE $where
            AND NOT EXISTS (SELECT newer.id FROM {{$table}} newer
                WHERE newer.companyuuid = a.companyuuid AND newer.uuid = a.uuid AND newer.revision > a.revision)
            ORDER BY a.uuid", $params);
    }

    private static function metrics_for_position(string $companyuuid, string $positionuuid): array {
        global $DB;
        $products = [];
        foreach (self::latest_published('local_kbacompany_function', $companyuuid, ['positionuuid' => $positionuuid]) as $function) {
            if ($function->productuuid !== '') { $products[$function->productuuid] = true; }
        }
        $metrics = [];
        foreach (array_keys($products) as $productuuid) {
            foreach ($DB->get_records('local_kbacompany_stat', [
                'companyuuid' => $companyuuid, 'productuuid' => $productuuid,
            ], 'code ASC') as $stat) {
                $metrics[$stat->uuid] = ['uuid' => (string)$stat->uuid, 'code' => (string)$stat->code,
                    'name' => (string)$stat->name, 'unit' => (string)$stat->unit,
                    'period_type' => (string)$stat->periodtype, 'direction' => (string)$stat->direction];
            }
        }
        return array_values($metrics);
    }

    public static function context(string $companyuuid, int $actor, string $selectedplan = '', int $targetuser = 0,
            string $week = '', string $positionuuid = '', array $options = []): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actor);
        $canmanage = self::can_manage($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $requested = $selectedplan !== '' ? self::plan($companyuuid, $selectedplan, $actor) : null;
        if ($requested) { $targetuser = (int)$requested->userid; }
        if ($targetuser <= 0) {
            // Employees open their own Battle Plan by default. A company manager/administrator may
            // legitimately manage the company without being an employee in its org model, so for
            // that case select the first available employee instead of failing the whole page.
            if (isset($employees[$actor])) {
                $targetuser = $actor;
            } else if ($canmanage && $employees) {
                $targetuser = (int)array_key_first($employees);
            } else {
                $targetuser = $actor;
            }
        }
        if (!$requested && !$canmanage && $targetuser !== $actor && !in_array($targetuser,
                access_role_service::department_view_userids($companyuuid, $actor, 'battle_plan'), true)) {
            self::fail('permission');
        }
        if (!$requested && !isset($employees[$targetuser])) {
            // Backward-compatible fallback for old links/controllers that explicitly supplied the
            // current manager's Moodle user id as the default target. Do not silently rewrite a
            // genuinely requested foreign/invalid employee id.
            if ($canmanage && $targetuser === $actor && $employees) {
                $targetuser = (int)array_key_first($employees);
            } else {
                self::fail('employeeerror');
            }
        }
        $week = $week !== '' ? self::week_start($week) : self::week_start(gmdate('Y-m-d'));
        $positions = self::current_positions($companyuuid, $actor, $targetuser);
        // A manager can change the employee in the GET context selector while a position from the
        // previous employee is still present in the query string. Treat that stale selector value as
        // UI context and fall back to the employee's first current position. create_plan() remains strict.
        if ($positionuuid !== '' && !isset($positions[$positionuuid])) { $positionuuid = ''; }
        if ($positionuuid === '' && $positions) { $positionuuid = (string)array_key_first($positions); }

        $plans = [];
        if ($requested && empty($options['load_plans'])) {
            $plans[$requested->uuid] = $requested;
        } else {
            list($where, $params) = self::access_sql($companyuuid, $actor);
            foreach ($DB->get_records_sql("SELECT p.* FROM {local_kbacompany_bplan} p WHERE $where ORDER BY p.periodstart DESC, p.id DESC", $params) as $row) {
                $plans[$row->uuid] = $row;
            }
        }
        if ($selectedplan === '' && $positionuuid !== '') {
            foreach ($plans as $plan) {
                if ((int)$plan->userid === $targetuser && $plan->positionuuid === $positionuuid && $plan->periodstart === $week) {
                    $selectedplan = (string)$plan->uuid; break;
                }
            }
        }
        $selected = null; $items = []; $by_stream = array_fill_keys(task_service::STREAMS, []);
        $availabletasks = []; $adminsources = []; $worksources = []; $metrics = []; $overallcondition = ['performance'=>null,'condition'=>''];
        $templateoptions = []; $normoptions = []; $positionindicators = [];
        if ($positionuuid !== '') {
            $publishedoptions = battle_plan_template_service::published_options($companyuuid, $positionuuid);
            $templateoptions = $publishedoptions['templates']; $normoptions = $publishedoptions['norms'];
        }
        if ($selectedplan !== '') {
            if (!isset($plans[$selectedplan])) { self::fail('reference'); }
            $selected = $plans[$selectedplan];
            $targetuser = (int)$selected->userid; $positionuuid = (string)$selected->positionuuid; $week = (string)$selected->periodstart;
            $positions = self::current_positions($companyuuid, $actor, $targetuser);
            if (!isset($positions[$positionuuid])) {
                $positions[$positionuuid] = (string)($DB->get_field('local_kbacompany_position', 'name',
                    ['companyuuid'=>$companyuuid,'uuid'=>$positionuuid]) ?: get_string('bpu_unavailable','local_kbacompany'));
            }

            $taskids = [];
            foreach ($DB->get_records('local_kbacompany_bplanitem', [
                'companyuuid' => $companyuuid, 'planuuid' => $selectedplan,
            ], 'stream ASC, sortorder ASC, id ASC') as $item) {
                $provider = (string)($item->provider ?: 'bms');
                $externalid = (string)($item->externalid ?: $item->taskuuid);
                if ($provider === 'bms') {
                    $task = task_service::readable_projection($companyuuid, $actor, (string)$item->taskuuid);
                    if ($task) { $task['provider'] = 'bms'; $task['external_id'] = (string)$item->taskuuid;
                        $task['source_url'] = (new \moodle_url('/local/kbacompany/tasks.php', ['uuid' => $companyuuid,
                            'task' => $item->taskuuid]))->out(false); }
                } else {
                    $task = task_provider_service::get_task($companyuuid, $actor, $provider, $externalid);
                }
                if (!$task) { continue; }
                $dto = ['uuid' => (string)$item->uuid, 'task_uuid' => (string)$item->taskuuid,
                    'provider' => $provider, 'external_id' => $externalid,
                    'stream' => (string)$item->stream, 'sort_order' => (int)$item->sortorder,
                    'time_created'=>(int)$item->timecreated, 'task' => $task];
                $items[] = $dto; $by_stream[$item->stream][] = $dto; $taskids[$provider . ':' . $externalid] = true;
            }
            if (!isset($options['load_sources']) || $options['load_sources']) {
                $availabletasks = task_service::battle_plan_candidates($companyuuid, $actor, (int)$selected->userid,
                    $selectedplan, (string)($options['task_search'] ?? ''), 50);
                // Existing non-BMS providers retain their own company/learning permission contracts.
                foreach (task_provider_service::providers() as $key=>$provider) {
                    if ($key === 'bms' || !$provider->available()) { continue; }
                    foreach ($provider->list_tasks($companyuuid, $actor, $targetuser, 'my') as $task) {
                        $ref = $task['provider'].':'.$task['external_id'];
                        if ((int)$task['assignee_id'] === $targetuser && !isset($taskids[$ref]) &&
                                !in_array($task['status'], ['done','cancelled'], true) &&
                                ($key !== 'kbacorporate_impl' || !empty($task['attachable']))) { $availabletasks[] = $task; }
                    }
                }
                $sourcewhere = "(ownerpositionuuid = :sourceposition OR ownerpositionuuid = '')";
                foreach (self::latest_published('local_kbacompany_adminitem', $companyuuid, ['kind'=>'task'],
                        $sourcewhere, ['sourceposition'=>$positionuuid]) as $source) {
                    $adminsources[] = ['uuid'=>(string)$source->uuid,'revision'=>(int)$source->revision,
                        'code'=>(string)$source->code,'name'=>(string)$source->name,
                        'expected_result'=>(string)$source->expectedresult,'due_date'=>(string)$source->duedate];
                }
                foreach (self::latest_published('local_kbacompany_poswork', $companyuuid, ['positionuuid'=>$positionuuid]) as $source) {
                    $worksources[] = ['uuid'=>(string)$source->uuid,'revision'=>(int)$source->revision,
                        'action'=>(string)$source->action,'product'=>(string)$source->product,
                        'frequency'=>(string)$source->frequency,'schedule'=>(string)$source->schedule];
                }
            }
            if ((string)$selected->normuuid !== '' && (int)$selected->normrevision > 0) {
                $metrics = battle_plan_template_service::plan_metrics($companyuuid, $selected);
                $norm = battle_plan_template_service::exact_norm($companyuuid, (string)$selected->normuuid, (int)$selected->normrevision);
                $overallcondition = battle_plan_template_service::overall_condition($metrics,
                    $norm ? (array)($norm['payload']['thresholds'] ?? []) : []);
            } else {
                $metrics = self::metrics_for_position($companyuuid, $positionuuid);
            }
            $publishedoptions = battle_plan_template_service::published_options($companyuuid, $positionuuid);
            $templateoptions = $publishedoptions['templates']; $normoptions = $publishedoptions['norms'];
            $positionindicators = battle_plan_indicator_service::projection($companyuuid, $actor, $positionuuid,
                $week, (string)$selected->uuid);
        }
        // Position Coverage 03: read-only manager view for the already-authorized plan.
        // Do NOT change selected_can_edit, plan owner, reviewer, metric rights or task ACL.
        $coveragewindow = [];
        if ($selected && position_coverage_service::can_manage($companyuuid, $actor)) {
            $coveragewindow = position_coverage_service::battle_plan_window($companyuuid, $actor,
                (string)$selected->positionuuid, (int)$selected->userid,
                (string)$selected->periodstart, (string)$selected->periodend);
        }
        $summary = ['tasks' => count($items), 'done' => 0, 'plan_minutes' => 0, 'fact_minutes' => 0];
        foreach ($items as $item) {
            if ($item['task']['status'] === 'done') { $summary['done']++; }
            $summary['plan_minutes'] += (int)$item['task']['plan_minutes'];
            $summary['fact_minutes'] += (int)$item['task']['fact_minutes'];
        }
        $weekworkspace = ['goal'=>'','outcome'=>'','rollover'=>[]];
        $weekmeetings = ['count'=>0,'minutes'=>0,'items'=>[],'details_visible'=>false];
        $rolloveritems = [];
        $rollovertarget = null;
        $nextplan = null;
        $metricstatuscounts = ['ok'=>0,'risk'=>0,'critical'=>0,'nodata'=>0];
        $previousweekcomparison = null;
        $performancehistory = [];
        if ($selected) {
            $weekworkspace = self::week_workspace_payload((string)($selected->statepayload ?? ''));
            $weekmeetings = self::week_meeting_projection($companyuuid, $actor, (int)$selected->userid,
                (string)$selected->periodstart, (string)$selected->periodend, $canmanage);
            $rolloveritems = self::open_rollover_items($companyuuid, $actor, $items);
            // Always project the next weekly Battle Plan independently from rollover state.
            // This keeps navigation/creation UX available even when no task has been carried forward yet.
            $nextstart = (new \DateTimeImmutable((string)$selected->periodstart, new \DateTimeZone('UTC')))
                ->modify('+7 days')->format('Y-m-d');
            $nextrow = $DB->get_record('local_kbacompany_bplan', [
                'companyuuid'=>$companyuuid,
                'userid'=>(int)$selected->userid,
                'positionuuid'=>(string)$selected->positionuuid,
                'periodstart'=>$nextstart,
            ]);
            if ($nextrow && self::can_access_plan($nextrow, $actor, $canmanage)) {
                $nextplan = [
                    'uuid'=>(string)$nextrow->uuid,
                    'status'=>(string)$nextrow->status,
                    'period_start'=>(string)$nextrow->periodstart,
                    'period_end'=>(string)$nextrow->periodend,
                ];
            }

            $targetuuid = trim((string)($weekworkspace['rollover']['targetplanuuid'] ?? ''));
            if ($targetuuid !== '') {
                $target = $DB->get_record('local_kbacompany_bplan', ['companyuuid'=>$companyuuid,'uuid'=>$targetuuid]);
                if ($target) {
                    $rollovertarget = [
                        'uuid'=>(string)$target->uuid,'status'=>(string)$target->status,
                        'period_start'=>(string)$target->periodstart,'period_end'=>(string)$target->periodend,
                    ];
                }
            }
            foreach ($positionindicators as $indicator) {
                $status = (string)($indicator['performance']['status'] ?? 'nodata');
                if (!isset($metricstatuscounts[$status])) { $status = 'nodata'; }
                $metricstatuscounts[$status]++;
            }

            // Read-only comparison with the immediately preceding Battle Plan for the same employee/position.
            // Reuse canonical task projections and indicator projections; do not create a parallel analytics model.
            $previousstart = (new \DateTimeImmutable((string)$selected->periodstart, new \DateTimeZone('UTC')))
                ->modify('-7 days')->format('Y-m-d');
            $previousrow = $DB->get_record('local_kbacompany_bplan', [
                'companyuuid'=>$companyuuid,
                'userid'=>(int)$selected->userid,
                'positionuuid'=>(string)$selected->positionuuid,
                'periodstart'=>$previousstart,
            ]);
            if ($previousrow && self::can_access_plan($previousrow, $actor, $canmanage)) {
                $previoustasks = ['tasks'=>0,'done'=>0,'open'=>0];
                foreach ($DB->get_records('local_kbacompany_bplanitem', [
                    'companyuuid'=>$companyuuid,'planuuid'=>(string)$previousrow->uuid,
                ], 'stream ASC, sortorder ASC, id ASC') as $previousitem) {
                    $provider = (string)($previousitem->provider ?: 'bms');
                    $externalid = (string)($previousitem->externalid ?: $previousitem->taskuuid);
                    if ($provider === 'bms') {
                        $task = task_service::readable_projection($companyuuid, $actor, (string)$previousitem->taskuuid);
                    } else {
                        $task = task_provider_service::get_task($companyuuid, $actor, $provider, $externalid);
                    }
                    if (!$task) { continue; }
                    $previoustasks['tasks']++;
                    if ((string)$task['status'] === 'done') { $previoustasks['done']++; }
                    if (!in_array((string)$task['status'], ['done','cancelled'], true)) { $previoustasks['open']++; }
                }
                $previousmetriccounts = ['ok'=>0,'risk'=>0,'critical'=>0,'nodata'=>0];
                foreach (battle_plan_indicator_service::projection($companyuuid, $actor, (string)$selected->positionuuid,
                        $previousstart, (string)$previousrow->uuid) as $indicator) {
                    $status = (string)($indicator['performance']['status'] ?? 'nodata');
                    if (!isset($previousmetriccounts[$status])) { $status = 'nodata'; }
                    $previousmetriccounts[$status]++;
                }
                $previousweekcomparison = [
                    'uuid'=>(string)$previousrow->uuid,
                    'status'=>(string)$previousrow->status,
                    'period_start'=>(string)$previousrow->periodstart,
                    'period_end'=>(string)$previousrow->periodend,
                    'tasks'=>$previoustasks,
                    'metric_status_counts'=>$previousmetriccounts,
                ];
            }

            // Bounded 8-week management-cycle trend for the same employee/position.
            // Historical values are derived from the same canonical task/indicator projections as the current week.
            $historyrows = $DB->get_records_select('local_kbacompany_bplan',
                'companyuuid = :hcompany AND userid = :huser AND positionuuid = :hposition AND periodstart < :hcurrent',
                ['hcompany'=>$companyuuid,'huser'=>(int)$selected->userid,'hposition'=>(string)$selected->positionuuid,
                    'hcurrent'=>(string)$selected->periodstart],
                'periodstart DESC', '*', 0, 7);
            $historyrows = array_reverse(array_values($historyrows));
            foreach ($historyrows as $historyrow) {
                if (!self::can_access_plan($historyrow, $actor, $canmanage)) { continue; }
                $htotal=0; $hdone=0;
                foreach ($DB->get_records('local_kbacompany_bplanitem', [
                    'companyuuid'=>$companyuuid,'planuuid'=>(string)$historyrow->uuid,
                ], 'stream ASC, sortorder ASC, id ASC') as $historyitem) {
                    $provider=(string)($historyitem->provider ?: 'bms');
                    $externalid=(string)($historyitem->externalid ?: $historyitem->taskuuid);
                    $task=$provider==='bms'
                        ? task_service::readable_projection($companyuuid,$actor,(string)$historyitem->taskuuid)
                        : task_provider_service::get_task($companyuuid,$actor,$provider,$externalid);
                    if (!$task) { continue; }
                    $htotal++;
                    if ((string)$task['status']==='done') { $hdone++; }
                }
                $hmetrics=['ok'=>0,'risk'=>0,'critical'=>0,'nodata'=>0];
                foreach (battle_plan_indicator_service::projection($companyuuid,$actor,(string)$selected->positionuuid,
                        (string)$historyrow->periodstart,(string)$historyrow->uuid) as $indicator) {
                    $status=(string)($indicator['performance']['status']??'nodata');
                    if (!isset($hmetrics[$status])) { $status='nodata'; }
                    $hmetrics[$status]++;
                }
                $hevaluated=(int)$hmetrics['ok']+(int)$hmetrics['risk']+(int)$hmetrics['critical'];
                $htotalmetrics=$hevaluated+(int)$hmetrics['nodata'];
                $performancehistory[]=[
                    'uuid'=>(string)$historyrow->uuid,
                    'period_start'=>(string)$historyrow->periodstart,
                    'period_end'=>(string)$historyrow->periodend,
                    'productivity'=>$htotal>0 ? (int)round(($hdone/$htotal)*100) : null,
                    'effectiveness'=>$hevaluated>0 ? (int)round(((int)$hmetrics['ok']/$hevaluated)*100) : null,
                    'coverage'=>$htotalmetrics>0 ? (int)round(($hevaluated/$htotalmetrics)*100) : null,
                ];
            }
            $cevaluated=(int)$metricstatuscounts['ok']+(int)$metricstatuscounts['risk']+(int)$metricstatuscounts['critical'];
            $ctotalmetrics=$cevaluated+(int)$metricstatuscounts['nodata'];
            $performancehistory[]=[
                'uuid'=>(string)$selected->uuid,
                'period_start'=>(string)$selected->periodstart,
                'period_end'=>(string)$selected->periodend,
                'productivity'=>(int)$summary['tasks']>0 ? (int)round(((int)$summary['done']/(int)$summary['tasks'])*100) : null,
                'effectiveness'=>$cevaluated>0 ? (int)round(((int)$metricstatuscounts['ok']/$cevaluated)*100) : null,
                'coverage'=>$ctotalmetrics>0 ? (int)round(($cevaluated/$ctotalmetrics)*100) : null,
            ];
        }
        $loadsummary = [
            'task_minutes'=>(int)$summary['plan_minutes'],
            'meeting_minutes'=>(int)$weekmeetings['minutes'],
            'total_minutes'=>(int)$summary['plan_minutes'] + (int)$weekmeetings['minutes'],
        ];
        // Company-scoped workload: canonical tasks only, counted once per UUID even when referenced
        // in several plans. Calendar meeting duration is added separately for the selected owner.
        $capacity = self::weekly_capacity_projection($companyuuid, $actor, $targetuser,
            $week, self::week_end($week), $canmanage ? array_keys($employees) : [$targetuser]);
        $capacity['owner_meeting_minutes'] = (int)$weekmeetings['minutes'];
        $capacity['owner_total_minutes'] = (int)$capacity['owner_task_minutes'] + (int)$weekmeetings['minutes'];
        $capacity['owner_remaining_minutes'] = (int)$capacity['owner_capacity_minutes'] - (int)$capacity['owner_total_minutes'];
        $capacity['owner_utilisation'] = round(100 * $capacity['owner_total_minutes'] / max(1, (int)$capacity['owner_capacity_minutes']));
        $ownerpositionoptions = [];
        if ($selected && $canmanage && $selected->status === 'draft' && count($items) === 0) {
            foreach ($employees as $employeeid => $employeename) {
                foreach (self::current_positions($companyuuid, $actor, (int)$employeeid) as $puuid => $pname) {
                    $ownerpositionoptions[(int)$employeeid . '::' . (string)$puuid] = $employeename . ' · ' . $pname;
                }
            }
            $currentkey = (int)$selected->userid . '::' . (string)$selected->positionuuid;
            if (!isset($ownerpositionoptions[$currentkey])) {
                $ownerpositionoptions[$currentkey] = ($employees[(int)$selected->userid] ?? (string)$selected->userid) . ' · ' .
                    ($positions[(string)$selected->positionuuid] ?? (string)$selected->positionuuid);
            }
        }
        return ['company' => $company, 'can_manage' => $canmanage, 'employees' => $employees,
            'target_user' => $targetuser, 'positions' => $positions, 'position_uuid' => $positionuuid,
            'owner_position_options' => $ownerpositionoptions,
            'week_start' => $week, 'week_end' => self::week_end($week),
            'plans' => array_values($plans), 'selected' => $selected ? [
                'uuid' => (string)$selected->uuid, 'user_id' => (int)$selected->userid,
                'position_uuid' => (string)$selected->positionuuid, 'period_start' => (string)$selected->periodstart,
                'period_end' => (string)$selected->periodend, 'status' => (string)$selected->status,
                'reviewer_id' => (int)$selected->reviewerid, 'observer_id' => (int)$selected->observerid,
                'template_uuid' => (string)$selected->templateuuid, 'template_revision' => (int)$selected->templaterevision,
                'norm_uuid' => (string)$selected->normuuid, 'norm_revision' => (int)$selected->normrevision,
                'state_payload' => (string)($selected->statepayload ?? ''), 'created_by' => (int)$selected->createdby,
                'time_created'=>(int)$selected->timecreated, 'time_modified'=>(int)$selected->timemodified,
                'edit_token'=>self::edit_token($selected), 'approved_by' => (int)$selected->approvedby, 'submitted_at' => (int)$selected->submittedat,
                'approved_at' => (int)$selected->approvedat, 'closed_at' => (int)$selected->closedat,
            ] : null,
            'reference_count' => $selected ? $DB->count_records('local_kbacompany_bplanitem', ['companyuuid'=>$companyuuid,'planuuid'=>$selectedplan]) : 0,
            'items' => $items, 'by_stream' => $by_stream, 'available_tasks' => $availabletasks,
            'administrative_tasks' => $adminsources, 'position_work' => $worksources,
            'position_indicators' => $positionindicators, 'coverage_window' => $coveragewindow, 'metrics' => $metrics, 'summary' => $summary, 'overall_condition' => $overallcondition,
            'week_workspace' => $weekworkspace, 'week_meetings' => $weekmeetings, 'load_summary' => $loadsummary, 'capacity_summary' => $capacity,
            'rollover_items' => $rolloveritems, 'rollover_target' => $rollovertarget, 'next_plan' => $nextplan,
            'metric_status_counts' => $metricstatuscounts, 'previous_week_comparison' => $previousweekcomparison,
            'performance_history' => $performancehistory,
            'template_options' => $templateoptions, 'norm_options' => $normoptions,
            'review_history' => $selected ? self::review_history($companyuuid, $actor, (string)$selected->uuid) : [],
            'review_cycle_history' => $selected ? battle_plan_review_cycle_service::project(
                (string)($selected->statepayload ?? ''), $employees) : ['rows'=>[], 'cycles'=>0, 'timed'=>0, 'average_seconds'=>null],
            'selected_can_edit' => $selected ? ($canmanage || (int)$selected->userid === $actor ||
                access_role_service::may_edit_department_plan($companyuuid, $actor, (int)$selected->userid)) : false];
    }
    /** Permission-scoped Battle Plan periods for the BMS Calendar. */
    public static function calendar_projection(string $companyuuid, int $actor, string $fromdate, string $todate): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$fromdate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$todate)) {
            throw new \moodle_exception('invalidparameter');
        }
        list($where,$params)=self::access_sql($companyuuid,$actor);
        $where .= ' AND p.periodstart BETWEEN :calfromdate AND :caltodate';
        $params += ['calfromdate'=>$fromdate,'caltodate'=>$todate];
        $sql = "SELECT p.uuid,p.userid,p.positionuuid,p.periodstart,p.periodend,p.status,pos.name AS positionname,
                       u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename
                  FROM {local_kbacompany_bplan} p
             LEFT JOIN {local_kbacompany_position} pos ON pos.companyuuid=p.companyuuid AND pos.uuid=p.positionuuid
             LEFT JOIN {user} u ON u.id=p.userid
                 WHERE $where ORDER BY p.periodstart,p.id";
        $rows=[];
        foreach($DB->get_records_sql($sql,$params) as $row){
            $rows[]=[
                'uuid'=>(string)$row->uuid,'user_id'=>(int)$row->userid,'employee'=>$row->userid ? fullname($row) : '',
                'position'=>(string)$row->positionname,'period_start'=>(string)$row->periodstart,
                'period_end'=>(string)$row->periodend,'status'=>(string)$row->status,
            ];
        }
        return $rows;
    }

    /** Read-only BMS list. Filters and permissions are applied before pagination. */
    public static function listing(string $companyuuid, int $actor, array $filters = [], int $page = 0): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);

        // Build the employee filter from plans the actor may actually access. This keeps the
        // manager workspace useful without widening Battle Plan visibility or leaking foreign plans.
        list($employeewhere, $employeeparams) = self::access_sql($companyuuid, $actor);
        $employeeoptions = [];
        $directory = self::employees($companyuuid, $actor);
        foreach ($DB->get_records_sql("SELECT DISTINCT p.userid FROM {local_kbacompany_bplan} p WHERE $employeewhere ORDER BY p.userid",
                $employeeparams) as $ownerrow) {
            $ownerid = (int)$ownerrow->userid;
            if (isset($directory[$ownerid])) { $employeeoptions[$ownerid] = $directory[$ownerid]; }
        }

        list($where, $params) = self::access_sql($companyuuid, $actor);
        $scope = $filters['scope'] ?? 'all';
        if (!in_array($scope, ['all','my','review','watching'], true)) { self::fail('reference'); }
        if ($scope === 'my') { $where .= ' AND p.userid = :scopeactor'; $params['scopeactor'] = $actor; }
        if ($scope === 'watching') { $where .= ' AND p.observerid = :scopeactor'; $params['scopeactor'] = $actor; }
        if ($scope === 'review') { $where .= " AND p.reviewerid = :scopeactor AND p.status = 'submitted'"; $params['scopeactor'] = $actor; }
        if (!empty($filters['status']) && !in_array($filters['status'], self::STATES, true)) {
            self::fail('state');
        }
        if (!empty($filters['user'])) { $where .= ' AND p.userid = :filteruser'; $params['filteruser'] = (int)$filters['user']; }
        if (!empty($filters['week'])) { $where .= ' AND p.periodstart = :filterweek'; $params['filterweek'] = self::week_start($filters['week']); }
        if (!empty($filters['q'])) {
            // Full names and multi-word positions: every bounded term may match either name or position.
            $terms = preg_split('/\s+/u', trim(\core_text::substr((string)$filters['q'],0,200)), 5);
            foreach ($terms as $n=>$term) {
                $pattern = '%'.$DB->sql_like_escape($term).'%';
                $where .= ' AND ('.$DB->sql_like('u.firstname', ':qfirst'.$n, false).' OR '.$DB->sql_like('u.lastname', ':qlast'.$n, false).
                    ' OR '.$DB->sql_like('pos.name', ':qposition'.$n, false).')';
                $params += ['qfirst'.$n=>$pattern,'qlast'.$n=>$pattern,'qposition'.$n=>$pattern];
            }
        }
        $from = " FROM {local_kbacompany_bplan} p
                  LEFT JOIN {user} u ON u.id = p.userid
                  LEFT JOIN {user} r ON r.id = p.reviewerid
                  LEFT JOIN {local_kbacompany_position} pos ON pos.companyuuid = p.companyuuid AND pos.uuid = p.positionuuid";
        // Count all status groups for the actor-authorized scope and other filters, BEFORE
        // applying the selected status. Counts are not restricted to one paginated result page.
        // In 'review' scope the existing submitted-only rule intentionally still applies.
        $statuscounts = array_fill_keys(self::STATES, 0);
        $statusrows = $DB->get_records_sql('SELECT p.status, COUNT(p.id) AS total'.$from.
            ' WHERE '.$where.' GROUP BY p.status', $params);
        foreach ($statusrows as $statusrow) {
            if (array_key_exists((string)$statusrow->status, $statuscounts)) {
                $statuscounts[(string)$statusrow->status] = (int)$statusrow->total;
            }
        }
        $statuscounts['all'] = array_sum($statuscounts);
        if (!empty($filters['status'])) {
            $where .= ' AND p.status = :filterstate';
            $params['filterstate'] = $filters['status'];
        }
        $total = (int)$DB->count_records_sql('SELECT COUNT(p.id)'.$from.' WHERE '.$where, $params);
        $pagesize = 20; $page = max(0, min($page, max(0, (int)ceil($total/$pagesize)-1)));
        $sql = 'SELECT p.*, u.firstname, u.lastname, r.firstname AS rfirstname, r.lastname AS rlastname, pos.name AS positionname'.$from.
            ' WHERE '.$where.' ORDER BY p.periodstart DESC, p.id DESC';
        $rows = [];
        foreach ($DB->get_records_sql($sql, $params, $page*$pagesize, $pagesize) as $row) {
            $rows[] = ['uuid'=>(string)$row->uuid,'employee'=>trim($row->firstname.' '.$row->lastname),
                'reviewer'=>trim($row->rfirstname.' '.$row->rlastname),'position'=>(string)$row->positionname,
                'period_start'=>(string)$row->periodstart,'period_end'=>(string)$row->periodend,
                'status'=>(string)$row->status,'updated'=>(int)$row->timemodified];
        }
        return ['rows'=>$rows,'total'=>$total,'status_counts'=>$statuscounts,'page'=>$page,'pagesize'=>$pagesize,
            'can_manage'=>self::can_manage($companyuuid, $actor), 'employee_options'=>$employeeoptions];
    }

    /** A separate create form never chooses an unrelated first employee on behalf of a manager. */
    /** Defaults for preparing the immediately following weekly plan from an accessible active/closed plan.
     *
     * Only reusable plan settings are inherited. Canonical tasks, metric facts, week goal/outcome and review
     * history remain attached to the source week and are never copied by this helper.
     */
    public static function next_plan_creation_defaults(string $companyuuid, int $actor, string $sourceuuid,
            int $targetuser, string $positionuuid, string $periodstart): array {
        company_context_service::context($companyuuid, $actor);
        $source = self::plan($companyuuid, $sourceuuid, $actor);
        if (!in_array((string)$source->status, ['active','closed'], true)) { return []; }
        if ((int)$source->userid !== $targetuser || (string)$source->positionuuid !== $positionuuid) { return []; }

        $expected = (new \DateTimeImmutable((string)$source->periodstart, new \DateTimeZone('UTC')))
            ->modify('+7 days')->format('Y-m-d');
        if (self::week_start($periodstart) !== $expected) { return []; }

        $employees = self::employees($companyuuid, $actor);
        $reviewer = isset($employees[(int)$source->reviewerid]) ? (int)$source->reviewerid : 0;
        $observer = (int)$source->observerid > 0 && isset($employees[(int)$source->observerid]) ? (int)$source->observerid : 0;
        $published = battle_plan_template_service::published_options($companyuuid, $positionuuid);
        $template = isset($published['templates'][(string)$source->templateuuid]) ? (string)$source->templateuuid : '';
        $norm = isset($published['norms'][(string)$source->normuuid]) ? (string)$source->normuuid : '';

        return [
            'reviewerid'=>$reviewer,
            'observerid'=>$observer,
            'plantemplateuuid'=>$template,
            'normuuid'=>$norm,
            'source_uuid'=>(string)$source->uuid,
            'source_period_start'=>(string)$source->periodstart,
            'source_period_end'=>(string)$source->periodend,
        ];
    }

    public static function form_context(string $companyuuid, int $actor, int $targetuser = 0, string $position = ''): array {
        company_context_service::context($companyuuid, $actor);
        $employees = self::employees($companyuuid, $actor);
        $manager = self::can_manage($companyuuid, $actor)
            || access_role_service::can($companyuuid, $actor, 'battle_plan', 'create');
        $scopedusers = access_role_service::department_plan_create_userids($companyuuid, $actor);
        if ($targetuser <= 0 && isset($employees[$actor])) { $targetuser = $actor; }
        if (!$manager && $targetuser !== $actor && !in_array($targetuser, $scopedusers, true)) {
            self::fail('permission');
        }
        if (!$manager) {
            $employees = array_intersect_key($employees, array_fill_keys(array_merge([$actor], $scopedusers), true));
        }
        if ($targetuser > 0 && !isset($employees[$targetuser])) { self::fail('employeeerror'); }
        $positions = $targetuser > 0 ? self::current_positions($companyuuid, $actor, $targetuser) : [];
        if ($position !== '' && !isset($positions[$position])) { $position = ''; }
        $published = $position !== '' ? battle_plan_template_service::published_options($companyuuid, $position) : ['templates'=>[],'norms'=>[]];
        return ['employees'=>$employees,'can_manage'=>$manager || (bool)$scopedusers,'target_user'=>$targetuser,'positions'=>$positions,
            'position_uuid'=>$position,'template_options'=>$published['templates'],'norm_options'=>$published['norms']];
    }

    /** Existing record fields and composition protect stale UI actions; this is not a new revision engine. */
    private static function edit_token(\stdClass $plan): string {
        global $DB;
        $fields = (array)$plan; ksort($fields);
        $items = $DB->get_records('local_kbacompany_bplanitem', ['companyuuid'=>$plan->companyuuid,'planuuid'=>$plan->uuid],
            'id ASC', 'id,uuid,taskuuid,provider,externalid,stream,sortorder');
        return hash('sha256', json_encode([$fields,array_values($items)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    private static function assert_token(\stdClass $plan, string $expected): void {
        if ($expected !== '' && !hash_equals(self::edit_token($plan), $expected)) { self::fail('staleform'); }
    }

    /** Save Battle Plan editable fields. Lifecycle status still changes only through transition(). */
    public static function save_settings(string $companyuuid, int $actor, string $planuuid, string $expected, array $values): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($expected === '') { self::fail('staleform'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('battleplan:'.$companyuuid.':'.$planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $plan = self::plan($companyuuid, $planuuid, $actor);
            self::assert_token($plan, $expected);
            $canmanage = self::can_manage($companyuuid, $actor);
            $deptedit = access_role_service::may_edit_department_plan($companyuuid, $actor, (int)$plan->userid);
            if (!$canmanage && !$deptedit && (int)$plan->userid !== $actor) { self::fail('permission'); }
            if ($plan->status === 'closed') { self::fail('state'); }

            if ($deptedit && !$canmanage && (int)$plan->userid !== $actor) {
                if (isset($values['ownerposition']) && (string)$values['ownerposition'] !== ''
                        && (string)$values['ownerposition'] !== ((int)$plan->userid . '::' . (string)$plan->positionuuid)) { self::fail('permission'); }
                if (isset($values['plantemplateuuid']) && (string)$values['plantemplateuuid'] !== (string)$plan->templateuuid) { self::fail('permission'); }
                if (isset($values['normuuid']) && (string)$values['normuuid'] !== (string)$plan->normuuid) { self::fail('permission'); }
            }
            $reviewer = (int)($values['reviewerid'] ?? $plan->reviewerid);
            $observer = (int)($values['observerid'] ?? 0);
            self::validate_plan_people($companyuuid, $actor, $reviewer, $observer);

            if ($plan->status === 'draft') {
                $ownerchanged = false;
                $newuserid = (int)$plan->userid;
                $newposition = (string)$plan->positionuuid;
                $newperiod = isset($values['periodstart']) ? self::week_start((string)$values['periodstart']) : (string)$plan->periodstart;

                if (isset($values['ownerposition']) && (string)$values['ownerposition'] !== '' && !($deptedit && !$canmanage)) {
                    if (!$canmanage) { self::fail('permission'); }
                    $parts = explode('::', (string)$values['ownerposition'], 2);
                    $newuserid = isset($parts[0]) ? (int)$parts[0] : 0;
                    $newposition = isset($parts[1]) ? (string)$parts[1] : '';
                    if ($newuserid <= 0 || $newposition === '') { self::fail('reference'); }
                    if ($newuserid !== (int)$plan->userid || $newposition !== (string)$plan->positionuuid) {
                        if ($DB->record_exists('local_kbacompany_bplanitem', ['companyuuid'=>$companyuuid,'planuuid'=>$planuuid])) {
                            self::fail('state');
                        }
                        $employees = self::employees($companyuuid, $actor);
                        if (!isset($employees[$newuserid])) { self::fail('employeeerror'); }
                        $validpositions = self::current_positions($companyuuid, $actor, $newuserid);
                        if (!isset($validpositions[$newposition])) { self::fail('positionerror'); }
                        $ownerchanged = true;
                    }
                }

                if ($DB->record_exists_select('local_kbacompany_bplan',
                        'companyuuid = :company AND userid = :userid AND positionuuid = :positionuuid AND periodstart = :periodstart AND uuid <> :uuid',
                        ['company'=>$companyuuid,'userid'=>$newuserid,'positionuuid'=>$newposition,
                         'periodstart'=>$newperiod,'uuid'=>(string)$plan->uuid])) {
                    self::fail('duplicate');
                }

                $plan->userid = $newuserid;
                $plan->positionuuid = $newposition;
                $plan->periodstart = $newperiod;
                $plan->periodend = self::week_end($newperiod);
                if ($ownerchanged) {
                    $plan->templateuuid = '';
                    $plan->templaterevision = 0;
                    $plan->normuuid = '';
                    $plan->normrevision = 0;
                    $plan->statepayload = '';
                }

                $template = (string)($values['plantemplateuuid'] ?? $plan->templateuuid);
                $norm = (string)($values['normuuid'] ?? $plan->normuuid);
                // Changing owner/position clears design pins; the new position can be configured on the next save.
                // Otherwise an unchanged selection preserves its historical pin, even when a newer revision exists.
                if (!$ownerchanged && ($template !== (string)$plan->templateuuid || $norm !== (string)$plan->normuuid)) {
                    list($tr, $nr) = self::design_revisions($companyuuid, (string)$plan->positionuuid,
                        $template !== (string)$plan->templateuuid ? $template : '', $norm !== (string)$plan->normuuid ? $norm : '');
                    if ($template !== (string)$plan->templateuuid) { $plan->templateuuid=$template; $plan->templaterevision=$tr; }
                    if ($norm !== (string)$plan->normuuid) {
                        $plan->normuuid=$norm; $plan->normrevision=$nr;
                        $payload=json_decode((string)$plan->statepayload,true) ?: []; unset($payload['overrides']);
                        $plan->statepayload=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    }
                }
            } else if (isset($values['plantemplateuuid']) || isset($values['normuuid']) || isset($values['periodstart']) || isset($values['ownerposition'])) { self::fail('state'); }
            $plan->reviewerid=$reviewer; $plan->observerid=$observer; $plan->timemodified=time();
            $DB->update_record('local_kbacompany_bplan',$plan);
            $tx->allow_commit();
        } catch (\Throwable $e) { if (isset($tx)) { $tx->rollback($e); } throw $e; }
        finally { $lock->release(); }
    }


    /** Decode the Battle Plan state payload without changing any existing payload namespaces. */
    private static function state_payload_array(string $raw): array {
        if ($raw === '') { return []; }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** Battle Plan 2.0 weekly narrative stored inside the existing versioned state payload. */
    private static function week_workspace_payload(string $raw): array {
        $payload = self::state_payload_array($raw);
        $week = isset($payload['week2']) && is_array($payload['week2']) ? $payload['week2'] : [];
        return [
            'goal' => trim((string)($week['goal'] ?? '')),
            'outcome' => trim((string)($week['outcome'] ?? '')),
            'rollover' => isset($week['rollover']) && is_array($week['rollover']) ? $week['rollover'] : [],
        ];
    }

    /** Save the week goal and closing outcome without introducing parallel Battle Plan storage. */
    public static function save_week_workspace(string $companyuuid, int $actor, string $planuuid,
            string $expected, string $goal, string $outcome): void {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $goal = trim($goal);
        $outcome = trim($outcome);
        if (\core_text::strlen($goal) > 4000 || \core_text::strlen($outcome) > 4000) { self::fail('weektext'); }
        if ($expected === '') { self::fail('staleform'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('battleplan:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $plan = self::plan($companyuuid, $planuuid, $actor);
            self::assert_token($plan, $expected);
            $canmanage = self::can_manage($companyuuid, $actor);
            $deptedit = access_role_service::may_edit_department_plan($companyuuid, $actor, (int)$plan->userid);
            if (!$canmanage && !$deptedit && (int)$plan->userid !== $actor) { self::fail('permission'); }
            if ((string)$plan->status === 'closed') { self::fail('state'); }
            $payload = self::state_payload_array((string)($plan->statepayload ?? ''));
            $week = isset($payload['week2']) && is_array($payload['week2']) ? $payload['week2'] : [];
            $week['goal'] = $goal;
            $week['outcome'] = $outcome;
            $week['updatedby'] = $actor;
            $week['updatedat'] = time();
            $payload['week2'] = $week;
            $plan->statepayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $plan->timemodified = time();
            $DB->update_record('local_kbacompany_bplan', $plan);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

    /** Week meetings are a read-only projection over canonical Meeting participation. */
    private static function week_meeting_projection(string $companyuuid, int $actor, int $ownerid,
            string $periodstart, string $periodend, bool $canmanage): array {
        global $DB;
        $from = strtotime($periodstart . ' 00:00:00 UTC');
        $to = strtotime($periodend . ' 23:59:59 UTC');
        if ($from === false || $to === false) { return ['count'=>0,'minutes'=>0,'items'=>[],'details_visible'=>false]; }
        $sql = "SELECT m.uuid,m.title,m.status,m.startsat,m.endsat,m.location
                  FROM {local_kbacompany_meeting} m
                 WHERE m.companyuuid = :company
                   AND m.status <> :cancelled
                   AND m.startsat BETWEEN :fromts AND :tots
                   AND EXISTS (SELECT p.id FROM {local_kbacompany_meetpart} p
                                WHERE p.companyuuid = m.companyuuid AND p.meetinguuid = m.uuid
                                  AND p.userid = :ownerid AND p.active = 1)
              ORDER BY m.startsat,m.id";
        $records = $DB->get_records_sql($sql, [
            'company'=>$companyuuid,'cancelled'=>'cancelled','fromts'=>$from,'tots'=>$to,'ownerid'=>$ownerid,
        ]);
        $minutes = 0; $items = [];
        $details = $actor === $ownerid;
        foreach ($records as $row) {
            $duration = max(0, (int)$row->endsat - (int)$row->startsat);
            $minutes += (int)round($duration / 60);
            if ($details) {
                $items[] = [
                    'uuid'=>(string)$row->uuid,'title'=>(string)$row->title,'status'=>(string)$row->status,
                    'starts_at'=>(int)$row->startsat,'ends_at'=>(int)$row->endsat,'location'=>(string)$row->location,
                    'url'=>(new \moodle_url('/local/kbacompany/meetings.php', [
                        'uuid'=>$companyuuid,'meeting'=>(string)$row->uuid,
                    ]))->out(false),
                ];
            }
        }
        return ['count'=>count($records),'minutes'=>$minutes,'items'=>$items,'details_visible'=>$details];
    }

    /** Weekly planning capacity, not recorded working time. Task dates are inclusive. */
    private static function weekly_capacity_projection(string $companyuuid, int $actor, int $ownerid,
            string $from, string $to, array $visibleusers): array {
        global $DB;
        $userids = array_values(array_unique(array_filter(array_map('intval', $visibleusers),
            static function($id) { return $id > 0; })));
        if (!in_array($ownerid, $userids, true)) { $userids[] = $ownerid; }
        list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'loaduid');
        $params['loadcompany'] = $companyuuid;
        $params['loadcancelled'] = 'cancelled';
        $params['loadfrom'] = $from;
        $params['loadto'] = $to;
        $params['loadperiod'] = $from;
        $sql = "SELECT t.assigneeid, SUM(t.planminutes) AS minutes
                  FROM {local_kbacompany_task} t
                 WHERE t.companyuuid = :loadcompany AND t.assigneeid $insql
                   AND t.status <> :loadcancelled
                   AND (t.periodkey = :loadperiod OR
                        (t.startdate <> '' AND t.duedate <> ''
                          AND t.startdate <= :loadto AND t.duedate >= :loadfrom))
              GROUP BY t.assigneeid";
        $minutesbyuser = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $minutesbyuser[(int)$row->assigneeid] = max(0, (int)$row->minutes);
        }
        $total = array_sum($minutesbyuser);
        $capacities = employee_schedule_service::capacities($companyuuid, $userids);
        $teamcapacity = array_sum($capacities);
        return [
            'owner_task_minutes' => $minutesbyuser[$ownerid] ?? 0,
            'owner_capacity_minutes' => $capacities[$ownerid] ?? 2400,
            'headcount' => count($userids),
            'team_capacity_minutes' => $teamcapacity,
            'team_task_minutes' => $total,
            'team_remaining_task_minutes' => $teamcapacity - $total,
            'includes_team_meetings' => false,
        ];
    }

    /** Open plan items that can be explicitly carried into the next weekly plan by reference. */
    private static function open_rollover_items(string $companyuuid, int $actor, array $items): array {
        $out = [];
        foreach ($items as $item) {
            $task = $item['task'] ?? [];
            if (in_array((string)($task['status'] ?? ''), ['done','cancelled'], true)) { continue; }
            $out[] = [
                'item_uuid'=>(string)$item['uuid'], 'provider'=>(string)$item['provider'],
                'external_id'=>(string)$item['external_id'], 'stream'=>(string)$item['stream'],
                'title'=>(string)($task['title'] ?? ''), 'status'=>(string)($task['status'] ?? ''),
                'due_date'=>(string)($task['due_date'] ?? ''),
            ];
        }
        return $out;
    }

    /** Carry selected open canonical task references into next week's plan; no task is copied or rescheduled. */
    public static function rollover_open_tasks(string $companyuuid, int $actor, string $planuuid,
            string $expected, array $itemuuids): string {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        if ($expected === '') { self::fail('staleform'); }
        $itemuuids = array_values(array_unique(array_filter(array_map('trim', $itemuuids))));
        if (!$itemuuids || count($itemuuids) > 100) { self::fail('rolloveritems'); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('battleplan:' . $companyuuid . ':' . $planuuid, 10);
        if (!$lock) { self::fail('lock'); }
        try {
            $tx = $DB->start_delegated_transaction();
            $plan = self::plan($companyuuid, $planuuid, $actor);
            self::assert_token($plan, $expected);
            $canmanage = self::can_manage($companyuuid, $actor);
            if ((string)$plan->status !== 'active' || (!$canmanage && (int)$plan->userid !== $actor)) { self::fail('state'); }

            $open = [];
            foreach ($DB->get_records('local_kbacompany_bplanitem', [
                'companyuuid'=>$companyuuid,'planuuid'=>$planuuid,
            ], 'sortorder ASC,id ASC') as $item) {
                $provider = (string)($item->provider ?: 'bms');
                $externalid = (string)($item->externalid ?: $item->taskuuid);
                if ($provider === 'bms') {
                    $task = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$companyuuid,'uuid'=>(string)$item->taskuuid]);
                    $status = $task ? (string)$task->status : 'cancelled';
                } else {
                    $task = task_provider_service::get_task($companyuuid, $actor, $provider, $externalid);
                    $status = $task ? (string)$task['status'] : 'cancelled';
                }
                if (!in_array($status, ['done','cancelled'], true)) { $open[(string)$item->uuid] = $item; }
            }
            foreach ($itemuuids as $itemuuid) { if (!isset($open[$itemuuid])) { self::fail('rolloveritems'); } }

            $nextstart = (new \DateTimeImmutable((string)$plan->periodstart, new \DateTimeZone('UTC')))
                ->modify('+7 days')->format('Y-m-d');
            $published = battle_plan_template_service::published_options($companyuuid, (string)$plan->positionuuid);
            $nexttemplate = isset($published['templates'][(string)$plan->templateuuid]) ? (string)$plan->templateuuid : '';
            $nextnorm = isset($published['norms'][(string)$plan->normuuid]) ? (string)$plan->normuuid : '';
            $targetuuid = self::create_plan($companyuuid, $actor, (int)$plan->userid, (string)$plan->positionuuid,
                $nextstart, (int)$plan->reviewerid, (int)$plan->observerid, $nexttemplate, $nextnorm);
            $target = self::plan($companyuuid, $targetuuid, $actor);
            if ((string)$target->status !== 'draft') { self::fail('rolloverstate'); }

            $sort = (int)$DB->get_field_sql("SELECT COALESCE(MAX(sortorder),0) FROM {local_kbacompany_bplanitem}
                                              WHERE companyuuid=:company AND planuuid=:plan",
                ['company'=>$companyuuid,'plan'=>$targetuuid]);
            foreach ($itemuuids as $itemuuid) {
                $source = $open[$itemuuid];
                $provider = (string)($source->provider ?: 'bms');
                $externalid = (string)($source->externalid ?: $source->taskuuid);
                if ($DB->record_exists('local_kbacompany_bplanitem', [
                    'companyuuid'=>$companyuuid,'planuuid'=>$targetuuid,'provider'=>$provider,'externalid'=>$externalid,
                ])) { continue; }
                $sort += 10;
                $DB->insert_record('local_kbacompany_bplanitem', (object)[
                    'companyuuid'=>$companyuuid,'uuid'=>self::uuid(),'planuuid'=>$targetuuid,
                    'taskuuid'=>(string)$source->taskuuid,'provider'=>$provider,'externalid'=>$externalid,
                    'stream'=>(string)$source->stream,'sortorder'=>$sort,'timecreated'=>time(),
                ]);
            }

            $payload = self::state_payload_array((string)($plan->statepayload ?? ''));
            $week = isset($payload['week2']) && is_array($payload['week2']) ? $payload['week2'] : [];
            $previouscarried = isset($week['rollover']['itemuuids']) && is_array($week['rollover']['itemuuids'])
                ? array_map('strval', $week['rollover']['itemuuids']) : [];
            $carrieditems = array_values(array_unique(array_merge($previouscarried, $itemuuids)));
            $week['rollover'] = [
                'targetplanuuid'=>$targetuuid,'itemuuids'=>$carrieditems,'createdby'=>$actor,'createdat'=>time(),
            ];
            $payload['week2'] = $week;
            $plan->statepayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $plan->timemodified = time();
            $DB->update_record('local_kbacompany_bplan', $plan);

            $targetpayload = self::state_payload_array((string)($target->statepayload ?? ''));
            $targetweek = isset($targetpayload['week2']) && is_array($targetpayload['week2']) ? $targetpayload['week2'] : [];
            $targetweek['rolledfrom'] = $planuuid;
            $targetpayload['week2'] = $targetweek;
            $target->statepayload = json_encode($targetpayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $target->timemodified = time();
            $DB->update_record('local_kbacompany_bplan', $target);

            $tx->allow_commit();
            return $targetuuid;
        } catch (\Throwable $e) {
            if (isset($tx)) { $tx->rollback($e); }
            throw $e;
        } finally { $lock->release(); }
    }

}
