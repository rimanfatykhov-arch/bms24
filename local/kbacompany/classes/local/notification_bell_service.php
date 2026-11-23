<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * One sound signal per new notification batch for an account, across all its tabs and browsers.
 *
 * Browser storage cannot be relied on (private mode, blocked site data), so the server arbitrates under a Moodle lock.
 * Deduplication is by notification identity, never by "highest id": the account keeps the set of notification ids that
 * already produced a signal (a Moodle user preference). A claim plays only if it contains at least one visible, unread,
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
    /** user_preferences.value is char(1333); keep a margin. */
    private const MAX_VALUE = 1300;

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
            $params, 'id DESC', 'id, threaduuid, timecreated');
        $visible = [];
        $cache = [];
        foreach ($rows as $row) {
            if (communication_service::notification_source_visible($companyuuid, $actor, (string)$row->threaduuid, $cache)) {
                $visible[(int)$row->id] = (int)$row->timecreated;
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
            [$floor, $signalled] = self::signalled($actor, $since);
            $new = array_filter($visible, static function(int $created, int $id) use ($floor, $signalled): bool {
                return $created > $floor && !isset($signalled[$id]);
            }, ARRAY_FILTER_USE_BOTH);
            if (!$new) {
                return false;
            }
            self::store($actor, $floor, $signalled + array_fill_keys(array_keys($visible), true));
            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * Current set, read from the database (the session copy of preferences may be stale in another tab), pruned to
     * rows that can still be claimed. Format: "f<timestamp>;" (optional overflow floor) followed by comma separated ids.
     *
     * @return array{0:int,1:array<int,bool>}
     */
    private static function signalled(int $actor, int $since): array {
        global $DB;
        $raw = (string)$DB->get_field('user_preferences', 'value', ['userid' => $actor, 'name' => self::SIGNALLED_PREFERENCE]);
        $floor = 0;
        if (preg_match('/^f(\d+);/', $raw, $m)) {
            $floor = (int)$m[1];
            $raw = substr($raw, strlen($m[0]));
        }
        if ($floor < $since) {
            $floor = 0; // Everything at or below it is too old to be claimed anyway.
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
        if (!$ids) {
            return [$floor, []];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'bells');
        $params += ['belluser' => $actor, 'bellsince' => $since];
        $keep = $DB->get_fieldset_select('local_kbacompany_commnotif', 'id',
            "userid = :belluser AND timecreated >= :bellsince AND id $insql", $params);
        return [$floor, array_fill_keys(array_map('intval', $keep), true)];
    }

    /**
     * Stores the set. If it no longer fits (more than ~150 signalled notifications within FRESH_SECONDS), every
     * notification created up to now counts as signalled: in such a flood silence is preferred over a repeated signal.
     *
     * @param array<int,bool> $signalled
     */
    private static function store(int $actor, int $floor, array $signalled): void {
        ksort($signalled);
        $value = ($floor ? 'f' . $floor . ';' : '') . implode(',', array_keys($signalled));
        if (strlen($value) > self::MAX_VALUE) {
            $value = 'f' . time() . ';';
        }
        set_user_preference(self::SIGNALLED_PREFERENCE, $value, $actor);
    }
}
