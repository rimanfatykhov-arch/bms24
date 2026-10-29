<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stateless recurrence expansion for explicit series creation.
 * No recurrence source of truth is stored: callers create ordinary canonical records.
 */
class recurrence_service {
    private const MAX_OCCURRENCES = 52;

    /**
     * Expand a recurrence definition into start timestamps, including the original start.
     *
     * Supported modes: none, weekly, monthly, weekdays.
     * Weekdays use ISO-8601 1=Mon..7=Sun and return the requested number of occurrences.
     */
    public static function starts(int $start, string $mode, int $count, array $weekdays = []): array {
        if ($start <= 0) {
            return [];
        }
        $mode = in_array($mode, ['none', 'weekly', 'monthly', 'weekdays'], true) ? $mode : 'none';
        $count = max(1, min(self::MAX_OCCURRENCES, $count));
        if ($mode === 'none' || $count === 1) {
            return [$start];
        }

        $tz = \core_date::get_user_timezone_object();
        $base = (new \DateTimeImmutable('@' . $start))->setTimezone($tz);
        $time = [$base->format('H'), $base->format('i'), $base->format('s')];
        $result = [];

        if ($mode === 'weekly') {
            for ($i = 0; $i < $count; $i++) {
                $result[] = $base->modify('+' . $i . ' week')->getTimestamp();
            }
            return $result;
        }

        if ($mode === 'monthly') {
            $day = (int)$base->format('j');
            $cursor = $base->modify('first day of this month');
            $months = 0;
            while (count($result) < $count && $months < ($count * 2 + 24)) {
                $month = $cursor->modify('+' . $months . ' month');
                $daysinmonth = (int)$month->format('t');
                if ($day <= $daysinmonth) {
                    $candidate = $month->setDate((int)$month->format('Y'), (int)$month->format('n'), $day)
                        ->setTime((int)$time[0], (int)$time[1], (int)$time[2]);
                    if ($candidate->getTimestamp() >= $start) {
                        $result[] = $candidate->getTimestamp();
                    }
                }
                $months++;
            }
            return $result ?: [$start];
        }

        $days = array_values(array_unique(array_filter(array_map('intval', $weekdays), static function(int $day): bool {
            return $day >= 1 && $day <= 7;
        })));
        sort($days);
        if (!$days) {
            $days = [(int)$base->format('N')];
        }
        $cursor = $base;
        $guard = 0;
        while (count($result) < $count && $guard < 370) {
            if (in_array((int)$cursor->format('N'), $days, true) && $cursor->getTimestamp() >= $start) {
                $result[] = $cursor->getTimestamp();
            }
            $cursor = $cursor->modify('+1 day')->setTime((int)$time[0], (int)$time[1], (int)$time[2]);
            $guard++;
        }
        return $result ?: [$start];
    }
}
