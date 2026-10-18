<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Human-reviewed period/advisory report read-model for one canonical Management Cycle. */
class management_cycle_report_service {
    public const CONTRACT_VERSION = '0.8.11';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_management_cycle_report',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'storage' => [],
            'rules' => [
                'report_is_read_model_not_canonical_fact',
                'service_delivery_is_separate_from_business_effect',
                'observed_indicator_change_is_not_claimed_as_causal_effect',
                'missing_fact_remains_missing_not_zero',
                'only_canonical_meetings_decisions_tasks_battleplans_indicators_a3_and_standardization_are_read',
                'draft_requires_human_review_before_external_use',
                'no_report_write_back_to_source_objects',
                'all_reads_are_company_scoped',
            ],
        ];
    }

    private static function require_manage(string $companyuuid, int $actor): array {
        $company = company_context_service::context($companyuuid, $actor);
        if (!access_role_service::can($companyuuid, $actor, 'management_cycle', 'view')) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
        return $company;
    }

    private static function user_name(int $userid): string {
        global $DB;
        if ($userid <= 0) { return ''; }
        $user = $DB->get_record('user', ['id'=>$userid],
            'id,firstname,lastname,firstnamephonetic,lastnamephonetic,middlename,alternatename');
        return $user ? fullname($user) : (string)$userid;
    }

    private static function cycle(string $companyuuid, string $cycleuuid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_mcycle', [
            'companyuuid'=>$companyuuid, 'uuid'=>$cycleuuid,
        ], '*', MUST_EXIST);
    }

    private static function indicator_rows(string $companyuuid, int $actor, \stdClass $cycle): array {
        $payload = json_decode((string)$cycle->statepayload, true);
        $indicatoruuids = is_array($payload) ? (array)($payload['indicatoruuids'] ?? []) : [];
        if (!$indicatoruuids) { return []; }
        $registry = indicator_registry_service::context($companyuuid, $actor);
        $meta = [];
        foreach ((array)($registry['metrics'] ?? []) as $metric) {
            $meta[(string)($metric['uuid'] ?? '')] = $metric;
        }
        $out = [];
        foreach ($indicatoruuids as $indicatoruuid) {
            $indicatoruuid = trim((string)$indicatoruuid);
            if ($indicatoruuid === '' || !isset($meta[$indicatoruuid])) { continue; }
            $metric = $meta[$indicatoruuid];
            $series = indicator_registry_service::series_for_metric($companyuuid, $indicatoruuid, 52);
            $facts = [];
            $plans = [];
            foreach ($series as $point) {
                $period = (string)($point['period'] ?? '');
                if ($period < (string)$cycle->periodstart || $period > (string)$cycle->periodend) { continue; }
                if (array_key_exists('fact', $point) && $point['fact'] !== null) {
                    $facts[] = ['period'=>$period, 'value'=>(string)$point['fact']];
                }
                if (array_key_exists('plan', $point) && $point['plan'] !== null) {
                    $plans[] = ['period'=>$period, 'value'=>(string)$point['plan']];
                }
            }
            $first = $facts ? reset($facts) : null;
            $last = $facts ? end($facts) : null;
            $latestplan = $plans ? end($plans) : null;
            $delta = null;
            if (count($facts) >= 2 && is_numeric($first['value']) && is_numeric($last['value'])) {
                $delta = (float)$last['value'] - (float)$first['value'];
            }
            $out[] = [
                'uuid'=>$indicatoruuid,
                'code'=>(string)($metric['code'] ?? ''),
                'name'=>(string)($metric['name'] ?? ''),
                'unit'=>(string)($metric['unit'] ?? ''),
                'direction'=>(string)($metric['direction'] ?? ''),
                'first_fact'=>$first,
                'last_fact'=>$last,
                'latest_plan'=>$latestplan,
                'delta'=>$delta,
                'fact_count'=>count($facts),
            ];
        }
        return $out;
    }

    private static function meetings(string $companyuuid, \stdClass $cycle): array {
        global $DB;
        $rows = [];
        $summary = ['checkpoints'=>0, 'checkpoints_done'=>0, 'meetings'=>0, 'meetings_closed'=>0,
            'accepted_decisions'=>0, 'proposed_decisions'=>0];
        $checkpoints = $DB->get_records('local_kbacompany_mcyclecp', [
            'companyuuid'=>$companyuuid, 'cycleuuid'=>$cycle->uuid,
        ], 'sequence ASC');
        foreach ($checkpoints as $cp) {
            $summary['checkpoints']++;
            if ((string)$cp->status === 'done') { $summary['checkpoints_done']++; }
            $entry = [
                'checkpointuuid'=>(string)$cp->uuid,
                'checkpoint'=>(string)$cp->title,
                'plannedat'=>(int)$cp->plannedat,
                'checkpoint_status'=>(string)$cp->status,
                'checkpoint_result'=>(string)$cp->result,
                'meeting'=>null,
            ];
            if ((string)$cp->meetinguuid !== '') {
                $meeting = $DB->get_record('local_kbacompany_meeting', [
                    'companyuuid'=>$companyuuid, 'uuid'=>(string)$cp->meetinguuid,
                ]);
                if ($meeting) {
                    $summary['meetings']++;
                    if ((string)$meeting->status === 'closed') { $summary['meetings_closed']++; }
                    $decisions = [];
                    foreach ($DB->get_records('local_kbacompany_decision', [
                        'companyuuid'=>$companyuuid, 'meetinguuid'=>(string)$meeting->uuid,
                    ], 'id ASC') as $decision) {
                        if ((string)$decision->status === 'accepted') { $summary['accepted_decisions']++; }
                        if ((string)$decision->status === 'proposed') { $summary['proposed_decisions']++; }
                        $decisions[] = [
                            'uuid'=>(string)$decision->uuid,
                            'title'=>(string)$decision->title,
                            'statement'=>(string)$decision->statement,
                            'expected_result'=>(string)$decision->expectedresult,
                            'status'=>(string)$decision->status,
                            'owner'=>(int)$decision->ownerid,
                            'owner_name'=>self::user_name((int)$decision->ownerid),
                        ];
                    }
                    $entry['meeting'] = [
                        'uuid'=>(string)$meeting->uuid,
                        'title'=>(string)$meeting->title,
                        'status'=>(string)$meeting->status,
                        'starts_at'=>(int)$meeting->startsat,
                        'closed_at'=>(int)$meeting->closedat,
                        'minutes'=>(string)$meeting->minutes,
                        'result'=>(string)$meeting->result,
                        'decisions'=>$decisions,
                        'url'=>(new \moodle_url('/local/kbacompany/meetings.php', [
                            'uuid'=>$companyuuid, 'meeting'=>(string)$meeting->uuid,
                        ]))->out(false),
                    ];
                }
            }
            $rows[] = $entry;
        }
        return ['rows'=>$rows, 'summary'=>$summary];
    }

    private static function execution(string $companyuuid, \stdClass $cycle): array {
        global $DB;
        $plans = [];
        $tasks = [];
        $seen = [];
        $summary = ['plans'=>0, 'plans_approved'=>0, 'plans_closed'=>0, 'tasks'=>0, 'tasks_done'=>0,
            'tasks_open'=>0, 'plan_minutes'=>0, 'fact_minutes'=>0];
        $planrows = $DB->get_records_select('local_kbacompany_bplan',
            'companyuuid = :c AND userid = :u AND periodstart >= :s AND periodstart <= :e', [
                'c'=>$companyuuid, 'u'=>(int)$cycle->ownerid,
                's'=>(string)$cycle->periodstart, 'e'=>(string)$cycle->periodend,
            ], 'periodstart ASC');
        foreach ($planrows as $plan) {
            $summary['plans']++;
            if ((string)$plan->status === 'active') { $summary['plans_approved']++; }
            if ((string)$plan->status === 'closed') { $summary['plans_closed']++; }
            $plans[] = [
                'uuid'=>(string)$plan->uuid,
                'periodstart'=>(string)$plan->periodstart,
                'periodend'=>(string)$plan->periodend,
                'status'=>(string)$plan->status,
                'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php', [
                    'uuid'=>$companyuuid, 'plan'=>(string)$plan->uuid,
                ]))->out(false),
            ];
            foreach ($DB->get_records('local_kbacompany_bplanitem', [
                'companyuuid'=>$companyuuid, 'planuuid'=>(string)$plan->uuid, 'provider'=>'bms',
            ], 'sortorder ASC, id ASC') as $item) {
                $taskuuid = trim((string)$item->taskuuid);
                if ($taskuuid === '' || isset($seen[$taskuuid])) { continue; }
                $task = $DB->get_record('local_kbacompany_task', [
                    'companyuuid'=>$companyuuid, 'uuid'=>$taskuuid,
                ]);
                if (!$task) { continue; }
                $seen[$taskuuid] = true;
                $summary['tasks']++;
                $summary['plan_minutes'] += (int)$task->planminutes;
                $summary['fact_minutes'] += (int)$task->factminutes;
                if ((string)$task->status === 'done') { $summary['tasks_done']++; }
                if (!in_array((string)$task->status, ['done','cancelled','deleted'], true)) { $summary['tasks_open']++; }
                $tasks[] = [
                    'uuid'=>(string)$task->uuid,
                    'title'=>(string)$task->title,
                    'product'=>(string)$task->product,
                    'status'=>(string)$task->status,
                    'result'=>(string)$task->result,
                    'duedate'=>(string)$task->duedate,
                    'planminutes'=>(int)$task->planminutes,
                    'factminutes'=>(int)$task->factminutes,
                    'sourcekind'=>(string)$task->sourcekind,
                    'url'=>(new \moodle_url('/local/kbacompany/tasks.php', [
                        'uuid'=>$companyuuid, 'task'=>(string)$task->uuid,
                    ]))->out(false),
                ];
            }
        }
        return ['plans'=>$plans, 'tasks'=>$tasks, 'summary'=>$summary];
    }

    private static function a3(string $companyuuid, \stdClass $cycle): array {
        global $DB;
        $rows = [];
        $summary = ['total'=>0, 'closed'=>0, 'confirmed_standardizations'=>0];
        $a3rows = $DB->get_records('local_kbacompany_a3review', [
            'companyuuid'=>$companyuuid, 'cycleuuid'=>(string)$cycle->uuid,
        ], 'timecreated ASC');
        foreach ($a3rows as $a3) {
            $summary['total']++;
            if ((string)$a3->status === 'closed') { $summary['closed']++; }
            $standards = [];
            foreach ($DB->get_records('local_kbacompany_a3std', [
                'companyuuid'=>$companyuuid, 'a3uuid'=>(string)$a3->uuid,
            ], 'timecreated ASC') as $std) {
                if ((string)$std->status === 'confirmed') { $summary['confirmed_standardizations']++; }
                $standards[] = [
                    'targetkind'=>(string)$std->targetkind,
                    'targetlabel'=>(string)$std->targetlabel,
                    'proposal'=>(string)$std->proposal,
                    'status'=>(string)$std->status,
                ];
            }
            $rows[] = [
                'uuid'=>(string)$a3->uuid,
                'title'=>(string)$a3->title,
                'status'=>(string)$a3->status,
                'problem'=>(string)$a3->problemcontext,
                'baseline_fact'=>(string)$a3->baselinefact,
                'baseline_period'=>(string)$a3->baselineperiod,
                'target_fact'=>(string)$a3->targetfact,
                'repeat_fact'=>(string)$a3->repeatfact,
                'repeat_period'=>(string)$a3->repeatperiod,
                'conclusion'=>(string)$a3->conclusion,
                'standardization'=>(string)$a3->standardization,
                'standardizations'=>$standards,
                'url'=>(new \moodle_url('/local/kbacompany/management_a3.php', [
                    'uuid'=>$companyuuid, 'a3'=>(string)$a3->uuid,
                ]))->out(false),
            ];
        }
        return ['rows'=>$rows, 'summary'=>$summary];
    }

    private static function next_cycles(string $companyuuid, string $cycleuuid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('local_kbacompany_mcycle', ['companyuuid'=>$companyuuid], 'periodstart ASC') as $row) {
            $payload = json_decode((string)$row->statepayload, true);
            $rollover = is_array($payload) ? (array)($payload['rollover'] ?? []) : [];
            if ((string)($rollover['sourcecycleuuid'] ?? '') !== $cycleuuid) { continue; }
            $out[] = [
                'uuid'=>(string)$row->uuid, 'title'=>(string)$row->title,
                'periodstart'=>(string)$row->periodstart, 'periodend'=>(string)$row->periodend,
                'status'=>(string)$row->status,
                'url'=>(new \moodle_url('/local/kbacompany/management_cycles.php', [
                    'uuid'=>$companyuuid, 'cycle'=>(string)$row->uuid,
                ]))->out(false),
            ];
        }
        return $out;
    }

    private static function draft_text(array $ctx): string {
        $c = $ctx['cycle'];
        $lines = [];
        $lines[] = get_string('mcycle_report_draft_heading','local_kbacompany');
        $lines[] = (string)$c['title'] . ' · ' . (string)$c['periodstart'] . ' — ' . (string)$c['periodend'];
        $lines[] = '';
        $lines[] = get_string('mcycle_report_draft_delivery','local_kbacompany');
        $lines[] = get_string('mcycle_report_draft_sessions','local_kbacompany', (object)[
            'closed'=>$ctx['meetings']['summary']['meetings_closed'],
            'total'=>$ctx['meetings']['summary']['meetings'],
        ]);
        $lines[] = get_string('mcycle_report_draft_decisions','local_kbacompany', $ctx['meetings']['summary']['accepted_decisions']);
        $lines[] = get_string('mcycle_report_draft_plans','local_kbacompany', $ctx['execution']['summary']['plans']);
        $lines[] = '';
        $lines[] = get_string('mcycle_report_draft_effect','local_kbacompany');
        if (!$ctx['indicators']) {
            $lines[] = get_string('mcycle_report_no_indicator_facts','local_kbacompany');
        } else {
            foreach ($ctx['indicators'] as $metric) {
                $first = $metric['first_fact'] ? $metric['first_fact']['value'] : '—';
                $last = $metric['last_fact'] ? $metric['last_fact']['value'] : '—';
                $lines[] = '- ' . $metric['name'] . ': ' . $first . ' → ' . $last . ' ' . $metric['unit'];
            }
        }
        $lines[] = '';
        $lines[] = get_string('mcycle_report_draft_execution','local_kbacompany');
        $lines[] = get_string('mcycle_report_draft_tasks','local_kbacompany', (object)[
            'done'=>$ctx['execution']['summary']['tasks_done'],
            'total'=>$ctx['execution']['summary']['tasks'],
            'open'=>$ctx['execution']['summary']['tasks_open'],
        ]);
        $lines[] = get_string('mcycle_report_draft_a3','local_kbacompany', (object)[
            'closed'=>$ctx['a3']['summary']['closed'],
            'total'=>$ctx['a3']['summary']['total'],
            'std'=>$ctx['a3']['summary']['confirmed_standardizations'],
        ]);
        $lines[] = '';
        $lines[] = get_string('mcycle_report_draft_note','local_kbacompany');
        return implode("\n", $lines);
    }

    public static function context(string $companyuuid, int $actor, string $cycleuuid): array {
        $company = self::require_manage($companyuuid, $actor);
        $cycle = self::cycle($companyuuid, $cycleuuid);
        $cycleprojection = management_cycle_template_service::context($companyuuid, $actor, $cycleuuid)['selected'];
        $meetings = self::meetings($companyuuid, $cycle);
        $execution = self::execution($companyuuid, $cycle);
        $a3 = self::a3($companyuuid, $cycle);
        $ctx = [
            'company'=>$company,
            'cycle'=>$cycleprojection,
            'is_advisory'=>(string)$cycle->templatecode === 'advisory4x90',
            'meetings'=>$meetings,
            'execution'=>$execution,
            'indicators'=>self::indicator_rows($companyuuid, $actor, $cycle),
            'a3'=>$a3,
            'next_cycles'=>self::next_cycles($companyuuid, $cycleuuid),
            'contract'=>self::contract(),
        ];
        $ctx['draft_text'] = self::draft_text($ctx);
        return $ctx;
    }
}
