<?php
namespace local_kbagamification;

defined('MOODLE_INTERNAL') || die();

use local_kbagamification\local\achievement_manager;
use local_kbagamification\local\engine;
use local_kbagamification\local\leaderboard_manager;
use local_kbagamification\local\level_manager;
use local_kbagamification\local\reward_manager;

/**
 * Stable internal API for other KBA plugins such as Corporate Dashboard.
 *
 * @package local_kbagamification
 */
class api {
    /**
     * Get a compact gamification summary for one user.
     *
     * @param int $userid User id.
     * @return array
     */
    public static function get_user_summary(int $userid): array {
        global $DB;

        $wallet = engine::get_wallet($userid);
        $current = level_manager::get_current_level((int) $wallet->xp);
        $next = level_manager::get_next_level((int) $wallet->xp);
        $count = $DB->count_records('local_kbagam_userach', ['userid' => $userid]);
        $balancestate = reward_manager::get_balance_state($userid);

        return [
            'userid' => $userid,
            'xp' => (int) $wallet->xp,
            'coins' => (int) $wallet->coins,
            'reservedcoins' => (int) $balancestate['reservedcoins'],
            'availablecoins' => (int) $balancestate['availablecoins'],
            'levelid' => $current ? (int) $current->id : 0,
            'levelname' => $current ? format_string($current->name) : '',
            'nextlevelid' => $next ? (int) $next->id : 0,
            'nextlevelname' => $next ? format_string($next->name) : '',
            'levelprogress' => level_manager::get_progress_percent((int) $wallet->xp),
            'achievementcount' => $count,
        ];
    }

    /**
     * Get awarded achievements for one user.
     *
     * @param int $userid User id.
     * @return array
     */
    public static function get_user_achievements(int $userid): array {
        global $DB;

        $sql = "SELECT ua.id AS userachievementid, ua.timeawarded, ua.source,
                       a.id, a.name, a.description, a.icon, a.rarity,
                       a.rewardxp, a.rewardcoins
                  FROM {local_kbagam_userach} ua
                  JOIN {local_kbagam_achievements} a ON a.id = ua.achievementid
                 WHERE ua.userid = :userid
              ORDER BY ua.timeawarded DESC, ua.id DESC";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);
        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => (int) $record->id,
                'name' => format_string($record->name),
                'description' => format_text($record->description, FORMAT_PLAIN),
                'icon' => achievement_manager::icon_glyph($record->icon),
                'rarity' => $record->rarity,
                'rewardxp' => (int) $record->rewardxp,
                'rewardcoins' => (int) $record->rewardcoins,
                'timeawarded' => (int) $record->timeawarded,
                'source' => $record->source,
            ];
        }
        return $result;
    }

    /**
     * Get the most recently awarded achievement for one user.
     *
     * @param int $userid Moodle user id.
     * @return array|null
     */
    public static function get_user_latest_achievement(int $userid): ?array {
        $items = self::get_latest_achievements_for_users([$userid]);
        return $items[$userid] ?? null;
    }

    /**
     * Get the latest achievement for each supplied user in one batched query.
     *
     * @param array $userids Moodle user ids.
     * @return array Keyed by userid.
     */
    public static function get_latest_achievements_for_users(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), static function($id) {
            return $id > 0;
        })));
        if (!$userids) {
            return [];
        }

        $result = [];
        foreach (array_chunk($userids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'latestusr');
            $sql = "SELECT ua.id AS userachievementid, ua.userid, ua.timeawarded, ua.source,
                           a.id AS achievementid, a.name, a.description, a.icon, a.rarity,
                           a.rewardxp, a.rewardcoins,
                           u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                           u.middlename, u.alternatename
                      FROM {local_kbagam_userach} ua
                      JOIN {local_kbagam_achievements} a ON a.id = ua.achievementid
                      JOIN {user} u ON u.id = ua.userid
                     WHERE ua.userid {$insql}
                       AND u.deleted = 0
                  ORDER BY ua.userid ASC, ua.timeawarded DESC, ua.id DESC";
            foreach ($DB->get_records_sql($sql, $params) as $record) {
                $userid = (int) $record->userid;
                if (isset($result[$userid])) {
                    continue;
                }
                $result[$userid] = [
                    'userid' => $userid,
                    'fullname' => fullname($record),
                    'id' => (int) $record->achievementid,
                    'name' => format_string($record->name),
                    'description' => format_text($record->description, FORMAT_PLAIN),
                    'icon' => achievement_manager::icon_glyph($record->icon),
                    'rarity' => $record->rarity,
                    'rewardxp' => (int) $record->rewardxp,
                    'rewardcoins' => (int) $record->rewardcoins,
                    'timeawarded' => (int) $record->timeawarded,
                    'source' => $record->source,
                ];
            }
        }
        return $result;
    }

    /**
     * Build a leaderboard for an arbitrary set of Moodle user IDs.
     *
     * This is the preferred integration point for Corporate Dashboard: the corporate plugin
     * remains responsible for deciding which employees belong to a company, while Gamification
     * remains responsible for ranking and totals.
     *
     * @param array $userids Moodle user IDs.
     * @param int $limit Maximum rows, 0 for all.
     * @param string $metric xp or achievements.
     * @return array
     */
    public static function get_leaderboard_for_users(array $userids, int $limit = 50, string $metric = 'xp'): array {
        return leaderboard_manager::get_user_leaderboard($userids, $metric, $limit);
    }

    /**
     * Corporate-friendly alias for a set of company employee user IDs.
     *
     * @param array $employeeuserids Company employee Moodle IDs supplied by Corporate Dashboard.
     * @param int $limit Maximum rows, 0 for all.
     * @param string $metric xp or achievements.
     * @return array
     */
    public static function get_company_leaderboard(array $employeeuserids, int $limit = 50, string $metric = 'xp'): array {
        return leaderboard_manager::get_user_leaderboard($employeeuserids, $metric, $limit);
    }

    /**
     * Aggregate company gamification figures for Corporate Dashboard.
     *
     * @param array $employeeuserids Company employee Moodle IDs.
     * @return array
     */
    public static function get_company_gamification_summary(array $employeeuserids): array {
        return leaderboard_manager::get_summary_for_users($employeeuserids);
    }


    /**
     * Get a combined corporate snapshot for Corporate Dashboard or trusted integrations.
     *
     * @param array $employeeuserids Company employee Moodle IDs.
     * @param int $limit Leaderboard row limit, 0 for all.
     * @param string $metric xp or achievements.
     * @return array
     */
    public static function get_company_snapshot(array $employeeuserids, int $limit = 20, string $metric = 'xp'): array {
        $metric = leaderboard_manager::normalise_metric($metric);
        return [
            'summary' => leaderboard_manager::get_summary_for_users($employeeuserids),
            'leaderboard' => leaderboard_manager::get_user_leaderboard($employeeuserids, $metric, $limit),
            'latestachievements' => self::get_latest_achievements_for_users($employeeuserids),
            'metric' => $metric,
        ];
    }

    /**
     * Get a course/group leaderboard.
     *
     * @param int $courseid Moodle course ID.
     * @param int $groupid Moodle group ID, 0 for whole course.
     * @param int $limit Maximum rows, 0 for all.
     * @param string $metric xp or achievements.
     * @return array
     */
    public static function get_course_leaderboard(
        int $courseid,
        int $groupid = 0,
        int $limit = 50,
        string $metric = 'xp'
    ): array {
        return leaderboard_manager::get_course_leaderboard($courseid, $groupid, $metric, $limit);
    }

    /**
     * Get team totals for Moodle groups in one course.
     *
     * @param int $courseid Moodle course ID.
     * @param int $limit Maximum groups, 0 for all.
     * @return array
     */
    public static function get_course_team_leaderboard(int $courseid, int $limit = 50): array {
        return leaderboard_manager::get_course_team_leaderboard($courseid, $limit);
    }

    /**
     * Get the enabled KBA Rewards catalogue for integrations such as kzn.mba.
     * No purchase is performed by this read method.
     *
     * @param int $userid Optional user ID to include current eligibility.
     * @return array
     */
    public static function get_rewards_catalog(int $userid = 0): array {
        $result = [];
        foreach (reward_manager::get_rewards(true) as $reward) {
            $eligibility = $userid ? reward_manager::can_purchase($reward, $userid) : ['allowed' => true, 'reason' => ''];
            $result[] = [
                'id' => (int) $reward->id,
                'name' => format_string($reward->name),
                'description' => format_text($reward->description, FORMAT_PLAIN),
                'type' => $reward->rewardtype,
                'icon' => reward_manager::icon_glyph($reward->icon),
                'sku' => $reward->sku,
                'costcoins' => (int) $reward->costcoins,
                'stock' => (int) $reward->stock,
                'minlevelid' => (int) $reward->minlevelid,
                'minlevelname' => $eligibility['levelname'] ?? '',
                'allowed' => (bool) $eligibility['allowed'],
                'blockedreason' => $eligibility['reason'],
            ];
        }
        return $result;
    }

    /**
     * Check a user's eligibility and available balance for one reward.
     *
     * @param int $rewardid
     * @param int $userid
     * @return array
     */
    public static function check_reward_eligibility(int $rewardid, int $userid): array {
        $reward = reward_manager::get_reward($rewardid);
        $eligibility = reward_manager::can_purchase($reward, $userid);
        $balance = reward_manager::get_balance_state($userid);
        return [
            'userid' => $userid,
            'rewardid' => $rewardid,
            'rewardname' => format_string($reward->name),
            'sku' => (string) $reward->sku,
            'costcoins' => (int) $reward->costcoins,
            'allowed' => (bool) $eligibility['allowed'],
            'blockedreason' => (string) $eligibility['reason'],
            'levelname' => (string) ($eligibility['levelname'] ?? ''),
            'coinsbalance' => $balance['coins'],
            'reservedcoins' => $balance['reservedcoins'],
            'availablecoins' => $balance['availablecoins'],
        ];
    }

    /**
     * Reserve a reward for external checkout.
     *
     * @param int $rewardid
     * @param int $userid
     * @param string $reservationkey
     * @param int $ttl
     * @param string $externalref
     * @param array $metadata
     * @return array
     */
    public static function reserve_reward(
        int $rewardid,
        int $userid,
        string $reservationkey,
        int $ttl = 900,
        string $externalref = '',
        array $metadata = []
    ): array {
        $reservation = reward_manager::reserve($rewardid, $userid, $reservationkey, $ttl, $externalref, $metadata);
        return reward_manager::reservation_to_array($reservation);
    }

    /**
     * Commit a reward reservation and create the Moodle mirror order.
     *
     * @param string $reservationkey
     * @param string $externalref
     * @param array $delivery
     * @return array
     */
    public static function commit_reward_reservation(
        string $reservationkey,
        string $externalref = '',
        array $delivery = []
    ): array {
        $result = reward_manager::commit_reservation($reservationkey, $externalref, $delivery);
        $reservation = reward_manager::reservation_to_array($result['reservation']);
        $order = $result['order'];
        return [
            'reservation' => $reservation,
            'orderid' => $order ? (int) $order->id : 0,
            'ordernumber' => $order ? (string) $order->ordernumber : '',
            'orderstatus' => $order ? (string) $order->status : '',
            'externalref' => $order ? (string) $order->externalref : (string) $reservation['externalref'],
        ];
    }

    /**
     * Cancel a reward reservation.
     *
     * @param string $reservationkey
     * @param string $externalref
     * @return array
     */
    public static function cancel_reward_reservation(string $reservationkey, string $externalref = ''): array {
        return reward_manager::reservation_to_array(reward_manager::cancel_reservation($reservationkey, $externalref));
    }

    /**
     * Get one reservation state.
     *
     * @param string $reservationkey
     * @return array
     */
    public static function get_reward_reservation(string $reservationkey): array {
        return reward_manager::reservation_to_array(reward_manager::get_reservation($reservationkey));
    }

    /**
     * Get reward order history for one user.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_reward_orders(int $userid): array {
        $statuses = reward_manager::get_statuses();
        $result = [];
        foreach (reward_manager::get_user_orders($userid) as $order) {
            $result[] = [
                'id' => (int) $order->id,
                'ordernumber' => $order->ordernumber,
                'rewardid' => (int) $order->rewardid,
                'rewardname' => format_string($order->rewardname),
                'costcoins' => (int) $order->costcoins,
                'status' => $order->status,
                'statuslabel' => $statuses[$order->status] ?? $order->status,
                'timecreated' => (int) $order->timecreated,
            ];
        }
        return $result;
    }

    /**
     * Get recent XP / Coins activity for a user.
     *
     * @param int $userid Moodle user id.
     * @param int $limit Number of rows to return.
     * @return array
     */
    public static function get_user_activity_history(int $userid, int $limit = 20): array {
        global $DB;

        $limit = max(1, min(100, $limit));
        $records = $DB->get_records(
            'local_kbagam_txn',
            ['userid' => $userid],
            'timecreated DESC, id DESC',
            '*',
            0,
            $limit
        );

        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => (int) $record->id,
                'currency' => $record->currency,
                'amount' => (int) $record->amount,
                'balancebefore' => (int) $record->balancebefore,
                'balanceafter' => (int) $record->balanceafter,
                'reason' => format_text($record->reason, FORMAT_PLAIN),
                'source' => $record->source,
                'sourceid' => (int) $record->sourceid,
                'timecreated' => (int) $record->timecreated,
            ];
        }
        return $result;
    }

    /**
     * Get level progress information for one user.
     *
     * @param int $userid Moodle user id.
     * @return array
     */
    public static function get_user_level_progress(int $userid): array {
        $summary = self::get_user_summary($userid);
        $nextminxp = 0;
        if (!empty($summary['nextlevelid'])) {
            $nextlevel = level_manager::get_level((int) $summary['nextlevelid']);
            if ($nextlevel) {
                $nextminxp = (int) $nextlevel->minxp;
            }
        }

        return [
            'userid' => $userid,
            'xp' => (int) $summary['xp'],
            'levelid' => (int) $summary['levelid'],
            'levelname' => $summary['levelname'],
            'nextlevelid' => (int) $summary['nextlevelid'],
            'nextlevelname' => $summary['nextlevelname'],
            'nextlevelxp' => $nextminxp,
            'xptogo' => $nextminxp ? max(0, $nextminxp - (int) $summary['xp']) : 0,
            'progress' => (int) $summary['levelprogress'],
        ];
    }

    /**
     * Get a complete student-facing gamification profile for integrations.
     *
     * @param int $userid Moodle user id.
     * @param int $activitylimit Recent activity row limit.
     * @return array
     */
    public static function get_user_gamification_profile(int $userid, int $activitylimit = 10): array {
        return [
            'summary' => self::get_user_summary($userid),
            'levelprogress' => self::get_user_level_progress($userid),
            'achievements' => self::get_user_achievements($userid),
            'recentactivity' => self::get_user_activity_history($userid, $activitylimit),
        ];
    }


    /**
     * Get compact theme-friendly learner badge data.
     *
     * @param int $userid Moodle user id.
     * @return array
     */
    public static function get_user_theme_badge(int $userid): array {
        $summary = self::get_user_summary($userid);
        $showcoins = get_config('local_kbagamification', 'profile_showcoins');
        if ($showcoins === false) {
            $showcoins = 1;
        }

        return [
            'userid' => $userid,
            'levelname' => $summary['levelname'],
            'xp' => (int) $summary['xp'],
            'coins' => (int) $summary['coins'],
            'showcoins' => (bool) $showcoins,
            'achievementcount' => (int) $summary['achievementcount'],
            'profileurl' => (new \moodle_url('/local/kbagamification/profile.php', ['userid' => $userid]))->out(false),
        ];
    }


    /**
     * Get compact data for the existing KBA personal cabinet (/my/).
     *
     * The method is deliberately presentation-oriented but keeps Moodle/Gamification
     * as the source of truth. Themes can consume this method without reading plugin
     * tables directly.
     *
     * @param int $userid Moodle user id.
     * @return array Empty array when Personal Cabinet integration is disabled.
     */
    public static function get_personal_cabinet_widget_data(int $userid): array {
        $enabled = get_config('local_kbagamification', 'personalcabinetintegration');
        if ($enabled !== false && !(bool) $enabled) {
            return [];
        }

        $summary = self::get_user_summary($userid);
        $progress = self::get_user_level_progress($userid);
        $systemcontext = \context_system::instance();

        $showcoins = get_config('local_kbagamification', 'profile_showcoins');
        if ($showcoins === false) {
            $showcoins = 1;
        }

        $data = [
            'userid' => $userid,
            'levelname' => $summary['levelname'],
            'xp' => (int) $summary['xp'],
            'coins' => (int) $summary['coins'],
            'showcoins' => (bool) $showcoins,
            'achievementcount' => (int) $summary['achievementcount'],
            'progress' => (int) $progress['progress'],
            'nextlevelname' => $progress['nextlevelname'],
            'xptogo' => (int) $progress['xptogo'],
            'hasnextlevel' => !empty($progress['nextlevelid']),
            'profileurl' => (new \moodle_url('/local/kbagamification/profile.php', ['userid' => $userid]))->out(false),
            'achievementsurl' => (new \moodle_url(
                '/local/kbagamification/profile.php',
                ['userid' => $userid, 'section' => 'achievements'],
                'kba-achievements'
            ))->out(false),
            'showleaderboards' => false,
            'leaderboardsurl' => '',
            'showrewards' => false,
            'rewardsurl' => '',
        ];

        if ((bool) get_config('local_kbagamification', 'leaderboardsenabled')
                && has_capability('local/kbagamification:viewleaderboards', $systemcontext)) {
            $data['showleaderboards'] = true;
            $data['leaderboardsurl'] = (new \moodle_url('/local/kbagamification/leaderboards.php'))->out(false);
        }

        if ((bool) get_config('local_kbagamification', 'rewardstoreenabled')
                && has_capability('local/kbagamification:viewstore', $systemcontext)) {
            $data['showrewards'] = true;
            $data['rewardsurl'] = (new \moodle_url('/local/kbagamification/store.php'))->out(false);
        }

        return $data;
    }

    /**
     * Render the compact Personal Cabinet widget using the Gamification template.
     *
     * @param int $userid Moodle user id.
     * @return string HTML, or an empty string when integration is disabled.
     */
    public static function render_personal_cabinet_widget(int $userid): string {
        global $OUTPUT;

        $data = self::get_personal_cabinet_widget_data($userid);
        if (empty($data)) {
            return '';
        }

        return $OUTPUT->render_from_template('local_kbagamification/personal_cabinet_widget', $data);
    }

}
