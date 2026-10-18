<?php
namespace local_kbacorporate\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Optional integration adapter for local_kbagamification.
 *
 * Corporate Dashboard never reads Gamification tables directly. All runtime data
 * comes through \local_kbagamification\api so both plugins can evolve independently.
 *
 * @package local_kbacorporate
 */
class gamification {
    /**
     * Whether the integration is enabled in Corporate Dashboard settings.
     *
     * Existing upgraded installations have no stored value yet, so missing config
     * intentionally falls back to enabled (the admin-setting default).
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        $value = get_config('local_kbacorporate', 'gamificationenabled');
        return $value === false ? true : !empty($value);
    }

    /**
     * Whether the required KBA Gamification internal API is available.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists('\\local_kbagamification\\api')
            && method_exists('\\local_kbagamification\\api', 'get_company_snapshot')
            && method_exists('\\local_kbagamification\\api', 'get_user_summary')
            && method_exists('\\local_kbagamification\\api', 'get_user_achievements');
    }

    /**
     * Whether employee leaderboard rows should be shown to corporate viewers.
     *
     * @return bool
     */
    public static function show_leaderboard(): bool {
        $value = get_config('local_kbacorporate', 'gamificationshowleaderboard');
        return $value === false ? true : !empty($value);
    }

    /**
     * Whether personal KBA Coins balances may be shown in Corporate Dashboard.
     *
     * @return bool
     */
    public static function show_coins(): bool {
        $value = get_config('local_kbacorporate', 'gamificationshowcoins');
        return $value === false ? false : !empty($value);
    }

    /**
     * Configured leaderboard row limit.
     *
     * @return int
     */
    public static function leaderboard_limit(): int {
        $value = get_config('local_kbacorporate', 'gamificationleaderboardlimit');
        if ($value === false || (int)$value <= 0) {
            return 20;
        }
        return min(200, max(1, (int)$value));
    }

    /**
     * Read a company-scoped gamification snapshot through the public internal API.
     *
     * The caller must supply only employee IDs already authorised by Corporate Dashboard.
     * This adapter intentionally does not attempt to determine company membership itself.
     *
     * @param array $employeeuserids Visible employee Moodle user IDs.
     * @param int $limit Leaderboard limit, 0 for all.
     * @param string $metric xp or achievements.
     * @return array|null Null when the optional integration cannot be used.
     */
    public static function get_company_snapshot(array $employeeuserids, int $limit = 20, string $metric = 'xp'): ?array {
        if (!self::is_enabled() || !self::is_available()) {
            return null;
        }
        try {
            return \local_kbagamification\api::get_company_snapshot($employeeuserids, $limit, $metric);
        } catch (\Throwable $e) {
            debugging('KBA Corporate Dashboard: KBA Gamification snapshot failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Read one employee's gamification summary through the public internal API.
     *
     * @param int $userid Moodle user ID.
     * @return array|null
     */
    public static function get_user_summary(int $userid): ?array {
        if (!self::is_enabled() || !self::is_available()) {
            return null;
        }
        try {
            return \local_kbagamification\api::get_user_summary($userid);
        } catch (\Throwable $e) {
            debugging('KBA Corporate Dashboard: KBA Gamification user summary failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Read one employee's awarded achievements through the public internal API.
     *
     * @param int $userid Moodle user ID.
     * @return array
     */
    public static function get_user_achievements(int $userid): array {
        if (!self::is_enabled() || !self::is_available()) {
            return [];
        }
        try {
            return \local_kbagamification\api::get_user_achievements($userid);
        } catch (\Throwable $e) {
            debugging('KBA Corporate Dashboard: KBA Gamification achievements failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
    }

    /**
     * Read one employee's latest awarded achievement through the public internal API.
     *
     * @param int $userid Moodle user ID.
     * @return array|null
     */
    public static function get_user_latest_achievement(int $userid): ?array {
        if (!self::is_enabled() || !self::is_available()
                || !method_exists('\\local_kbagamification\\api', 'get_user_latest_achievement')) {
            return null;
        }
        try {
            return \local_kbagamification\api::get_user_latest_achievement($userid);
        } catch (\Throwable $e) {
            debugging('KBA Corporate Dashboard: latest Gamification achievement failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
    }

    /**
     * Return administrator diagnostics for the optional plugin and API contract.
     *
     * @return array
     */
    public static function status(): array {
        global $CFG;

        $diskversion = 0;
        $release = '';
        $component = 'local_kbagamification';
        $versionfile = $CFG->dirroot . '/local/kbagamification/version.php';
        if (is_readable($versionfile)) {
            try {
                $diskinfo = (static function(string $file): \stdClass {
                    $plugin = new \stdClass();
                    require($file);
                    return $plugin;
                })($versionfile);
                $diskversion = isset($diskinfo->version) ? (int)$diskinfo->version : 0;
                $release = isset($diskinfo->release) ? (string)$diskinfo->release : '';
                $component = isset($diskinfo->component) ? (string)$diskinfo->component : $component;
            } catch (\Throwable $e) {
                debugging('KBA Corporate Dashboard: unable to read KBA Gamification version.php: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $dbversionraw = get_config('local_kbagamification', 'version');
        $dbversion = ($dbversionraw === false || $dbversionraw === null || $dbversionraw === '')
            ? 0
            : (int)$dbversionraw;
        $available = self::is_available();

        return [
            'enabled' => self::is_enabled(),
            'available' => $available,
            'component' => $component,
            'diskversion' => $diskversion,
            'dbversion' => $dbversion,
            'release' => $release,
            'synchronised' => $diskversion > 0 && $dbversion > 0 && $diskversion === $dbversion,
            'apicompatible' => $available,
        ];
    }
}
