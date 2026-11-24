<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * One sound signal per new notification batch for an account, across all its tabs and browsers.
 *
 * Browser storage cannot be relied on (private mode, blocked site data), so the server arbitrates under a Moodle lock.
 * Deduplication is by notification identity, never by "highest id": the account keeps the set of notification ids that
 * already produced a signal (stored in Moodle user preferences). A claim plays only if it contains at least one visible, unread,
 * fresh notification of this user in this company that is not yet in the set; all visible ids of the claim are then
 * added. So a notification never sounds twice (several tabs, repeated polls), a batch sounds once, and an older id that
 * never sounded (another company, out-of-order creation) is not suppressed by a newer one.
 *
 * The set only needs ids that could still be claimed: rows older than FRESH_SECONDS never sound, so they are pruned.
 * The canonical notification registry, its generation and read rules are untouched; nothing is marked read.
 */
final class notification_bell_service {
    /** Ids of notifications that already produced a signal for this account (Moodle user preference). */
    public const SIGNALLED_PREFERENCE = 'local_kbacompany_notification_sound_signalled';
    /** Rows older than this never sound (no queued signals after reconnect or sleep). */
    public const FRESH_SECONDS = 600;
    public const MAX_CLAIM = 20;
    /** 64 signed BIGINT ids with separators fit within user_preferences.value (1333 characters). */
    private const IDS_PER_PREFERENCE = 64;

    /**
     * @param string[] $uuids notification uuids the tab saw as new
     * @return bool true only for the claim that should play
     */
    public static function claim_signal(string $companyuuid, int $actor, array $uuids): bool {
        global $DB;
        company_context_service::context($companyuuid, $actor);
        $uuids = array_values(array_unique(array_filter(array_map('strval', $uuids), static function(string $u): bool {
            return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $u);
        })));
        if (!$uuids || count($uuids) > self::MAX_CLAIM || !communication_center_ui_service::sound_enabled($actor)) {
            return false;
        }
        $since = time() - self::FRESH_SECONDS;
        [$insql, $params] = $DB->get_in_or_equal($uuids, SQL_PARAMS_NAMED, 'bellu');
        $params += ['bellcompany' => $companyuuid, 'belluser' => $actor, 'bellsince' => $since];
        $rows = $DB->get_records_select('local_kbacompany_commnotif',
            "companyuuid = :bellcompany AND userid = :belluser AND isread = 0 AND timecreated >= :bellsince AND uuid $insql",
            $params, 'id DESC', 'id, threaduuid');
        $visible = [];
        $cache = [];
        foreach ($rows as $row) {
            if (communication_service::notification_source_visible($companyuuid, $actor, (string)$row->threaduuid, $cache)) {
                $visible[(int)$row->id] = true;
            }
        }
        if (!$visible) {
            return false;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany_bell')->get_lock('user' . $actor, 5);
        if (!$lock) {
            return false; // Prefer silence over a duplicate signal.
        }
        try {
            $preferences = self::signal_preferences($actor);
            $signalled = self::signalled($actor, $since, $preferences);
            $new = array_diff_key($visible, $signalled);
            if (!$new) {
                return false;
            }
            self::store($actor, $preferences, $signalled + $visible);
            return true;
        } finally {
            $lock->release();
        }
    }

    /** Read only this account's bell preferences, bypassing any stale session copy. */
    public static function signal_preferences(int $actor): array {
        global $DB;
        $prefix = $DB->sql_like_escape(self::SIGNALLED_PREFERENCE . '_');
        $rows = $DB->get_records_select_menu('user_preferences',
            'userid = :belluser AND (name = :bellname OR ' . $DB->sql_like('name', ':bellprefix') . ')',
            ['belluser' => $actor, 'bellname' => self::SIGNALLED_PREFERENCE, 'bellprefix' => $prefix . '%'], '', 'name, value');
        $pattern = '/^' . self::SIGNALLED_PREFERENCE . '(?:_[1-9][0-9]*)?$/D';
        return array_filter($rows, static function(string $name) use ($pattern): bool {
            return (bool)preg_match($pattern, $name);
        }, ARRAY_FILTER_USE_KEY);
    }

    /** Keep only explicit identities whose notifications are still claimable. */
    private static function signalled(int $actor, int $since, array $preferences): array {
        global $DB;
        $ids = [];
        foreach ($preferences as $raw) {
            // Older draft builds could store an overflow marker. It does not identify any notification.
            $raw = preg_replace('/^f\d+;/', '', (string)$raw);
            foreach (explode(',', $raw) as $id) {
                if (ctype_digit($id) && (int)$id > 0) {
                    $ids[(int)$id] = true;
                }
            }
        }
        $keep = [];
        foreach (array_chunk(array_keys($ids), 400) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'bells');
            $params += ['belluser' => $actor, 'bellsince' => $since];
            $rows = $DB->get_fieldset_select('local_kbacompany_commnotif', 'id',
                "userid = :belluser AND timecreated >= :bellsince AND id $insql", $params);
            $keep += array_fill_keys(array_map('intval', $rows), true);
        }
        return $keep;
    }

    /** Write the exact set in bounded rows and remove surplus rows under the same lock and transaction. */
    private static function store(int $actor, array $preferences, array $signalled): void {
        global $DB;
        ksort($signalled);
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach (array_chunk(array_keys($signalled), self::IDS_PER_PREFERENCE) as $index => $ids) {
                $name = self::SIGNALLED_PREFERENCE . ($index === 0 ? '' : '_' . $index);
                $value = implode(',', $ids);
                if (($preferences[$name] ?? null) !== $value) {
                    set_user_preference($name, $value, $actor);
                }
                unset($preferences[$name]);
            }
            foreach (array_keys($preferences) as $name) {
                unset_user_preference($name, $actor);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }
}
