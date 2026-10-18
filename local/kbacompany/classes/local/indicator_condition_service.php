<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Management-condition read model over canonical indicator history.
 *
 * Important boundaries:
 * - the condition belongs to an indicator binding/context, not to the bare statistic;
 * - facts remain in statval and are never copied here;
 * - trend shape and condition are separate analytical layers;
 * - steep-growth / steep-decline thresholds are explicit versioned policy values;
 * - Non-Existence and Power Change are contextual/manual states and are not inferred
 *   from a statistic alone.
 */
class indicator_condition_service {
    public const CONDITIONS = [
        'nonexistence', 'danger', 'emergency', 'normal', 'affluence', 'power', 'powerchange',
    ];

    /** Resolve an effective condition-policy revision for a binding at a period. */
    public static function policy_for_period(array $policies, string $binduuid, string $period): ?array {
        $best = null;
        $bestfrom = '';
        foreach ($policies as $policy) {
            $row = is_object($policy) ? (array)$policy : $policy;
            if ((string)($row['binduuid'] ?? '') !== $binduuid) { continue; }
            $effectivefrom = (string)($row['effectivefrom'] ?? '');
            if ((string)($row['status'] ?? 'active') === 'archived' && !empty($row['timecreated'])) {
                $archivefrom = gmdate('Y-m-d', (int)$row['timecreated']);
                if ($archivefrom > $effectivefrom) { $effectivefrom = $archivefrom; }
            }
            if ($effectivefrom > $period) { continue; }
            if ($best === null || $effectivefrom > $bestfrom ||
                    ($effectivefrom === $bestfrom && (int)($row['revision'] ?? 0) > (int)($best['revision'] ?? 0))) {
                $best = $row;
                $bestfrom = $effectivefrom;
            }
        }
        if (!$best || (string)($best['status'] ?? 'active') === 'archived') { return null; }
        if ((string)($best['effectiveto'] ?? '') !== '' && (string)$best['effectiveto'] < $period) { return null; }
        return $best;
    }

    /** Direction-aware change: positive means management improvement. */
    public static function improvement_percent(float $previous, float $current, string $direction): ?float {
        if ($direction !== 'up' && $direction !== 'down') { return null; }
        if ($previous == 0.0) { return $current == 0.0 ? 0.0 : null; }
        if ($direction === 'down') {
            return (($previous - $current) / abs($previous)) * 100.0;
        }
        return (($current - $previous) / abs($previous)) * 100.0;
    }

    /** Achievement against a target norm. Range norms intentionally have no Power ratio. */
    public static function performance_to_norm(float $value, array $norm, string $direction): ?float {
        if ((string)($norm['normkind'] ?? '') !== 'target') { return null; }
        if (!array_key_exists('targetvalue', $norm) || $norm['targetvalue'] === null || $norm['targetvalue'] === '') {
            return null;
        }
        $target = (float)$norm['targetvalue'];
        if ($direction === 'up') {
            if ($target == 0.0) { return $value == 0.0 ? 100.0 : ($value > 0.0 ? 200.0 : 0.0); }
            return ($value / $target) * 100.0;
        }
        if ($direction === 'down') {
            if ($value == 0.0) { return 200.0; }
            if ($target == 0.0) { return $value <= 0.0 ? 100.0 : 0.0; }
            return ($target / $value) * 100.0;
        }
        return null;
    }

    private static function base_result(array $facts): array {
        return [
            'condition' => '',
            'source' => 'undetermined',
            'reason' => 'insufficient',
            'fact_count' => count($facts),
            'latest_change_pct' => null,
            'emergency_streak' => 0,
            'norm_performance' => null,
            'policy' => null,
            'norm' => null,
            'manual_only' => ['nonexistence', 'powerchange'],
        ];
    }

    /** Count consecutive periods whose direction-aware change is flat or worsening. */
    private static function emergency_streak(array $facts, string $direction): int {
        if (count($facts) < 2) { return 0; }
        $streak = 0;
        for ($i = count($facts) - 1; $i >= 1; $i--) {
            $change = self::improvement_percent((float)$facts[$i - 1]['value'], (float)$facts[$i]['value'], $direction);
            if ($change === null || $change > 0.0) { break; }
            $streak++;
        }
        return $streak;
    }

    /** Whether the latest values represent gradual positive growth below Affluence steepness. */
    private static function normal_growth(array $facts, string $direction, float $affluencegrowth): bool {
        if (count($facts) < 3) { return false; }
        $window = array_slice($facts, -3);
        for ($i = 1; $i < count($window); $i++) {
            $change = self::improvement_percent((float)$window[$i - 1]['value'], (float)$window[$i]['value'], $direction);
            if ($change === null || $change <= 0.0 || $change >= $affluencegrowth) { return false; }
        }
        return true;
    }

    /**
     * Determine an automatic condition where the methodology provides enough evidence.
     *
     * Required policy values are explicit because the source methodology uses the
     * qualitative concepts "very steeply down" and "steeply up" without a universal
     * numeric percentage. BMS therefore refuses to invent those percentages.
     */
    public static function analyze(array $series, string $direction, array $normhistory = [],
            array $policyhistory = [], string $binduuid = ''): array {
        $facts = indicator_trend_service::facts($series);
        $result = self::base_result($facts);
        if (!$facts) {
            $result['reason'] = 'no_data';
            return $result;
        }
        if ($binduuid === '') {
            $result['reason'] = 'binding_required';
            return $result;
        }
        if ($direction !== 'up' && $direction !== 'down') {
            $result['reason'] = 'direction_not_supported';
            return $result;
        }

        $latest = $facts[count($facts) - 1];
        $policy = self::policy_for_period($policyhistory, $binduuid, (string)$latest['period']);
        $result['policy'] = $policy;
        if (!$policy) {
            $result['reason'] = 'policy_missing';
            return $result;
        }
        if (count($facts) < 2) {
            $result['reason'] = 'insufficient';
            return $result;
        }

        $previous = $facts[count($facts) - 2];
        $change = self::improvement_percent((float)$previous['value'], (float)$latest['value'], $direction);
        $result['latest_change_pct'] = $change;
        $result['emergency_streak'] = self::emergency_streak($facts, $direction);

        $norm = indicator_event_service::norm_for_period($normhistory, $binduuid, (string)$latest['period']);
        $result['norm'] = $norm;
        if ($norm) {
            $result['norm_performance'] = self::performance_to_norm((float)$latest['value'], $norm, $direction);
        }

        $dangerdecline = $policy['dangerdecline'] === null || $policy['dangerdecline'] === '' ? null :
            (float)$policy['dangerdecline'];
        $dangerperiods = max(0, (int)($policy['dangerperiods'] ?? 0));
        $affluencegrowth = $policy['affluencegrowth'] === null || $policy['affluencegrowth'] === '' ? null :
            (float)$policy['affluencegrowth'];
        $powerlevel = $policy['powerlevel'] === null || $policy['powerlevel'] === '' ? null :
            (float)$policy['powerlevel'];
        $powerperiods = max(0, (int)($policy['powerperiods'] ?? 0));

        if ($change !== null && $dangerdecline !== null && $dangerdecline > 0.0 && $change <= -$dangerdecline) {
            $result['condition'] = 'danger';
            $result['source'] = 'automatic';
            $result['reason'] = 'steep_decline';
            return $result;
        }
        if ($dangerperiods > 0 && $result['emergency_streak'] >= $dangerperiods) {
            $result['condition'] = 'danger';
            $result['source'] = 'automatic';
            $result['reason'] = 'prolonged_emergency';
            return $result;
        }

        if ($change === null) {
            $result['reason'] = 'insufficient';
            return $result;
        }
        if ($change <= 0.0) {
            $result['condition'] = 'emergency';
            $result['source'] = 'automatic';
            $result['reason'] = $change < 0.0 ? 'decline' : 'flat';
            return $result;
        }

        if ($affluencegrowth === null || $affluencegrowth <= 0.0) {
            $result['reason'] = 'steepness_policy_required';
            return $result;
        }

        // Power: unusually high vs sufficient target, held for configured periods,
        // with the latest movement back in the gradual Normal-growth corridor.
        if ($powerlevel !== null && $powerlevel > 100.0 && $powerperiods >= 2 && count($facts) >= $powerperiods &&
                $change > 0.0 && $change < $affluencegrowth) {
            $power = true;
            $recent = array_slice($facts, -$powerperiods);
            foreach ($recent as $fact) {
                $periodnorm = indicator_event_service::norm_for_period($normhistory, $binduuid, (string)$fact['period']);
                if (!$periodnorm) { $power = false; break; }
                $performance = self::performance_to_norm((float)$fact['value'], $periodnorm, $direction);
                if ($performance === null || $performance < $powerlevel) { $power = false; break; }
            }
            if ($power) {
                $result['condition'] = 'power';
                $result['source'] = 'automatic';
                $result['reason'] = 'high_level_held';
                return $result;
            }
        }

        if ($change >= $affluencegrowth) {
            $result['condition'] = 'affluence';
            $result['source'] = 'automatic';
            $result['reason'] = 'steep_growth';
            return $result;
        }

        if (self::normal_growth($facts, $direction, $affluencegrowth)) {
            $result['condition'] = 'normal';
            $result['source'] = 'automatic';
            $result['reason'] = 'gradual_growth';
            return $result;
        }

        $result['reason'] = 'insufficient_normal_trend';
        return $result;
    }

    /**
     * Compatibility adapter for pre-0.5.3 Battle Plan quota bands.
     * New indicator condition analytics must use analyze(); this method only keeps
     * already-published Battle Plan revisions resolving exactly as before.
     */
    public static function legacy_quota_condition(?float $previous, ?float $fact, float $quota, string $comparison,
            bool $critical, array $thresholds): array {
        if ($fact === null) { return ['condition' => '', 'performance' => null, 'trend' => null]; }
        $performance = self::legacy_performance($fact, $quota, $comparison);
        $trend = self::legacy_trend($previous, $fact, $comparison);
        if ($fact == 0.0 && ($previous === null || $previous == 0.0)) {
            return ['condition' => 'nonexistence', 'performance' => $performance, 'trend' => null];
        }
        $danger = (float)($thresholds['danger_below'] ?? 70.0);
        $emergency = (float)($thresholds['emergency_below'] ?? 90.0);
        $normalhigh = (float)($thresholds['normal_high'] ?? 110.0);
        $affluencehigh = (float)($thresholds['affluence_high'] ?? 130.0);
        $dangertrend = (float)($thresholds['danger_trend'] ?? -20.0);
        if ($performance === null) { return ['condition' => '', 'performance' => null, 'trend' => $trend]; }
        if (($critical && $performance < $emergency) || $performance < $danger ||
                ($trend !== null && $trend <= $dangertrend)) {
            $condition = 'danger';
        } else if ($performance < $emergency) {
            $condition = 'emergency';
        } else if ($performance <= $normalhigh) {
            $condition = 'normal';
        } else if ($performance < $affluencehigh) {
            $condition = 'affluence';
        } else {
            $prevperformance = $previous === null ? null : self::legacy_performance($previous, $quota, $comparison);
            $condition = ($prevperformance !== null && $prevperformance >= $affluencehigh) ? 'power' : 'affluence';
        }
        return ['condition' => $condition, 'performance' => $performance, 'trend' => $trend];
    }

    private static function legacy_performance(float $fact, float $quota, string $comparison): ?float {
        if ($comparison === 'up') {
            if ($quota == 0.0) { return $fact == 0.0 ? 100.0 : ($fact > 0 ? 200.0 : 0.0); }
            return ($fact / $quota) * 100.0;
        }
        if ($fact == 0.0) { return 200.0; }
        if ($quota == 0.0) { return $fact <= 0.0 ? 100.0 : 0.0; }
        return ($quota / $fact) * 100.0;
    }

    private static function legacy_trend(?float $previous, float $current, string $comparison): ?float {
        if ($previous === null || $previous == 0.0) { return null; }
        if ($comparison === 'down') { return (($previous - $current) / abs($previous)) * 100.0; }
        return (($current - $previous) / abs($previous)) * 100.0;
    }

    /** Stable action-formula language keys. No Task is auto-created by this read model. */
    public static function action_keys(string $condition): array {
        $map = [
            'nonexistence' => ['indicator_condition_action_nonexistence_1', 'indicator_condition_action_nonexistence_2',
                'indicator_condition_action_nonexistence_3', 'indicator_condition_action_nonexistence_4'],
            'danger' => ['indicator_condition_action_danger_1', 'indicator_condition_action_danger_2',
                'indicator_condition_action_danger_3', 'indicator_condition_action_danger_4'],
            'emergency' => ['indicator_condition_action_emergency_1', 'indicator_condition_action_emergency_2',
                'indicator_condition_action_emergency_3', 'indicator_condition_action_emergency_4'],
            'normal' => ['indicator_condition_action_normal_1', 'indicator_condition_action_normal_2',
                'indicator_condition_action_normal_3'],
            'affluence' => ['indicator_condition_action_affluence_1', 'indicator_condition_action_affluence_2',
                'indicator_condition_action_affluence_3'],
            'power' => ['indicator_condition_action_power_1', 'indicator_condition_action_power_2'],
            'powerchange' => ['indicator_condition_action_powerchange_1', 'indicator_condition_action_powerchange_2'],
        ];
        return $map[$condition] ?? [];
    }
}
