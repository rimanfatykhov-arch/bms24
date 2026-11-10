<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/** Deterministic statistics for read-only acceptance benchmarks. */
final class implementation_acceptance_metrics {
    public static function median(array $values): float {
        if (!$values) {
            return 0.0;
        }
        $values = array_map('floatval', array_values($values));
        sort($values, SORT_NUMERIC);
        $n = count($values);
        $middle = intdiv($n, 2);
        return $n % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** Nearest-rank percentile, appropriate for small samples and p95. */
    public static function percentile(array $values, int $percent): float {
        if (!$values) {
            return 0.0;
        }
        if ($percent < 1 || $percent > 100) {
            throw new \InvalidArgumentException('Percentile outside 1..100');
        }
        $values = array_map('floatval', array_values($values));
        sort($values, SORT_NUMERIC);
        $index = max(0, (int)ceil(count($values) * $percent / 100) - 1);
        return $values[$index];
    }

    public static function aggregate(array $samples): array {
        $ms = array_column($samples, 'ms');
        $queries = array_column($samples, 'queries');
        return [
            'runs' => count($samples),
            'median_ms' => round(self::median($ms), 2),
            'p95_ms' => round(self::percentile($ms, 95), 2),
            'median_queries' => self::median($queries),
            'p95_queries' => self::percentile($queries, 95),
        ];
    }
}
