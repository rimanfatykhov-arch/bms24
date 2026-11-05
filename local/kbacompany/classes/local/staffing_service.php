<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Versioned staffing plans and factual placement in individual staffing slots. */
class staffing_service {
    public const CONTRACT_VERSION = '1.1.0-rc13';
    private const ASSIGNMENT_TYPES = ['primary', 'acting', 'additional'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'kba_staffing_plan_placement',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'objects' => ['StaffingPlan', 'StaffingPlanLine', 'StaffingSlot', 'PositionAssignment'],
            'write_policy' => 'explicit_authorised_commands',
            'rules' => [
                'staffing_plan_is_normative_and_versioned',
                'new_draft_defaults_to_copy_of_current_approved_plan',
                'approved_plan_has_one_click_edit_draft',
                'draft_lines_are_editable_and_removable',
                'version_diff_is_available_before_approval',
                'draft_editor_supports_batch_save_and_safe_discard',
                'staffing_plan_crud_preserves_approved_history',
                'staffing_plan_shows_current_occupancy_and_vacancy',
                'draft_approval_previews_assignment_carry_forward_and_conflicts',
                'approval_conflicts_require_explicit_user_acknowledgement',
                'draft_coverage_uses_current_approved_staffing_placement',
                'staffing_placement_is_factual_and_historical',
                'staffing_placement_supports_crud_transfer_fte_and_temporary_assignments',
                'assignment_fte_is_fact_separate_from_normative_slot_capacity',
                'approved_version_carries_forward_compatible_current_slot_assignments',
                'capacity_change_never_silently_ends_an_assignment',
                'vacant_slot_has_no_fabricated_employee',
                'approved_plan_is_immutable',
                'assignment_does_not_change_competency',
                'course_completion_does_not_confirm_competency_level',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    public static function plan_context(string $companyuuid, int $userid, string $planuuid = ''): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $plans = $DB->get_records('local_kbacompany_staffplan', ['companyuuid' => $companyuuid],
            'timecreated DESC, id DESC');
        $planstats = [];
        if ($plans) {
            $sql = "SELECT planuuid, COUNT(1) AS linecount, COALESCE(SUM(plannedfte), 0) AS plannedfte,
                           COALESCE(SUM(budgetamount), 0) AS budgetamount
                      FROM {local_kbacompany_staffline}
                     WHERE companyuuid = :companyuuid
                  GROUP BY planuuid";
            foreach ($DB->get_records_sql($sql, ['companyuuid' => $companyuuid]) as $stat) {
                $planstats[(string)$stat->planuuid] = [
                    'lines' => (int)$stat->linecount,
                    'planned_fte' => (float)$stat->plannedfte,
                    'budget_amount' => (float)$stat->budgetamount,
                ];
            }
        }
        $selected = null;
        if ($planuuid !== '') {
            $selected = $DB->get_record('local_kbacompany_staffplan',
                ['companyuuid' => $companyuuid, 'uuid' => $planuuid], '*', MUST_EXIST);
        } else {
            foreach ($plans as $plan) {
                if ($plan->status === 'draft') {
                    $selected = $plan;
                    break;
                }
                $selected = $selected ?? $plan;
            }
        }
        $board = organizing_board_service::board_context($companyuuid, $userid);
        $positions = $DB->get_records('local_kbacompany_position',
            ['companyuuid' => $companyuuid, 'active' => 1], 'code ASC');
        $positionmap = [];
        foreach ($positions as $position) {
            $positionmap[$position->uuid] = $position;
        }
        $nodemap = [];
        foreach ($DB->get_records('local_kbacompany_orgnode',
                ['companyuuid' => $companyuuid, 'active' => 1]) as $node) {
            $nodemap[$node->uuid] = ['name' => (string)$node->name];
        }
        $lines = [];
        $plannedfte = 0.0;
        $budget = 0.0;
        if ($selected) {
            foreach ($DB->get_records('local_kbacompany_staffline', [
                    'companyuuid' => $companyuuid, 'planuuid' => $selected->uuid],
                    'sortorder ASC, id ASC') as $line) {
                $position = $positionmap[$line->positionuuid] ?? null;
                $node = $nodemap[$line->orgnodeuuid] ?? null;
                $plannedfte += (float)$line->plannedfte;
                $budget += (float)$line->budgetamount;
                $lines[] = [
                    'id' => (int)$line->id,
                    'position_uuid' => (string)$line->positionuuid,
                    'position_code' => $position ? (string)$position->code : '',
                    'position_name' => $position ? (string)$position->name : '',
                    'org_node_uuid' => (string)$line->orgnodeuuid,
                    'org_node_name' => $node ? (string)$node['name'] : '',
                    'planned_fte' => (float)$line->plannedfte,
                    'salary_min' => (float)$line->salarymin,
                    'salary_max' => (float)$line->salarymax,
                    'budget_amount' => (float)$line->budgetamount,
                ];
            }
        }
        $previous = null;
        $comparison = ['added' => [], 'changed' => [], 'removed' => []];
        if ($selected) {
            $previous = self::previous_approved_plan($companyuuid, (string)$selected->branchuuid, (string)$selected->uuid);
            if ($selected->status === 'draft' && $previous) {
                $comparison = self::compare_plan_records($companyuuid, $previous, $selected);
            }
        }
        $changemap = [];
        foreach ($comparison['added'] as $item) {
            $changemap[$item['position_uuid']] = 'added';
        }
        foreach ($comparison['changed'] as $item) {
            $changemap[$item['position_uuid']] = 'changed';
        }
        // The plan is normative; occupancy is factual. For a draft we compare proposed FTE against the
        // currently approved placement. For an approved/archived version we read placement for that version.
        $occupancysource = null;
        if ($selected) {
            if ($selected->status === 'draft') {
                $occupancysource = $previous;
            } else {
                $occupancysource = $selected;
            }
        }
        $occupancy = $occupancysource
            ? self::current_occupancy_for_plan($companyuuid, (string)$occupancysource->uuid)
            : ['by_position' => [], 'total' => 0.0];
        $occupiedfte = 0.0;
        $vacantfte = 0.0;
        $overstaffedfte = 0.0;
        foreach ($lines as &$line) {
            $line['change_status'] = $changemap[$line['position_uuid']] ?? 'unchanged';
            $occupied = (float)($occupancy['by_position'][$line['position_uuid']] ?? 0.0);
            $planned = (float)$line['planned_fte'];
            $vacant = max(0.0, $planned - $occupied);
            $overstaffed = max(0.0, $occupied - $planned);
            $line['occupied_fte'] = $occupied;
            $line['vacant_fte'] = $vacant;
            $line['overstaffed_fte'] = $overstaffed;
            $line['occupancy_percent'] = $planned > 0.0001 ? round(($occupied / $planned) * 100, 1) : 0.0;
            $line['staffing_status'] = $overstaffed > 0.0001 ? 'overstaffed' : ($vacant > 0.0001 ? 'vacant' : 'staffed');
            $occupiedfte += $occupied;
            $vacantfte += $vacant;
            $overstaffedfte += $overstaffed;
        }
        unset($line);
        $approvalimpact = [
            'total_assignments' => 0,
            'total_fte' => 0.0,
            'carried_assignments' => 0,
            'carried_fte' => 0.0,
            'conflict_count' => 0,
            'conflict_fte' => 0.0,
            'conflicts' => [],
        ];
        if ($selected && $selected->status === 'draft' && $previous) {
            $approvalimpact = self::approval_impact_for_draft($companyuuid, $previous, $selected);
        }

        $departmentuuids = [];
        foreach ($lines as $line) {
            if ($line['org_node_uuid'] !== '') {
                $departmentuuids[$line['org_node_uuid']] = true;
            }
        }
        return [
            'company' => $company,
            'plans' => array_values(array_map([self::class, 'plan_dto'], $plans)),
            'plan' => $selected ? self::plan_dto($selected) : null,
            'lines' => $lines,
            'positions' => array_values(array_map(static function($position) use ($nodemap): array {
                $node = $nodemap[(string)$position->orgnodeuuid] ?? null;
                return ['uuid' => (string)$position->uuid, 'code' => (string)$position->code,
                    'name' => (string)$position->name, 'org_node_uuid' => (string)$position->orgnodeuuid,
                    'org_node_name' => $node ? (string)$node['name'] : '',
                    'branch_uuid' => (string)$position->branchuuid];
            }, $positions)),
            'branches' => $board['branches'],
            'summary' => ['planned_fte' => $plannedfte, 'occupied_fte' => $occupiedfte,
                'vacant_fte' => $vacantfte, 'overstaffed_fte' => $overstaffedfte,
                'budget_amount' => $budget, 'lines' => count($lines),
                'departments' => count($departmentuuids)],
            'plan_stats' => $planstats,
            'can_manage' => organizing_board_service::can_manage($companyuuid, $userid),
            'previous_plan' => $previous ? self::plan_dto($previous) : null,
            'occupancy_source_plan' => $occupancysource ? self::plan_dto($occupancysource) : null,
            'comparison' => $comparison,
            'approval_impact' => $approvalimpact,
        ];
    }


    /** Open an existing draft for the branch or create one from the current approved staffing plan. */
    public static function start_editing_plan(string $companyuuid, int $userid, string $sourceplanuuid): string {
        global $DB;
        self::require_manage($companyuuid, $userid);
        company_context_service::context($companyuuid, $userid);
        $source = $DB->get_record('local_kbacompany_staffplan', [
            'companyuuid' => $companyuuid, 'uuid' => $sourceplanuuid, 'status' => 'approved',
        ], '*', MUST_EXIST);
        $current = self::current_approved_plan($companyuuid, (string)$source->branchuuid);
        if (!$current || (string)$current->uuid !== (string)$source->uuid) {
            throw new \moodle_exception('staffplaneditcurrentonly', 'local_kbacompany');
        }
        $drafts = $DB->get_records('local_kbacompany_staffplan', [
            'companyuuid' => $companyuuid, 'branchuuid' => $source->branchuuid, 'status' => 'draft',
        ], 'timemodified DESC, id DESC', '*', 0, 1);
        if ($drafts) {
            $draft = reset($drafts);
            return (string)$draft->uuid;
        }
        return self::create_plan($companyuuid, $userid, [
            'branchuuid' => (string)$source->branchuuid,
            'name' => get_string('staffplanversiondefault', 'local_kbacompany', userdate(time(), '%d.%m.%Y')),
            'currency' => (string)$source->currency,
            'effectivefrom' => business_date_service::day(time()),
            'copycurrent' => 1,
        ]);
    }

    public static function create_plan(string $companyuuid, int $userid, array $data): string {
        global $DB;
        self::require_manage($companyuuid, $userid);
        company_context_service::context($companyuuid, $userid);
        $branchuuid = trim((string)($data['branchuuid'] ?? ''));
        $DB->get_record('local_kbacompany_orgbranch',
            ['companyuuid' => $companyuuid, 'uuid' => $branchuuid, 'active' => 1], '*', MUST_EXIST);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new \moodle_exception('staffplannameempty', 'local_kbacompany');
        }
        $effectivefrom = self::parse_effective_date((string)($data['effectivefrom'] ?? ''));
        $now = time();
        $uuid = self::uuid_v4();
        $transaction = $DB->start_delegated_transaction();
        $DB->insert_record('local_kbacompany_staffplan', (object)[
            'companyuuid' => $companyuuid, 'branchuuid' => $branchuuid, 'uuid' => $uuid,
            'name' => $name, 'status' => 'draft', 'effectivefrom' => $effectivefrom, 'effectiveto' => 0,
            'currency' => strtoupper(substr(trim((string)($data['currency'] ?? 'RUB')), 0, 3)) ?: 'RUB',
            'approvedby' => 0, 'timeapproved' => 0, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $copycurrent = !array_key_exists('copycurrent', $data) || !empty($data['copycurrent']);
        if ($copycurrent) {
            $source = self::current_approved_plan($companyuuid, $branchuuid);
            if ($source) {
                foreach ($DB->get_records('local_kbacompany_staffline', [
                        'companyuuid' => $companyuuid, 'planuuid' => $source->uuid], 'sortorder ASC, id ASC') as $line) {
                    $clone = clone $line;
                    unset($clone->id);
                    $clone->planuuid = $uuid;
                    $clone->timecreated = $now;
                    $clone->timemodified = $now;
                    $DB->insert_record('local_kbacompany_staffline', $clone);
                }
            }
        }
        $transaction->allow_commit();
        return $uuid;
    }

    public static function update_plan(string $companyuuid, int $userid, string $planuuid, array $data): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $plan = self::require_draft_plan($companyuuid, $planuuid);
        $name = trim((string)($data['name'] ?? $plan->name));
        if ($name === '') {
            throw new \moodle_exception('staffplannameempty', 'local_kbacompany');
        }
        $plan->name = $name;
        $plan->currency = strtoupper(substr(trim((string)($data['currency'] ?? $plan->currency)), 0, 3)) ?: 'RUB';
        $plan->effectivefrom = self::parse_effective_date((string)($data['effectivefrom'] ?? ''), (int)$plan->effectivefrom);
        $plan->timemodified = time();
        $DB->update_record('local_kbacompany_staffplan', $plan);
    }

    /** Save version metadata and all editable staffing lines in one transaction. */
    public static function save_draft_editor(string $companyuuid, int $userid, string $planuuid,
            array $plandata, array $lines): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        self::require_draft_plan($companyuuid, $planuuid);
        $transaction = $DB->start_delegated_transaction();
        self::update_plan($companyuuid, $userid, $planuuid, $plandata);
        foreach ($lines as $lineid => $values) {
            $lineid = (int)$lineid;
            if ($lineid <= 0) {
                continue;
            }
            $line = $DB->get_record('local_kbacompany_staffline', [
                'id' => $lineid, 'companyuuid' => $companyuuid, 'planuuid' => $planuuid,
            ], '*', MUST_EXIST);
            $plannedfte = round((float)($values['plannedfte'] ?? $line->plannedfte), 2);
            if ($plannedfte <= 0 || $plannedfte > 999) {
                throw new \moodle_exception('invalidplannedfte', 'local_kbacompany');
            }
            $salarymin = max(0, round((float)($values['salarymin'] ?? $line->salarymin), 2));
            $salarymax = max(0, round((float)($values['salarymax'] ?? $line->salarymax), 2));
            if ($salarymax > 0 && $salarymax < $salarymin) {
                throw new \moodle_exception('invalidsalaryrange', 'local_kbacompany');
            }
            $line->plannedfte = $plannedfte;
            $line->salarymin = $salarymin;
            $line->salarymax = $salarymax;
            $line->budgetamount = max(0, round((float)($values['budgetamount'] ?? $line->budgetamount), 2));
            $line->timemodified = time();
            $DB->update_record('local_kbacompany_staffline', $line);
        }
        $transaction->allow_commit();
    }

    /** Discard a draft version. Approved and archived versions are never deleted. */
    public static function discard_draft(string $companyuuid, int $userid, string $planuuid): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $plan = self::require_draft_plan($companyuuid, $planuuid);
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_kbacompany_staffline', [
            'companyuuid' => $companyuuid, 'planuuid' => $plan->uuid,
        ]);
        $DB->delete_records('local_kbacompany_staffplan', [
            'companyuuid' => $companyuuid, 'uuid' => $plan->uuid, 'status' => 'draft',
        ]);
        $transaction->allow_commit();
    }

    public static function add_line(string $companyuuid, int $userid, string $planuuid, array $data): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $plan = self::require_draft_plan($companyuuid, $planuuid);
        $position = $DB->get_record('local_kbacompany_position', [
            'companyuuid' => $companyuuid, 'uuid' => trim((string)($data['positionuuid'] ?? '')), 'active' => 1,
        ], '*', MUST_EXIST);
        if ((string)$position->branchuuid !== (string)$plan->branchuuid) {
            throw new \moodle_exception('positionoutsideplanbranch', 'local_kbacompany');
        }
        $plannedfte = round((float)($data['plannedfte'] ?? 0), 2);
        if ($plannedfte <= 0 || $plannedfte > 999) {
            throw new \moodle_exception('invalidplannedfte', 'local_kbacompany');
        }
        $salarymin = max(0, round((float)($data['salarymin'] ?? 0), 2));
        $salarymax = max(0, round((float)($data['salarymax'] ?? 0), 2));
        if ($salarymax > 0 && $salarymax < $salarymin) {
            throw new \moodle_exception('invalidsalaryrange', 'local_kbacompany');
        }
        $now = time();
        $record = $DB->get_record('local_kbacompany_staffline',
            ['planuuid' => $planuuid, 'positionuuid' => $position->uuid]);
        $values = (object)[
            'companyuuid' => $companyuuid, 'planuuid' => $planuuid,
            'orgnodeuuid' => (string)$position->orgnodeuuid, 'positionuuid' => (string)$position->uuid,
            'plannedfte' => $plannedfte, 'salarymin' => $salarymin, 'salarymax' => $salarymax,
            'budgetamount' => max(0, round((float)($data['budgetamount'] ?? 0), 2)),
            'sortorder' => 0, 'timemodified' => $now,
        ];
        if ($record) {
            $values->id = $record->id;
            $DB->update_record('local_kbacompany_staffline', $values);
        } else {
            $values->timecreated = $now;
            $DB->insert_record('local_kbacompany_staffline', $values);
        }
    }

    public static function delete_line(string $companyuuid, int $userid, string $planuuid, int $lineid): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        self::require_draft_plan($companyuuid, $planuuid);
        $line = $DB->get_record('local_kbacompany_staffline',
            ['id' => $lineid, 'companyuuid' => $companyuuid, 'planuuid' => $planuuid], '*', MUST_EXIST);
        $DB->delete_records('local_kbacompany_staffline', ['id' => $line->id]);
    }

    public static function approve_plan(string $companyuuid, int $userid, string $planuuid): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_kbacompany_staffing');
        $lock = $lockfactory->get_lock('approve:' . $planuuid, 10);
        if (!$lock) {
            throw new \moodle_exception('staffinglockfailed', 'local_kbacompany');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            $plan = self::require_draft_plan($companyuuid, $planuuid);
            $lines = $DB->get_records('local_kbacompany_staffline', [
                'companyuuid' => $companyuuid, 'planuuid' => $planuuid,
            ], 'id ASC');
            if (!$lines) {
                throw new \moodle_exception('staffplanempty', 'local_kbacompany');
            }
            // Capture the factual placement before the previous version is archived. Assignments belong to
            // canonical positions and must not disappear merely because a new normative plan is approved.
            $previousplan = self::current_approved_plan($companyuuid, (string)$plan->branchuuid);
            $carryassignments = $previousplan
                ? self::current_slot_assignments_for_plan($companyuuid, (string)$previousplan->uuid)
                : [];
            $newslots = [];
            foreach ($lines as $line) {
                $position = $DB->get_record('local_kbacompany_position',
                    ['companyuuid' => $companyuuid, 'uuid' => $line->positionuuid], '*', MUST_EXIST);
                $remaining = (float)$line->plannedfte;
                $number = 1;
                while ($remaining > 0.0001) {
                    $fte = min(1.0, $remaining);
                    $slot = (object)[
                        'companyuuid' => $companyuuid, 'planuuid' => $planuuid, 'lineid' => $line->id,
                        'uuid' => self::uuid_v4(),
                        'code' => $position->code . '-' . strtoupper(substr(str_replace('-', '', $planuuid), 0, 4)) .
                            '-S' . str_pad((string)$number, 3, '0', STR_PAD_LEFT),
                        'orgnodeuuid' => $line->orgnodeuuid, 'positionuuid' => $line->positionuuid,
                        'fte' => $fte, 'status' => 'vacant', 'active' => 1,
                        'timecreated' => time(), 'timemodified' => time(),
                    ];
                    $slot->id = $DB->insert_record('local_kbacompany_staffslot', $slot);
                    $newslots[(string)$line->positionuuid][] = $slot;
                    $remaining -= $fte;
                    $number++;
                }
            }
            self::carry_forward_slot_assignments($companyuuid, $carryassignments, $newslots);
            foreach ($DB->get_records('local_kbacompany_staffplan', [
                    'companyuuid' => $companyuuid, 'branchuuid' => $plan->branchuuid, 'status' => 'approved']) as $old) {
                $old->status = 'archived';
                if ((int)$plan->effectivefrom > 0) {
                    $old->effectiveto = max(0, (int)$plan->effectivefrom - 1);
                }
                $old->timemodified = time();
                $DB->update_record('local_kbacompany_staffplan', $old);
            }
            $plan->status = 'approved';
            if ((int)$plan->effectivefrom === 0) {
                $plan->effectivefrom = usergetmidnight(time());
            }
            $plan->approvedby = $userid;
            $plan->timeapproved = time();
            $plan->timemodified = time();
            $DB->update_record('local_kbacompany_staffplan', $plan);
            $transaction->allow_commit();
        } finally {
            $lock->release();
        }
    }

    public static function placement_context(string $companyuuid, int $userid, string $planuuid = ''): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $userid);
        $plans = $DB->get_records('local_kbacompany_staffplan',
            ['companyuuid' => $companyuuid, 'status' => 'approved'], 'timeapproved DESC, id DESC');
        $currentplan = $plans ? reset($plans) : null;
        $plan = $planuuid === '' ? $currentplan : $DB->get_record('local_kbacompany_staffplan',
            ['companyuuid' => $companyuuid, 'uuid' => $planuuid, 'status' => 'approved'], '*', MUST_EXIST);
        $employees = self::placement_employees($companyuuid, $userid);
        $positions = $DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid]);
        $positionmap = [];
        foreach ($positions as $position) { $positionmap[(string)$position->uuid] = $position; }
        $nodemap = [];
        foreach ($DB->get_records('local_kbacompany_orgnode',
                ['companyuuid' => $companyuuid, 'active' => 1]) as $node) {
            $nodemap[(string)$node->uuid] = ['name' => (string)$node->name];
        }

        $planned = 0.0;
        $planpositions = [];
        $slotsource = [];
        if ($plan) {
            foreach ($DB->get_records('local_kbacompany_staffline', [
                    'companyuuid' => $companyuuid, 'planuuid' => $plan->uuid], 'id ASC') as $line) {
                $planned += (float)$line->plannedfte;
                $planpositions[(string)$line->positionuuid] = true;
            }
            $slotsource = $DB->get_records('local_kbacompany_staffslot',
                ['companyuuid' => $companyuuid, 'planuuid' => $plan->uuid, 'active' => 1], 'code ASC');
        }

        $now = time();
        $assignmentrows = [];
        $historyrows = [];
        if ($planpositions) {
            list($positionsql, $positionparams) = $DB->get_in_or_equal(array_keys($planpositions),
                SQL_PARAMS_NAMED, 'placementposition');
            $baseparams = array_merge(['companyuuid' => $companyuuid], $positionparams);
            $sql = "SELECT a.*, s.code AS slotcode, s.fte AS slotfte, s.planuuid AS slotplanuuid,
                           s.orgnodeuuid AS slotorgnodeuuid
                      FROM {local_kbacompany_posassign} a
                 LEFT JOIN {local_kbacompany_staffslot} s
                        ON s.companyuuid = a.companyuuid AND s.uuid = a.slotuuid
                     WHERE a.companyuuid = :companyuuid
                       AND a.positionuuid $positionsql
                       AND a.active = 1
                       AND (a.effectiveto = 0 OR a.effectiveto >= :placementnow)
                  ORDER BY a.effectivefrom ASC, a.id ASC";
            $params = $baseparams;
            $params['placementnow'] = $now;
            $assignmentrows = array_values($DB->get_records_sql($sql, $params));

            $sql = "SELECT a.*, s.code AS slotcode, s.fte AS slotfte, s.planuuid AS slotplanuuid,
                           s.orgnodeuuid AS slotorgnodeuuid
                      FROM {local_kbacompany_posassign} a
                 LEFT JOIN {local_kbacompany_staffslot} s
                        ON s.companyuuid = a.companyuuid AND s.uuid = a.slotuuid
                     WHERE a.companyuuid = :companyuuid
                       AND a.positionuuid $positionsql
                       AND (a.active = 0 OR (a.effectiveto > 0 AND a.effectiveto < :historynow))
                  ORDER BY a.effectiveto DESC, a.id DESC";
            $params = $baseparams;
            $params['historynow'] = $now;
            $historyrows = array_values($DB->get_records_sql($sql, $params, 0, 200));
        }

        $slotalloc = [];
        $slotnames = [];
        $assignments = [];
        $history = [];
        $occupied = 0.0;
        $unplaced = 0.0;
        foreach ($assignmentrows as $row) {
            $position = $positionmap[(string)$row->positionuuid] ?? null;
            $nodeuuid = !empty($row->slotorgnodeuuid) ? (string)$row->slotorgnodeuuid : ($position ? (string)$position->orgnodeuuid : '');
            $node = $nodemap[$nodeuuid] ?? null;
            $fte = max(0.0, (float)($row->assignmentfte ?? 1.0));
            $iscurr = self::is_current($row, $now);
            $scheduled = (int)$row->effectivefrom > $now;
            $inslot = $plan && !empty($row->slotuuid) && (string)$row->slotplanuuid === (string)$plan->uuid;
            if ($iscurr) {
                $occupied += $fte;
                if (!$inslot) { $unplaced += $fte; }
                if ($inslot) {
                    $slotalloc[(string)$row->slotuuid] = ($slotalloc[(string)$row->slotuuid] ?? 0.0) + $fte;
                    $slotnames[(string)$row->slotuuid][] = $employees[(int)$row->userid]['full_name'] ?? ('#' . (int)$row->userid);
                }
            }
            $assignments[] = [
                'id' => (int)$row->id,
                'employee_id' => (int)$row->userid,
                'employee_name' => $employees[(int)$row->userid]['full_name'] ?? ('#' . (int)$row->userid),
                'position_uuid' => (string)$row->positionuuid,
                'position_name' => $position ? (string)$position->name : (string)$row->positionuuid,
                'org_node_name' => $node ? (string)$node['name'] : '',
                'slot_uuid' => (string)$row->slotuuid,
                'slot_code' => (string)($row->slotcode ?? ''),
                'fte' => $fte,
                'type' => (string)$row->assignmenttype,
                'effective_from' => (int)$row->effectivefrom,
                'effective_to' => (int)$row->effectiveto,
                'status' => $scheduled ? 'scheduled' : ($iscurr ? 'current' : 'ended'),
                'unplaced' => !$inslot,
            ];
        }
        foreach ($historyrows as $row) {
            $position = $positionmap[(string)$row->positionuuid] ?? null;
            $nodeuuid = !empty($row->slotorgnodeuuid) ? (string)$row->slotorgnodeuuid : ($position ? (string)$position->orgnodeuuid : '');
            $node = $nodemap[$nodeuuid] ?? null;
            $history[] = [
                'id' => (int)$row->id,
                'employee_id' => (int)$row->userid,
                'employee_name' => $employees[(int)$row->userid]['full_name'] ?? ('#' . (int)$row->userid),
                'position_name' => $position ? (string)$position->name : (string)$row->positionuuid,
                'org_node_name' => $node ? (string)$node['name'] : '',
                'slot_code' => (string)($row->slotcode ?? ''),
                'fte' => max(0.0, (float)($row->assignmentfte ?? 1.0)),
                'type' => (string)$row->assignmenttype,
                'effective_from' => (int)$row->effectivefrom,
                'effective_to' => (int)$row->effectiveto,
            ];
        }

        $slots = [];
        foreach ($slotsource as $slot) {
            $position = $positionmap[(string)$slot->positionuuid] ?? null;
            $node = $nodemap[(string)$slot->orgnodeuuid] ?? null;
            $allocated = (float)($slotalloc[(string)$slot->uuid] ?? 0.0);
            $available = max(0.0, (float)$slot->fte - $allocated);
            $status = $allocated <= 0.0001 ? 'vacant' : ($available > 0.0001 ? 'partial' : 'occupied');
            $slots[] = [
                'uuid' => (string)$slot->uuid,
                'code' => (string)$slot->code,
                'position_uuid' => (string)$slot->positionuuid,
                'position_name' => $position ? (string)$position->name : '',
                'org_node_name' => $node ? (string)$node['name'] : '',
                'fte' => (float)$slot->fte,
                'allocated_fte' => $allocated,
                'available_fte' => $available,
                'status' => $status,
                'employee_names' => $slotnames[(string)$slot->uuid] ?? [],
            ];
        }
        $editable = $plan && $currentplan && (string)$plan->uuid === (string)$currentplan->uuid;
        return [
            'company' => $company,
            'plans' => array_values(array_map([self::class, 'plan_dto'], $plans)),
            'plan' => $plan ? self::plan_dto($plan) : null,
            'current_plan_uuid' => $currentplan ? (string)$currentplan->uuid : '',
            'is_current_plan' => (bool)$editable,
            'slots' => $slots,
            'assignments' => $assignments,
            'history' => $history,
            'employees' => array_values($employees),
            'summary' => [
                'planned_fte' => $planned,
                'occupied_fte' => $occupied,
                'vacant_fte' => max(0.0, $planned - $occupied),
                'overstaffed_fte' => max(0.0, $occupied - $planned),
                'unplaced_fte' => $unplaced,
                'slots' => count($slots),
                'assignments' => count($assignments),
            ],
            'can_manage' => $editable && organizing_board_service::can_manage($companyuuid, $userid),
        ];
    }

    /** Backward-compatible quick assignment: consume the whole slot capacity. */
    public static function assign_slot(string $companyuuid, int $userid, string $slotuuid,
            int $employeeid, string $assignmenttype): int {
        global $DB;
        $slot = $DB->get_record('local_kbacompany_staffslot',
            ['companyuuid' => $companyuuid, 'uuid' => $slotuuid, 'active' => 1], '*', MUST_EXIST);
        return self::save_slot_assignment($companyuuid, $userid, 0, [
            'slotuuid' => $slotuuid,
            'employeeid' => $employeeid,
            'assignmenttype' => $assignmenttype,
            'assignmentfte' => (float)$slot->fte,
            'effectivefrom' => '',
            'effectiveto' => '',
        ]);
    }

    /** Create or update one factual staffing assignment. No historical row is deleted. */
    public static function save_slot_assignment(string $companyuuid, int $userid, int $assignmentid, array $data): int {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $slotuuid = trim((string)($data['slotuuid'] ?? ''));
        $slot = $DB->get_record('local_kbacompany_staffslot',
            ['companyuuid' => $companyuuid, 'uuid' => $slotuuid, 'active' => 1], '*', MUST_EXIST);
        $plan = $DB->get_record('local_kbacompany_staffplan',
            ['companyuuid' => $companyuuid, 'uuid' => $slot->planuuid, 'status' => 'approved'], '*', MUST_EXIST);
        $current = self::current_approved_plan($companyuuid, (string)$plan->branchuuid);
        if (!$current || (string)$current->uuid !== (string)$plan->uuid) {
            throw new \moodle_exception('staffingassignmenteditcurrentonly', 'local_kbacompany');
        }
        $employeeid = (int)($data['employeeid'] ?? 0);
        $eligible = self::placement_employees($companyuuid, $userid);
        if (!isset($eligible[$employeeid])) {
            throw new \moodle_exception('employeenotavailable', 'local_kbacompany');
        }
        $assignmenttype = trim((string)($data['assignmenttype'] ?? 'primary'));
        if (!in_array($assignmenttype, self::ASSIGNMENT_TYPES, true)) {
            throw new \moodle_exception('invalidassignmenttype', 'local_kbacompany');
        }
        $assignmentfte = round((float)($data['assignmentfte'] ?? 0), 2);
        if ($assignmentfte <= 0.0 || $assignmentfte > (float)$slot->fte + 0.0001) {
            throw new \moodle_exception('staffingassignmentinvalidfte', 'local_kbacompany');
        }
        $existing = null;
        if ($assignmentid > 0) {
            $existing = $DB->get_record('local_kbacompany_posassign',
                ['id' => $assignmentid, 'companyuuid' => $companyuuid, 'active' => 1], '*', MUST_EXIST);
            if ((int)$existing->effectiveto > 0 && (int)$existing->effectiveto < time()) {
                throw new \moodle_exception('staffingassignmenteditcurrentonly', 'local_kbacompany');
            }
        }
        $startfallback = $existing ? (int)$existing->effectivefrom : time();
        $effectivefrom = self::parse_assignment_date((string)($data['effectivefrom'] ?? ''), false, $startfallback);
        $effectiveto = self::parse_assignment_date((string)($data['effectiveto'] ?? ''), true, 0);
        if ($effectiveto > 0 && $effectiveto < $effectivefrom) {
            throw new \moodle_exception('staffingassignmentinvaliddates', 'local_kbacompany');
        }
        if ($assignmenttype === 'primary' && self::has_overlapping_primary_assignment(
                $companyuuid, $employeeid, $effectivefrom, $effectiveto, $assignmentid)) {
            throw new \moodle_exception('staffingassignmentprimaryoverlap', 'local_kbacompany');
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_kbacompany_staffing');
        $lock = $lockfactory->get_lock('slot:' . $slotuuid, 10);
        if (!$lock) { throw new \moodle_exception('staffinglockfailed', 'local_kbacompany'); }
        try {
            $reserved = self::peak_slot_allocation($companyuuid, $slotuuid, $effectivefrom, $effectiveto, $assignmentid);
            if ($reserved + $assignmentfte > (float)$slot->fte + 0.0001) {
                throw new \moodle_exception('staffingassignmentcapacityexceeded', 'local_kbacompany');
            }
            $transaction = $DB->start_delegated_transaction();
            $now = time();
            $oldslotuuid = $existing ? (string)$existing->slotuuid : '';
            $istransfer = $existing && (string)$existing->slotuuid !== $slotuuid;
            if ($istransfer) {
                if ($effectivefrom < (int)$existing->effectivefrom) {
                    throw new \moodle_exception('staffingassignmentinvaliddates', 'local_kbacompany');
                }
                // A transfer is history, not mutation: close the old row and create a new assignment row.
                $existing->effectiveto = max(0, $effectivefrom - 1);
                $existing->active = $effectivefrom <= $now ? 0 : 1;
                $existing->timemodified = $now;
                $DB->update_record('local_kbacompany_posassign', $existing);
                $id = (int)$DB->insert_record('local_kbacompany_posassign', (object)[
                    'companyuuid' => $companyuuid,
                    'positionuuid' => (string)$slot->positionuuid,
                    'slotuuid' => $slotuuid,
                    'userid' => (int)$existing->userid,
                    'assignmenttype' => $assignmenttype,
                    'assignmentfte' => $assignmentfte,
                    'effectivefrom' => $effectivefrom,
                    'effectiveto' => $effectiveto,
                    'active' => 1,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            } else if ($existing) {
                $existing->assignmenttype = $assignmenttype;
                $existing->assignmentfte = $assignmentfte;
                $existing->effectivefrom = $effectivefrom;
                $existing->effectiveto = $effectiveto;
                $existing->timemodified = $now;
                $DB->update_record('local_kbacompany_posassign', $existing);
                $id = (int)$existing->id;
            } else {
                $id = (int)$DB->insert_record('local_kbacompany_posassign', (object)[
                    'companyuuid' => $companyuuid,
                    'positionuuid' => (string)$slot->positionuuid,
                    'slotuuid' => $slotuuid,
                    'userid' => $employeeid,
                    'assignmenttype' => $assignmenttype,
                    'assignmentfte' => $assignmentfte,
                    'effectivefrom' => $effectivefrom,
                    'effectiveto' => $effectiveto,
                    'active' => 1,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
            self::refresh_slot_status($companyuuid, $slotuuid);
            if ($oldslotuuid !== '' && $oldslotuuid !== $slotuuid) {
                self::refresh_slot_status($companyuuid, $oldslotuuid);
            }
            $transaction->allow_commit();
            return $id;
        } finally {
            $lock->release();
        }
    }

    public static function end_slot_assignment(string $companyuuid, int $userid, int $assignmentid): void {
        global $DB;
        self::require_manage($companyuuid, $userid);
        $assignment = $DB->get_record('local_kbacompany_posassign',
            ['id' => $assignmentid, 'companyuuid' => $companyuuid, 'active' => 1], '*', MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        $assignment->active = 0;
        $assignment->effectiveto = time();
        $assignment->timemodified = time();
        $DB->update_record('local_kbacompany_posassign', $assignment);
        if ((string)$assignment->slotuuid !== '') {
            self::refresh_slot_status($companyuuid, (string)$assignment->slotuuid);
        }
        $transaction->allow_commit();
    }

    /** Current open staffing assignments attached to slots of one exact approved plan. */
    /**
     * Preview how current factual assignments will map to slots generated by a draft plan.
     * This intentionally mirrors approve_plan()/carry_forward_slot_assignments(): only equal-FTE
     * slots of the same canonical position are considered compatible. No assignment is ended here.
     */
    private static function approval_impact_for_draft(string $companyuuid, \stdClass $previous, \stdClass $draft): array {
        global $DB;

        $assignments = self::current_slot_assignments_for_plan($companyuuid, (string)$previous->uuid);
        $result = [
            'total_assignments' => count($assignments),
            'total_fte' => 0.0,
            'carried_assignments' => 0,
            'carried_fte' => 0.0,
            'conflict_count' => 0,
            'conflict_fte' => 0.0,
            'conflicts' => [],
        ];
        if (!$assignments) {
            return $result;
        }

        $capacity = [];
        foreach ($DB->get_records('local_kbacompany_staffline', [
                'companyuuid' => $companyuuid,
                'planuuid' => (string)$draft->uuid,
            ], 'id ASC', 'id,positionuuid,plannedfte') as $line) {
            $remaining = (float)$line->plannedfte;
            while ($remaining > 0.0001) {
                $fte = min(1.0, $remaining);
                $capacity[(string)$line->positionuuid][] = $fte;
                $remaining -= $fte;
            }
        }

        $positionuuids = [];
        $userids = [];
        foreach ($assignments as $assignment) {
            $positionuuids[(string)$assignment->positionuuid] = true;
            $userids[(int)$assignment->userid] = true;
        }
        $positions = [];
        if ($positionuuids) {
            list($insql, $params) = $DB->get_in_or_equal(array_keys($positionuuids), SQL_PARAMS_NAMED, 'impactposition');
            $sql = "SELECT uuid, code, name
                      FROM {local_kbacompany_position}
                     WHERE companyuuid = :companyuuid
                       AND uuid $insql";
            $params['companyuuid'] = $companyuuid;
            foreach ($DB->get_records_sql($sql, $params) as $position) {
                $positions[(string)$position->uuid] = $position;
            }
        }
        $users = [];
        if ($userids) {
            foreach ($DB->get_records_list('user', 'id', array_keys($userids), '', 'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename') as $user) {
                $users[(int)$user->id] = fullname($user);
            }
        }

        foreach ($assignments as $assignment) {
            $fte = max(0.0, (float)$assignment->assignmentfte);
            $result['total_fte'] += $fte;
            $positionuuid = (string)$assignment->positionuuid;
            $placed = false;
            if (!empty($capacity[$positionuuid])) {
                foreach ($capacity[$positionuuid] as $index => $freefte) {
                    if ((float)$freefte + 0.0001 >= $fte) {
                        $capacity[$positionuuid][$index] = max(0.0, (float)$freefte - $fte);
                        $placed = true;
                        break;
                    }
                }
            }
            if ($placed) {
                $result['carried_assignments']++;
                $result['carried_fte'] += $fte;
                continue;
            }

            $position = $positions[$positionuuid] ?? null;
            $reason = empty($capacity[$positionuuid]) ? 'removed' : 'capacity';
            $result['conflict_count']++;
            $result['conflict_fte'] += $fte;
            $result['conflicts'][] = [
                'assignment_id' => (int)$assignment->assignmentid,
                'employee_id' => (int)$assignment->userid,
                'employee_name' => $users[(int)$assignment->userid] ?? ('#' . (int)$assignment->userid),
                'position_uuid' => $positionuuid,
                'position_name' => $position ? (string)$position->name : $positionuuid,
                'position_code' => $position ? (string)$position->code : '',
                'fte' => $fte,
                'reason' => $reason,
            ];
        }

        return $result;
    }

    private static function current_slot_assignments_for_plan(string $companyuuid, string $planuuid): array {
        global $DB;
        $now = time();
        $sql = "SELECT a.id AS assignmentid, a.slotuuid, a.positionuuid, a.userid, a.assignmenttype,
                       a.assignmentfte, a.effectivefrom, a.effectiveto, s.fte AS slotfte, s.code AS slotcode
                  FROM {local_kbacompany_posassign} a
                  JOIN {local_kbacompany_staffslot} s
                    ON s.companyuuid = a.companyuuid
                   AND s.uuid = a.slotuuid
                 WHERE a.companyuuid = :companyuuid
                   AND s.planuuid = :planuuid
                   AND a.active = 1
                   AND (a.effectivefrom = 0 OR a.effectivefrom <= :nowfrom)
                   AND (a.effectiveto = 0 OR a.effectiveto >= :nowto)
              ORDER BY a.positionuuid ASC, s.code ASC, a.id ASC";
        return array_values($DB->get_records_sql($sql, [
            'companyuuid' => $companyuuid,
            'planuuid' => $planuuid,
            'nowfrom' => $now,
            'nowto' => $now,
        ]));
    }

    /**
     * Move factual assignments to compatible slots of the newly approved version without ending them.
     * Only equal-FTE slots are paired: changing the staffing norm must never silently change an employee's FTE.
     * Assignments without a compatible slot remain active on their historical slot and are exposed as overstaffing.
     */
    private static function carry_forward_slot_assignments(string $companyuuid, array $assignments, array $newslots): void {
        global $DB;
        $available = [];
        foreach ($newslots as $positionuuid => $slots) {
            foreach ($slots as $slot) {
                $available[(string)$positionuuid][] = ['slot' => $slot, 'free' => (float)$slot->fte];
            }
        }
        foreach ($assignments as $assignment) {
            $positionuuid = (string)$assignment->positionuuid;
            $assignmentfte = max(0.0, (float)$assignment->assignmentfte);
            $slot = null;
            if (!empty($available[$positionuuid])) {
                foreach ($available[$positionuuid] as $index => $candidate) {
                    if ((float)$candidate['free'] + 0.0001 >= $assignmentfte) {
                        $slot = $candidate['slot'];
                        $available[$positionuuid][$index]['free'] = max(0.0, (float)$candidate['free'] - $assignmentfte);
                        break;
                    }
                }
            }
            if (!$slot) {
                continue;
            }
            $record = $DB->get_record('local_kbacompany_posassign', [
                'id' => (int)$assignment->assignmentid,
                'companyuuid' => $companyuuid,
                'active' => 1,
            ], '*', MUST_EXIST);
            $record->slotuuid = (string)$slot->uuid;
            $record->timemodified = time();
            $DB->update_record('local_kbacompany_posassign', $record);
            $slotrecord = $DB->get_record('local_kbacompany_staffslot', [
                'companyuuid' => $companyuuid,
                'uuid' => (string)$slot->uuid,
            ], '*', MUST_EXIST);
            $slotrecord->status = 'occupied';
            $slotrecord->timemodified = time();
            $DB->update_record('local_kbacompany_staffslot', $slotrecord);
        }
    }

    /** Current factual occupied FTE for positions contained in one staffing-plan version. */
    private static function current_occupancy_for_plan(string $companyuuid, string $planuuid): array {
        global $DB;
        $now = time();
        $sql = "SELECT l.positionuuid, COALESCE(SUM(a.assignmentfte), 0) AS occupiedfte
                  FROM {local_kbacompany_staffline} l
                  JOIN {local_kbacompany_posassign} a
                    ON a.companyuuid = l.companyuuid
                   AND a.positionuuid = l.positionuuid
                   AND a.active = 1
                   AND a.slotuuid <> ''
                  JOIN {local_kbacompany_staffslot} s
                    ON s.companyuuid = a.companyuuid
                   AND s.uuid = a.slotuuid
                 WHERE l.companyuuid = :companyuuid
                   AND l.planuuid = :planuuid
                   AND (a.effectivefrom = 0 OR a.effectivefrom <= :nowfrom)
                   AND (a.effectiveto = 0 OR a.effectiveto >= :nowto)
              GROUP BY l.positionuuid";
        $records = $DB->get_records_sql($sql, [
            'companyuuid' => $companyuuid,
            'planuuid' => $planuuid,
            'nowfrom' => $now,
            'nowto' => $now,
        ]);
        $byposition = [];
        $total = 0.0;
        foreach ($records as $record) {
            $fte = (float)$record->occupiedfte;
            $byposition[(string)$record->positionuuid] = $fte;
            $total += $fte;
        }
        return ['by_position' => $byposition, 'total' => $total];
    }

    private static function has_overlapping_primary_assignment(string $companyuuid, int $employeeid,
            int $start, int $end, int $excludeid = 0): bool {
        global $DB;
        $params = ['companyuuid' => $companyuuid, 'employeeid' => $employeeid, 'start' => $start];
        $select = "companyuuid = :companyuuid AND userid = :employeeid AND assignmenttype = 'primary' AND active = 1
                   AND (effectiveto = 0 OR effectiveto >= :start)";
        if ($end > 0) {
            $select .= " AND (effectivefrom = 0 OR effectivefrom <= :finish)";
            $params['finish'] = $end;
        }
        if ($excludeid > 0) {
            $select .= " AND id <> :excludeid";
            $params['excludeid'] = $excludeid;
        }
        return $DB->record_exists_select('local_kbacompany_posassign', $select, $params);
    }

    /** Maximum already allocated FTE in a slot during a requested interval. */
    private static function peak_slot_allocation(string $companyuuid, string $slotuuid,
            int $start, int $end, int $excludeid = 0): float {
        global $DB;
        $params = ['companyuuid' => $companyuuid, 'slotuuid' => $slotuuid, 'start' => $start];
        $select = "companyuuid = :companyuuid AND slotuuid = :slotuuid AND active = 1
                   AND (effectiveto = 0 OR effectiveto >= :start)";
        if ($end > 0) {
            $select .= " AND (effectivefrom = 0 OR effectivefrom <= :finish)";
            $params['finish'] = $end;
        }
        if ($excludeid > 0) {
            $select .= " AND id <> :excludeid";
            $params['excludeid'] = $excludeid;
        }
        $records = $DB->get_records_select('local_kbacompany_posassign', $select, $params,
            'effectivefrom ASC, id ASC', 'id,assignmentfte,effectivefrom,effectiveto');
        if (!$records) { return 0.0; }
        $points = [$start => true];
        foreach ($records as $record) {
            $point = max($start, (int)$record->effectivefrom);
            if ($end === 0 || $point <= $end) { $points[$point] = true; }
        }
        $peak = 0.0;
        foreach (array_keys($points) as $point) {
            $sum = 0.0;
            foreach ($records as $record) {
                if (((int)$record->effectivefrom === 0 || (int)$record->effectivefrom <= $point) &&
                        ((int)$record->effectiveto === 0 || (int)$record->effectiveto >= $point)) {
                    $sum += max(0.0, (float)$record->assignmentfte);
                }
            }
            $peak = max($peak, $sum);
        }
        return $peak;
    }

    /** Keep the denormalised slot status aligned with current factual allocation. */
    private static function refresh_slot_status(string $companyuuid, string $slotuuid): void {
        global $DB;
        $slot = $DB->get_record('local_kbacompany_staffslot',
            ['companyuuid' => $companyuuid, 'uuid' => $slotuuid]);
        if (!$slot) { return; }
        $now = time();
        $sql = "SELECT COALESCE(SUM(assignmentfte), 0)
                  FROM {local_kbacompany_posassign}
                 WHERE companyuuid = :companyuuid AND slotuuid = :slotuuid AND active = 1
                   AND (effectivefrom = 0 OR effectivefrom <= :nowfrom)
                   AND (effectiveto = 0 OR effectiveto >= :nowto)";
        $allocated = (float)$DB->get_field_sql($sql, [
            'companyuuid' => $companyuuid, 'slotuuid' => $slotuuid, 'nowfrom' => $now, 'nowto' => $now,
        ]);
        $slot->status = $allocated > 0.0001 ? 'occupied' : 'vacant';
        $slot->timemodified = $now;
        $DB->update_record('local_kbacompany_staffslot', $slot);
    }

    private static function parse_assignment_date(string $value, bool $endofday, int $fallback = 0): int {
        $value = trim($value);
        if ($value === '') { return $fallback; }
        if (!preg_match('/^(\\d{4})-(\\d{2})-(\\d{2})$/', $value, $m) ||
                !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new \moodle_exception('invalidstaffplandate', 'local_kbacompany');
        }
        return make_timestamp((int)$m[1], (int)$m[2], (int)$m[3],
            $endofday ? 23 : 0, $endofday ? 59 : 0, $endofday ? 59 : 0);
    }

    private static function require_draft_plan(string $companyuuid, string $planuuid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_staffplan',
            ['companyuuid' => $companyuuid, 'uuid' => $planuuid, 'status' => 'draft'], '*', MUST_EXIST);
    }

    private static function require_manage(string $companyuuid, int $userid): void {
        if (!organizing_board_service::can_manage($companyuuid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
    }

    private static function plan_dto(\stdClass $plan): array {
        return ['uuid' => (string)$plan->uuid, 'branch_uuid' => (string)$plan->branchuuid,
            'name' => (string)$plan->name, 'status' => (string)$plan->status,
            'currency' => (string)$plan->currency, 'approved_by' => (int)$plan->approvedby,
            'time_approved' => (int)$plan->timeapproved, 'effective_from' => (int)$plan->effectivefrom,
            'effective_to' => (int)$plan->effectiveto];
    }

    private static function current_approved_plan(string $companyuuid, string $branchuuid): ?\stdClass {
        global $DB;
        $records = $DB->get_records('local_kbacompany_staffplan',
            ['companyuuid' => $companyuuid, 'branchuuid' => $branchuuid, 'status' => 'approved'],
            'timeapproved DESC, id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    private static function previous_approved_plan(string $companyuuid, string $branchuuid, string $excludeuuid): ?\stdClass {
        global $DB;
        $sql = "SELECT * FROM {local_kbacompany_staffplan}
                 WHERE companyuuid = :companyuuid AND branchuuid = :branchuuid
                   AND status = :status AND uuid <> :excludeuuid
              ORDER BY timeapproved DESC, id DESC";
        $records = $DB->get_records_sql($sql, ['companyuuid' => $companyuuid, 'branchuuid' => $branchuuid,
            'status' => 'approved', 'excludeuuid' => $excludeuuid], 0, 1);
        return $records ? reset($records) : null;
    }

    private static function compare_plan_records(string $companyuuid, \stdClass $old, \stdClass $new): array {
        global $DB;
        $oldlines = $DB->get_records('local_kbacompany_staffline',
            ['companyuuid' => $companyuuid, 'planuuid' => $old->uuid], '', 'positionuuid,plannedfte,salarymin,salarymax,budgetamount');
        $newlines = $DB->get_records('local_kbacompany_staffline',
            ['companyuuid' => $companyuuid, 'planuuid' => $new->uuid], '', 'positionuuid,plannedfte,salarymin,salarymax,budgetamount');
        $positions = $DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid], '', 'uuid,code,name');
        $result = ['added' => [], 'changed' => [], 'removed' => []];
        foreach ($newlines as $key => $line) {
            $label = isset($positions[$key]) ? trim((string)$positions[$key]->code . ' — ' . (string)$positions[$key]->name) : $key;
            if (!isset($oldlines[$key])) {
                $result['added'][] = ['position_uuid' => (string)$key, 'position' => $label, 'new' => self::line_values($line)];
                continue;
            }
            $before = self::line_values($oldlines[$key]);
            $after = self::line_values($line);
            if ($before !== $after) {
                $result['changed'][] = ['position_uuid' => (string)$key, 'position' => $label, 'old' => $before, 'new' => $after];
            }
        }
        foreach ($oldlines as $key => $line) {
            if (!isset($newlines[$key])) {
                $label = isset($positions[$key]) ? trim((string)$positions[$key]->code . ' — ' . (string)$positions[$key]->name) : $key;
                $result['removed'][] = ['position_uuid' => (string)$key, 'position' => $label, 'old' => self::line_values($line)];
            }
        }
        return $result;
    }

    private static function line_values(\stdClass $line): array {
        return ['fte' => (float)$line->plannedfte, 'salary_min' => (float)$line->salarymin,
            'salary_max' => (float)$line->salarymax, 'budget' => (float)$line->budgetamount];
    }

    private static function placement_employees(string $companyuuid, int $userid): array {
        $directory = employee_service::employees_context($companyuuid, $userid);
        $employees = [];
        foreach ($directory['employees'] as $employee) {
            if (!$employee['active'] || (!in_array('employee', $employee['roles'], true) &&
                    $employee['membership_source'] !== 'local_kbacorporate_compatibility')) {
                continue;
            }
            $id = (int)$employee['user_id'];
            $employees[$id] = ['user_id' => $id, 'full_name' => $employee['full_name']];
        }
        return $employees;
    }

    private static function parse_effective_date(string $value, int $fallback = 0): int {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) ||
                !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new \moodle_exception('invalidstaffplandate', 'local_kbacompany');
        }
        return make_timestamp((int)$m[1], (int)$m[2], (int)$m[3], 0, 0, 0);
    }

    private static function is_current(\stdClass $assignment, int $now): bool {
        return !empty($assignment->active) &&
            ((int)$assignment->effectivefrom === 0 || (int)$assignment->effectivefrom <= $now) &&
            ((int)$assignment->effectiveto === 0 || (int)$assignment->effectiveto >= $now);
    }

    private static function uuid_v4(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' .
            substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
