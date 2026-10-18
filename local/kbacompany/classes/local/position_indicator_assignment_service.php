<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Position -> canonical indicator bindings used by Battle Plans. */
class position_indicator_assignment_service {
    public const CONTRACT_VERSION = '1.1.0-rc6';

    public static function contract(): array {
        return [
            'contract' => 'bms_position_indicator_assignment',
            'version' => self::CONTRACT_VERSION,
            'canonical_indicator' => 'local_kbacompany_stat',
            'canonical_binding' => 'local_kbacompany_statbind',
            'canonical_norm' => 'local_kbacompany_statnorm',
            'rules' => [
                'position_owns_no_indicator_copy',
                'position_assignment_is_canonical_indicator_binding',
                'battle_plan_reads_weekly_position_bindings',
                'norm_is_versioned_separately',
                'company_scope_before_fetch',
            ],
        ];
    }

    private static function metric_map(array $context): array {
        $map = [];
        foreach ($context['metrics'] as $metric) {
            $map[(string)$metric['uuid']] = $metric;
        }
        return $map;
    }

    private static function norm_map(array $context): array {
        $map = [];
        foreach ($context['norms'] as $norm) {
            $map[(string)$norm['binduuid']] = $norm;
        }
        return $map;
    }

    public static function context(string $companyuuid, int $actor, string $positionuuid): array {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $position = $DB->get_record('local_kbacompany_position', [
            'companyuuid' => $companyuuid, 'uuid' => $positionuuid,
        ], '*', MUST_EXIST);
        $registry = indicator_registry_service::context($companyuuid, $actor);
        $metrics = self::metric_map($registry);
        $norms = self::norm_map($registry);
        $rows = [];
        $boundstats = [];
        foreach ($registry['bindings'] as $binding) {
            if ((string)$binding['contextkind'] !== 'position' ||
                    (string)$binding['contextuuid'] !== $positionuuid) {
                continue;
            }
            $metric = $metrics[(string)$binding['statuuid']] ?? null;
            if (!$metric) { continue; }
            $boundstats[(string)$metric['uuid']] = true;
            $rows[] = [
                'binding' => $binding,
                'metric' => $metric,
                'norm' => $norms[(string)$binding['uuid']] ?? null,
                'compatibility' => !empty($binding['compatibility']),
                'appears_in_weekly_battle_plan' => (string)$metric['periodtype'] === 'weekly',
            ];
        }
        usort($rows, static function(array $a, array $b): int {
            $as = (int)($a['binding']['sortorder'] ?? 0);
            $bs = (int)($b['binding']['sortorder'] ?? 0);
            if ($as !== $bs) { return $as <=> $bs; }
            return strcmp((string)$a['metric']['code'], (string)$b['metric']['code']);
        });
        $available = [];
        foreach ($registry['metrics'] as $metric) {
            if (!isset($boundstats[(string)$metric['uuid']])) { $available[] = $metric; }
        }
        return [
            'position' => $position,
            'rows' => $rows,
            'available' => $available,
            'can_manage' => indicator_registry_service::can_manage($companyuuid, $actor),
            'contract' => self::contract(),
        ];
    }

    private static function binding_for_position(string $companyuuid, int $actor, string $positionuuid,
            string $binduuid): array {
        $ctx = self::context($companyuuid, $actor, $positionuuid);
        foreach ($ctx['rows'] as $row) {
            if ((string)$row['binding']['uuid'] === $binduuid && empty($row['compatibility'])) {
                return $row;
            }
        }
        throw new \moodle_exception('positionindicator_invalid_binding', 'local_kbacompany');
    }

    public static function save(string $companyuuid, int $actor, string $positionuuid, array $data,
            string $binduuid = ''): string {
        if (!indicator_registry_service::can_manage($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        if ($binduuid !== '') { self::binding_for_position($companyuuid, $actor, $positionuuid, $binduuid); }
        $saved = indicator_registry_service::save_binding($companyuuid, $actor, [
            'statuuid' => (string)($data['statuuid'] ?? ''),
            'contextkind' => 'position',
            'contextuuid' => $positionuuid,
            'isprimary' => !empty($data['isprimary']) ? 1 : 0,
            'critical' => !empty($data['critical']) ? 1 : 0,
            'weight' => (string)($data['weight'] ?? '1'),
            'sortorder' => (int)($data['sortorder'] ?? 0),
        ], $binduuid);

        $normkind = trim((string)($data['normkind'] ?? ''));
        $target = trim((string)($data['targetvalue'] ?? ''));
        $min = trim((string)($data['minval'] ?? ''));
        $max = trim((string)($data['maxval'] ?? ''));
        if ($target !== '' || $min !== '' || $max !== '') {
            if ($normkind === '') { $normkind = 'target'; }
            indicator_registry_service::save_norm($companyuuid, $actor, [
                'binduuid' => $saved,
                'normkind' => $normkind,
                'targetvalue' => $target,
                'minval' => $min,
                'maxval' => $max,
                'effectivefrom' => (string)($data['effectivefrom'] ?? date('Y-m-d')),
                'effectiveto' => (string)($data['effectiveto'] ?? ''),
            ]);
        }
        return $saved;
    }

    public static function archive(string $companyuuid, int $actor, string $positionuuid, string $binduuid): void {
        if (!indicator_registry_service::can_manage($companyuuid, $actor)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/kbacompany:manageorganization', 'nopermissions', '');
        }
        self::binding_for_position($companyuuid, $actor, $positionuuid, $binduuid);
        indicator_registry_service::archive_binding($companyuuid, $actor, $binduuid);
    }
}
