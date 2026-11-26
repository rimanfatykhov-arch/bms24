<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only Information Centre model (#190): main company metric on top, then the active divisions of the
 * default organizing-board branch as columns in board order, each listing its own departments/sections and
 * positions that have bound metrics.
 *
 * - Authorisation and company scope come from indicator_registry_service::context() (company membership via
 *   company_context_service). Every query here is filtered by the canonical company UUID; nothing from other
 *   companies is fetched and filtered in PHP.
 * - No divisions, positions or metrics are hard-coded: the structure is the active org board and the effective
 *   bindings of the indicator registry (including read-only compatibility bindings).
 * - Nothing is written. Trend direction comes from indicator_trend_service.
 */
class information_center_service {
    /** Allowed chart windows, in periods. */
    public const WINDOWS = [13, 26, 52];
    /** Periods shown on the mini chart. */
    public const MINI_POINTS = 13;

    /**
     * Build the overview.
     *
     * @return array{company: array, main: array[], divisions: array[], unbound: array[], has_board: bool,
     *               multiple_main: bool}
     */
    public static function overview(string $companyuuid, int $userid): array {
        $registry = indicator_registry_service::context($companyuuid, $userid);
        $metrics = [];
        foreach ($registry['metrics'] as $metric) {
            if (($metric['status'] ?? 'active') === 'archived') { continue; }
            $metrics[(string)$metric['uuid']] = $metric;
        }

        $branchuuid = self::default_branch($companyuuid);
        $nodes = [];
        foreach ($registry['nodes'] as $node) {
            if ($branchuuid !== '' && (string)$node->branchuuid === $branchuuid) {
                $nodes[(string)$node->uuid] = $node;
            }
        }
        $positions = [];
        foreach ($registry['positions'] as $position) {
            if (isset($nodes[(string)$position->orgnodeuuid])) {
                $positions[(string)$position->uuid] = $position;
            }
        }

        // Metrics per org context, in the registry binding order (sortorder, then code).
        $bound = ['orgnode' => [], 'position' => []];
        $placed = [];
        $bindings = $registry['bindings'];
        usort($bindings, static function(array $a, array $b): int {
            return [(int)($a['sortorder'] ?? 0), (string)$a['statuuid']] <=> [(int)($b['sortorder'] ?? 0), (string)$b['statuuid']];
        });
        foreach ($bindings as $binding) {
            $kind = (string)$binding['contextkind'];
            $context = (string)$binding['contextuuid'];
            $stat = (string)$binding['statuuid'];
            if (!isset($metrics[$stat]) || !isset($bound[$kind])) { continue; }
            if (($kind === 'orgnode' && !isset($nodes[$context])) || ($kind === 'position' && !isset($positions[$context]))) {
                continue;
            }
            if (!in_array($stat, $bound[$kind][$context] ?? [], true)) {
                $bound[$kind][$context][] = $stat;
                $placed[$stat] = true;
            }
        }

        $cards = [];
        $card = static function(string $stat) use (&$cards, $companyuuid, $metrics): array {
            if (!isset($cards[$stat])) {
                $cards[$stat] = self::metric_card($companyuuid, $metrics[$stat]);
            }
            return $cards[$stat];
        };

        $main = [];
        foreach ($metrics as $stat => $metric) {
            if (!empty($metric['maincompany'])) { $main[] = $card($stat); }
        }

        // Board order: children by sortorder then code, as on the organizing board.
        $children = [];
        foreach ($nodes as $node) {
            $children[(string)$node->parentuuid][] = $node;
        }
        foreach ($children as &$list) {
            usort($list, [self::class, 'compare_nodes']);
        }
        unset($list);
        $nodepositions = [];
        foreach ($positions as $position) {
            $nodepositions[(string)$position->orgnodeuuid][] = $position;
        }
        foreach ($nodepositions as &$list) {
            usort($list, static function($a, $b): int {
                return [(int)$a->sortorder, (string)$a->code] <=> [(int)$b->sortorder, (string)$b->code];
            });
        }
        unset($list);

        $divisionnodes = array_values(array_filter($nodes, static function($node): bool {
            return (string)$node->nodetype === 'division';
        }));
        usort($divisionnodes, [self::class, 'compare_nodes']);
        $divisions = [];
        foreach ($divisionnodes as $division) {
            $groups = [];
            self::collect_groups($division, 0, $children, $nodepositions, $bound, $card, $groups);
            $count = 0;
            foreach ($groups as $group) { $count += count($group['metrics']); }
            $divisions[] = [
                'uuid' => (string)$division->uuid,
                'code' => (string)$division->code,
                'name' => (string)$division->name,
                'color' => self::safe_color((string)$division->color),
                'groups' => $groups,
                'metric_count' => $count,
            ];
        }

        $unbound = [];
        foreach ($metrics as $stat => $metric) {
            if (empty($metric['maincompany']) && empty($placed[$stat])) { $unbound[] = $card($stat); }
        }

        return [
            'company' => $registry['company'],
            'main' => $main,
            'multiple_main' => count($main) > 1,
            'divisions' => $divisions,
            'has_board' => $divisionnodes !== [],
            'unbound' => $unbound,
            'can_manage' => !empty($registry['can_manage']),
        ];
    }

    /** Default active branch of the organizing board: the flagged default, else the first by sortorder/name. */
    private static function default_branch(string $companyuuid): string {
        global $DB;
        $branches = $DB->get_records('local_kbacompany_orgbranch', ['companyuuid' => $companyuuid, 'active' => 1],
            'sortorder ASC, name ASC', 'id, uuid, isdefault');
        foreach ($branches as $branch) {
            if (!empty($branch->isdefault)) { return (string)$branch->uuid; }
        }
        $first = reset($branches);
        return $first ? (string)$first->uuid : '';
    }

    public static function compare_nodes($a, $b): int {
        return [(int)$a->sortorder, (string)$a->code, (string)$a->uuid] <=> [(int)$b->sortorder, (string)$b->code, (string)$b->uuid];
    }

    /** Depth-first walk of a division: the node itself, its positions, then child nodes in board order. */
    private static function collect_groups($node, int $depth, array $children, array $nodepositions, array $bound,
            callable $card, array &$groups): void {
        $nodeuuid = (string)$node->uuid;
        $stats = $bound['orgnode'][$nodeuuid] ?? [];
        if ($stats) {
            $groups[] = ['kind' => 'orgnode', 'nodetype' => (string)($node->nodetype ?? ''), 'uuid' => $nodeuuid,
                'code' => (string)$node->code,
                'name' => (string)$node->name, 'depth' => $depth, 'metrics' => array_map($card, $stats)];
        }
        foreach ($nodepositions[$nodeuuid] ?? [] as $position) {
            $stats = $bound['position'][(string)$position->uuid] ?? [];
            if ($stats) {
                $groups[] = ['kind' => 'position', 'uuid' => (string)$position->uuid, 'code' => (string)$position->code,
                    'name' => (string)$position->name, 'depth' => $depth + 1, 'metrics' => array_map($card, $stats)];
            }
        }
        foreach ($children[$nodeuuid] ?? [] as $child) {
            self::collect_groups($child, $depth + 1, $children, $nodepositions, $bound, $card, $groups);
        }
    }

    /** Only #rgb/#rrggbb colours reach the style attribute. */
    public static function safe_color(string $color): string {
        return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color) ? $color : '';
    }

    /** One metric with its chart points (largest window), mini-chart slice and trend of the mini window. */
    public static function metric_card(string $companyuuid, array $metric): array {
        $series = indicator_registry_service::series_for_metric($companyuuid, (string)$metric['uuid'], max(self::WINDOWS));
        $points = self::points($series, (string)$metric['periodtype']);
        $mini = self::tail($points, self::MINI_POINTS);
        $trend = indicator_trend_service::analyze(array_map(static function(array $p): array {
            return ['period' => $p['period'], 'fact' => $p['fact']];
        }, array_values(array_filter($mini, static function(array $p): bool { return !$p['gap']; }))),
            (string)$metric['direction']);
        $last = null;
        foreach (array_reverse($points) as $point) {
            if (!$point['gap'] && $point['fact'] !== null) { $last = $point; break; }
        }
        return [
            'uuid' => (string)$metric['uuid'],
            'code' => (string)$metric['code'],
            'name' => (string)$metric['name'],
            'unit' => (string)$metric['unit'],
            'periodtype' => (string)$metric['periodtype'],
            'direction' => (string)$metric['direction'],
            'points' => $points,
            'mini' => $mini,
            'fact_count' => count(array_filter($points, static function(array $p): bool { return $p['fact'] !== null; })),
            'last' => $last,
            'trend' => self::trend_view($trend, (string)$metric['direction']),
        ];
    }

    /**
     * Normalise a registry series into chart points and insert explicit gap markers where expected periods are
     * missing, so a chart never joins values across a missing period. Values stay exact decimal strings converted
     * to float only here; a real zero stays 0.0 (never "no data").
     *
     * @return array[] {period:string, fact:?float, plan:?float, gap:bool}
     */
    public static function points(array $series, string $periodtype): array {
        $out = [];
        $previous = null;
        foreach ($series as $row) {
            $period = (string)$row['period'];
            if ($previous !== null) {
                $expected = self::next_period($previous, $periodtype);
                if ($expected !== null && strcmp($period, $expected) > 0) {
                    $out[] = ['period' => $expected, 'fact' => null, 'plan' => null, 'gap' => true];
                }
            }
            $out[] = [
                'period' => $period,
                'fact' => $row['fact'] === null || $row['fact'] === '' ? null : (float)$row['fact'],
                'plan' => $row['plan'] === null || $row['plan'] === '' ? null : (float)$row['plan'],
                'gap' => false,
            ];
            $previous = $period;
        }
        return $out;
    }

    /** Last $count real periods (gap markers do not count, but are kept inside the window). */
    public static function tail(array $points, int $count): array {
        $real = 0;
        for ($i = count($points) - 1; $i >= 0; $i--) {
            if (!$points[$i]['gap']) { $real++; }
            if ($real === $count) { return array_slice($points, $i); }
        }
        return $points;
    }

    /** Expected next period start (YYYY-MM-DD) for a period type, or null if unknown or unparsable. */
    public static function next_period(string $period, string $periodtype): ?string {
        $steps = ['daily' => 'P1D', 'weekly' => 'P7D', 'monthly' => 'P1M', 'quarterly' => 'P3M', 'annual' => 'P1Y'];
        if (!isset($steps[$periodtype])) { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $period, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d') !== $period) { return null; }
        return $date->add(new \DateInterval($steps[$periodtype]))->format('Y-m-d');
    }

    /**
     * Human trend: symbol + text key for movement, and a direction-aware assessment. Colour is only a duplicate
     * cue in the template; the symbol and the words carry the meaning.
     */
    public static function trend_view(array $trend, string $direction): array {
        $map = [
            'sustained_up' => ['symbol' => '↑', 'key' => 'infocenter2_trend_up'],
            'sustained_down' => ['symbol' => '↓', 'key' => 'infocenter2_trend_down'],
            'unchanged' => ['symbol' => '→', 'key' => 'infocenter2_trend_flat'],
            'mixed' => ['symbol' => '≈', 'key' => 'infocenter2_trend_mixed'],
            'insufficient' => ['symbol' => '·', 'key' => 'infocenter2_trend_insufficient'],
        ];
        $movement = isset($map[$trend['movement']]) ? $trend['movement'] : 'insufficient';
        $quality = (string)$trend['quality'];
        $assessment = '';
        if ($quality === 'improving') {
            $assessment = 'infocenter2_quality_better';
        } else if ($quality === 'worsening') {
            $assessment = 'infocenter2_quality_worse';
        } else if (in_array($movement, ['sustained_up', 'sustained_down'], true) && $direction === 'neutral') {
            $assessment = 'infocenter2_quality_neutral';
        }
        return ['movement' => $movement, 'symbol' => $map[$movement]['symbol'], 'key' => $map[$movement]['key'],
            'quality' => $quality, 'assessment' => $assessment, 'fact_count' => (int)$trend['fact_count']];
    }

    /**
     * Server-side mini chart geometry in a 240×96 viewBox: grid lines, fact segments broken at gaps and empty
     * periods, and one dot per real fact. A flat series is centred; a single fact is one dot without a line.
     *
     * @return array{empty: bool, segments: string[], dots: array[], grid: float[], first: string, last: string}
     */
    public static function mini_geometry(array $points): array {
        $width = 240.0; $height = 96.0; $padx = 8.0; $top = 8.0; $bottom = 86.0;
        $values = [];
        foreach ($points as $point) {
            if (!$point['gap'] && $point['fact'] !== null) { $values[] = $point['fact']; }
        }
        $first = $points ? (string)$points[0]['period'] : '';
        $last = $points ? (string)$points[count($points) - 1]['period'] : '';
        $grid = [$top, ($top + $bottom) / 2, $bottom];
        if (!$values) {
            return ['empty' => true, 'segments' => [], 'dots' => [], 'grid' => $grid, 'first' => $first, 'last' => $last];
        }
        $low = min($values); $high = max($values);
        if ($high === $low) {
            $pad = $low == 0.0 ? 1.0 : abs($low) * 0.1;
            $low -= $pad; $high += $pad;
        }
        $n = count($points);
        $x = static function(int $i) use ($n, $padx, $width): float {
            return $n === 1 ? $width / 2 : $padx + $i * ($width - 2 * $padx) / ($n - 1);
        };
        $y = static function(float $v) use ($low, $high, $top, $bottom): float {
            return $bottom - ($v - $low) * ($bottom - $top) / ($high - $low);
        };
        $segments = []; $current = []; $dots = [];
        foreach ($points as $i => $point) {
            if ($point['gap'] || $point['fact'] === null) {
                if (count($current) > 1) { $segments[] = implode(' ', $current); }
                $current = [];
                continue;
            }
            $px = round($x($i), 1); $py = round($y($point['fact']), 1);
            $current[] = $px . ',' . $py;
            $dots[] = ['x' => $px, 'y' => $py, 'period' => $point['period'], 'value' => $point['fact']];
        }
        if (count($current) > 1) { $segments[] = implode(' ', $current); }
        return ['empty' => false, 'segments' => $segments, 'dots' => $dots, 'grid' => $grid, 'first' => $first, 'last' => $last];
    }
}
