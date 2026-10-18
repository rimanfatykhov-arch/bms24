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
    public const CONTRACT_VERSION = '0.5.10';
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
        $out = ['total' => 0, 'open' => 0, 'overdue' => 0, 'done' => 0];
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
            if ((string)$task->status === 'done') {
                $out['done']++;
                continue;
            }
            if ((string)$task->status === 'cancelled') { continue; }
            $out['open']++;
            if ((string)$task->duedate !== '' && (string)$task->duedate < $today) { $out['overdue']++; }
        }
        return $out;
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
        $reviewers = [];
        foreach ($DB->get_records('local_kbacompany_bplan', ['companyuuid'=>$companyuuid, 'periodstart'=>$week], 'userid ASC, id ASC') as $plan) {
            if ((string)$plan->status === 'closed' || !self::can_access_plan($plan, $actor, $canmanage)) { continue; }
            $position = $positions[(string)$plan->positionuuid] ?? ['uuid'=>(string)$plan->positionuuid,'name'=>(string)$plan->positionuuid,'orgnode_uuid'=>''];
            if ($orgnodeuuid !== '' && (string)$position['orgnode_uuid'] !== $orgnodeuuid) { continue; }
            if ($reviewerid > 0 && (int)$plan->reviewerid !== $reviewerid) { continue; }
            if ((int)$plan->reviewerid > 0) { $reviewers[(int)$plan->reviewerid] = $users[(int)$plan->reviewerid] ?? (string)$plan->reviewerid; }
            $pex = $exceptionsbyposition[(string)$plan->positionuuid] ?? ['total'=>0,'critical'=>0,'needs_decision'=>0];
            $taskcounts = self::canonical_task_counts($companyuuid, $actor, (string)$plan->uuid);
            $row = [
                'plan_uuid' => (string)$plan->uuid,
                'status' => (string)$plan->status,
                'employee_id' => (int)$plan->userid,
                'employee_name' => $users[(int)$plan->userid] ?? (string)$plan->userid,
                'reviewer_id' => (int)$plan->reviewerid,
                'reviewer_name' => $users[(int)$plan->reviewerid] ?? ((int)$plan->reviewerid > 0 ? (string)$plan->reviewerid : ''),
                'position' => $position,
                'orgnode' => $nodes[(string)$position['orgnode_uuid']] ?? ['uuid'=>'','name'=>'','parent_uuid'=>''],
                'critical_indicators' => (int)($criticalbyposition[(string)$plan->positionuuid] ?? 0),
                'critical_exceptions' => (int)$pex['critical'],
                'exceptions' => (int)$pex['total'],
                'needs_decision' => (int)$pex['needs_decision'],
                'tasks' => $taskcounts,
                'url' => (new \moodle_url('/local/kbacompany/battle_plan.php', ['uuid'=>$companyuuid, 'plan'=>(string)$plan->uuid]))->out(false),
            ];
            $row['attention'] = self::attention_status($row);
            if ($attention !== 'all' && $row['attention'] !== $attention) { continue; }
            $rows[] = $row;
        }

        $summary = ['active_plans'=>count($rows), 'critical_indicators'=>0, 'critical_exceptions'=>0,
            'needs_decision'=>0, 'open_tasks'=>0, 'overdue_tasks'=>0];
        $byunit = [];
        $byreviewer = [];
        foreach ($rows as $row) {
            $summary['critical_indicators'] += (int)$row['critical_indicators'];
            $summary['critical_exceptions'] += (int)$row['critical_exceptions'];
            $summary['needs_decision'] += (int)$row['needs_decision'];
            $summary['open_tasks'] += (int)$row['tasks']['open'];
            $summary['overdue_tasks'] += (int)$row['tasks']['overdue'];

            $unitkey = (string)$row['orgnode']['uuid'];
            if ($unitkey === '') { $unitkey = '_none'; }
            if (!isset($byunit[$unitkey])) {
                $byunit[$unitkey] = ['uuid'=>(string)$row['orgnode']['uuid'], 'name'=>(string)($row['orgnode']['name'] ?: get_string('battleplan_overview_no_unit','local_kbacompany')),
                    'plans'=>0,'needs_decision'=>0,'overdue_tasks'=>0,'critical_exceptions'=>0];
            }
            $byunit[$unitkey]['plans']++;
            $byunit[$unitkey]['needs_decision'] += (int)$row['needs_decision'];
            $byunit[$unitkey]['overdue_tasks'] += (int)$row['tasks']['overdue'];
            $byunit[$unitkey]['critical_exceptions'] += (int)$row['critical_exceptions'];

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
        usort($rows, static function(array $a, array $b): int {
            $rank = ['needs_decision'=>5,'overdue'=>4,'critical'=>3,'open'=>2,'clear'=>1];
            $cmp = ($rank[$b['attention']] ?? 0) <=> ($rank[$a['attention']] ?? 0);
            if ($cmp !== 0) { return $cmp; }
            $cmp = strcmp((string)$a['orgnode']['name'], (string)$b['orgnode']['name']);
            if ($cmp !== 0) { return $cmp; }
            return strcmp((string)$a['employee_name'], (string)$b['employee_name']);
        });

        return [
            'company'=>$company, 'can_manage'=>$canmanage, 'week_start'=>$week,
            'week_end'=>(new \DateTimeImmutable($week, new \DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d'),
            'orgnode_filter'=>$orgnodeuuid, 'reviewer_filter'=>$reviewerid, 'attention_filter'=>$attention,
            'orgnodes'=>$nodes, 'reviewers'=>$reviewers, 'summary'=>$summary, 'rows'=>$rows,
            'by_unit'=>array_values($byunit), 'by_reviewer'=>array_values($byreviewer),
        ];
    }
}
