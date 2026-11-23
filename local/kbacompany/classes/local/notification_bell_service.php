<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * One sound signal per new notification batch for an account, across all its tabs and browsers.
 *
 * Browser storage cannot be relied on (private mode, blocked site data), so the server arbitrates: under a Moodle
 * lock it compares the newest claimed notification id with a high-water mark kept as the user's own preference.
 * Only the first claim of a newer visible unread notification gets "play". The canonical notification registry,
 * its generation and read rules are untouched; nothing is marked read.
 */
final class notification_bell_service {
    /** Highest notification id that already produced a signal for this account (Moodle user preference). */
    public const MARK_PREFERENCE = 'local_kbacompany_notification_sound_mark';
    /** Rows older than this never sound (no queued signals after reconnect or sleep). */
    public const FRESH_SECONDS = 600;
    public const MAX_CLAIM = 20;

    /**
     * @param string[] $uuids notification uuids the tab saw as new
     * @return bool true only for the single claim that should play
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
        [$insql, $params] = $DB->get_in_or_equal($uuids, SQL_PARAMS_NAMED, 'bellu');
        $params += ['bellcompany' => $companyuuid, 'belluser' => $actor, 'bellsince' => time() - self::FRESH_SECONDS];
        $rows = $DB->get_records_select('local_kbacompany_commnotif',
            "companyuuid = :bellcompany AND userid = :belluser AND isread = 0 AND timecreated >= :bellsince AND uuid $insql",
            $params, 'id DESC', 'id, threaduuid');
        $newest = 0;
        $cache = [];
        foreach ($rows as $row) {
            if (communication_service::notification_source_visible($companyuuid, $actor, (string)$row->threaduuid, $cache)) {
                $newest = max($newest, (int)$row->id);
            }
        }
        if ($newest === 0) {
            return false;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany_bell')->get_lock('user' . $actor, 5);
        if (!$lock) {
            return false; // Prefer silence over a duplicate signal.
        }
        try {
            // Read the mark from the database: the session copy of preferences may be stale in another tab.
            $mark = (int)$DB->get_field('user_preferences', 'value', ['userid' => $actor, 'name' => self::MARK_PREFERENCE]);
            if ($newest <= $mark) {
                return false;
            }
            set_user_preference(self::MARK_PREFERENCE, (string)$newest, $actor);
            return true;
        } finally {
            $lock->release();
        }
    }
}
