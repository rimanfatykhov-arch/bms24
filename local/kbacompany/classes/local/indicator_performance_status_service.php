<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only BMS24 performance status over one canonical metric period.
 *
 * The service never writes facts, plans or norms. It deliberately avoids hidden
 * percentage thresholds: an out-of-target value is Risk; it becomes Critical
 * only when the binding is explicitly critical or the canonical trend is worsening.
 */
class indicator_performance_status_service {
    public const STATUSES = ['nodata', 'ok', 'risk', 'critical'];

    public static function contract(): array {
        return [
            'version' => '1.0',
            'statuses' => self::STATUSES,
            'target_priority' => ['period_plan', 'effective_norm'],
            'rules' => [
                'no_fact_or_no_target_is_nodata',
                'inside_target_is_ok',
                'outside_target_is_risk',
                'outside_target_is_critical_when_binding_is_critical_or_trend_is_worsening',
                'no_hidden_percentage_thresholds',
                'status_is_read_model_only',
            ],
        ];
    }

    /** Latest plan/fact values for one exact canonical period. */
    public static function period_values(array $series, string $period): array {
        foreach ($series as $row) {
            if ((string)($row['period'] ?? '') !== $period) { continue; }
            return [
                'plan' => array_key_exists('plan', $row) && $row['plan'] !== null ? (float)$row['plan'] : null,
                'fact' => array_key_exists('fact', $row) && $row['fact'] !== null ? (float)$row['fact'] : null,
            ];
        }
        return ['plan' => null, 'fact' => null];
    }

    /**
     * Assess one period. A period plan has priority over a general effective norm.
     */
    public static function assess(?float $fact, ?float $plan, ?array $norm, string $direction,
            bool $criticalbinding = false, string $trendquality = 'insufficient'): array {
        $base = [
            'status' => 'nodata',
            'reason' => 'no_fact',
            'source' => '',
            'target_kind' => '',
            'target' => null,
            'min' => null,
            'max' => null,
            'variance' => null,
            'variance_percent' => null,
            'inside' => null,
        ];
        if ($fact === null) { return $base; }

        if ($plan !== null) {
            $base['source'] = 'plan';
            $base['target_kind'] = 'target';
            $base['target'] = $plan;
        } else if ($norm) {
            $base['source'] = 'norm';
            if ((string)($norm['normkind'] ?? '') === 'range') {
                if (($norm['minval'] ?? '') === '' || ($norm['maxval'] ?? '') === '') {
                    $base['reason'] = 'no_target';
                    return $base;
                }
                $base['target_kind'] = 'range';
                $base['min'] = (float)$norm['minval'];
                $base['max'] = (float)$norm['maxval'];
            } else {
                if (($norm['targetvalue'] ?? '') === '') {
                    $base['reason'] = 'no_target';
                    return $base;
                }
                $base['target_kind'] = 'target';
                $base['target'] = (float)$norm['targetvalue'];
            }
        } else {
            $base['reason'] = 'no_target';
            return $base;
        }

        if ($base['target_kind'] === 'range') {
            $inside = $fact >= (float)$base['min'] && $fact <= (float)$base['max'];
            $base['inside'] = $inside;
            if ($inside) {
                $base['variance'] = 0.0;
            } else if ($fact < (float)$base['min']) {
                $base['variance'] = $fact - (float)$base['min'];
                if ((float)$base['min'] != 0.0) {
                    $base['variance_percent'] = ($base['variance'] / abs((float)$base['min'])) * 100;
                }
            } else {
                $base['variance'] = $fact - (float)$base['max'];
                if ((float)$base['max'] != 0.0) {
                    $base['variance_percent'] = ($base['variance'] / abs((float)$base['max'])) * 100;
                }
            }
        } else {
            $target = (float)$base['target'];
            if ($direction === 'up') {
                $inside = $fact >= $target;
            } else if ($direction === 'down') {
                $inside = $fact <= $target;
            } else {
                $inside = $fact == $target;
            }
            $base['inside'] = $inside;
            $base['variance'] = $fact - $target;
            if ($target != 0.0) {
                $base['variance_percent'] = ($base['variance'] / abs($target)) * 100;
            }
        }

        if ($base['inside']) {
            $base['status'] = 'ok';
            $base['reason'] = 'inside_target';
            return $base;
        }
        if ($criticalbinding || $trendquality === 'worsening') {
            $base['status'] = 'critical';
            $base['reason'] = $criticalbinding ? 'critical_binding' : 'worsening';
            return $base;
        }
        $base['status'] = 'risk';
        $base['reason'] = 'outside_target';
        return $base;
    }
}
