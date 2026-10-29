<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicit Management Cycle continuation and hierarchy orchestration.
 *
 * Rollover never copies canonical Tasks or Battle Plans. It stores references to unfinished
 * canonical work in the next cycle state payload so that users can deliberately include that
 * work in a new period through the existing Task/Battle Plan UI.
 */
class management_cycle_rollover_service {
    public const CONTRACT_VERSION = '0.8.9';

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_management_cycle_rollover',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'existing local_kbacompany_mcycle.statepayload only',
            'hierarchy' => ['year'=>'quarter', 'quarter'=>'month', 'month'=>'week'],
            'rules' => [
                'next_period_is_created_explicitly_by_a_manager',
                'rollover_references_existing_tasks_without_copying_them',
                'original_task_due_dates_and_history_are_never_reset',
                'source_battle_plans_remain_immutable_historical_references',
                'new_period_starts_as_draft',
                'year_quarter_month_week_hierarchy_is_explicit',
                'all_reads_and_writes_are_company_scoped',
            ],
        ];
    }

    private static function require_manage(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid, $actor);
        if (!access_role_service::can($companyuuid, $actor, 'management_cycle', 'view')) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
    }

    private static function cycle(string $companyuuid, string $cycleuuid): \stdClass {
        global $DB;
        $row = $DB->get_record('local_kbacompany_mcycle', [
            'companyuuid' => $companyuuid,
            'uuid' => $cycleuuid,
        ]);
        if (!$row) {
            throw new \moodle_exception('mcycle_not_found', 'local_kbacompany');
        }
        return $row;
    }

    private static function payload(\stdClass $cycle): array {
        $payload = json_decode((string)$cycle->statepayload, true);
        return is_array($payload) ? $payload : [];
    }

    private static function date_object(string $date): \DateTimeImmutable {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $date) {
            throw new \moodle_exception('mcycle_invalid', 'local_kbacompany');
        }
        return $d;
    }

    private static function next_dates(\stdClass $cycle): array {
        $start = self::date_object((string)$cycle->periodstart);
        $end = self::date_object((string)$cycle->periodend);
        if ($end < $start) {
            throw new \moodle_exception('mcycle_invalid', 'local_kbacompany');
        }
        $days = (int)$start->diff($end)->format('%a');
        $nextstart = $end->modify('+1 day');
        $nextend = $nextstart->modify('+' . $days . ' days');
        return [$nextstart->format('Y-m-d'), $nextend->format('Y-m-d')];
    }

    private static function source_plans(string $companyuuid, \stdClass $cycle): array {
        global $DB;
        return array_values($DB->get_records_select('local_kbacompany_bplan',
            'companyuuid = :companyuuid AND userid = :userid AND periodstart >= :periodstart AND periodstart <= :periodend',
            [
                'companyuuid' => $companyuuid,
                'userid' => (int)$cycle->ownerid,
                'periodstart' => (string)$cycle->periodstart,
                'periodend' => (string)$cycle->periodend,
            ], 'periodstart ASC, id ASC'));
    }

    /** Open canonical BMS tasks referenced by Battle Plans in this cycle period. */
    private static function open_tasks(string $companyuuid, \stdClass $cycle, array $plans): array {
        global $DB;
        if (!$plans) { return []; }
        $planuuids = array_values(array_unique(array_map(function($p) { return (string)$p->uuid; }, $plans)));
        list($insql, $params) = $DB->get_in_or_equal($planuuids, SQL_PARAMS_NAMED, 'p');
        $params['companyuuid'] = $companyuuid;
        $sql = "SELECT t.id, t.uuid, t.title, t.product, t.status, t.duedate, t.assigneeid,
                       bi.planuuid, bp.periodstart AS sourceperiod
                  FROM {local_kbacompany_bplanitem} bi
                  JOIN {local_kbacompany_bplan} bp
                    ON bp.companyuuid = bi.companyuuid AND bp.uuid = bi.planuuid
                  JOIN {local_kbacompany_task} t
                    ON t.companyuuid = bi.companyuuid AND t.uuid = bi.taskuuid
                 WHERE bi.companyuuid = :companyuuid
                   AND bi.planuuid {$insql}
                   AND bi.provider = 'bms'
                   AND bi.taskuuid <> ''
                   AND t.status NOT IN ('done','cancelled','deleted')
              ORDER BY bp.periodstart ASC, t.duedate ASC, t.id ASC";
        $rows = $DB->get_records_sql($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $uuid = (string)$row->uuid;
            if (isset($out[$uuid])) { continue; }
            $out[$uuid] = [
                'uuid' => $uuid,
                'title' => (string)$row->title,
                'product' => (string)$row->product,
                'status' => (string)$row->status,
                'due_date' => (string)$row->duedate,
                'assignee_id' => (int)$row->assigneeid,
                'source_plan_uuid' => (string)$row->planuuid,
                'source_period' => (string)$row->sourceperiod,
                'url' => (new \moodle_url('/local/kbacompany/tasks.php', [
                    'uuid'=>$companyuuid, 'task'=>$uuid,
                ]))->out(false),
            ];
        }
        return array_values($out);
    }

    public static function preview(string $companyuuid, int $actor, string $cycleuuid): array {
        self::require_manage($companyuuid, $actor);
        $cycle = self::cycle($companyuuid, $cycleuuid);
        list($nextstart, $nextend) = self::next_dates($cycle);
        $plans = self::source_plans($companyuuid, $cycle);
        $tasks = self::open_tasks($companyuuid, $cycle, $plans);
        $childmap = ['year'=>'quarter', 'quarter'=>'month', 'month'=>'week'];
        $sourceplanrefs = [];
        foreach ($plans as $plan) {
            $sourceplanrefs[] = [
                'uuid'=>(string)$plan->uuid,
                'period_start'=>(string)$plan->periodstart,
                'status'=>(string)$plan->status,
                'url'=>(new \moodle_url('/local/kbacompany/battle_plan.php', [
                    'uuid'=>$companyuuid, 'plan'=>(string)$plan->uuid,
                ]))->out(false),
            ];
        }
        return [
            'cycle' => $cycle,
            'next_period_start' => $nextstart,
            'next_period_end' => $nextend,
            'source_battle_plans' => $sourceplanrefs,
            'open_tasks' => $tasks,
            'child_template' => $childmap[(string)$cycle->templatecode] ?? '',
            'contract' => self::contract(),
        ];
    }

    /**
     * Create the next sibling period. Selected open tasks are referenced, not copied or edited.
     * Repeating the same rollover returns the already-created next cycle.
     */
    public static function create_next(string $companyuuid, int $actor, string $cycleuuid,
            string $title, array $carrytaskuuids): string {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $source = self::cycle($companyuuid, $cycleuuid);
        if (!in_array((string)$source->status, ['active','closed'], true)) {
            throw new \moodle_exception('mcycle_rollover_state', 'local_kbacompany');
        }
        list($nextstart, $nextend) = self::next_dates($source);
        $preview = self::preview($companyuuid, $actor, $cycleuuid);
        $allowedtasks = [];
        foreach ($preview['open_tasks'] as $task) { $allowedtasks[$task['uuid']] = true; }
        $selected = [];
        foreach ($carrytaskuuids as $taskuuid) {
            $taskuuid = trim((string)$taskuuid);
            if ($taskuuid === '') { continue; }
            if (!isset($allowedtasks[$taskuuid])) {
                throw new \moodle_exception('mcycle_rollover_task_invalid', 'local_kbacompany');
            }
            $selected[$taskuuid] = true;
        }
        $selected = array_keys($selected);
        $title = trim($title);
        if ($title === '') {
            $title = (string)$source->title . ' · ' . $nextstart;
        }

        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('mcycle-rollover:' . $companyuuid . ':' . $cycleuuid, 10);
        if (!$lock) {
            throw new \moodle_exception('mcycle_rollover_lock', 'local_kbacompany');
        }
        try {
            foreach ($DB->get_records('local_kbacompany_mcycle', [
                'companyuuid'=>$companyuuid,
                'templatecode'=>(string)$source->templatecode,
                'ownerid'=>(int)$source->ownerid,
                'periodstart'=>$nextstart,
            ], 'id ASC') as $candidate) {
                $candidatepayload = self::payload($candidate);
                if ((string)($candidatepayload['rollover']['sourcecycleuuid'] ?? '') === $cycleuuid) {
                    return (string)$candidate->uuid;
                }
                if ((string)$candidate->scopekind === (string)$source->scopekind &&
                        (string)$candidate->scopeuuid === (string)$source->scopeuuid) {
                    throw new \moodle_exception('mcycle_rollover_exists', 'local_kbacompany');
                }
            }

            $tx = $DB->start_delegated_transaction();
            try {
                $sourcepayload = self::payload($source);
                $newuuid = management_cycle_template_service::create($companyuuid, $actor, [
                    'templatecode'=>(string)$source->templatecode,
                    'title'=>$title,
                    'priority'=>(string)$source->priority,
                    'outcome'=>(string)$source->outcome,
                    'periodstart'=>$nextstart,
                    'periodend'=>$nextend,
                    'ownerid'=>(int)$source->ownerid,
                    'curatorid'=>(int)$source->curatorid,
                    'parentuuid'=>(string)$source->parentuuid,
                    'scopekind'=>(string)$source->scopekind,
                    'scopeuuid'=>(string)$source->scopeuuid,
                    'indicatoruuids'=>(array)($sourcepayload['indicatoruuids'] ?? []),
                ]);
                $new = self::cycle($companyuuid, $newuuid);
                $payload = self::payload($new);
                $payload['rollover'] = [
                    'sourcecycleuuid'=>$cycleuuid,
                    'sourcebattleplanuuids'=>array_values(array_map(function($p) { return $p['uuid']; }, $preview['source_battle_plans'])),
                    'carrytaskuuids'=>$selected,
                    'createdby'=>$actor,
                    'createdat'=>time(),
                ];
                $new->statepayload = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $new->timemodified = time();
                $DB->update_record('local_kbacompany_mcycle', $new);
                $tx->allow_commit();
                return $newuuid;
            } catch (\Throwable $e) { $tx->rollback($e); }

        } finally {
            $lock->release();
        }
    }

    /** Create one explicit child cycle in the Year → Quarter → Month → Week cascade. */
    public static function create_child(string $companyuuid, int $actor, string $parentuuid,
            string $periodstart, string $periodend, string $title, string $requestkey = ''): string {
        access_role_service::require_permission($companyuuid, $actor, 'management_cycle', 'edit');
        self::require_manage($companyuuid, $actor);
        $parent = self::cycle($companyuuid, $parentuuid);
        $map = ['year'=>'quarter', 'quarter'=>'month', 'month'=>'week'];
        $childtemplate = $map[(string)$parent->templatecode] ?? '';
        if ($childtemplate === '') {
            throw new \moodle_exception('mcycle_child_not_available', 'local_kbacompany');
        }
        $start = self::date_object($periodstart);
        $end = self::date_object($periodend);
        $parentstart = self::date_object((string)$parent->periodstart);
        $parentend = self::date_object((string)$parent->periodend);
        if ($end < $start || $start < $parentstart || $end > $parentend) {
            throw new \moodle_exception('mcycle_child_period_invalid', 'local_kbacompany');
        }
        $payload = self::payload($parent);
        $templates = management_cycle_template_service::templates();
        $indicatoruuids = array_slice((array)($payload['indicatoruuids'] ?? []), 0,
            (int)$templates[$childtemplate]['indicator_max']);
        while (count($indicatoruuids) < (int)$templates[$childtemplate]['indicator_min']) {
            throw new \moodle_exception('mcycle_not_enough_indicators', 'local_kbacompany');
        }
        $title = trim($title);
        if ($title === '') {
            $title = get_string('mcycle_template_' . $childtemplate, 'local_kbacompany') . ' · ' . $periodstart;
        }
        return management_cycle_template_service::create($companyuuid, $actor, [
            'requestkey'=>$requestkey,
            'templatecode'=>$childtemplate,
            'title'=>$title,
            'priority'=>(string)$parent->priority,
            'outcome'=>(string)$parent->outcome,
            'periodstart'=>$periodstart,
            'periodend'=>$periodend,
            'ownerid'=>(int)$parent->ownerid,
            'curatorid'=>(int)$parent->curatorid,
            'parentuuid'=>$parentuuid,
            'scopekind'=>(string)$parent->scopekind,
            'scopeuuid'=>(string)$parent->scopeuuid,
            'indicatoruuids'=>$indicatoruuids,
        ]);
    }
}
