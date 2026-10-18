<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Weekly management protocol read-model.
 *
 * Reuses canonical Meetings, Battle Plans, Indicators, Coordination, Finance and Policies.
 * The protocol is not a second plan, decision, finance or policy registry.
 */
class weekly_management_protocol_service {
    public const CONTRACT_VERSION = '0.8.6.4';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_weekly_management_protocol',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'meeting' => 'meeting_service',
                'preparation' => 'management_cycle_meeting_preparation_service',
                'battle_plans' => 'battle_plan_service + battle_plan_management_overview_service',
                'coordination' => 'battle_plan_coordination_service',
                'finance' => 'financial_statement_service + budgeting_service + resource_gate_service',
                'policy' => 'policy_service',
            ],
            'rules' => [
                'protocol_is_read_model_not_parallel_storage',
                'facts_and_quotas_are_read_from_canonical_indicators_and_battle_plans',
                'agreed_coordination_does_not_mutate_plans',
                'finance_is_shown_only_when_actor_has_existing_finance_access',
                'draft_policy_is_a_proposal_not_an_approved_policy',
                'meeting_decisions_remain_canonical_decision_objects',
                'generated_minutes_are_draft_text_and_never_auto_saved',
            ],
        ];
    }

    private static function previous_week(string $week): string {
        return (new \DateTimeImmutable($week, new \DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
    }

    private static function user_name(int $userid): string {
        global $DB;
        $user = $DB->get_record('user', ['id'=>$userid],
            'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
        return $user ? fullname($user) : (string)$userid;
    }

    private static function plan_block(string $companyuuid, int $actor, string $week): array {
        global $DB;
        $rows = [];
        $summary = ['plans'=>0, 'open_tasks'=>0, 'overdue_tasks'=>0];
        $today = gmdate('Y-m-d');
        foreach ($DB->get_records('local_kbacompany_bplan', [
            'companyuuid'=>$companyuuid, 'periodstart'=>$week,
        ], 'userid ASC, id ASC') as $plan) {
            $ctx = battle_plan_service::context($companyuuid, $actor, (string)$plan->uuid,
                (int)$plan->userid, $week, (string)$plan->positionuuid);
            $position = $DB->get_record('local_kbacompany_position', [
                'companyuuid'=>$companyuuid, 'uuid'=>(string)$plan->positionuuid,
            ], 'uuid,name,orgnodeuuid');
            $orgnode = null;
            if ($position && (string)$position->orgnodeuuid !== '') {
                $orgnode = $DB->get_record('local_kbacompany_orgnode', [
                    'companyuuid'=>$companyuuid, 'uuid'=>(string)$position->orgnodeuuid,
                ], 'uuid,name');
            }
            $items = [];
            $open = 0; $overdue = 0;
            foreach ((array)$ctx['items'] as $item) {
                $task = (array)$item['task'];
                $status = (string)($task['status'] ?? '');
                if (!in_array($status, ['done','cancelled'], true)) {
                    $open++;
                    $duedate = (string)($task['due_date'] ?? $task['duedate'] ?? '');
                    if ($duedate !== '' && $duedate < $today) { $overdue++; }
                }
                $items[] = [
                    'title'=>(string)($task['title'] ?? ''),
                    'product'=>(string)($task['product'] ?? ''),
                    'status'=>$status,
                    'plan_minutes'=>(int)($task['plan_minutes'] ?? 0),
                    'fact_minutes'=>(int)($task['fact_minutes'] ?? 0),
                ];
            }
            $rows[] = [
                'plan_uuid'=>(string)$plan->uuid,
                'status'=>(string)$plan->status,
                'employee_name'=>self::user_name((int)$plan->userid),
                'position_name'=>$position ? (string)$position->name : (string)$plan->positionuuid,
                'orgnode_name'=>$orgnode ? (string)$orgnode->name : '',
                'summary'=>(array)$ctx['summary'],
                'items'=>$items,
                'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php', [
                    'uuid'=>$companyuuid, 'plan'=>(string)$plan->uuid,
                ]))->out(false),
            ];
            $summary['plans']++;
            $summary['open_tasks'] += $open;
            $summary['overdue_tasks'] += $overdue;
        }
        return ['summary'=>$summary, 'rows'=>$rows];
    }

    private static function finance(string $companyuuid, int $actor, string $weekstart, string $weekend): array {
        $out = [
            'available'=>false, 'statements'=>[], 'approved_budgets'=>0, 'resource_gates'=>[],
            'statements_url'=>(new \moodle_url('/local/kbacompany/financial_statements.php', ['uuid'=>$companyuuid]))->out(false),
            'budgets_url'=>(new \moodle_url('/local/kbacompany/budgeting.php', ['uuid'=>$companyuuid]))->out(false),
            'resource_url'=>(new \moodle_url('/local/kbacompany/resource_gate.php', ['uuid'=>$companyuuid]))->out(false),
        ];
        try {
            $ctx = financial_statement_service::context($companyuuid, $actor);
            $out['available'] = true;
            foreach ((array)$ctx['statements'] as $statement) {
                if ((string)$statement['periodend'] < $weekstart || (string)$statement['periodstart'] > $weekend) { continue; }
                $summary = (array)($statement['summary'] ?? []);
                $revenue = null;
                if ((string)$statement['statementtype'] === 'bdr') {
                    $values = (array)($statement['values'] ?? []);
                    if (isset($values['REV'][1])) { $revenue = (float)$values['REV'][1]; }
                }
                $out['statements'][] = [
                    'code'=>(string)$statement['code'], 'name'=>(string)$statement['name'],
                    'type'=>(string)$statement['statementtype'], 'status'=>(string)$statement['status'],
                    'period_start'=>(string)$statement['periodstart'], 'period_end'=>(string)$statement['periodend'],
                    'currency'=>(string)$statement['currency'], 'revenue'=>$revenue, 'summary'=>$summary,
                ];
            }
            $budgets = budgeting_service::context($companyuuid, $actor);
            foreach ((array)$budgets['budgets'] as $budget) {
                if ((int)($budget['effectiveapprovedrevision'] ?? 0) > 0) { $out['approved_budgets']++; }
            }
            $resources = resource_gate_service::context($companyuuid, $actor);
            foreach ((array)$resources['gates'] as $gate) {
                $out['resource_gates'][] = [
                    'code'=>(string)($gate['code'] ?? ''), 'name'=>(string)($gate['name'] ?? ''),
                    'status'=>(string)($gate['status'] ?? ''),
                ];
            }
        } catch (\required_capability_exception $e) {
            // Existing finance ACL is authoritative. No escalation for the protocol read-model.
        } catch (\moodle_exception $e) {
            // Treat unavailable finance context as unavailable instead of weakening its domain rules.
        }
        return $out;
    }

    private static function policy_proposals(string $companyuuid, int $actor): array {
        $out = [];
        try {
            $ctx = policy_service::context($companyuuid, $actor);
            foreach ((array)$ctx['records'] as $row) {
                if ((string)$row->kind !== 'policy' || (string)$row->status !== 'draft') { continue; }
                $out[] = [
                    'uuid'=>(string)$row->uuid, 'revision'=>(int)$row->revision,
                    'code'=>(string)$row->code, 'title'=>(string)$row->title,
                    'purpose'=>(string)$row->purpose, 'basis'=>(string)$row->basis,
                    'url'=>(new \moodle_url('/local/kbacompany/policies.php', [
                        'uuid'=>$companyuuid, 'object'=>(string)$row->uuid,
                    ]))->out(false),
                ];
            }
        } catch (\moodle_exception $e) {
            // Policy ACL/domain remains authoritative.
        }
        return $out;
    }

    private static function minutes_draft(array $data): string {
        $lines = [];
        $lines[] = get_string('weeklyproto_draft_heading', 'local_kbacompany', (object)[
            'start'=>$data['week_start'], 'end'=>$data['week_end'],
        ]);
        $lines[] = '';
        $lines[] = get_string('weeklyproto_draft_results', 'local_kbacompany');
        $ps = $data['previous_plans']['summary'];
        $lines[] = '- ' . get_string('weeklyproto_draft_plan_summary', 'local_kbacompany', (object)[
            'plans'=>(int)$ps['plans'], 'open'=>(int)$ps['open_tasks'],
            'overdue'=>(int)$ps['overdue_tasks'],
        ]);
        $lines[] = '';
        $lines[] = get_string('weeklyproto_draft_coordination', 'local_kbacompany');
        foreach ($data['coordination']['agreed'] as $row) {
            $lines[] = '- ' . $row['source_label'] . ' → ' . $row['target_label'] . ': ' . $row['summary'];
        }
        if (!$data['coordination']['agreed']) { $lines[] = '- ' . get_string('weeklyproto_none', 'local_kbacompany'); }
        $lines[] = '';
        $lines[] = get_string('weeklyproto_draft_decisions', 'local_kbacompany');
        foreach ($data['decisions'] as $decision) {
            $lines[] = '- [' . $decision['status'] . '] ' . $decision['title'];
        }
        if (!$data['decisions']) { $lines[] = '- ' . get_string('weeklyproto_none', 'local_kbacompany'); }
        $lines[] = '';
        $lines[] = get_string('weeklyproto_draft_next', 'local_kbacompany');
        foreach ($data['current_plans']['rows'] as $row) {
            $lines[] = '- ' . trim($row['orgnode_name'] . ' / ' . $row['employee_name'], ' /') . ': ' .
                get_string('weeklyproto_draft_tasks', 'local_kbacompany', (object)[
                    'done'=>(int)$row['summary']['done'], 'total'=>(int)$row['summary']['tasks'],
                ]);
        }
        return implode("\n", $lines);
    }

    public static function context(string $companyuuid, int $actor, string $meetinguuid): array {
        company_context_service::context($companyuuid, $actor);
        if (!permission_service::can_manage_management($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
        $prep = management_cycle_meeting_preparation_service::context($companyuuid, $actor, $meetinguuid);
        if (!$prep) { throw new \moodle_exception('weeklyproto_notcycle', 'local_kbacompany'); }
        $meetingctx = meeting_service::context($companyuuid, $actor, $meetinguuid);
        $meeting = (array)$meetingctx['selected'];
        $weekstart = (string)$prep['week'];
        $weekend = (new \DateTimeImmutable($weekstart, new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
        $previous = self::previous_week($weekstart);

        $coord = battle_plan_coordination_service::context($companyuuid, $actor, $weekstart);
        $agreed = []; $open = [];
        foreach ((array)$coord['rows'] as $row) {
            if ((string)$row['status'] === 'agreed') { $agreed[] = $row; }
            else if ((string)$row['status'] === 'open') { $open[] = $row; }
        }
        $decisions = [];
        foreach ((array)($meeting['decisions'] ?? []) as $decision) {
            $decisions[] = [
                'uuid'=>(string)$decision['uuid'], 'title'=>(string)$decision['title'],
                'status'=>(string)$decision['status'], 'owner_name'=>(string)$decision['owner_name'],
                'expected_result'=>(string)$decision['expected_result'], 'tasks'=>(array)$decision['tasks'],
            ];
        }
        $data = [
            'meeting_uuid'=>$meetinguuid,
            'meeting_title'=>(string)$meeting['title'],
            'meeting_status'=>(string)$meeting['status'],
            'cycle_uuid'=>(string)$prep['cycle_uuid'], 'cycle_title'=>(string)$prep['cycle_title'],
            'checkpoint_title'=>(string)$prep['checkpoint_title'],
            'priority'=>(string)$prep['priority'], 'outcome'=>(string)$prep['outcome'],
            'week_start'=>$weekstart, 'week_end'=>$weekend, 'previous_week'=>$previous,
            'previous_plans'=>self::plan_block($companyuuid, $actor, $previous),
            'current_plans'=>self::plan_block($companyuuid, $actor, $weekstart),
            'indicators'=>(array)$prep['indicators'],
            'coordination'=>['agreed'=>$agreed, 'open'=>$open],
            'finance'=>self::finance($companyuuid, $actor, $weekstart, $weekend),
            'policy_proposals'=>self::policy_proposals($companyuuid, $actor),
            'decisions'=>$decisions,
            'meeting_url'=>(new \moodle_url('/local/kbacompany/meetings.php', ['uuid'=>$companyuuid,'meeting'=>$meetinguuid]))->out(false),
            'cycle_url'=>(string)$prep['cycle_url'],
            'contract'=>self::contract(),
        ];
        $data['minutes_draft'] = self::minutes_draft($data);
        return $data;
    }
}
