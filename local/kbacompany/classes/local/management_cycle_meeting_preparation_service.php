<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Read model that prepares a checkpoint meeting from canonical Battle Plans and Indicators. */
class management_cycle_meeting_preparation_service {
    public const CONTRACT_VERSION = '0.8.6.1';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_management_cycle_meeting_preparation',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'reads' => [
                'cycle' => 'local_kbacompany_mcycle + local_kbacompany_mcyclecp',
                'meeting' => 'local_kbacompany_meeting',
                'battle_plan' => 'local_kbacompany_bplan + local_kbacompany_bplanitem',
                'indicators' => 'canonical Indicator Registry via Battle Plan projection',
            ],
            'rules' => [
                'read_model_only',
                'no_parallel_task_or_indicator_storage',
                'previous_and_current_plans_remain_canonical_battle_plans',
                'quota_is_existing_indicator_norm',
                'unfinished_commitments_are_existing_plan_tasks',
                'suggested_agenda_does_not_auto_create_decisions_or_tasks',
            ],
        ];
    }

    private static function plan_rows(string $companyuuid, int $actor, int $ownerid, string $week): array {
        global $DB;
        $rows = [];
        foreach ($DB->get_records('local_kbacompany_bplan', [
            'companyuuid' => $companyuuid, 'userid' => $ownerid, 'periodstart' => $week,
        ], 'id ASC') as $plan) {
            $ctx = battle_plan_service::context($companyuuid, $actor, (string)$plan->uuid, $ownerid,
                $week, (string)$plan->positionuuid);
            $items = [];
            foreach ((array)$ctx['items'] as $item) {
                $task = (array)$item['task'];
                $items[] = [
                    'uuid' => (string)($task['uuid'] ?? $item['task_uuid']),
                    'title' => (string)($task['title'] ?? ''),
                    'product' => (string)($task['product'] ?? ''),
                    'status' => (string)($task['status'] ?? ''),
                    'stream' => (string)($item['stream'] ?? ''),
                    'plan_minutes' => (int)($task['plan_minutes'] ?? 0),
                    'fact_minutes' => (int)($task['fact_minutes'] ?? 0),
                    'url' => (string)($task['source_url'] ?? ''),
                ];
            }
            $rows[] = [
                'uuid' => (string)$plan->uuid,
                'positionuuid' => (string)$plan->positionuuid,
                'periodstart' => (string)$plan->periodstart,
                'periodend' => (string)$plan->periodend,
                'status' => (string)$plan->status,
                'summary' => (array)$ctx['summary'],
                'items' => $items,
                'indicators' => (array)$ctx['position_indicators'],
                'url' => (new \moodle_url('/local/kbacompany/battle_plan.php', [
                    'uuid' => $companyuuid, 'plan' => (string)$plan->uuid,
                ]))->out(false),
            ];
        }
        return $rows;
    }

    public static function context(string $companyuuid, int $actor, string $meetinguuid): ?array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $cp = $DB->get_record('local_kbacompany_mcyclecp', [
            'companyuuid' => $companyuuid, 'meetinguuid' => $meetinguuid,
        ]);
        if (!$cp) { return null; }
        $cycle = $DB->get_record('local_kbacompany_mcycle', [
            'companyuuid' => $companyuuid, 'uuid' => (string)$cp->cycleuuid,
        ], '*', MUST_EXIST);
        if ($actor !== (int)$cycle->ownerid && $actor !== (int)$cycle->curatorid &&
                !access_role_service::can($companyuuid, $actor, 'management_cycle', 'view')) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }

        $basis = (int)$cp->plannedat > 0 ? (int)$cp->plannedat : time();
        $week = battle_plan_service::week_start(gmdate('Y-m-d', $basis));
        $previous = (new \DateTimeImmutable($week, new \DateTimeZone('UTC')))->modify('-7 days')->format('Y-m-d');
        $previousplans = self::plan_rows($companyuuid, $actor, (int)$cycle->ownerid, $previous);
        $currentplans = self::plan_rows($companyuuid, $actor, (int)$cycle->ownerid, $week);

        $unfinished = [];
        foreach ($previousplans as $plan) {
            foreach ($plan['items'] as $item) {
                if (!in_array($item['status'], ['done', 'cancelled'], true)) {
                    $item['plan_uuid'] = $plan['uuid'];
                    $item['plan_url'] = $plan['url'];
                    $unfinished[] = $item;
                }
            }
        }

        $indicators = [];
        $sourceplans = $currentplans ?: $previousplans;
        foreach ($sourceplans as $plan) {
            foreach ($plan['indicators'] as $indicator) {
                $key = (string)$indicator['stat_uuid'];
                if (!isset($indicators[$key])) {
                    $indicators[$key] = $indicator;
                }
            }
        }

        $agenda = [
            ['key'=>'previous', 'label'=>get_string('mcycle_prep_agenda_previous','local_kbacompany')],
            ['key'=>'indicators', 'label'=>get_string('mcycle_prep_agenda_indicators','local_kbacompany')],
        ];
        if ($unfinished) {
            $agenda[] = ['key'=>'unfinished', 'label'=>get_string('mcycle_prep_agenda_unfinished','local_kbacompany', count($unfinished))];
        }
        $agenda[] = ['key'=>'current', 'label'=>get_string('mcycle_prep_agenda_current','local_kbacompany')];
        $agenda[] = ['key'=>'coordination', 'label'=>get_string('mcycle_prep_agenda_coordination','local_kbacompany')];

        return [
            'cycle_uuid' => (string)$cycle->uuid,
            'cycle_title' => (string)$cycle->title,
            'checkpoint_uuid' => (string)$cp->uuid,
            'checkpoint_title' => (string)$cp->title,
            'checkpoint_planned_at' => (int)$cp->plannedat,
            'owner_id' => (int)$cycle->ownerid,
            'curator_id' => (int)$cycle->curatorid,
            'priority' => (string)$cycle->priority,
            'outcome' => (string)$cycle->outcome,
            'week' => $week,
            'previous_week' => $previous,
            'previous_plans' => $previousplans,
            'current_plans' => $currentplans,
            'unfinished' => $unfinished,
            'indicators' => array_values($indicators),
            'agenda' => $agenda,
            'cycle_url' => (new \moodle_url('/local/kbacompany/management_cycles.php', [
                'uuid'=>$companyuuid, 'cycle'=>(string)$cycle->uuid,
            ]))->out(false),
            'contract' => self::contract(),
        ];
    }
}
