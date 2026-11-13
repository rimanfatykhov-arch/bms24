<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Schema-neutral management overview over weekly Battle Plans.
 *
 * Aggregates existing Battle Plans, position-bound indicator exceptions and
 * canonical BMS Tasks. It stores nothing and does not create a second incident
 * or execution model.
 */
class battle_plan_management_overview_service {
    public const CONTRACT_VERSION = '0.5.11';
    public const ATTENTION_FILTERS = ['needs_decision', 'overdue', 'critical', 'open', 'clear'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan_management_overview',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'battle_plans' => 'local_kbacompany_bplan + local_kbacompany_bplanitem',
                'organization' => 'local_kbacompany_position + local_kbacompany_orgnode',
                'indicator_exceptions' => 'indicator_management_exception_service read-model',
                'canonical_tasks' => 'local_kbacompany_task',
            ],
            'rules' => [
                'overview_is_read_model_not_persisted_summary',
                'only_accessible_battle_plans_are_aggregated',
                'canonical_task_acl_is_not_bypassed',
                'critical_indicator_count_comes_from_canonical_position_bindings',
                'decision_required_count_reuses_management_exception_cycle',
                'no_hidden_percentage_or_age_thresholds',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function can_access_plan(\stdClass $plan, int $actor, bool $canmanage): bool {
        return $canmanage || (int)$plan->userid === $actor || (int)$plan->reviewerid === $actor ||
            (int)$plan->observerid === $actor;
    }

    private static function user_names(string $companyuuid, int $actor): array {
        global $DB;
        $names = [];
        $organization = organization_service::organization_context($companyuuid, $actor);
        foreach ($organization['employees'] as $employee) {
            $names[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        return $names;
    }

    private static function canonical_task_counts(string $companyuuid, int $actor, string $planuuid): array {
        global $DB;
        $today = gmdate('Y-m-d');
        $out = ['total' => 0, 'open' => 0, 'overdue' => 0, 'done' => 0,
            'manual_open' => 0, 'manual_done' => 0, 'manual_items' => []];
        $items = $DB->get_records('local_kbacompany_bplanitem', [
            'companyuuid' => $companyuuid, 'planuuid' => $planuuid, 'provider' => 'bms',
        ], 'id ASC');
        foreach ($items as $item) {
            $taskuuid = (string)($item->externalid ?: $item->taskuuid);
            if ($taskuuid === '' || !task_service::can_view_task($companyuuid, $actor, $taskuuid)) { continue; }
            $task = $DB->get_record('local_kbacompany_task', [
                'companyuuid' => $companyuuid, 'uuid' => $taskuuid,
            ]);
            if (!$task || (string)$task->status === 'deleted') { continue; }
            $out['total']++;
            $ismanual = (string)$task->sourcekind === 'manual';
            if ((string)$task->status === 'done') {
                $out['done']++;
                if ($ismanual) { $out['manual_done']++; }
                continue;
            }
            if ((string)$task->status === 'cancelled') { continue; }
            $out['open']++;
            if ($ismanual) {
                $out['manual_open']++;
                if (count($out['manual_items']) < 3) {
                    $out['manual_items'][] = [
                        'uuid'=>(string)$task->uuid,
                        'title'=>(string)$task->title,
                        'status'=>(string)$task->status,
                    ];
                }
            }
            if ((string)$task->duedate !== '' && (string)$task->duedate < $today) { $out['overdue']++; }
        }
        return $out;
    }

    /** Read-only prior-week rollover summary. Counts use CURRENT task statuses, not a frozen week-end snapshot. */
    public static function rollover_summary(string $companyuuid, int $actor, string $currentweek): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $previousweek = (new \DateTimeImmutable($currentweek, new \DateTimeZone('UTC')))
            ->modify('-7 days')->format('Y-m-d');
        $names = self::user_names($companyuuid, $actor);
        $rows = [];
        $totals = ['open' => 0, 'carried' => 0, 'notcarried' => 0];
        $plans = $DB->get_records('local_kbacompany_bplan',
            ['companyuuid' => $companyuuid, 'periodstart' => $previousweek], 'userid ASC, id ASC');
        foreach ($plans as $plan) {
            if (!self::can_access_plan($plan, $actor, $canmanage)) { continue; }
            $payload = json_decode((string)($plan->statepayload ?? ''), true);
            $rollover = is_array($payload) ? (array)($payload['week2']['rollover'] ?? []) : [];
            $snapshot = is_array($payload) && (string)$plan->status === 'closed' ?
                ($payload['closure_snapshot'] ?? null) : null;
            $hassnapshot = is_array($snapshot) && (int)($snapshot['version'] ?? 0) === 1 &&
                is_array($snapshot['items'] ?? null);
            $carriedids = array_fill_keys(array_map('strval', (array)($rollover['itemuuids'] ?? [])), true);
            $targetuuid = (string)($rollover['targetplanuuid'] ?? '');
            $target = $targetuuid !== '' ? $DB->get_record('local_kbacompany_bplan',
                ['companyuuid' => $companyuuid, 'uuid' => $targetuuid]) : false;
            $validtarget = $target && (string)$target->periodstart === $currentweek &&
                (int)$target->userid === (int)$plan->userid;
            $counts = ['open' => 0, 'carried' => 0, 'notcarried' => 0];
            if ($hassnapshot) {
                foreach ($snapshot['items'] as $frozenitem) {
                    if (!is_array($frozenitem) || ($frozenitem['provider'] ?? '') !== 'bms') { continue; }
                    $taskuuid = (string)($frozenitem['externalid'] ?? '');
                    if ($taskuuid === '' || !task_service::can_view_task($companyuuid, $actor, $taskuuid)) { continue; }
                    if (in_array((string)($frozenitem['status'] ?? ''), ['done', 'cancelled', 'deleted'], true)) { continue; }
                    $counts['open']++;
                    $counts[!empty($frozenitem['carried']) ? 'carried' : 'notcarried']++;
                }
            } else {
            foreach ($DB->get_records('local_kbacompany_bplanitem',
                ['companyuuid'=>$companyuuid, 'planuuid'=>(string)$plan->uuid, 'provider'=>'bms'], 'id ASC') as $item) {
                $taskuuid = (string)($item->externalid ?: $item->taskuuid);
                if ($taskuuid === '' || !task_service::can_view_task($companyuuid, $actor, $taskuuid)) { continue; }
                $task = $DB->get_record('local_kbacompany_task', ['companyuuid'=>$companyuuid, 'uuid'=>$taskuuid]);
                if (!$task || in_array((string)$task->status, ['done','cancelled','deleted'], true)) { continue; }
                $counts['open']++;
                $wascarried = $validtarget && isset($carriedids[(string)$item->uuid]) &&
                    $DB->record_exists('local_kbacompany_bplanitem',
                        ['companyuuid'=>$companyuuid,'planuuid'=>$targetuuid,'provider'=>'bms','externalid'=>$taskuuid]);
                $counts[$wascarried ? 'carried' : 'notcarried']++;
            }
            }
            if (!$counts['open'] && !$counts['carried']) { continue; }
            foreach ($totals as $key => $value) { $totals[$key] += $counts[$key]; }
            $rows[] = [
                'plan_uuid'=>(string)$plan->uuid,
                'employee'=>(string)($names[(int)$plan->userid] ?? (string)$plan->userid),
                'counts'=>$counts,
                'is_snapshot'=>$hassnapshot,
                'snapshot_at'=>$hassnapshot ? (int)($snapshot['capturedat'] ?? 0) : 0,
                'target_uuid'=>$validtarget ? $targetuuid : '',
            ];
        }
        return ['week'=>$previousweek, 'totals'=>$totals, 'rows'=>$rows];
    }

    /**
     * Proven repeated task carryovers over the eight weeks ending in $week.
     * Both source payload item ID and target canonical task reference must match.
     * The result is deliberately read-only; historical records are not reconstructed.
     */
    public static function rollover_history(string $companyuuid, int $actor, string $week): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $start = (new \DateTimeImmutable($week, new \DateTimeZone('UTC')))
            ->modify('-7 weeks')->format('Y-m-d');
        $plans = $DB->get_records_select('local_kbacompany_bplan',
            'companyuuid = :company AND periodstart >= :start AND periodstart <= :finish',
            ['company'=>$companyuuid, 'start'=>$start, 'finish'=>$week], 'periodstart ASC, id ASC');
        $authorized = [];
        foreach ($plans as $plan) {
            if (self::can_access_plan($plan, $actor, $canmanage)) {
                $authorized[(string)$plan->uuid] = $plan;
            }
        }
        $names = self::user_names($companyuuid, $actor);
        $events = [];
        foreach ($authorized as $sourceuuid => $source) {
            $payload = json_decode((string)($source->statepayload ?? ''), true);
            if (!is_array($payload)) { continue; }
            $rollover = (array)($payload['week2']['rollover'] ?? []);
            $targetuuid = trim((string)($rollover['targetplanuuid'] ?? ''));
            $target = $authorized[$targetuuid] ?? null;
            if (!$target || (int)$target->userid !== (int)$source->userid) { continue; }
            $expectedweek = (new \DateTimeImmutable((string)$source->periodstart, new \DateTimeZone('UTC')))
                ->modify('+7 days')->format('Y-m-d');
            if ((string)$target->periodstart !== $expectedweek) { continue; }
            $sourceitems = $DB->get_records('local_kbacompany_bplanitem',
                ['companyuuid'=>$companyuuid, 'planuuid'=>$sourceuuid, 'provider'=>'bms']);
            $byuuid = [];
            foreach ($sourceitems as $item) { $byuuid[(string)$item->uuid] = $item; }
            foreach (array_unique(array_map('strval', (array)($rollover['itemuuids'] ?? []))) as $itemuuid) {
                if (!isset($byuuid[$itemuuid])) { continue; }
                $item = $byuuid[$itemuuid];
                $taskuuid = (string)($item->externalid ?: $item->taskuuid);
                if ($taskuuid === '' || !task_service::can_view_task($companyuuid, $actor, $taskuuid)) { continue; }
                if (!$DB->record_exists('local_kbacompany_bplanitem',
                    ['companyuuid'=>$companyuuid, 'planuuid'=>$targetuuid,
                     'provider'=>'bms', 'externalid'=>$taskuuid])) { continue; }
                $key = (int)$source->userid . ':' . $taskuuid;
                if (!isset($events[$key])) {
                    $task = $DB->get_record('local_kbacompany_task',
                        ['companyuuid'=>$companyuuid, 'uuid'=>$taskuuid]);
                    $events[$key] = ['employee'=>(string)($names[(int)$source->userid] ?? (string)$source->userid),
                        'userid'=>(int)$source->userid, 'task_uuid'=>$taskuuid, 'title'=>$task ? (string)$task->title : $taskuuid,
                        'count'=>0, 'weeks'=>[], 'plan_uuid'=>$sourceuuid];
                }
                $events[$key]['count']++;
                $events[$key]['weeks'][] = (string)$source->periodstart;
                $events[$key]['plan_uuid'] = $sourceuuid;
            }
        }
        $rows = array_values(array_filter($events, static function(array $row): bool {
            return $row['count'] >= 2;
        }));
        usort($rows, static function(array $a, array $b): int {
            return ($b['count'] <=> $a['count']) ?: strcmp($a['employee'], $b['employee']);
        });
        return ['rows'=>array_slice($rows, 0, 30), 'repeated_count'=>count($rows),
            'from'=>$start, 'to'=>$week];
    }

    /**
     * Observed completion at plan CLOSE TIME for the last eight selected weeks.
     * This is NOT an on-time-rate: close time may be later than the period end.
     * Legacy plans without a snapshot are excluded, not inferred from live tasks.
     */
    public static function closure_discipline(string $companyuuid, int $actor, string $week): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $start = (new \DateTimeImmutable($week, new \DateTimeZone('UTC')))
            ->modify('-7 weeks')->format('Y-m-d');
        $plans = $DB->get_records_select('local_kbacompany_bplan',
            'companyuuid = :company AND periodstart >= :start AND periodstart <= :finish AND status = :status',
            ['company'=>$companyuuid, 'start'=>$start, 'finish'=>$week, 'status'=>'closed'],
            'userid ASC, periodstart DESC, id ASC');
        $names = self::user_names($companyuuid, $actor);
        $rows = [];
        $legacy = 0;
        foreach ($plans as $plan) {
            if (!self::can_access_plan($plan, $actor, $canmanage)) { continue; }
            $payload = json_decode((string)($plan->statepayload ?? ''), true);
            $snapshot = is_array($payload) ? ($payload['closure_snapshot'] ?? null) : null;
            if (!is_array($snapshot) || (int)($snapshot['version'] ?? 0) !== 1 ||
                    !is_array($snapshot['items'] ?? null)) {
                $legacy++;
                continue;
            }
            $uid = (int)$plan->userid;
            if (!isset($rows[$uid])) {
                $rows[$uid] = ['employee'=>(string)($names[$uid] ?? $uid), 'userid'=>$uid,
                    'plans'=>0, 'total'=>0, 'done'=>0, 'unfinished'=>0, 'carried'=>0,
                    'rate'=>null, 'plan_uuid'=>(string)$plan->uuid];
            }
            $counts = ['total'=>0, 'done'=>0, 'unfinished'=>0, 'carried'=>0];
            foreach ($snapshot['items'] as $item) {
                if (!is_array($item) || (string)($item['provider'] ?? '') !== 'bms') { continue; }
                $taskuuid = (string)($item['externalid'] ?? '');
                if ($taskuuid === '' || !task_service::can_view_task($companyuuid, $actor, $taskuuid)) { continue; }
                $status = (string)($item['status'] ?? 'missing');
                if (in_array($status, ['cancelled', 'deleted', 'missing'], true)) { continue; }
                $counts['total']++;
                if ($status === 'done') {
                    $counts['done']++;
                } else {
                    $counts['unfinished']++;
                    if (!empty($item['carried'])) { $counts['carried']++; }
                }
            }
            $rows[$uid]['plans']++;
            foreach ($counts as $key => $value) { $rows[$uid][$key] += $value; }
        }
        foreach ($rows as &$row) {
            $row['rate'] = $row['total'] > 0 ? (int)round(100 * $row['done'] / $row['total']) : null;
        }
        unset($row);
        return ['rows'=>array_values($rows), 'legacy_plans'=>$legacy, 'from'=>$start, 'to'=>$week];
    }

    /**
     * Verified weekly completion trend based solely on immutable close-time snapshots.
     * A week with no eligible tasks has no rate; missing and legacy weeks are not zeroes.
     */
    public static function closure_discipline_trend(string $companyuuid, int $actor, string $week, int $employeeid = 0): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $start = (new \DateTimeImmutable($week, new \DateTimeZone('UTC')))
            ->modify('-7 weeks')->format('Y-m-d');
        $plans = $DB->get_records_select('local_kbacompany_bplan',
            'companyuuid = :company AND periodstart >= :start AND periodstart <= :finish AND status = :status',
            ['company'=>$companyuuid, 'start'=>$start, 'finish'=>$week, 'status'=>'closed'],
            'periodstart ASC, userid ASC, id ASC');
        $points = [];
        for ($i = 0; $i < 8; $i++) {
            $date = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
                ->modify('+' . $i . ' weeks')->format('Y-m-d');
            $points[$date] = ['week'=>$date, 'plans'=>0, 'legacy'=>0,
                'total'=>0, 'done'=>0, 'unfinished'=>0, 'carried'=>0, 'rate'=>null];
        }
        foreach ($plans as $plan) {
            if (!self::can_access_plan($plan, $actor, $canmanage)) { continue; }
            if ($employeeid > 0 && (int)$plan->userid !== $employeeid) { continue; }
            $date = (string)$plan->periodstart;
            if (!isset($points[$date])) { continue; }
            $payload = json_decode((string)($plan->statepayload ?? ''), true);
            $snapshot = is_array($payload) ? ($payload['closure_snapshot'] ?? null) : null;
            if (!is_array($snapshot) || (int)($snapshot['version'] ?? 0) !== 1 ||
                    !is_array($snapshot['items'] ?? null)) {
                $points[$date]['legacy']++;
                continue;
            }
            $points[$date]['plans']++;
            foreach ($snapshot['items'] as $item) {
                if (!is_array($item) || (string)($item['provider'] ?? '') !== 'bms') { continue; }
                $taskuuid = (string)($item['externalid'] ?? '');
                if ($taskuuid === '' || !task_service::can_view_task($companyuuid, $actor, $taskuuid)) { continue; }
                $status = (string)($item['status'] ?? 'missing');
                if (in_array($status, ['cancelled', 'deleted', 'missing'], true)) { continue; }
                $points[$date]['total']++;
                if ($status === 'done') {
                    $points[$date]['done']++;
                } else {
                    $points[$date]['unfinished']++;
                    if (!empty($item['carried'])) { $points[$date]['carried']++; }
                }
            }
        }
        foreach ($points as &$point) {
            if ($point['total'] > 0) {
                $point['rate'] = (int)round(100 * $point['done'] / $point['total']);
            }
        }
        unset($point);
        return ['points'=>array_values($points), 'from'=>$start, 'to'=>$week];
    }

    private static function metric_performance(string $companyuuid, int $actor, string $positionuuid,
            string $week, string $planuuid): array {
        $counts = ['ok'=>0, 'risk'=>0, 'critical'=>0, 'nodata'=>0];
        foreach (battle_plan_indicator_service::projection($companyuuid, $actor, $positionuuid, $week, $planuuid) as $indicator) {
            $status = (string)($indicator['performance']['status'] ?? 'nodata');
            if (!isset($counts[$status])) { $status = 'nodata'; }
            $counts[$status]++;
        }
        $evaluated = (int)$counts['ok'] + (int)$counts['risk'] + (int)$counts['critical'];
        $total = $evaluated + (int)$counts['nodata'];
        return [
            'counts'=>$counts,
            'evaluated'=>$evaluated,
            'total'=>$total,
            'effectiveness'=>$evaluated > 0 ? (int)round(((int)$counts['ok'] / $evaluated) * 100) : null,
            'coverage'=>$total > 0 ? (int)round(($evaluated / $total) * 100) : null,
        ];
    }

    private static function attention_status(array $row): string {
        if ((int)$row['needs_decision'] > 0) { return 'needs_decision'; }
        if ((int)$row['tasks']['overdue'] > 0) { return 'overdue'; }
        if ((int)$row['critical_exceptions'] > 0) { return 'critical'; }
        if ((int)$row['tasks']['open'] > 0 || (int)$row['exceptions'] > 0) { return 'open'; }
        return 'clear';
    }

    public static function context(string $companyuuid, int $actor, string $week = '', string $orgnodeuuid = '',
            int $reviewerid = 0, string $attention = 'all'): array {
        global $DB;
        $company = company_context_service::context($companyuuid, $actor);
        $canmanage = task_service::can_manage($companyuuid, $actor);
        $week = $week !== '' ? battle_plan_service::week_start($week) : battle_plan_service::week_start(gmdate('Y-m-d'));
        if ($attention !== 'all' && !in_array($attention, self::ATTENTION_FILTERS, true)) { $attention = 'all'; }

        $users = self::user_names($companyuuid, $actor);
        $nodes = [];
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'active' => 1], 'sortorder ASC, name ASC') as $node) {
            $nodes[(string)$node->uuid] = ['uuid'=>(string)$node->uuid, 'name'=>(string)$node->name, 'parent_uuid'=>(string)$node->parentuuid];
        }
        if ($orgnodeuuid !== '' && !isset($nodes[$orgnodeuuid])) { $orgnodeuuid = ''; }

        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid, 'active' => 1], 'sortorder ASC, name ASC') as $position) {
            $positions[(string)$position->uuid] = [
                'uuid'=>(string)$position->uuid, 'name'=>(string)$position->name,
                'orgnode_uuid'=>(string)$position->orgnodeuuid,
            ];
        }

        $registry = indicator_registry_service::context($companyuuid, $actor);
        $criticalbyposition = [];
        foreach ($registry['bindings'] as $binding) {
            if (!empty($binding['compatibility']) || (string)$binding['contextkind'] !== 'position') { continue; }
            $positionuuid = (string)$binding['contextuuid'];
            if (!empty($binding['critical'])) { $criticalbyposition[$positionuuid] = ($criticalbyposition[$positionuuid] ?? 0) + 1; }
        }

        $exceptions = indicator_management_exception_service::context($companyuuid, $actor, 'all', 'all', 'all');
        $exceptionsbyposition = [];
        foreach ($exceptions['rows'] as $exception) {
            if ((string)$exception['binding']['contextkind'] !== 'position') { continue; }
            $positionuuid = (string)$exception['binding']['contextuuid'];
            if (!isset($exceptionsbyposition[$positionuuid])) {
                $exceptionsbyposition[$positionuuid] = ['total'=>0, 'critical'=>0, 'needs_decision'=>0];
            }
            $exceptionsbyposition[$positionuuid]['total']++;
            if ((string)$exception['severity'] === 'critical') { $exceptionsbyposition[$positionuuid]['critical']++; }
            if (!empty($exception['management_cycle']['requires_new_decision']) ||
                    (string)$exception['management_cycle']['status'] === 'needs_decision') {
                $exceptionsbyposition[$positionuuid]['needs_decision']++;
            }
        }

        $rows = [];
        $reviewqueue = [];
        $reviewers = [];
        foreach ($DB->get_records('local_kbacompany_bplan', ['companyuuid'=>$companyuuid, 'periodstart'=>$week], 'userid ASC, id ASC') as $plan) {
            if ((string)$plan->status === 'closed' || !self::can_access_plan($plan, $actor, $canmanage)) { continue; }
            $position = $positions[(string)$plan->positionuuid] ?? ['uuid'=>(string)$plan->positionuuid,'name'=>(string)$plan->positionuuid,'orgnode_uuid'=>''];
            if ($orgnodeuuid !== '' && (string)$position['orgnode_uuid'] !== $orgnodeuuid) { continue; }
            if ($reviewerid > 0 && (int)$plan->reviewerid !== $reviewerid) { continue; }
            if ((int)$plan->reviewerid > 0) { $reviewers[(int)$plan->reviewerid] = $users[(int)$plan->reviewerid] ?? (string)$plan->reviewerid; }
            $pex = $exceptionsbyposition[(string)$plan->positionuuid] ?? ['total'=>0,'critical'=>0,'needs_decision'=>0];
            $taskcounts = self::canonical_task_counts($companyuuid, $actor, (string)$plan->uuid);
            $metricperformance = self::metric_performance($companyuuid, $actor, (string)$plan->positionuuid,
                $week, (string)$plan->uuid);
            $productivity = (int)$taskcounts['total'] > 0
                ? (int)round(((int)$taskcounts['done'] / (int)$taskcounts['total']) * 100) : null;

            // Previous visible plan is used only for factual week-over-week deltas. No ranking or target threshold.
            $previousperformance = [
                'productivity'=>null, 'effectiveness'=>null, 'coverage'=>null,
                'task_total'=>0, 'task_done'=>0, 'metric_ok'=>0, 'metric_evaluated'=>0, 'metric_total'=>0,
            ];
            $previousrows = $DB->get_records_select('local_kbacompany_bplan',
                'companyuuid = :pcompany AND userid = :puser AND positionuuid = :pposition AND periodstart < :pweek',
                ['pcompany'=>$companyuuid, 'puser'=>(int)$plan->userid, 'pposition'=>(string)$plan->positionuuid,
                    'pweek'=>$week], 'periodstart DESC', '*', 0, 3);
            foreach ($previousrows as $previousplan) {
                if (!self::can_access_plan($previousplan, $actor, $canmanage)) { continue; }
                $prevtasks = self::canonical_task_counts($companyuuid, $actor, (string)$previousplan->uuid);
                $prevmetrics = self::metric_performance($companyuuid, $actor, (string)$previousplan->positionuuid,
                    (string)$previousplan->periodstart, (string)$previousplan->uuid);
                $previousperformance = [
                    'productivity'=>(int)$prevtasks['total'] > 0
                        ? (int)round(((int)$prevtasks['done'] / (int)$prevtasks['total']) * 100) : null,
                    'effectiveness'=>$prevmetrics['effectiveness'],
                    'coverage'=>$prevmetrics['coverage'],
                    'task_total'=>(int)$prevtasks['total'],
                    'task_done'=>(int)$prevtasks['done'],
                    'metric_ok'=>(int)($prevmetrics['counts']['ok'] ?? 0),
                    'metric_evaluated'=>(int)$prevmetrics['evaluated'],
                    'metric_total'=>(int)$prevmetrics['total'],
                ];
                break;
            }
            $row = [
                'plan_uuid' => (string)$plan->uuid,
                'status' => (string)$plan->status,
                'employee_id' => (int)$plan->userid,
                'employee_name' => $users[(int)$plan->userid] ?? (string)$plan->userid,
                'reviewer_id' => (int)$plan->reviewerid,
                'reviewer_valid' => (int)$plan->reviewerid > 0 && isset($users[(int)$plan->reviewerid]),
                'submitted_at' => (int)$plan->submittedat,
                'reviewer_name' => $users[(int)$plan->reviewerid] ?? ((int)$plan->reviewerid > 0 ? (string)$plan->reviewerid : ''),
                'position' => $position,
                'orgnode' => $nodes[(string)$position['orgnode_uuid']] ?? ['uuid'=>'','name'=>'','parent_uuid'=>''],
                'critical_indicators' => (int)($criticalbyposition[(string)$plan->positionuuid] ?? 0),
                'critical_exceptions' => (int)$pex['critical'],
                'exceptions' => (int)$pex['total'],
                'needs_decision' => (int)$pex['needs_decision'],
                'tasks' => $taskcounts,
                'performance' => [
                    'productivity'=>$productivity,
                    'effectiveness'=>$metricperformance['effectiveness'],
                    'coverage'=>$metricperformance['coverage'],
                    'metric_counts'=>$metricperformance['counts'],
                    'metric_evaluated'=>$metricperformance['evaluated'],
                    'metric_total'=>$metricperformance['total'],
                    'previous'=>$previousperformance,
                    'delta'=>[
                        'productivity'=>$productivity !== null && $previousperformance['productivity'] !== null
                            ? $productivity - (int)$previousperformance['productivity'] : null,
                        'effectiveness'=>$metricperformance['effectiveness'] !== null && $previousperformance['effectiveness'] !== null
                            ? (int)$metricperformance['effectiveness'] - (int)$previousperformance['effectiveness'] : null,
                        'coverage'=>$metricperformance['coverage'] !== null && $previousperformance['coverage'] !== null
                            ? (int)$metricperformance['coverage'] - (int)$previousperformance['coverage'] : null,
                    ],
                ],
                'url' => (new \moodle_url('/local/kbacompany/battle_plan.php', ['uuid'=>$companyuuid, 'plan'=>(string)$plan->uuid]))->out(false),
            ];
            $row['attention'] = self::attention_status($row);
            // Approval queue is independent of the attention signal: keep the week/unit/reviewer
            // filters but do not hide waiting reviews merely because another attention filter is set.
            // Reuse the same accessible plan projection, never query cross-company data here.
            if ((string)$plan->status === 'submitted' && ($canmanage ||
                    (int)$plan->reviewerid === $actor ||
                    access_role_service::may_approve_department_plan($companyuuid, $actor, (int)$plan->userid))) {
                $reviewqueue[] = $row;
            }
            if ($attention !== 'all' && $row['attention'] !== $attention) { continue; }
            $rows[] = $row;
        }

        $summary = ['active_plans'=>count($rows), 'critical_indicators'=>0, 'critical_exceptions'=>0,
            'needs_decision'=>0, 'open_tasks'=>0, 'overdue_tasks'=>0,
            'performance'=>['task_total'=>0,'task_done'=>0,'metric_ok'=>0,'metric_evaluated'=>0,'metric_total'=>0,
                'productivity'=>null,'effectiveness'=>null,'coverage'=>null]];
        $byunit = [];
        $byreviewer = [];
        foreach ($rows as $row) {
            $summary['critical_indicators'] += (int)$row['critical_indicators'];
            $summary['critical_exceptions'] += (int)$row['critical_exceptions'];
            $summary['needs_decision'] += (int)$row['needs_decision'];
            $summary['open_tasks'] += (int)$row['tasks']['open'];
            $summary['overdue_tasks'] += (int)$row['tasks']['overdue'];
            $summary['performance']['task_total'] += (int)$row['tasks']['total'];
            $summary['performance']['task_done'] += (int)$row['tasks']['done'];
            $summary['performance']['metric_ok'] += (int)($row['performance']['metric_counts']['ok'] ?? 0);
            $summary['performance']['metric_evaluated'] += (int)$row['performance']['metric_evaluated'];
            $summary['performance']['metric_total'] += (int)$row['performance']['metric_total'];

            $unitkey = (string)$row['orgnode']['uuid'];
            if ($unitkey === '') { $unitkey = '_none'; }
            if (!isset($byunit[$unitkey])) {
                $byunit[$unitkey] = [
                    'uuid'=>(string)$row['orgnode']['uuid'],
                    'name'=>(string)($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),
                    'plans'=>0,'needs_decision'=>0,'overdue_tasks'=>0,'critical_exceptions'=>0,
                    'management_tasks'=>['open'=>0,'done'=>0],
                    'performance'=>[
                        'task_total'=>0,'task_done'=>0,'metric_ok'=>0,'metric_evaluated'=>0,'metric_total'=>0,
                        'previous'=>['task_total'=>0,'task_done'=>0,'metric_ok'=>0,'metric_evaluated'=>0,'metric_total'=>0],
                    ],
                ];
            }
            $byunit[$unitkey]['plans']++;
            $byunit[$unitkey]['needs_decision'] += (int)$row['needs_decision'];
            $byunit[$unitkey]['overdue_tasks'] += (int)$row['tasks']['overdue'];
            $byunit[$unitkey]['critical_exceptions'] += (int)$row['critical_exceptions'];
            $byunit[$unitkey]['management_tasks']['open'] += (int)($row['tasks']['manual_open'] ?? 0);
            $byunit[$unitkey]['management_tasks']['done'] += (int)($row['tasks']['manual_done'] ?? 0);
            $byunit[$unitkey]['performance']['task_total'] += (int)($row['tasks']['total'] ?? 0);
            $byunit[$unitkey]['performance']['task_done'] += (int)($row['tasks']['done'] ?? 0);
            $byunit[$unitkey]['performance']['metric_ok'] += (int)($row['performance']['metric_counts']['ok'] ?? 0);
            $byunit[$unitkey]['performance']['metric_evaluated'] += (int)($row['performance']['metric_evaluated'] ?? 0);
            $byunit[$unitkey]['performance']['metric_total'] += (int)($row['performance']['metric_total'] ?? 0);
            $byunit[$unitkey]['performance']['previous']['task_total'] += (int)($row['performance']['previous']['task_total'] ?? 0);
            $byunit[$unitkey]['performance']['previous']['task_done'] += (int)($row['performance']['previous']['task_done'] ?? 0);
            $byunit[$unitkey]['performance']['previous']['metric_ok'] += (int)($row['performance']['previous']['metric_ok'] ?? 0);
            $byunit[$unitkey]['performance']['previous']['metric_evaluated'] += (int)($row['performance']['previous']['metric_evaluated'] ?? 0);
            $byunit[$unitkey]['performance']['previous']['metric_total'] += (int)($row['performance']['previous']['metric_total'] ?? 0);

            $reviewkey = (int)$row['reviewer_id'];
            if (!isset($byreviewer[$reviewkey])) {
                $byreviewer[$reviewkey] = ['user_id'=>$reviewkey, 'name'=>(string)($row['reviewer_name'] ?: get_string('battleplan_overview_no_reviewer','local_kbacompany')),
                    'plans'=>0,'needs_decision'=>0,'overdue_tasks'=>0,'critical_exceptions'=>0];
            }
            $byreviewer[$reviewkey]['plans']++;
            $byreviewer[$reviewkey]['needs_decision'] += (int)$row['needs_decision'];
            $byreviewer[$reviewkey]['overdue_tasks'] += (int)$row['tasks']['overdue'];
            $byreviewer[$reviewkey]['critical_exceptions'] += (int)$row['critical_exceptions'];
        }
        $summary['performance']['productivity'] = (int)$summary['performance']['task_total'] > 0
            ? (int)round(((int)$summary['performance']['task_done'] / (int)$summary['performance']['task_total']) * 100) : null;
        $summary['performance']['effectiveness'] = (int)$summary['performance']['metric_evaluated'] > 0
            ? (int)round(((int)$summary['performance']['metric_ok'] / (int)$summary['performance']['metric_evaluated']) * 100) : null;
        $summary['performance']['coverage'] = (int)$summary['performance']['metric_total'] > 0
            ? (int)round(((int)$summary['performance']['metric_evaluated'] / (int)$summary['performance']['metric_total']) * 100) : null;


        foreach ($byunit as &$unit) {
            $p = &$unit['performance'];
            $p['productivity'] = (int)$p['task_total'] > 0
                ? (int)round(((int)$p['task_done'] / (int)$p['task_total']) * 100) : null;
            $p['effectiveness'] = (int)$p['metric_evaluated'] > 0
                ? (int)round(((int)$p['metric_ok'] / (int)$p['metric_evaluated']) * 100) : null;
            $p['coverage'] = (int)$p['metric_total'] > 0
                ? (int)round(((int)$p['metric_evaluated'] / (int)$p['metric_total']) * 100) : null;
            $prev = &$p['previous'];
            $prev['productivity'] = (int)$prev['task_total'] > 0
                ? (int)round(((int)$prev['task_done'] / (int)$prev['task_total']) * 100) : null;
            $prev['effectiveness'] = (int)$prev['metric_evaluated'] > 0
                ? (int)round(((int)$prev['metric_ok'] / (int)$prev['metric_evaluated']) * 100) : null;
            $prev['coverage'] = (int)$prev['metric_total'] > 0
                ? (int)round(((int)$prev['metric_evaluated'] / (int)$prev['metric_total']) * 100) : null;
            $p['delta'] = [
                'productivity'=>$p['productivity'] !== null && $prev['productivity'] !== null
                    ? (int)$p['productivity'] - (int)$prev['productivity'] : null,
                'effectiveness'=>$p['effectiveness'] !== null && $prev['effectiveness'] !== null
                    ? (int)$p['effectiveness'] - (int)$prev['effectiveness'] : null,
                'coverage'=>$p['coverage'] !== null && $prev['coverage'] !== null
                    ? (int)$p['coverage'] - (int)$prev['coverage'] : null,
            ];
            unset($prev);
            unset($p);
        }
        unset($unit);

        usort($rows, static function(array $a, array $b): int {
            $rank = ['needs_decision'=>5,'overdue'=>4,'critical'=>3,'open'=>2,'clear'=>1];
            $cmp = ($rank[$b['attention']] ?? 0) <=> ($rank[$a['attention']] ?? 0);
            if ($cmp !== 0) { return $cmp; }
            $cmp = strcmp((string)$a['orgnode']['name'], (string)$b['orgnode']['name']);
            if ($cmp !== 0) { return $cmp; }
            return strcmp((string)$a['employee_name'], (string)$b['employee_name']);
        });

        usort($reviewqueue, static function(array $a, array $b): int {
            // Oldest submitted plan first, then employee name; all items keep their canonical URL.
            $cmp = (int)$a['submitted_at'] <=> (int)$b['submitted_at'];
            return $cmp !== 0 ? $cmp : strcmp((string)$a['employee_name'], (string)$b['employee_name']);
        });
        return [
            'review_queue'=>$reviewqueue,
            'company'=>$company, 'can_manage'=>$canmanage, 'week_start'=>$week,
            'week_end'=>(new \DateTimeImmutable($week, new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d'),
            'orgnode_filter'=>$orgnodeuuid, 'reviewer_filter'=>$reviewerid, 'attention_filter'=>$attention,
            'orgnodes'=>$nodes, 'reviewers'=>$reviewers, 'summary'=>$summary, 'rows'=>$rows,
            'by_unit'=>array_values($byunit), 'by_reviewer'=>array_values($byreviewer),
        ];
    }
}
