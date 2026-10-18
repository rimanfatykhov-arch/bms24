<?php
namespace local_kbagamification\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Achievement engine.
 *
 * @package local_kbagamification
 */
class achievement_manager {
    /**
     * Evaluate all enabled achievements for a user.
     *
     * @param int $userid User id.
     * @param string $source Evaluation source.
     * @param int $sourceid Source object id.
     * @return int Number of newly awarded achievements.
     */
    public static function evaluate_user(int $userid, string $source = 'automatic', int $sourceid = 0): int {
        global $DB;

        if (!engine::is_enabled() || !(bool) get_config('local_kbagamification', 'achievementsenabled')) {
            return 0;
        }

        $achievements = $DB->get_records('local_kbagam_achievements', ['enabled' => 1], 'sortorder ASC, id ASC');
        $awarded = 0;
        foreach ($achievements as $achievement) {
            if ($DB->record_exists('local_kbagam_userach', [
                'userid' => $userid,
                'achievementid' => $achievement->id,
            ])) {
                continue;
            }

            $progress = self::get_progress($achievement, $userid);
            if ($progress['complete'] && self::award($achievement, $userid, $source, $sourceid, 0, $progress)) {
                $awarded++;
            }
        }

        return $awarded;
    }

    /**
     * Award a specific achievement once.
     *
     * @param \stdClass|int $achievement Achievement record or id.
     * @param int $userid User id.
     * @param string $source Source code.
     * @param int $sourceid Source object id.
     * @param int $actorid Actor user id, 0 for system.
     * @param array $metadata Optional metadata.
     * @return bool True when newly awarded.
     */
    public static function award($achievement, int $userid, string $source = 'manual', int $sourceid = 0,
            int $actorid = 0, array $metadata = []): bool {
        global $DB;

        if (is_int($achievement)) {
            $achievement = $DB->get_record('local_kbagam_achievements', ['id' => $achievement], '*', MUST_EXIST);
        }

        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('invaliduser');
        }

        if ($DB->record_exists('local_kbagam_userach', [
            'userid' => $userid,
            'achievementid' => $achievement->id,
        ])) {
            return false;
        }

        $transaction = $DB->start_delegated_transaction();
        $record = (object) [
            'userid' => $userid,
            'achievementid' => (int) $achievement->id,
            'source' => $source,
            'sourceid' => $sourceid,
            'actorid' => $actorid,
            'metadatajson' => empty($metadata) ? null : json_encode($metadata),
            'timeawarded' => time(),
        ];
        $record->id = $DB->insert_record('local_kbagam_userach', $record);
        $transaction->allow_commit();

        if ((int) $achievement->rewardxp !== 0 || (int) $achievement->rewardcoins !== 0) {
            engine::adjust(
                $userid,
                (int) $achievement->rewardxp,
                (int) $achievement->rewardcoins,
                get_string('reasonachievementreward', 'local_kbagamification', format_string($achievement->name)),
                'achievement',
                (int) $achievement->id,
                $actorid,
                sha1('achievement-reward:' . $achievement->id . ':user:' . $userid),
                0,
                ['userachievementid' => (int) $record->id]
            );
        }

        $event = \local_kbagamification\event\achievement_awarded::create([
            'objectid' => $record->id,
            'context' => \context_system::instance(),
            'relateduserid' => $userid,
            'other' => [
                'achievementid' => (int) $achievement->id,
                'source' => $source,
            ],
        ]);
        $event->add_record_snapshot('local_kbagam_userach', $record);
        $event->trigger();

        self::send_notification($achievement, $userid);
        return true;
    }

    /**
     * Get progress for an achievement.
     *
     * @param \stdClass $achievement Achievement definition.
     * @param int $userid User id.
     * @return array
     */
    public static function get_progress(\stdClass $achievement, int $userid): array {
        global $DB;

        $target = max(1, (int) $achievement->targetvalue);
        $current = 0;

        switch ($achievement->conditiontype) {
            case 'xp_total':
                $wallet = engine::get_wallet($userid);
                $current = (int) $wallet->xp;
                break;

            case 'activity_completed_count':
                $sql = "SELECT COUNT(1)
                          FROM {course_modules_completion}
                         WHERE userid = :userid AND completionstate > :incomplete";
                $current = (int) $DB->get_field_sql($sql, [
                    'userid' => $userid,
                    'incomplete' => COMPLETION_INCOMPLETE,
                ]);
                break;

            case 'course_completed_count':
                $sql = "SELECT COUNT(1)
                          FROM {course_completions}
                         WHERE userid = :userid AND timecompleted IS NOT NULL";
                $current = (int) $DB->get_field_sql($sql, ['userid' => $userid]);
                break;

            case 'specific_course_completed':
                $courseid = (int) $achievement->courseid;
                $current = $courseid > 0 && $DB->record_exists_select('course_completions',
                    'userid = :userid AND course = :courseid AND timecompleted IS NOT NULL',
                    ['userid' => $userid, 'courseid' => $courseid]) ? 1 : 0;
                $target = 1;
                break;

            case 'coins_earned_total':
                $sql = "SELECT COALESCE(SUM(amount), 0)
                          FROM {local_kbagam_txn}
                         WHERE userid = :userid AND currency = :currency AND amount > 0";
                $current = (int) $DB->get_field_sql($sql, ['userid' => $userid, 'currency' => 'COIN']);
                break;
        }

        $percent = min(100, (int) floor(($current / $target) * 100));
        return [
            'current' => $current,
            'target' => $target,
            'percent' => max(0, $percent),
            'complete' => $current >= $target,
        ];
    }

    /**
     * Get enabled achievements with user award/progress state.
     *
     * @param int $userid User id.
     * @return array
     */
    public static function get_catalog_for_user(int $userid): array {
        global $DB;

        $achievements = $DB->get_records('local_kbagam_achievements', ['enabled' => 1], 'sortorder ASC, id ASC');
        $userawards = $DB->get_records('local_kbagam_userach', ['userid' => $userid], '', 'achievementid,timeawarded');
        $byachievement = [];
        foreach ($userawards as $award) {
            $byachievement[(int) $award->achievementid] = $award;
        }

        $result = [];
        foreach ($achievements as $achievement) {
            $progress = self::get_progress($achievement, $userid);
            $award = $byachievement[(int) $achievement->id] ?? null;
            $result[] = [
                'record' => $achievement,
                'awarded' => (bool) $award,
                'timeawarded' => $award ? (int) $award->timeawarded : 0,
                'progress' => $progress,
            ];
        }
        return $result;
    }

    /**
     * Map stored icon code to a safe display glyph.
     *
     * @param string $icon Icon code.
     * @return string
     */
    public static function icon_glyph(string $icon): string {
        $icons = [
            'trophy' => '🏆',
            'footsteps' => '👣',
            'target' => '🎯',
            'fire' => '🔥',
            'graduate' => '🎓',
            'brain' => '🧠',
            'star' => '⭐',
            'medal' => '🏅',
            'crown' => '👑',
            'book' => '📚',
            'briefcase' => '💼',
        ];
        return $icons[$icon] ?? '🏆';
    }

    /**
     * Send Moodle notification for a newly earned achievement.
     *
     * @param \stdClass $achievement Achievement record.
     * @param int $userid User id.
     */
    private static function send_notification(\stdClass $achievement, int $userid): void {
        global $DB;

        if (!(bool) get_config('local_kbagamification', 'achievementnotifications')) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            return;
        }

        $name = format_string($achievement->name);
        $subject = get_string('achievementnotificationsubject', 'local_kbagamification', $name);
        $text = get_string('achievementnotificationbody', 'local_kbagamification', $name);
        $url = new \moodle_url('/local/kbagamification/profile.php', ['userid' => $userid]);

        $message = new \core\message\message();
        $message->component = 'local_kbagamification';
        $message->name = 'achievement';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s($text));
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('learningprofile', 'local_kbagamification');
        message_send($message);
    }
}
