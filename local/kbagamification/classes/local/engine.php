<?php
namespace local_kbagamification\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Reward engine for XP and KBA Coins.
 */
class engine {
    /**
     * Check whether the plugin is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_kbagamification', 'enabled');
    }

    /**
     * Get or create a wallet.
     *
     * @param int $userid
     * @return \stdClass
     */
    public static function get_wallet(int $userid): \stdClass {
        global $DB;

        $wallet = $DB->get_record('local_kbagam_wallet', ['userid' => $userid]);
        if ($wallet) {
            return $wallet;
        }

        $now = time();
        $wallet = (object) [
            'userid' => $userid,
            'xp' => 0,
            'coins' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $wallet->id = $DB->insert_record('local_kbagam_wallet', $wallet);

        return $wallet;
    }

    /**
     * Apply a configured automatic rule.
     *
     * @param \stdClass $rule
     * @param int $userid
     * @param int $sourceid
     * @param string $reason
     * @param array $metadata
     * @return bool True if a new reward was applied.
     */
    public static function apply_rule(\stdClass $rule, int $userid, int $sourceid, string $reason, array $metadata = []): bool {
        $awardkey = sha1('rule:' . $rule->id . ':user:' . $userid . ':source:' . $sourceid);

        return self::adjust(
            $userid,
            (int) $rule->xp,
            (int) $rule->coins,
            $reason,
            $rule->eventcode,
            $sourceid,
            0,
            $awardkey,
            (int) $rule->id,
            $metadata
        );
    }

    /**
     * Apply an XP/coin adjustment and write an immutable ledger entry.
     *
     * @param int $userid
     * @param int $xpdelta
     * @param int $coinsdelta
     * @param string $reason
     * @param string $source
     * @param int $sourceid
     * @param int $actorid
     * @param string|null $awardkey
     * @param int $ruleid
     * @param array $metadata
     * @return bool False when the idempotency key has already been processed.
     */
    public static function adjust(
        int $userid,
        int $xpdelta,
        int $coinsdelta,
        string $reason,
        string $source = 'manual',
        int $sourceid = 0,
        int $actorid = 0,
        ?string $awardkey = null,
        int $ruleid = 0,
        array $metadata = []
    ): bool {
        global $DB;

        if ($xpdelta === 0 && $coinsdelta === 0) {
            return false;
        }

        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('invaliduser');
        }

        if ($awardkey === null) {
            $awardkey = sha1('manual:' . $userid . ':' . microtime(true) . ':' . random_string(20));
        }

        // Serialize wallet changes per user. This protects Coins purchases from concurrent overspending
        // and also makes automatic/manual XP adjustments safer under parallel requests and cron jobs.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_kbagamification_wallet');
        $lock = $lockfactory->get_lock('user:' . $userid, 10);
        if (!$lock) {
            throw new \moodle_exception('walletlocktimeout', 'local_kbagamification');
        }

        try {
            if ($DB->record_exists('local_kbagam_awards', ['awardkey' => $awardkey])) {
                return false;
            }

            $transaction = $DB->start_delegated_transaction();

            $wallet = self::get_wallet($userid);
            $xpafter = (int) $wallet->xp + $xpdelta;
            $coinsafter = (int) $wallet->coins + $coinsdelta;

            if ($coinsdelta < 0 && class_exists('\\local_kbagamification\\local\\reward_manager')) {
                $reservedcoins = reward_manager::get_reserved_coins($userid);
                if ($coinsafter < $reservedcoins) {
                    throw new \moodle_exception('notenoughavailablecoins', 'local_kbagamification');
                }
            }

            if ($xpafter < 0) {
                throw new \moodle_exception('negativexp', 'local_kbagamification');
            }
            if ($coinsafter < 0) {
                throw new \moodle_exception('notenoughcoins', 'local_kbagamification');
            }

            $now = time();
            $award = (object) [
                'awardkey' => $awardkey,
                'userid' => $userid,
                'ruleid' => $ruleid,
                'source' => $source,
                'sourceid' => $sourceid,
                'xp' => $xpdelta,
                'coins' => $coinsdelta,
                'reason' => $reason,
                'actorid' => $actorid,
                'metadatajson' => empty($metadata) ? null : json_encode($metadata),
                'timecreated' => $now,
            ];
            $award->id = $DB->insert_record('local_kbagam_awards', $award);

            if ($xpdelta !== 0) {
                self::insert_transaction(
                    $userid,
                    (int) $award->id,
                    'XP',
                    $xpdelta,
                    (int) $wallet->xp,
                    $xpafter,
                    $source,
                    $sourceid,
                    $reason,
                    $actorid,
                    $now
                );
            }

            if ($coinsdelta !== 0) {
                self::insert_transaction(
                    $userid,
                    (int) $award->id,
                    'COIN',
                    $coinsdelta,
                    (int) $wallet->coins,
                    $coinsafter,
                    $source,
                    $sourceid,
                    $reason,
                    $actorid,
                    $now
                );
            }

            $wallet->xp = $xpafter;
            $wallet->coins = $coinsafter;
            $wallet->timemodified = $now;
            $DB->update_record('local_kbagam_wallet', $wallet);

            $transaction->allow_commit();
        } finally {
            $lock->release();
        }

        $event = \local_kbagamification\event\reward_applied::create([
            'objectid' => $award->id,
            'context' => \context_system::instance(),
            'relateduserid' => $userid,
            'other' => [
                'xp' => $xpdelta,
                'coins' => $coinsdelta,
                'source' => $source,
            ],
        ]);
        $event->add_record_snapshot('local_kbagam_awards', $award);
        $event->trigger();

        // Re-evaluate achievements after any balance-changing reward. The achievement
        // manager is idempotent, so rewards granted by achievements are safe here too.
        achievement_manager::evaluate_user($userid, $source, $sourceid);

        return true;
    }

    /**
     * Insert one ledger transaction.
     */
    private static function insert_transaction(
        int $userid,
        int $awardid,
        string $currency,
        int $amount,
        int $balancebefore,
        int $balanceafter,
        string $source,
        int $sourceid,
        string $reason,
        int $actorid,
        int $timecreated
    ): void {
        global $DB;

        $record = (object) [
            'userid' => $userid,
            'awardid' => $awardid,
            'currency' => $currency,
            'amount' => $amount,
            'balancebefore' => $balancebefore,
            'balanceafter' => $balanceafter,
            'source' => $source,
            'sourceid' => $sourceid,
            'reason' => $reason,
            'actorid' => $actorid,
            'timecreated' => $timecreated,
        ];
        $DB->insert_record('local_kbagam_txn', $record);
    }
}
