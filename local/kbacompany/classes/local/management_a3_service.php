<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** A3 management review inside a canonical Management Cycle. */
class management_a3_service {
    public const CONTRACT_VERSION = '0.8.8';
    public const STATUSES = ['draft', 'active', 'verify', 'closed'];

    public static function contract(): array {
        return [
            'component' => 'local_kbacompany',
            'contract' => 'bms_management_a3_review',
            'version' => self::CONTRACT_VERSION,
            'company_scope' => 'required',
            'storage' => ['local_kbacompany_a3review'],
            'rules' => [
                'a3_is_management_review_inside_management_cycle',
                'no_parallel_problem_kpi_or_task_engine',
                'indicator_reference_is_optional_but_must_be_canonical_when_present',
                'countermeasure_tasks_remain_canonical_tasks_and_battle_plan_items',
                'repeat_fact_is_explicitly_captured_or_entered_and_never_inferred',
                'closing_a3_does_not_modify_indicator_fact_or_close_tasks',
                'standardization_routes_are_human_confirmed_via_a3_standardization_service',
                'all_reads_and_writes_are_company_scoped',
            ],
        ];
    }

    private static function uuid(): string {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }

    private static function require_manage(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid, $actor);
        if (!permission_service::can_manage_management($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
    }

    private static function cycle(string $companyuuid, string $cycleuuid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_mcycle', [
            'companyuuid'=>$companyuuid, 'uuid'=>$cycleuuid,
        ], '*', MUST_EXIST);
    }

    private static function row(string $companyuuid, string $a3uuid): \stdClass {
        global $DB;
        return $DB->get_record('local_kbacompany_a3review', [
            'companyuuid'=>$companyuuid, 'uuid'=>$a3uuid,
        ], '*', MUST_EXIST);
    }

    private static function clean(string $value, int $max = 0): string {
        $value = trim($value);
        if ($max > 0 && \core_text::strlen($value) > $max) {
            throw new \moodle_exception('a3_invalid', 'local_kbacompany');
        }
        return $value;
    }

    private static function cycle_indicator(string $companyuuid, int $actor, \stdClass $cycle, string $indicatoruuid): array {
        if ($indicatoruuid === '') { return []; }
        $payload = json_decode((string)$cycle->statepayload, true);
        $allowed = is_array($payload) ? (array)($payload['indicatoruuids'] ?? []) : [];
        if (!in_array($indicatoruuid, $allowed, true)) {
            throw new \moodle_exception('a3_indicator_invalid', 'local_kbacompany');
        }
        $ctx = indicator_registry_service::context($companyuuid, $actor, $indicatoruuid);
        foreach ((array)($ctx['metrics'] ?? []) as $metric) {
            if ((string)($metric['uuid'] ?? '') === $indicatoruuid) { return $metric; }
        }
        throw new \moodle_exception('a3_indicator_invalid', 'local_kbacompany');
    }

    private static function latest_fact(array $metric): array {
        $series = (array)($metric['series'] ?? []);
        if (!$series) { return ['', '']; }
        $last = end($series);
        if (!is_array($last)) { return ['', '']; }
        return [(string)($last['fact'] ?? ''), (string)($last['period'] ?? '')];
    }

    private static function tasks(\stdClass $row): array {
        $decoded = json_decode((string)$row->taskuuids, true);
        return is_array($decoded) ? array_values(array_unique(array_filter(array_map('strval', $decoded)))) : [];
    }

    public static function list_for_cycle(string $companyuuid, int $actor, string $cycleuuid): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        self::cycle($companyuuid, $cycleuuid);
        $rows = [];
        foreach ($DB->get_records('local_kbacompany_a3review', [
            'companyuuid'=>$companyuuid, 'cycleuuid'=>$cycleuuid,
        ], 'timecreated DESC') as $row) {
            $rows[] = self::dto($row);
        }
        return $rows;
    }

    public static function create(string $companyuuid, int $actor, string $cycleuuid, array $data): string {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $cycle = self::cycle($companyuuid, $cycleuuid);
        if (!in_array((string)$cycle->status, ['draft','active'], true)) {
            throw new \moodle_exception('a3_cycle_closed', 'local_kbacompany');
        }
        $title = self::clean((string)($data['title'] ?? ''), 255);
        if ($title === '') { throw new \moodle_exception('a3_invalid', 'local_kbacompany'); }
        $indicatoruuid = self::clean((string)($data['indicatoruuid'] ?? ''), 36);
        $metric = self::cycle_indicator($companyuuid, $actor, $cycle, $indicatoruuid);
        [$baselinefact, $baselineperiod] = $metric ? self::latest_fact($metric) : ['', ''];
        $now = time();
        $uuid = self::uuid();
        $DB->insert_record('local_kbacompany_a3review', (object)[
            'uuid'=>$uuid, 'companyuuid'=>$companyuuid, 'cycleuuid'=>$cycleuuid,
            'checkpointuuid'=>'', 'title'=>$title, 'indicatoruuid'=>$indicatoruuid,
            'problemcontext'=>self::clean((string)($data['problemcontext'] ?? '')),
            'currentstate'=>self::clean((string)($data['currentstate'] ?? '')),
            'targetstate'=>self::clean((string)($data['targetstate'] ?? '')),
            'gapanalysis'=>'', 'causeanalysis'=>'', 'countermeasures'=>'',
            'baselinefact'=>$baselinefact, 'baselineperiod'=>$baselineperiod,
            'targetfact'=>self::clean((string)($data['targetfact'] ?? ''), 64),
            'repeatfact'=>'', 'repeatperiod'=>'', 'conclusion'=>'', 'standardization'=>'',
            'taskuuids'=>'[]', 'status'=>'draft', 'ownerid'=>(int)$cycle->ownerid,
            'createdby'=>$actor, 'timecreated'=>$now, 'timemodified'=>$now,
        ]);
        return $uuid;
    }

    public static function save(string $companyuuid, int $actor, string $a3uuid, array $data): void {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $row = self::row($companyuuid, $a3uuid);
        if ((string)$row->status === 'closed') { throw new \moodle_exception('a3_closed', 'local_kbacompany'); }
        $cycle = self::cycle($companyuuid, (string)$row->cycleuuid);
        $indicatoruuid = self::clean((string)($data['indicatoruuid'] ?? $row->indicatoruuid), 36);
        if ($indicatoruuid !== (string)$row->indicatoruuid) {
            $metric = self::cycle_indicator($companyuuid, $actor, $cycle, $indicatoruuid);
            [$row->baselinefact, $row->baselineperiod] = $metric ? self::latest_fact($metric) : ['', ''];
            $row->indicatoruuid = $indicatoruuid;
        }
        foreach (['title'=>255,'problemcontext'=>0,'currentstate'=>0,'targetstate'=>0,'gapanalysis'=>0,'causeanalysis'=>0,
            'countermeasures'=>0,'targetfact'=>64,'repeatfact'=>64,'conclusion'=>0,'standardization'=>0] as $field=>$max) {
            if (array_key_exists($field, $data)) { $row->$field = self::clean((string)$data[$field], $max); }
        }
        $row->timemodified = time();
        $DB->update_record('local_kbacompany_a3review', $row);
    }

    public static function transition(string $companyuuid, int $actor, string $a3uuid, string $status): void {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $row = self::row($companyuuid, $a3uuid);
        $allowed = [
            'draft'=>['active'],
            'active'=>['verify'],
            'verify'=>['active','closed'],
            'closed'=>[],
        ];
        if (!in_array($status, $allowed[(string)$row->status] ?? [], true)) {
            throw new \moodle_exception('a3_invalid_status', 'local_kbacompany');
        }
        if ($status === 'closed' && trim((string)$row->conclusion) === '') {
            throw new \moodle_exception('a3_conclusion_required', 'local_kbacompany');
        }
        $row->status = $status;
        $row->timemodified = time();
        $DB->update_record('local_kbacompany_a3review', $row);
    }

    public static function capture_repeat_fact(string $companyuuid, int $actor, string $a3uuid): void {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $row = self::row($companyuuid, $a3uuid);
        if ((string)$row->status === 'closed') { throw new \moodle_exception('a3_closed', 'local_kbacompany'); }
        if ((string)$row->indicatoruuid === '') { throw new \moodle_exception('a3_indicator_required', 'local_kbacompany'); }
        $cycle = self::cycle($companyuuid, (string)$row->cycleuuid);
        $metric = self::cycle_indicator($companyuuid, $actor, $cycle, (string)$row->indicatoruuid);
        [$fact, $period] = self::latest_fact($metric);
        if ($fact === '') { throw new \moodle_exception('a3_no_fact', 'local_kbacompany'); }
        $row->repeatfact = $fact;
        $row->repeatperiod = $period;
        $row->timemodified = time();
        $DB->update_record('local_kbacompany_a3review', $row);
    }

    private static function cycle_plan_map(string $companyuuid, int $actor, \stdClass $cycle): array {
        global $DB;
        $plans = [];
        foreach ($DB->get_records_select('local_kbacompany_bplan',
            'companyuuid = :c AND userid = :u AND periodstart >= :s AND periodstart <= :e',
            ['c'=>$companyuuid,'u'=>(int)$cycle->ownerid,'s'=>(string)$cycle->periodstart,'e'=>(string)$cycle->periodend],
            'periodstart ASC') as $plan) {
            $plans[(string)$plan->uuid] = $plan;
        }
        return $plans;
    }

    public static function create_countermeasure_task(string $companyuuid, int $actor, string $a3uuid,
            string $planuuid, array $data): string {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $row = self::row($companyuuid, $a3uuid);
        if ((string)$row->status === 'closed') { throw new \moodle_exception('a3_closed', 'local_kbacompany'); }
        $cycle = self::cycle($companyuuid, (string)$row->cycleuuid);
        $plans = self::cycle_plan_map($companyuuid, $actor, $cycle);
        if (!isset($plans[$planuuid]) || (string)$plans[$planuuid]->status !== 'draft') {
            throw new \moodle_exception('a3_plan_invalid', 'local_kbacompany');
        }
        $description = self::clean((string)($data['description'] ?? ''));
        $trace = get_string('a3_task_trace', 'local_kbacompany', (object)['title'=>(string)$row->title]);
        $description = trim($description === '' ? $trace : $description . "\n\n" . $trace);
        $taskuuid = battle_plan_service::create_manual_task($companyuuid, $actor, $planuuid, [
            'title'=>self::clean((string)($data['title'] ?? ''), 255),
            'description'=>$description,
            'product'=>self::clean((string)($data['product'] ?? ''), 255),
            'stream'=>'change', 'priority'=>'high',
            'planminutes'=>max(0, (int)($data['planminutes'] ?? 0)),
            'duedate'=>self::clean((string)($data['duedate'] ?? ''), 10),
        ]);
        $tasks = self::tasks($row);
        if (!in_array($taskuuid, $tasks, true)) { $tasks[] = $taskuuid; }
        $row->taskuuids = json_encode($tasks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $row->timemodified = time();
        $DB->update_record('local_kbacompany_a3review', $row);
        return $taskuuid;
    }

    public static function link_task(string $companyuuid, int $actor, string $a3uuid, string $taskuuid): void {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $row = self::row($companyuuid, $a3uuid);
        if (!$DB->record_exists('local_kbacompany_task', ['companyuuid'=>$companyuuid,'uuid'=>$taskuuid])) {
            throw new \moodle_exception('a3_task_invalid', 'local_kbacompany');
        }
        $tasks = self::tasks($row);
        if (!in_array($taskuuid, $tasks, true)) { $tasks[] = $taskuuid; }
        $row->taskuuids = json_encode($tasks, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $row->timemodified = time();
        $DB->update_record('local_kbacompany_a3review', $row);
    }

    private static function dto(\stdClass $row): array {
        return [
            'uuid'=>(string)$row->uuid, 'cycleuuid'=>(string)$row->cycleuuid,
            'title'=>(string)$row->title, 'indicatoruuid'=>(string)$row->indicatoruuid,
            'status'=>(string)$row->status, 'ownerid'=>(int)$row->ownerid,
            'baselinefact'=>(string)$row->baselinefact, 'baselineperiod'=>(string)$row->baselineperiod,
            'targetfact'=>(string)$row->targetfact, 'repeatfact'=>(string)$row->repeatfact,
            'repeatperiod'=>(string)$row->repeatperiod, 'taskuuids'=>self::tasks($row),
            'timecreated'=>(int)$row->timecreated, 'timemodified'=>(int)$row->timemodified,
        ];
    }

    public static function context(string $companyuuid, int $actor, string $a3uuid): array {
        global $DB;
        self::require_manage($companyuuid, $actor);
        $row = self::row($companyuuid, $a3uuid);
        $cycle = self::cycle($companyuuid, (string)$row->cycleuuid);
        $metric = [];
        if ((string)$row->indicatoruuid !== '') {
            try { $metric = self::cycle_indicator($companyuuid, $actor, $cycle, (string)$row->indicatoruuid); }
            catch (\Throwable $e) { $metric = []; }
        }
        $payload = json_decode((string)$cycle->statepayload, true);
        $indicatoruuids = is_array($payload) ? (array)($payload['indicatoruuids'] ?? []) : [];
        $registry = indicator_registry_service::context($companyuuid, $actor);
        $indicators = [];
        foreach ((array)($registry['metrics'] ?? []) as $m) {
            if (in_array((string)($m['uuid'] ?? ''), $indicatoruuids, true)) {
                $indicators[(string)$m['uuid']] = (string)($m['title'] ?? $m['name'] ?? $m['uuid']);
            }
        }
        $plans = [];
        $taskoptions = [];
        foreach (self::cycle_plan_map($companyuuid, $actor, $cycle) as $planuuid=>$plan) {
            $plans[$planuuid] = [
                'uuid'=>$planuuid, 'periodstart'=>(string)$plan->periodstart, 'status'=>(string)$plan->status,
                'label'=>(string)$plan->periodstart . ' · ' . (string)$plan->status,
            ];
            try {
                $ctx = battle_plan_service::context($companyuuid, $actor, $planuuid, (int)$cycle->ownerid,
                    (string)$plan->periodstart, (string)$plan->positionuuid);
                foreach ((array)($ctx['items'] ?? []) as $item) {
                    if ((string)($item['provider'] ?? '') !== 'bms') { continue; }
                    $task = (array)($item['task'] ?? []);
                    $tuuid = (string)($task['uuid'] ?? $item['task_uuid'] ?? '');
                    if ($tuuid !== '') {
                        $taskoptions[$tuuid] = (string)($task['title'] ?? $tuuid);
                    }
                }
            } catch (\Throwable $e) { /* Keep A3 readable if one plan projection is unavailable. */ }
        }
        $linked = [];
        foreach (self::tasks($row) as $taskuuid) {
            $task = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$companyuuid,'uuid'=>$taskuuid]);
            if ($task) {
                $linked[] = [
                    'uuid'=>(string)$task->uuid, 'title'=>(string)$task->title, 'product'=>(string)$task->product,
                    'status'=>(string)$task->status, 'assigneeid'=>(int)$task->assigneeid,
                    'url'=>(new \moodle_url('/local/kbacompany/tasks.php',['uuid'=>$companyuuid,'task'=>(string)$task->uuid]))->out(false),
                ];
            }
        }
        $standardization = ['records'=>[], 'target_options'=>[]];
        try { $standardization = a3_standardization_service::context($companyuuid, $actor, $a3uuid); }
        catch (\Throwable $e) { /* A3 remains readable during an incomplete upgrade. */ }
        return [
            'row'=>$row, 'cycle'=>$cycle, 'metric'=>$metric, 'indicators'=>$indicators,
            'plans'=>$plans, 'task_options'=>$taskoptions, 'linked_tasks'=>$linked,
            'standardization_context'=>$standardization,
            'contract'=>self::contract(),
        ];
    }
}
