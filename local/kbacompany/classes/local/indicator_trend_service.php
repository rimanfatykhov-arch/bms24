<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-model analytics over canonical indicator fact history.
 *
 * This service never writes to stat/statval and deliberately does not calculate
 * management conditions. A trend shape is derived from the last three factual
 * observations using exact direction comparisons: no hidden percentage corridor.
 */
class indicator_trend_service {
    /** Number of factual observations required to classify a sustained shape. */
    private const CLASSIFICATION_FACTS = 3;

    /** Extract factual observations in the order supplied by product_service::series(). */
    public static function facts(array $series): array {
        $facts = [];
        foreach ($series as $row) {
            if (array_key_exists('fact', $row) && $row['fact'] !== null) {
                $facts[] = [
                    'period' => (string)$row['period'],
                    'value' => (float)$row['fact'],
                ];
            }
        }
        return $facts;
    }

    /** Exact sign of change without a percentage tolerance. */
    public static function compare(float $previous, float $current): string {
        if ($current > $previous) { return 'up'; }
        if ($current < $previous) { return 'down'; }
        return 'flat';
    }

    /**
     * Classify trend form separately from management assessment.
     *
     * movement:
     * - sustained_up
     * - sustained_down
     * - unchanged
     * - mixed
     * - insufficient
     *
     * quality is direction-aware and intentionally independent from future
     * management-condition formulas.
     */
    public static function analyze(array $series, string $direction): array {
        $facts = self::facts($series);
        $base = [
            'movement' => 'insufficient',
            'quality' => 'insufficient',
            'percent' => null,
            'streak' => 0,
            'latest_change' => 'insufficient',
            'latest' => null,
            'previous' => null,
            'fact_count' => count($facts),
            'classification_facts' => self::CLASSIFICATION_FACTS,
        ];
        if (!$facts) { return $base; }

        $latest = $facts[count($facts) - 1];
        $base['latest'] = $latest;
        if (count($facts) < 2) { return $base; }

        $previous = $facts[count($facts) - 2];
        $latestchange = self::compare((float)$previous['value'], (float)$latest['value']);
        $base['previous'] = $previous;
        $base['latest_change'] = $latestchange;
        if ((float)$previous['value'] != 0.0) {
            $base['percent'] = (((float)$latest['value'] - (float)$previous['value']) /
                abs((float)$previous['value'])) * 100;
        }

        $streak = 1;
        if ($latestchange !== 'flat') {
            for ($i = count($facts) - 2; $i >= 1; $i--) {
                $change = self::compare((float)$facts[$i - 1]['value'], (float)$facts[$i]['value']);
                if ($change !== $latestchange) { break; }
                $streak++;
            }
        } else {
            for ($i = count($facts) - 2; $i >= 1; $i--) {
                $change = self::compare((float)$facts[$i - 1]['value'], (float)$facts[$i]['value']);
                if ($change !== 'flat') { break; }
                $streak++;
            }
        }
        $base['streak'] = $streak;

        if (count($facts) < self::CLASSIFICATION_FACTS) { return $base; }

        $window = array_slice($facts, -self::CLASSIFICATION_FACTS);
        $changes = [];
        for ($i = 1; $i < count($window); $i++) {
            $changes[] = self::compare((float)$window[$i - 1]['value'], (float)$window[$i]['value']);
        }
        $unique = array_values(array_unique($changes));
        if (count($unique) === 1 && $unique[0] === 'up') {
            $movement = 'sustained_up';
        } else if (count($unique) === 1 && $unique[0] === 'down') {
            $movement = 'sustained_down';
        } else if (count($unique) === 1 && $unique[0] === 'flat') {
            $movement = 'unchanged';
        } else {
            $movement = 'mixed';
        }

        $quality = 'neutral';
        if ($movement === 'unchanged') {
            $quality = 'stable';
        } else if ($movement === 'sustained_up') {
            if ($direction === 'up') { $quality = 'improving'; }
            else if ($direction === 'down') { $quality = 'worsening'; }
        } else if ($movement === 'sustained_down') {
            if ($direction === 'down') { $quality = 'improving'; }
            else if ($direction === 'up') { $quality = 'worsening'; }
        }

        $base['movement'] = $movement;
        $base['quality'] = $quality;
        return $base;
    }
}
