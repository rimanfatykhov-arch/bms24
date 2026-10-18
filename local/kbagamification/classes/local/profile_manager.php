<?php
namespace local_kbagamification\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the learner-facing KBA Learning Profile view model.
 *
 * @package    local_kbagamification
 */
class profile_manager {
    /**
     * Build profile data for a Moodle user.
     *
     * @param int $userid User id.
     * @return array
     */
    public static function get_profile_data(int $userid): array {
        global $DB, $USER;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        $wallet = engine::get_wallet($userid);
        $currentlevel = level_manager::get_current_level((int) $wallet->xp);
        $nextlevel = level_manager::get_next_level((int) $wallet->xp);
        $progress = level_manager::get_progress_percent((int) $wallet->xp);

        $showcoins = self::config_enabled('profile_showcoins', true);
        $showhistory = self::config_enabled('profile_showhistory', true);
        $showlocked = self::config_enabled('profile_showlockedachievements', true);
        $showlevelprogress = self::config_enabled('profile_showlevelprogress', true);
        $historylimit = (int) get_config('local_kbagamification', 'profile_historylimit');
        if ($historylimit <= 0) {
            $historylimit = 10;
        }
        $historylimit = max(5, min(50, $historylimit));

        $catalog = achievement_manager::get_catalog_for_user($userid);
        $achievements = [];
        $nextgoals = [];
        $recentearned = [];
        $achievementcount = 0;

        foreach ($catalog as $item) {
            $achievement = $item['record'];
            $awarded = (bool) $item['awarded'];
            if ($awarded) {
                $achievementcount++;
            }

            $entry = [
                'id' => (int) $achievement->id,
                'name' => format_string($achievement->name),
                'description' => format_text($achievement->description, FORMAT_PLAIN),
                'icon' => achievement_manager::icon_glyph($achievement->icon),
                'rarity' => $achievement->rarity,
                'raritylabel' => get_string('rarity_' . $achievement->rarity, 'local_kbagamification'),
                'awarded' => $awarded,
                'locked' => !$awarded,
                'date' => $awarded ? userdate((int) $item['timeawarded']) : '',
                'timeawarded' => $awarded ? (int) $item['timeawarded'] : 0,
                'progress' => (int) $item['progress']['percent'],
                'current' => format_float((int) $item['progress']['current'], 0),
                'target' => format_float((int) $item['progress']['target'], 0),
                'remaining' => format_float(max(0,
                    (int) $item['progress']['target'] - (int) $item['progress']['current']), 0),
                'rewardxp' => (int) $achievement->rewardxp,
                'rewardcoins' => (int) $achievement->rewardcoins,
                'hasreward' => ((int) $achievement->rewardxp !== 0 || (int) $achievement->rewardcoins !== 0),
            ];

            if ($awarded || $showlocked) {
                $achievements[] = $entry;
            }

            if ($awarded) {
                $recentearned[] = $entry;
            } else {
                $nextgoals[] = $entry;
            }
        }

        usort($recentearned, static function(array $a, array $b): int {
            return $b['timeawarded'] <=> $a['timeawarded'];
        });
        $recentearned = array_slice($recentearned, 0, 3);

        usort($nextgoals, static function(array $a, array $b): int {
            if ($a['progress'] === $b['progress']) {
                return $a['id'] <=> $b['id'];
            }
            return $b['progress'] <=> $a['progress'];
        });
        $nextgoals = array_slice($nextgoals, 0, 3);

        $transactions = [];
        if ($showhistory) {
            $records = $DB->get_records(
                'local_kbagam_txn',
                ['userid' => $userid],
                'timecreated DESC, id DESC',
                '*',
                0,
                $historylimit
            );
            foreach ($records as $record) {
                $transactions[] = [
                    'currency' => $record->currency === 'COIN'
                        ? get_string('coins', 'local_kbagamification')
                        : get_string('xp', 'local_kbagamification'),
                    'currencycode' => $record->currency,
                    'amount' => ($record->amount > 0 ? '+' : '') . (int) $record->amount,
                    'reason' => format_text($record->reason, FORMAT_PLAIN),
                    'date' => userdate((int) $record->timecreated),
                    'balance' => (int) $record->balanceafter,
                    'positive' => (int) $record->amount > 0,
                    'negative' => (int) $record->amount < 0,
                ];
            }
        }

        $systemcontext = \context_system::instance();
        $isown = $userid === (int) $USER->id;
        $nextxp = $nextlevel ? (int) $nextlevel->minxp : 0;
        $xptogo = $nextlevel ? max(0, $nextxp - (int) $wallet->xp) : 0;

        $navigation = navigation_manager::get_user_nav_data($userid, 'profile');

        return [
            'navigation' => $navigation,
            'userid' => $userid,
            'fullname' => fullname($user),
            'xp' => format_float((int) $wallet->xp, 0),
            'coins' => format_float((int) $wallet->coins, 0),
            'showcoins' => $showcoins,
            'levelname' => $currentlevel ? format_string($currentlevel->name) : get_string('nolevel', 'local_kbagamification'),
            'progress' => $progress,
            'showlevelprogress' => $showlevelprogress,
            'hasnextlevel' => (bool) $nextlevel,
            'nextlevelname' => $nextlevel ? format_string($nextlevel->name) : '',
            'nextlevelxp' => $nextlevel ? format_float($nextxp, 0) : '',
            'xptogo' => format_float($xptogo, 0),
            'achievementcount' => $achievementcount,
            'achievementtotal' => count($catalog),
            'achievements' => $achievements,
            'hasachievements' => !empty($achievements),
            'nextgoals' => $nextgoals,
            'hasnextgoals' => !empty($nextgoals),
            'recentearned' => $recentearned,
            'hasrecentearned' => !empty($recentearned),
            'showhistory' => $showhistory,
            'transactions' => $transactions,
            'hastransactions' => !empty($transactions),
            'canaward' => has_capability('local/kbagamification:award', $systemcontext),
            'awardurl' => (new \moodle_url('/local/kbagamification/award.php', ['userid' => $userid]))->out(false),
            'canawardachievement' => has_capability('local/kbagamification:awardachievement', $systemcontext),
            'achievementawardurl' => (new \moodle_url('/local/kbagamification/achievement_award.php', ['userid' => $userid]))->out(false),
            'canviewleaderboards' => (bool) get_config('local_kbagamification', 'leaderboardsenabled')
                && has_capability('local/kbagamification:viewleaderboards', $systemcontext),
            'leaderboardurl' => (new \moodle_url('/local/kbagamification/leaderboards.php'))->out(false),
            'canviewstore' => $isown
                && (bool) get_config('local_kbagamification', 'rewardstoreenabled')
                && has_capability('local/kbagamification:viewstore', $systemcontext),
            'storeurl' => (new \moodle_url('/local/kbagamification/store.php'))->out(false),
            'ordersurl' => (new \moodle_url('/local/kbagamification/orders.php'))->out(false),
        ];
    }

    /**
     * Read a boolean config while preserving defaults after an older-version upgrade.
     *
     * @param string $name Config key.
     * @param bool $default Default value.
     * @return bool
     */
    private static function config_enabled(string $name, bool $default): bool {
        $value = get_config('local_kbagamification', $name);
        if ($value === false) {
            return $default;
        }
        return (bool) $value;
    }
}
