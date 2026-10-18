<?php
namespace local_kbagamification\local;

defined('MOODLE_INTERNAL') || die();

/**
 * XP level helper.
 */
class level_manager {
    /**
     * Get enabled levels ordered by XP threshold.
     *
     * @return array
     */
    public static function get_levels(): array {
        global $DB;
        return $DB->get_records('local_kbagam_levels', ['enabled' => 1], 'minxp ASC, sortorder ASC, id ASC');
    }

    /**
     * Get one enabled level by id.
     *
     * @param int $levelid Level id.
     * @return \stdClass|null
     */
    public static function get_level(int $levelid): ?\stdClass {
        global $DB;

        if ($levelid <= 0) {
            return null;
        }

        $level = $DB->get_record('local_kbagam_levels', ['id' => $levelid, 'enabled' => 1]);
        return $level ?: null;
    }

    /**
     * Get current level for XP balance.
     *
     * @param int $xp
     * @return \stdClass|null
     */
    public static function get_current_level(int $xp): ?\stdClass {
        $current = null;
        foreach (self::get_levels() as $level) {
            if ((int) $level->minxp <= $xp) {
                $current = $level;
            } else {
                break;
            }
        }
        return $current;
    }

    /**
     * Get next level for XP balance.
     *
     * @param int $xp
     * @return \stdClass|null
     */
    public static function get_next_level(int $xp): ?\stdClass {
        foreach (self::get_levels() as $level) {
            if ((int) $level->minxp > $xp) {
                return $level;
            }
        }
        return null;
    }

    /**
     * Get progress percentage inside current level.
     *
     * @param int $xp
     * @return int
     */
    public static function get_progress_percent(int $xp): int {
        $current = self::get_current_level($xp);
        $next = self::get_next_level($xp);
        if (!$current || !$next) {
            return $next ? 0 : 100;
        }

        $range = (int) $next->minxp - (int) $current->minxp;
        if ($range <= 0) {
            return 100;
        }

        $progress = (($xp - (int) $current->minxp) / $range) * 100;
        return max(0, min(100, (int) round($progress)));
    }
}
