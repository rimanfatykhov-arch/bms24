<?php
namespace local_kbagamification\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Leaderboard calculations for KBA Gamification.
 *
 * Leaderboards are calculated from the existing wallet and achievement data. No duplicate
 * ranking table is maintained, which keeps the wallet/ledger as the source of truth.
 *
 * @package local_kbagamification
 */
class leaderboard_manager {
    /** @var string XP ranking metric. */
    public const METRIC_XP = 'xp';

    /** @var string Achievement ranking metric. */
    public const METRIC_ACHIEVEMENTS = 'achievements';

    /**
     * Return supported metrics.
     *
     * @return array
     */
    public static function get_metrics(): array {
        return [self::METRIC_XP, self::METRIC_ACHIEVEMENTS];
    }

    /**
     * Normalise a ranking metric.
     *
     * @param string $metric Requested metric.
     * @return string
     */
    public static function normalise_metric(string $metric): string {
        return in_array($metric, self::get_metrics(), true) ? $metric : self::METRIC_XP;
    }

    /**
     * Build a leaderboard for a supplied set of Moodle user IDs.
     *
     * This method is intentionally generic so KBA Corporate Dashboard can pass the employee
     * user IDs that belong to a corporate company without Gamification needing to know how
     * the corporate plugin stores company membership.
     *
     * @param array $userids Moodle user IDs.
     * @param string $metric Ranking metric.
     * @param int $limit Maximum rows, 0 for all.
     * @return array Ranked rows.
     */
    public static function get_user_leaderboard(array $userids, string $metric = self::METRIC_XP, int $limit = 0): array {
        global $DB;

        $metric = self::normalise_metric($metric);
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids), static function($id) {
            return $id > 0;
        })));
        if (empty($userids)) {
            return [];
        }

        $users = [];
        $wallets = [];
        $achievementcounts = [];

        // Chunk IN clauses so large corporate cohorts do not hit database parameter limits.
        foreach (array_chunk($userids, 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'usr');
            $sql = "SELECT u.id, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
                           u.middlename, u.alternatename
                      FROM {user} u
                     WHERE u.id {$insql}
                       AND u.deleted = 0
                       AND u.suspended = 0";
            foreach ($DB->get_records_sql($sql, $params) as $user) {
                $users[(int) $user->id] = $user;
            }

            $walletrecords = $DB->get_records_list('local_kbagam_wallet', 'userid', $chunk);
            foreach ($walletrecords as $wallet) {
                $wallets[(int) $wallet->userid] = $wallet;
            }

            [$achsql, $achparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'achusr');
            $sql = "SELECT ua.userid, COUNT(ua.id) AS achievementcount
                      FROM {local_kbagam_userach} ua
                     WHERE ua.userid {$achsql}
                  GROUP BY ua.userid";
            foreach ($DB->get_records_sql($sql, $achparams) as $record) {
                $achievementcounts[(int) $record->userid] = (int) $record->achievementcount;
            }
        }

        $rows = [];
        foreach ($userids as $userid) {
            if (!isset($users[$userid])) {
                continue;
            }

            $user = $users[$userid];
            $wallet = $wallets[$userid] ?? null;
            $xp = $wallet ? (int) $wallet->xp : 0;
            $coins = $wallet ? (int) $wallet->coins : 0;
            $achievementcount = $achievementcounts[$userid] ?? 0;
            $level = level_manager::get_current_level($xp);

            $rows[] = [
                'userid' => $userid,
                'fullname' => fullname($user),
                'xp' => $xp,
                'coins' => $coins,
                'achievementcount' => $achievementcount,
                'levelid' => $level ? (int) $level->id : 0,
                'levelname' => $level ? format_string($level->name) : get_string('nolevel', 'local_kbagamification'),
            ];
        }

        usort($rows, static function(array $a, array $b) use ($metric): int {
            if ($metric === self::METRIC_ACHIEVEMENTS && $a['achievementcount'] !== $b['achievementcount']) {
                return $b['achievementcount'] <=> $a['achievementcount'];
            }
            if ($metric === self::METRIC_XP && $a['xp'] !== $b['xp']) {
                return $b['xp'] <=> $a['xp'];
            }
            // Stable and meaningful tie breakers.
            if ($a['xp'] !== $b['xp']) {
                return $b['xp'] <=> $a['xp'];
            }
            if ($a['achievementcount'] !== $b['achievementcount']) {
                return $b['achievementcount'] <=> $a['achievementcount'];
            }
            return strcasecmp($a['fullname'], $b['fullname']);
        });

        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);

        if ($limit > 0) {
            return array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    /**
     * Get active enrolled users ranked for a course or course group.
     *
     * @param int $courseid Moodle course ID.
     * @param int $groupid Moodle group ID, 0 for the whole course.
     * @param string $metric Ranking metric.
     * @param int $limit Maximum rows, 0 for all.
     * @return array
     */
    public static function get_course_leaderboard(
        int $courseid,
        int $groupid = 0,
        string $metric = self::METRIC_XP,
        int $limit = 0
    ): array {
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context, '', $groupid, 'u.id', 'u.id ASC', 0, 0, true);
        $userids = array_map('intval', array_keys($users));
        return self::get_user_leaderboard($userids, $metric, $limit);
    }

    /**
     * Get a team leaderboard for Moodle groups in one course.
     *
     * @param int $courseid Moodle course ID.
     * @param int $limit Maximum groups, 0 for all.
     * @return array
     */
    public static function get_course_team_leaderboard(int $courseid, int $limit = 0): array {
        global $CFG;

        require_once($CFG->libdir . '/grouplib.php');

        $activeusers = self::get_course_userids($courseid);
        $activeindex = array_fill_keys($activeusers, true);
        $groups = groups_get_all_groups($courseid, 0, 0, 'g.id,g.name');
        $rows = [];

        foreach ($groups as $group) {
            $members = groups_get_members((int) $group->id, 'u.id');
            $memberids = [];
            foreach ($members as $member) {
                $memberid = (int) $member->id;
                if (isset($activeindex[$memberid])) {
                    $memberids[] = $memberid;
                }
            }

            $ranking = self::get_user_leaderboard($memberids, self::METRIC_XP, 0);
            $totalxp = 0;
            $totalachievements = 0;
            foreach ($ranking as $memberrow) {
                $totalxp += (int) $memberrow['xp'];
                $totalachievements += (int) $memberrow['achievementcount'];
            }
            $participants = count($ranking);

            $rows[] = [
                'groupid' => (int) $group->id,
                'groupname' => format_string($group->name),
                'participants' => $participants,
                'totalxp' => $totalxp,
                'averagexp' => $participants > 0 ? (int) round($totalxp / $participants) : 0,
                'achievementcount' => $totalachievements,
            ];
        }

        usort($rows, static function(array $a, array $b): int {
            if ($a['totalxp'] !== $b['totalxp']) {
                return $b['totalxp'] <=> $a['totalxp'];
            }
            if ($a['achievementcount'] !== $b['achievementcount']) {
                return $b['achievementcount'] <=> $a['achievementcount'];
            }
            return strcasecmp($a['groupname'], $b['groupname']);
        });

        $rank = 1;
        foreach ($rows as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);

        if ($limit > 0) {
            return array_slice($rows, 0, $limit);
        }
        return $rows;
    }

    /**
     * Get active user IDs from a course.
     *
     * @param int $courseid Moodle course ID.
     * @return array
     */
    public static function get_course_userids(int $courseid): array {
        $context = \context_course::instance($courseid);
        $users = get_enrolled_users($context, '', 0, 'u.id', 'u.id ASC', 0, 0, true);
        return array_map('intval', array_keys($users));
    }

    /**
     * Aggregate gamification numbers for an arbitrary group of users.
     *
     * @param array $userids Moodle user IDs.
     * @return array
     */
    public static function get_summary_for_users(array $userids): array {
        $rows = self::get_user_leaderboard($userids, self::METRIC_XP, 0);
        $totalxp = 0;
        $totalcoins = 0;
        $achievementcount = 0;
        $levels = [];

        foreach ($rows as $row) {
            $totalxp += (int) $row['xp'];
            $totalcoins += (int) $row['coins'];
            $achievementcount += (int) $row['achievementcount'];
            $levelid = (int) $row['levelid'];
            if (!isset($levels[$levelid])) {
                $levels[$levelid] = [
                    'levelid' => $levelid,
                    'levelname' => $row['levelname'],
                    'count' => 0,
                ];
            }
            $levels[$levelid]['count']++;
        }

        // Order the level distribution according to the configured XP thresholds.
        $leveldistribution = [];
        foreach (level_manager::get_levels() as $level) {
            $levelid = (int) $level->id;
            if (isset($levels[$levelid])) {
                $leveldistribution[] = $levels[$levelid];
                unset($levels[$levelid]);
            }
        }
        foreach ($levels as $remaining) {
            $leveldistribution[] = $remaining;
        }

        $participants = count($rows);
        return [
            'participants' => $participants,
            'totalxp' => $totalxp,
            'averagexp' => $participants > 0 ? (int) round($totalxp / $participants) : 0,
            'totalcoins' => $totalcoins,
            'achievementcount' => $achievementcount,
            'averageachievements' => $participants > 0 ? round($achievementcount / $participants, 1) : 0,
            'leveldistribution' => $leveldistribution,
        ];
    }
}
