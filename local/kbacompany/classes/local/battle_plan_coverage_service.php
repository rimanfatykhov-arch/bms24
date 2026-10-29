<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Schema-neutral Battle Plan coverage and management-gap read-model.
 *
 * A management gap is not persisted. It is derived from factual position
 * assignments, weekly Battle Plans and canonical position indicator bindings.
 */
class battle_plan_coverage_service {
    public const CONTRACT_VERSION = '0.5.11';
    public const GAP_FILTERS = ['missing_plan', 'no_critical', 'no_norm'];

    public static function contract(): array {
        $contract = [
            'component' => 'local_kbacompany',
            'contract' => 'bms_battle_plan_coverage',
            'version' => self::CONTRACT_VERSION,
            'storage' => 'none',
            'sources' => [
                'assignments' => 'local_kbacompany_posassign',
                'positions' => 'local_kbacompany_position + local_kbacompany_orgnode',
                'battle_plans' => 'local_kbacompany_bplan',
                'critical_indicators' => 'local_kbacompany_statbind',
                'norms' => 'local_kbacompany_statnorm revision history',
            ],
            'rules' => [
                'coverage_is_read_model_not_control_registry',
                'one_expected_plan_per_employee_position_week',
                'historical_assignment_uses_effectivefrom_effectiveto',
                'closed_plan_still_counts_as_existing_weekly_plan',
                'critical_indicator_gap_uses_canonical_position_binding',
                'norm_gap_uses_effective_norm_revision_for_selected_week',
                'no_hidden_thresholds',
                'company_wide_gap_view_requires_management_permission',
            ],
        ];
        $contract['schema_hash_algorithm'] = 'sha256';
        $contract['schema_hash'] = hash('sha256', json_encode($contract,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $contract;
    }

    private static function require_management_access(string $companyuuid, int $actor): void {
        company_context_service::context($companyuuid, $actor);
        if (!task_service::can_manage($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                permission_service::CAPABILITY_MANAGE, 'nopermissions', '');
        }
    }

    private static function week_bounds(string $week): array {
        $start = battle_plan_service::week_start($week !== '' ? $week : gmdate('Y-m-d'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, new \DateTimeZone('UTC'));
        $end = $date->modify('+6 days')->format('Y-m-d');
        $startts = $date->getTimestamp();
        $endts = $date->modify('+7 days')->getTimestamp() - 1;
        return [$start, $end, $startts, $endts];
    }

    private static function employee_names(string $companyuuid, int $actor): array {
        $organization = organization_service::organization_context($companyuuid, $actor);
        $out = [];
        foreach ($organization['employees'] as $employee) {
            $out[(int)$employee['user_id']] = (string)$employee['full_name'];
        }
        return $out;
    }

    private static function assignments_for_week(string $companyuuid, int $startts, int $endts): array {
        global $DB;
        $rows = $DB->get_records('local_kbacompany_posassign', ['companyuuid' => $companyuuid],
            'userid ASC, positionuuid ASC, effectivefrom ASC, id ASC');
        $pairs = [];
        foreach ($rows as $row) {
            $from = (int)$row->effectivefrom;
            $to = (int)$row->effectiveto;
            if ($from > 0 && $from > $endts) { continue; }
            if ($to > 0 && $to < $startts) { continue; }
            $key = (int)$row->userid . '|' . (string)$row->positionuuid;
            // One weekly Battle Plan is expected per employee + position, not per staffing slot.
            if (!isset($pairs[$key]) || (string)$row->assignmenttype === 'primary') {
                $pairs[$key] = $row;
            }
        }
        return array_values($pairs);
    }

    public static function context(string $companyuuid, int $actor, string $week = '',
            string $orgnodeuuid = '', string $gapfilter = 'all'): array {
        global $DB;
        self::require_management_access($companyuuid, $actor);
        list($weekstart, $weekend, $startts, $endts) = self::week_bounds($week);
        if ($gapfilter !== 'all' && !in_array($gapfilter, self::GAP_FILTERS, true)) { $gapfilter = 'all'; }

        $employees = self::employee_names($companyuuid, $actor);
        $nodes = [];
        foreach ($DB->get_records('local_kbacompany_orgnode', ['companyuuid' => $companyuuid, 'active' => 1],
                'sortorder ASC, name ASC') as $node) {
            $nodes[(string)$node->uuid] = [
                'uuid' => (string)$node->uuid,
                'name' => (string)$node->name,
                'parent_uuid' => (string)$node->parentuuid,
            ];
        }
        if ($orgnodeuuid !== '' && !isset($nodes[$orgnodeuuid])) { $orgnodeuuid = ''; }

        $positions = [];
        foreach ($DB->get_records('local_kbacompany_position', ['companyuuid' => $companyuuid],
                'sortorder ASC, name ASC') as $position) {
            $positions[(string)$position->uuid] = [
                'uuid' => (string)$position->uuid,
                'name' => (string)$position->name,
                'code' => (string)$position->code,
                'orgnode_uuid' => (string)$position->orgnodeuuid,
                'active' => !empty($position->active),
            ];
        }

        $planmap = [];
        foreach ($DB->get_records('local_kbacompany_bplan', [
                'companyuuid' => $companyuuid, 'periodstart' => $weekstart]) as $plan) {
            $planmap[(int)$plan->userid . '|' . (string)$plan->positionuuid] = $plan;
        }

        $registry = indicator_registry_service::context($companyuuid, $actor);
        $metricmap = [];
        foreach ($registry['metrics'] as $metric) { $metricmap[(string)$metric['uuid']] = $metric; }
        $critical = [];
        foreach ($registry['bindings'] as $binding) {
            if (!empty($binding['compatibility']) || (string)$binding['contextkind'] !== 'position' || empty($binding['critical'])) {
                continue;
            }
            $critical[(string)$binding['contextuuid']][] = $binding;
        }

        $rows = [];
        $summary = [
            'expected_assignments' => 0,
            'covered_assignments' => 0,
            'missing_plans' => 0,
            'active_positions' => 0,
            'positions_without_critical' => 0,
            'critical_bindings' => 0,
            'critical_without_norm' => 0,
            'units_with_gaps' => 0,
        ];
        $unitstats = [];
        $ensureunit = static function(string $nodeuuid) use (&$unitstats, $nodes): void {
            $key = $nodeuuid !== '' ? $nodeuuid : '__none__';
            if (!isset($unitstats[$key])) {
                $unitstats[$key] = [
                    'uuid' => $nodeuuid,
                    'name' => $nodeuuid !== '' && isset($nodes[$nodeuuid]) ? $nodes[$nodeuuid]['name'] : '',
                    'expected_assignments' => 0,
                    'covered_assignments' => 0,
                    'missing_plans' => 0,
                    'positions_without_critical' => 0,
                    'critical_without_norm' => 0,
                    'gap_count' => 0,
                ];
            }
        };

        foreach (self::assignments_for_week($companyuuid, $startts, $endts) as $assignment) {
            $position = $positions[(string)$assignment->positionuuid] ?? [
                'uuid' => (string)$assignment->positionuuid, 'name' => (string)$assignment->positionuuid,
                'code' => '', 'orgnode_uuid' => '', 'active' => false,
            ];
            if ($orgnodeuuid !== '' && (string)$position['orgnode_uuid'] !== $orgnodeuuid) { continue; }
            $summary['expected_assignments']++;
            $ensureunit((string)$position['orgnode_uuid']);
            $ukey = (string)$position['orgnode_uuid'] !== '' ? (string)$position['orgnode_uuid'] : '__none__';
            $unitstats[$ukey]['expected_assignments']++;
            $pairkey = (int)$assignment->userid . '|' . (string)$assignment->positionuuid;
            $plan = $planmap[$pairkey] ?? null;
            if ($plan) {
                $summary['covered_assignments']++;
                $unitstats[$ukey]['covered_assignments']++;
                continue;
            }
            $summary['missing_plans']++;
            $unitstats[$ukey]['missing_plans']++;
            $unitstats[$ukey]['gap_count']++;
            if ($gapfilter === 'all' || $gapfilter === 'missing_plan') {
                $rows[] = [
                    'type' => 'missing_plan',
                    'employee_id' => (int)$assignment->userid,
                    'employee_name' => $employees[(int)$assignment->userid] ?? (string)$assignment->userid,
                    'assignment_type' => (string)$assignment->assignmenttype,
                    'position' => $position,
                    'orgnode' => $nodes[(string)$position['orgnode_uuid']] ?? ['uuid'=>'','name'=>'','parent_uuid'=>''],
                    'count' => 1,
                    'details' => '',
                    'action_url' => (new \moodle_url('/local/kbacompany/battle_plan.php', [
                        'uuid' => $companyuuid, 'view' => 'create', 'contextual' => 1,
                        'user' => (int)$assignment->userid, 'position' => (string)$assignment->positionuuid, 'week' => $weekstart,
                    ]))->out(false),
                    'action_kind' => 'battleplan',
                ];
            }
        }

        foreach ($positions as $positionuuid => $position) {
            if (empty($position['active'])) { continue; }
            if ($orgnodeuuid !== '' && (string)$position['orgnode_uuid'] !== $orgnodeuuid) { continue; }
            $summary['active_positions']++;
            $ensureunit((string)$position['orgnode_uuid']);
            $ukey = (string)$position['orgnode_uuid'] !== '' ? (string)$position['orgnode_uuid'] : '__none__';
            $bindings = $critical[$positionuuid] ?? [];
            if (!$bindings) {
                $summary['positions_without_critical']++;
                $unitstats[$ukey]['positions_without_critical']++;
                $unitstats[$ukey]['gap_count']++;
                if ($gapfilter === 'all' || $gapfilter === 'no_critical') {
                    $rows[] = [
                        'type' => 'no_critical', 'employee_id' => 0, 'employee_name' => '', 'assignment_type' => '',
                        'position' => $position,
                        'orgnode' => $nodes[(string)$position['orgnode_uuid']] ?? ['uuid'=>'','name'=>'','parent_uuid'=>''],
                        'count' => 1, 'details' => '',
                        'action_url' => (new \moodle_url('/local/kbacompany/indicators.php', [
                            'uuid'=>$companyuuid, 'tab'=>'tree', 'treeview'=>'organization']))->out(false),
                        'action_kind' => 'indicator',
                    ];
                }
                continue;
            }
            $summary['critical_bindings'] += count($bindings);
            $missing = [];
            foreach ($bindings as $binding) {
                $norm = indicator_event_service::norm_for_period($registry['normhistory'], (string)$binding['uuid'], $weekstart);
                if ($norm) { continue; }
                $metric = $metricmap[(string)$binding['statuuid']] ?? null;
                $missing[] = $metric ? ((string)$metric['code'] . ' — ' . (string)$metric['name']) : (string)$binding['statuuid'];
            }
            if ($missing) {
                $summary['critical_without_norm'] += count($missing);
                $unitstats[$ukey]['critical_without_norm'] += count($missing);
                $unitstats[$ukey]['gap_count'] += count($missing);
                if ($gapfilter === 'all' || $gapfilter === 'no_norm') {
                    $rows[] = [
                        'type' => 'no_norm', 'employee_id' => 0, 'employee_name' => '', 'assignment_type' => '',
                        'position' => $position,
                        'orgnode' => $nodes[(string)$position['orgnode_uuid']] ?? ['uuid'=>'','name'=>'','parent_uuid'=>''],
                        'count' => count($missing), 'details' => implode('; ', $missing),
                        'action_url' => (new \moodle_url('/local/kbacompany/indicators.php', [
                            'uuid'=>$companyuuid, 'tab'=>'norms']))->out(false),
                        'action_kind' => 'indicator',
                    ];
                }
            }
        }

        $byunit = array_values($unitstats);
        usort($byunit, static function(array $a, array $b): int {
            if ((int)$a['gap_count'] !== (int)$b['gap_count']) { return (int)$b['gap_count'] <=> (int)$a['gap_count']; }
            return strcmp((string)$a['name'], (string)$b['name']);
        });
        foreach ($byunit as $unit) { if ((int)$unit['gap_count'] > 0) { $summary['units_with_gaps']++; } }
        usort($rows, static function(array $a, array $b): int {
            $priority = ['missing_plan'=>1, 'no_critical'=>2, 'no_norm'=>3];
            $pa = $priority[(string)$a['type']] ?? 99;
            $pb = $priority[(string)$b['type']] ?? 99;
            if ($pa !== $pb) { return $pa <=> $pb; }
            $unitcmp = strcmp((string)$a['orgnode']['name'], (string)$b['orgnode']['name']);
            if ($unitcmp !== 0) { return $unitcmp; }
            return strcmp((string)$a['position']['name'], (string)$b['position']['name']);
        });

        return [
            'company' => company_context_service::context($companyuuid, $actor),
            'week_start' => $weekstart,
            'week_end' => $weekend,
            'orgnode_filter' => $orgnodeuuid,
            'gap_filter' => $gapfilter,
            'orgnodes' => array_values($nodes),
            'summary' => $summary,
            'by_unit' => $byunit,
            'rows' => $rows,
            'contract' => self::contract(),
        ];
    }
}
