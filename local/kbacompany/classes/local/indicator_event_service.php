<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/** Derived indicator events over canonical history; no event storage is created. */
class indicator_event_service {
    /** Resolve the active norm revision for one binding at one period. */
    public static function norm_for_period(array $norms, string $binduuid, string $period): ?array {
        $best = null;
        $bestfrom = '';
        foreach ($norms as $norm) {
            $row = is_object($norm) ? (array)$norm : $norm;
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

    /** Whether a fact satisfies one effective norm. */
    public static function in_norm(float $value, array $norm, string $direction): bool {
        if ((string)($norm['normkind'] ?? '') === 'range') {
            return $value >= (float)$norm['minval'] && $value <= (float)$norm['maxval'];
        }
        if (!array_key_exists('targetvalue', $norm) || $norm['targetvalue'] === null || $norm['targetvalue'] === '') {
            return false;
        }
        $target = (float)$norm['targetvalue'];
        if ($direction === 'up') { return $value >= $target; }
        if ($direction === 'down') { return $value <= $target; }
        return $value == $target;
    }

    /**
     * Derive events attached to the latest factual observation.
     *
     * Events can coexist (for example, a new maximum may also be outside norm).
     * Norm transition events are evaluated only when a concrete binding is supplied.
     */
    public static function latest(array $series, string $direction, array $norms = [], string $binduuid = ''): array {
        $facts = indicator_trend_service::facts($series);
        if (count($facts) < 2) { return []; }

        $latest = $facts[count($facts) - 1];
        $previous = $facts[count($facts) - 2];
        $events = [];

        // Reversal: compare the latest non-flat change with the nearest earlier non-flat change.
        $latestchange = indicator_trend_service::compare((float)$previous['value'], (float)$latest['value']);
        if ($latestchange !== 'flat') {
            $earlierchange = '';
            for ($i = count($facts) - 2; $i >= 1; $i--) {
                $candidate = indicator_trend_service::compare((float)$facts[$i - 1]['value'], (float)$facts[$i]['value']);
                if ($candidate !== 'flat') { $earlierchange = $candidate; break; }
            }
            if ($earlierchange !== '' && $earlierchange !== $latestchange) {
                $events[] = [
                    'type' => $latestchange === 'up' ? 'reversal_up' : 'reversal_down',
                    'period' => (string)$latest['period'],
                    'value' => (float)$latest['value'],
                ];
            }
        }

        $previousvalues = [];
        for ($i = 0; $i < count($facts) - 1; $i++) { $previousvalues[] = (float)$facts[$i]['value']; }
        if ($previousvalues) {
            if ((float)$latest['value'] > max($previousvalues)) {
                $events[] = ['type'=>'new_max', 'period'=>(string)$latest['period'], 'value'=>(float)$latest['value']];
            }
            if ((float)$latest['value'] < min($previousvalues)) {
                $events[] = ['type'=>'new_min', 'period'=>(string)$latest['period'], 'value'=>(float)$latest['value']];
            }
        }

        if ($binduuid !== '') {
            $latestnorm = self::norm_for_period($norms, $binduuid, (string)$latest['period']);
            $previousnorm = self::norm_for_period($norms, $binduuid, (string)$previous['period']);
            if ($latestnorm && $previousnorm) {
                $wasin = self::in_norm((float)$previous['value'], $previousnorm, $direction);
                $isin = self::in_norm((float)$latest['value'], $latestnorm, $direction);
                if ($wasin && !$isin) {
                    $events[] = ['type'=>'out_of_norm', 'period'=>(string)$latest['period'], 'value'=>(float)$latest['value']];
                } else if (!$wasin && $isin) {
                    $events[] = ['type'=>'back_in_norm', 'period'=>(string)$latest['period'], 'value'=>(float)$latest['value']];
                }
            }
        }
        return $events;
    }
}
