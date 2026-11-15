<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Records that a user opened a task card, in the canonical task history (tasklog).
 *
 * This is an "opened" event, not proof of reading. It never changes task state,
 * assignee, dates, result, notifications or permissions. The server determines the
 * user and time; company membership and current read access are re-checked on
 * every request. At most one event per user + task is kept per WINDOW seconds; a
 * named lock makes the check-then-insert safe against concurrent requests.
 */
class task_open_service {
    public const ACTION = 'opened';
    public const WINDOW = 900;
    /** Opened events shown in History in addition to the 50 business events. */
    public const HISTORY_LIMIT = 20;

    /**
     * @return string 'recorded' | 'duplicate' | 'busy'
     * @throws \moodle_exception when the company or task is not readable by the actor.
     */
    public static function record(string $companyuuid, int $actor, string $taskuuid): string {
        global $DB;
        if ($actor <= 0 || $companyuuid === '' || $taskuuid === '') { self::fail(); }
        // Membership (throws) and the canonical Task Center visibility predicate.
        if (!task_service::readable_projection($companyuuid, $actor, $taskuuid)) { self::fail(); }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')
            ->get_lock('taskopen:' . sha1($companyuuid . '|' . $taskuuid . '|' . $actor), 5);
        // A concurrent request for the same pair holds the lock and will write or dedupe itself.
        if (!$lock) { return 'busy'; }
        try {
            $now = time();
            $recent = $DB->record_exists_select('local_kbacompany_tasklog',
                'companyuuid = :company AND taskuuid = :task AND actorid = :actor AND action = :action AND timecreated > :since',
                ['company' => $companyuuid, 'task' => $taskuuid, 'actor' => $actor,
                    'action' => self::ACTION, 'since' => $now - self::WINDOW]);
            if ($recent) { return 'duplicate'; }
            $DB->insert_record('local_kbacompany_tasklog', (object)[
                'companyuuid' => $companyuuid, 'taskuuid' => $taskuuid, 'actorid' => $actor,
                'action' => self::ACTION, 'oldstatus' => '', 'newstatus' => '',
                'details' => '{}', 'timecreated' => $now,
            ]);
            return 'recorded';
        } finally {
            $lock->release();
        }
    }

    private static function fail(): void {
        throw new \moodle_exception('taskcenter_permission', 'local_kbacompany');
    }
}
