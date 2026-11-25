<?php
namespace local_kbacompany\local;

defined('MOODLE_INTERNAL') || die();

/**
 * ADR-0011 block 2 (owner decisions 25.11.2026): which days need a daily report and reminders when one is missing.
 *
 * Working days are Monday to Friday in the author's time zone: the People work calendar holds events, not a work
 * schedule, so it cannot tell a day off (ADR-0011 S5). Reminders (S6) go to the author on a working day at the
 * configured time while today's report is not sent, and to the author's reviewer (ADR-0010 resolution) once the last
 * working day stays unsent the next day. Both are one deterministic notification per day, so cron replays never
 * duplicate them. Behind dailyreports_enabled and dailyreports_remind_enabled (default off). Reads only; the only
 * writes are the notifications.
 */
final class daily_report_schedule_service {
    /** ISO weekdays that need a report (1 = Monday ... 7 = Sunday). */
    public const WORKDAYS = [1, 2, 3, 4, 5];
    public const DEFAULT_REMIND_TIME = '18:00';
    public const DEFAULT_OVERDUE_TIME = '10:00';

    /** True for a working day 'YYYY-MM-DD'; false for a day off or a malformed value. */
    public static function required(string $day): bool {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d') === $day && in_array((int)$date->format('N'), self::WORKDAYS, true);
    }

    /** The latest working day strictly before $day. */
    public static function previous_required(string $day): string {
        $date = new \DateTimeImmutable($day . ' 12:00:00', new \DateTimeZone('UTC'));
        do {
            $date = $date->modify('-1 day');
        } while (!in_array((int)$date->format('N'), self::WORKDAYS, true));
        return $date->format('Y-m-d');
    }

    /** Site switch (admin_setting), default off: the operator turns reminders on. */
    public static function reminders_enabled(): bool {
        return (bool)get_config('local_kbacompany', 'dailyreports_remind_enabled');
    }

    /** 'HH:MM' setting, or the default when empty or malformed. */
    public static function time_setting(string $name, string $default): string {
        $value = trim((string)get_config('local_kbacompany', $name));
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : $default;
    }

    /** At least one submitted version exists for this author and day, company-scoped. */
    public static function submitted(string $companyuuid, int $userid, string $day): bool {
        global $DB;
        return $DB->record_exists_select('local_kbacompany_dreport',
            'companyuuid = :c AND userid = :u AND reportday = :d AND currentversionuuid <> :empty',
            ['c' => $companyuuid, 'u' => $userid, 'd' => $day, 'empty' => '']);
    }

    /**
     * One cron pass. Returns counters for the task log.
     * @param int $now Unix time (tests pass a fixed instant)
     */
    public static function run_due(int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $stats = ['scanned' => 0, 'reminders' => 0, 'overdue' => 0, 'skipped' => 0];
        if (!daily_report_store_service::enabled() || !self::reminders_enabled()) {
            return $stats;
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_kbacompany')->get_lock('dailyreports_reminders', 0);
        if (!$lock) {
            return $stats;
        }
        $remind = self::time_setting('dailyreports_remind_time', self::DEFAULT_REMIND_TIME);
        $overdue = self::time_setting('dailyreports_overdue_time', self::DEFAULT_OVERDUE_TIME);
        try {
            $members = $DB->get_recordset_sql("SELECT m.id, m.companyuuid, m.userid, m.timecreated
                    FROM {local_kbacompany_member} m
                    JOIN {local_kbacompany_company} c ON c.uuid = m.companyuuid AND c.active = 1
                    JOIN {user} u ON u.id = m.userid AND u.deleted = 0 AND u.suspended = 0
                   WHERE m.active = 1
                ORDER BY m.id ASC");
            try {
                foreach ($members as $member) {
                    $stats['scanned']++;
                    try {
                        self::deliver((string)$member->companyuuid, (int)$member->userid, (int)$member->timecreated, $now,
                            $remind, $overdue, $stats);
                    } catch (\Throwable $e) {
                        $stats['skipped']++;
                        mtrace('BMS daily report reminder skipped: ' . get_class($e));
                    }
                }
            } finally {
                $members->close();
            }
        } finally {
            $lock->release();
        }
        return $stats;
    }

    private static function deliver(string $companyuuid, int $userid, int $since, int $now, string $remind, string $overdue,
            array &$stats): void {
        $user = \core_user::get_user($userid);
        if (!$user || !daily_report_store_service::eligible_reviewer($companyuuid, $userid, 0)) {
            // Same membership test as reviewers: active member of this company, not revoked.
            $stats['skipped']++;
            return;
        }
        $zone = \core_date::get_user_timezone_object($user);
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
        $today = $local->format('Y-m-d');
        $clock = $local->format('H:i');
        if (self::required($today) && strcmp($clock, $remind) >= 0 && !self::submitted($companyuuid, $userid, $today)) {
            $title = get_string('dailyreports_remind_title', 'local_kbacompany', $today);
            if (daily_report_review_service::notify($companyuuid, $userid, 'dailyreport_reminder', [$today], $title,
                    daily_report_ui_service::url($companyuuid, ['day' => $today]))) {
                $stats['reminders']++;
            }
        }
        if (strcmp($clock, $overdue) < 0) {
            return;
        }
        $day = self::previous_required($today);
        $dayend = (new \DateTimeImmutable($day . ' 23:59:59', $zone))->getTimestamp();
        if (($since > 0 && $since > $dayend) || self::submitted($companyuuid, $userid, $day)) {
            return;
        }
        [$reviewer, $source] = daily_report_store_service::resolve_reviewer($companyuuid, $userid, $day);
        if ($reviewer <= 0 || $reviewer === $userid || in_array($source, ['self_top', 'none'], true)) {
            return;
        }
        $title = get_string('dailyreports_overdue_title', 'local_kbacompany', (object)['name' => fullname($user), 'day' => $day]);
        if (daily_report_review_service::notify($companyuuid, $reviewer, 'dailyreport_overdue', [(string)$userid, $day], $title,
                daily_report_ui_service::url($companyuuid, ['tab' => 'review', 'queue' => 'team', 'day' => $day]))) {
            $stats['overdue']++;
        }
    }
}
